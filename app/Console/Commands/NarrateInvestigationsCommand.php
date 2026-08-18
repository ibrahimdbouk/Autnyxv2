<?php

namespace App\Console\Commands;

use App\Models\Investigation;
use App\Models\Tenant;
use App\Services\InvestigationNarratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NarrateInvestigationsCommand extends Command
{
    protected $signature = 'investigations:narrate
                            {--tenant= : Specific tenant ID}
                            {--investigation= : Specific investigation ID}
                            {--force : Re-narrate even if already generated}';

    protected $description = 'Generate AI narratives for open investigations (evidence package → single Claude call)';

    public function handle(InvestigationNarratorService $narrator): int
    {
        $force          = (bool) $this->option('force');
        $investigationId = $this->option('investigation');
        $tenantId        = $this->option('tenant');

        // Single investigation
        if ($investigationId) {
            $investigation = Investigation::find($investigationId);
            if (!$investigation) {
                $this->error("Investigation #{$investigationId} not found.");
                return Command::FAILURE;
            }
            $this->info("Narrating investigation #{$investigationId}…");
            $narrator->narrate($investigation, $force);
            $this->info('Done.');
            return Command::SUCCESS;
        }

        // All tenants or one tenant
        $tenants = $tenantId
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return Command::SUCCESS;
        }

        $this->info("Narrating investigations for {$tenants->count()} tenant(s)…");
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        $errors = 0;
        foreach ($tenants as $tenant) {
            try {
                if ($force) {
                    Investigation::where('tenant_id', $tenant->id)
                        ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
                        ->each(fn ($inv) => $narrator->narrate($inv, true));
                } else {
                    $narrator->narrateForTenant($tenant->id);
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error("[investigations:narrate] Tenant {$tenant->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($errors > 0) {
            $this->warn("{$errors} tenant(s) failed — check logs.");
            return Command::FAILURE;
        }

        $this->info('All tenants processed successfully.');
        return Command::SUCCESS;
    }
}
