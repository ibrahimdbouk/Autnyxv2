<?php

namespace App\Console\Commands;

use App\Mail\AnomalyDigestMail;
use App\Models\Anomaly;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyAnomaliesCommand extends Command
{
    /** Tenants that failed this run (WP5.2 — reported through the exit code). */
    private int $failures = 0;

    protected $signature = 'anomalies:notify {--tenant= : Specific tenant ID} {--dry-run : Preview without sending}';

    protected $description = 'Send anomaly digest emails for tenants that have a notification_email set';

    /** Most anomalies listed in one digest; the rest are counted ("and N more"). */
    public const MAX_LISTED = 50;

    /**
     * WP5.4 (audit M15, M16) — one digest per recipient per night:
     *   • only LIVE anomalies (not dismissed, not resolved), at the severities the
     *     organisation alerts on, and not covered by an active suppression;
     *   • recipients = the organisation address (unless unsubscribed) plus every
     *     person who opted in; each mail carries its own signed unsubscribe link;
     *   • the anomalies are marked notified BEFORE the mail is queued, so a
     *     retry never sends the same digest twice;
     *   • the mail lists the 50 that matter most and counts the rest.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun   = $this->option('dry-run');

        // WP1.4: kill-switch.
        if (! config('autnyx.digest_enabled') && ! $dryRun) {
            $this->warn('Anomaly digest is disabled (ANOMALY_DIGEST_ENABLED=false) — nothing sent.');

            return Command::SUCCESS;
        }

        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($tenantId, fn ($q) => $q->whereKey($tenantId))->get();

        $sent = 0;
        foreach ($tenants as $tenant) {
            try {
                $recipients = $this->recipients($tenant);
                if ($recipients === []) {
                    continue;
                }

                $severities = array_values(array_filter([
                    $tenant->notify_on_high ? 'high' : null,
                    $tenant->notify_on_medium ? 'medium' : null,
                ]));
                if ($severities === []) {
                    continue;
                }

                $suppression = app(\App\Services\Noise\SuppressionService::class);
                $anomalies = Anomaly::where('tenant_id', $tenant->id)
                    ->active()
                    ->whereNull('notified_at')
                    ->whereIn('severity', $severities)
                    ->get()
                    ->reject(fn ($a) => $suppression->matchFor($a) !== null)
                    ->sortBy([
                        fn ($a, $b) => ($a->severity === 'high' ? 0 : 1) <=> ($b->severity === 'high' ? 0 : 1),
                        fn ($a, $b) => \App\Support\Detection\ValueModel::amount((array) $b->context) <=> \App\Support\Detection\ValueModel::amount((array) $a->context),
                    ])->values();

                if ($anomalies->isEmpty()) {
                    $this->line("  {$tenant->name}: no new anomalies to notify.");
                    continue;
                }

                $this->line("  {$tenant->name}: {$anomalies->count()} anomaly(ies) → " . count($recipients) . ' recipient(s)');
                if ($dryRun) {
                    $sent++;
                    continue;
                }

                // Mark first (in chunks), then queue — a re-run can't double-send.
                foreach ($anomalies->pluck('id')->chunk(1000) as $ids) {
                    Anomaly::whereIn('id', $ids->all())->update(['notified_at' => now()]);
                }
                $listed = new \Illuminate\Database\Eloquent\Collection($anomalies->take(self::MAX_LISTED)->all());
                foreach ($recipients as [$email, $kind, $id]) {
                    $unsubscribe = \Illuminate\Support\Facades\URL::signedRoute('digest.unsubscribe', ['kind' => $kind, 'id' => $id]);
                    Mail::to($email)->queue(new AnomalyDigestMail($tenant, $listed, $anomalies->count(), $unsubscribe));
                }

                $sent++;
            } catch (\Throwable $e) {
                $this->error("  {$tenant->name} FAILED: {$e->getMessage()}");
                Log::error("[anomalies:notify] Tenant {$tenant->id}: {$e->getMessage()}");
                $this->failures++; // WP5.2: a tenant that failed fails the command
            }
        }

        $this->info($dryRun
            ? "Dry run complete — {$sent} tenant(s) would receive email."
            : "Digests queued for {$sent} tenant(s)."
        );

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int,array{0:string,1:string,2:int}> [email, kind, id] */
    private function recipients(Tenant $tenant): array
    {
        $out = [];
        $settings = (array) ($tenant->settings ?? []);
        if (! empty($tenant->notification_email) && empty($settings['digest_email_off'])) {
            $out[strtolower($tenant->notification_email)] = [$tenant->notification_email, 'tenant', (int) $tenant->id];
        }
        foreach (\App\Models\User::where('tenant_id', $tenant->id)->where('digest_opt_in', true)->whereNotNull('email')->get(['id', 'email']) as $u) {
            $out[strtolower($u->email)] ??= [$u->email, 'user', (int) $u->id];
        }

        return array_values($out);
    }
}
