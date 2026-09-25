<?php

namespace App\Jobs\Nightly;

use App\Models\JobRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * WP5.2 — the morning agents for one tenant, at its local morning and only
 * after its night finished: follow-ups and the daily briefing every day; the
 * weekly briefing and the data-quality check on its Monday.
 */
class RunMorningAgentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $tenantId, public bool $monday = false)
    {
    }

    public function handle(): void
    {
        $commands = ['agents:action-followup', 'agents:daily-briefing'];
        if ($this->monday) {
            array_push($commands, 'agents:data-quality', 'agents:weekly-briefing');
        }

        foreach ($commands as $command) {
            $t0 = microtime(true);
            try {
                $code = Artisan::call($command, ['--tenant' => $this->tenantId]);
                $message = $code === 0 ? null : Str::limit(trim(Artisan::output()), 500);
            } catch (\Throwable $e) {
                $code = 1;
                $message = Str::limit($e->getMessage(), 500);
            }
            try {
                JobRun::create([
                    'tenant_id' => $this->tenantId, 'command' => $command,
                    'status' => $code === 0 ? JobRun::STATUS_SUCCESS : JobRun::STATUS_FAILED,
                    'duration_ms' => (int) round((microtime(true) - $t0) * 1000), 'message' => $message, 'ran_at' => now(),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
