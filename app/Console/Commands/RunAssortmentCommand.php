<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Assortment\AssortmentEngine;
use Illuminate\Console\Command;

/**
 * Assortment Intelligence — rebuild the range model, the peer benchmark and the
 * range decisions. Runs in the tenant's night (outcomes step) for tenants that
 * hold the Assortment app or are flagged for a shadow run; --force runs it for
 * any tenant (e.g. to review a prospect's data before switching the app on).
 */
class RunAssortmentCommand extends Command
{
    protected $signature = 'assortment:run
        {--tenant= : Only this tenant id}
        {--force : Run even if the tenant is not enabled for Assortment}';

    protected $description = 'Compute Assortment range decisions (shadow mode until the validation gate passes).';

    public function handle(AssortmentEngine $engine): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')->get();

        $failed = false;
        foreach ($tenants as $tenant) {
            if (! $this->option('force') && ! AssortmentEngine::enabledFor($tenant)) {
                continue;
            }
            try {
                $run = $engine->run($tenant, (bool) $this->option('force'));
                $s = $run->stats ?? [];
                if ($run->status === 'skipped') {
                    $this->line("#{$tenant->id} {$tenant->name}: skipped — " . ($s['reason'] ?? ''));

                    continue;
                }
                $d = $s['decisions'] ?? [];
                $this->info(sprintf(
                    '#%d %s: as of %s · %d stores in %d peer groups (%d not judged) · add %d · delist %d%s · stockout-hidden %d',
                    $tenant->id, $tenant->name, $s['as_of'] ?? '—',
                    $s['range']['stores'] ?? 0, count($s['peer_groups'] ?? []), $s['stores_unjudged'] ?? 0,
                    $d['add'] ?? 0, $d['delist'] ?? 0, ($s['delists_allowed'] ?? false) ? '' : ' (held: under 26 weeks of history)',
                    $d['stockout_hidden'] ?? 0,
                ));
            } catch (\Throwable $e) {
                report($e);
                $this->error("#{$tenant->id} {$tenant->name}: failed — {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
