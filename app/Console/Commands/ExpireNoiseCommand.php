<?php

namespace App\Console\Commands;

use App\Services\Noise\SnoozeService;
use App\Services\Noise\SuppressionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Feature 6 — returns expired snoozes and expires due suppressions automatically.
 */
class ExpireNoiseCommand extends Command
{
    protected $signature = 'noise:expire';

    protected $description = 'Clear expired snoozes and expire due suppressions (all tenants)';

    public function handle(SnoozeService $snooze, SuppressionService $suppression): int
    {
        try {
            $unsnoozed = $snooze->clearExpired();
            $expired   = $suppression->expireDue();
            $this->info("Cleared {$unsnoozed} snoozes, expired {$expired} suppressions.");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed: {$e->getMessage()}");
            Log::error('[noise:expire] ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
