<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ops\PlatformHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Proactive failure alerting. Runs after the nightly pipeline; if any critical
 * command failed, went stale (didn't run — e.g. under hibernation), or queue
 * jobs failed, it alerts super admins (Filament database notification → the
 * admin bell) and emails the owner (best-effort; a no-op until MAIL is set).
 *
 * Recording/observability already exists (job_runs + PlatformHealthService);
 * this is the push half — so a failure surfaces instead of waiting to be found.
 */
class SystemHealthCheckCommand extends Command
{
    protected $signature = 'system:health-check';

    protected $description = 'Check the nightly pipeline for failures/staleness and alert super admins.';

    public function handle(PlatformHealthService $health): int
    {
        $problems = [];

        // Scheduled runs that failed in the last 24h.
        foreach ($health->recentFailures(20) as $failure) {
            if (Carbon::parse($failure->ran_at)->gte(now()->subDay())) {
                $problems[] = "{$failure->command} failed — " . Str::limit((string) $failure->message, 140);
            }
        }

        // Critical daily commands that didn't run when they should have.
        foreach ($health->staleCommands() as $stale) {
            $last = $stale['last_success'] ? Carbon::parse($stale['last_success'])->diffForHumans() : 'never';
            $problems[] = "{$stale['command']} has not succeeded in over {$stale['max_hours']}h (last success: {$last})";
        }

        // Failed queue jobs.
        if (($failedJobs = $health->failedQueueJobs()) > 0) {
            $problems[] = "{$failedJobs} failed queue job(s) in the failed_jobs table";
        }

        if (empty($problems)) {
            $this->info('System health: OK — no failures or stale jobs.');
            return self::SUCCESS;
        }

        $body = "Autnyx detected " . count($problems) . " platform issue(s):\n\n- " . implode("\n- ", $problems);
        $this->warn($body);
        $this->sendAlerts($body, count($problems));

        // Return SUCCESS so the health check itself is not logged as a failed run.
        return self::SUCCESS;
    }

    private function sendAlerts(string $body, int $count): void
    {
        $title = "Platform health: {$count} issue(s) detected";

        // Super admins — Filament database notification (shows in the admin bell).
        foreach (User::where('is_super_admin', true)->get() as $admin) {
            try {
                \Filament\Notifications\Notification::make()
                    ->title($title)
                    ->body(Str::limit($body, 500))
                    ->danger()
                    ->sendToDatabase($admin);
            } catch (\Throwable $e) {
                Log::warning('[health-check] db notification failed: ' . $e->getMessage());
            }
        }

        // Owner — best-effort email (no-op / logged if MAIL is not configured).
        $ownerEmail = config('autnyx.owner_email');
        if (! empty($ownerEmail)) {
            try {
                Mail::raw($body, function ($message) use ($ownerEmail, $title) {
                    $message->to($ownerEmail)->subject("[Autnyx] {$title}");
                });
            } catch (\Throwable $e) {
                Log::warning('[health-check] alert email failed: ' . $e->getMessage());
            }
        }
    }
}
