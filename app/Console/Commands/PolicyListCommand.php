<?php

namespace App\Console\Commands;

use App\Platform\Policy\PolicyRegistry;
use Illuminate\Console\Command;

/**
 * P4.1 — list a tenant's guardrails. Read-only.
 */
class PolicyListCommand extends Command
{
    protected $signature = 'policy:list {tenant : Tenant id}';

    protected $description = "List a tenant's policy guardrails.";

    public function handle(PolicyRegistry $registry): int
    {
        $tenantId = (int) $this->argument('tenant');
        $rules = $registry->all($tenantId);

        if ($rules->isEmpty()) {
            $this->info("No guardrails defined for tenant {$tenantId}.");
            return self::SUCCESS;
        }

        $this->table(['key', 'label', 'effect', 'active'],
            $rules->map(fn ($r) => [$r->key, $r->label, $r->effect, $r->active ? 'yes' : 'no'])->all());

        return self::SUCCESS;
    }
}
