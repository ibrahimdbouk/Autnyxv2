<?php

namespace App\Platform\Governance;

use App\Models\DataContract;
use Illuminate\Support\Collection;

/**
 * P3.4 — define and look up a tenant's ingestion data contracts. A contract is
 * data (a row), so declaring one is an upsert, not a migration.
 */
class ContractRegistry
{
    /**
     * @param  array<int,string>  $requiredColumns
     */
    public function define(
        int $tenantId,
        string $feedKey,
        array $requiredColumns = [],
        ?int $freshnessSlaHours = null,
        ?int $minRows = null,
    ): DataContract {
        return DataContract::updateOrCreate(
            ['tenant_id' => $tenantId, 'feed_key' => $feedKey],
            [
                'required_columns'    => $requiredColumns,
                'freshness_sla_hours' => $freshnessSlaHours,
                'min_rows'            => $minRows,
                'active'              => true,
            ],
        );
    }

    public function get(int $tenantId, string $feedKey): ?DataContract
    {
        return DataContract::query()
            ->where('tenant_id', $tenantId)
            ->where('feed_key', $feedKey)
            ->where('active', true)
            ->first();
    }

    /** @return Collection<int,DataContract> */
    public function all(int $tenantId): Collection
    {
        return DataContract::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('feed_key')
            ->get();
    }
}
