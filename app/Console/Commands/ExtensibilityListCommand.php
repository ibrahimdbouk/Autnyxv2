<?php

namespace App\Console\Commands;

use App\Platform\Extensibility\ExtensibilityRegistry;
use Illuminate\Console\Command;

/**
 * P3.2 — list a tenant's stored extensions (custom KPIs, rules, dimensions): the
 * things they added without a migration. Read-only introspection.
 */
class ExtensibilityListCommand extends Command
{
    protected $signature = 'ext:list {tenant : Tenant id}';

    protected $description = "List a tenant's custom KPIs, rules and dimensions.";

    public function handle(ExtensibilityRegistry $registry): int
    {
        $tenantId = (int) $this->argument('tenant');
        $counts = $registry->counts($tenantId);

        $this->info("Tenant {$tenantId}: {$counts['metrics']} metric(s), {$counts['rules']} rule(s), {$counts['dimensions']} dimension(s).");

        $metrics = $registry->metrics($tenantId);
        if ($metrics->isNotEmpty()) {
            $this->line('');
            $this->line('<comment>Custom KPIs</comment>');
            $this->table(['key', 'label', 'unit', 'objective', 'v'],
                $metrics->map(fn ($m) => [$m->key, $m->label, $m->unit, $m->objective ?? '—', $m->version])->all());
        }

        $rules = $registry->rules($tenantId);
        if ($rules->isNotEmpty()) {
            $this->line('');
            $this->line('<comment>Custom rules</comment>');
            $this->table(['key', 'label', 'severity', 'objective'],
                $rules->map(fn ($r) => [$r->key, $r->label, $r->severity, $r->objective ?? '—'])->all());
        }

        $dimensions = $registry->dimensions($tenantId);
        if ($dimensions->isNotEmpty()) {
            $this->line('');
            $this->line('<comment>Custom dimensions</comment>');
            $this->table(['entity', 'key', 'label', 'type'],
                $dimensions->map(fn ($d) => [$d->entity_type, $d->key, $d->label, $d->data_type])->all());
        }

        return self::SUCCESS;
    }
}
