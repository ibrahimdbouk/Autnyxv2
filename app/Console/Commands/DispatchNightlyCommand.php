<?php

namespace App\Console\Commands;

use App\Services\Pipeline\NightlyChain;
use Illuminate\Console\Command;

/**
 * WP5.2 — runs hourly. Starts each tenant's nightly chain at its local night
 * and its morning agents once that night has finished. Needs the queue worker.
 */
class DispatchNightlyCommand extends Command
{
    protected $signature = 'nightly:dispatch {--tenant= : Only this tenant} {--force : Start (or re-run) tonight\'s chain now, whatever the hour}';

    protected $description = 'Start each tenant\'s nightly chain at its local night, and its morning agents after it (hourly).';

    public function handle(NightlyChain $chain): int
    {
        $r = $chain->dispatchDue(null, $this->option('tenant') ? (int) $this->option('tenant') : null, (bool) $this->option('force'));
        $this->info("Nightly chains started: {$r['nights']}; morning agents started: {$r['mornings']}.");

        return self::SUCCESS;
    }
}
