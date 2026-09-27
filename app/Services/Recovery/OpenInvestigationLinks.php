<?php

namespace App\Services\Recovery;

use App\Models\Investigation;
use App\Models\Tenant;

/**
 * Root Cause's answer to the platform SubjectLinks contract: the open
 * investigations about a product at a store (as its primary subject, or as one
 * of its SKU entities there). Other apps — Assortment's stockout-hidden
 * decisions — link to them without importing Root Cause.
 */
final class OpenInvestigationLinks
{
    public function __invoke(int $tenantId, ?int $storeId, ?string $sku): array
    {
        if ($sku === null || $storeId === null) {
            return [];
        }
        $tenant = Tenant::find($tenantId);
        if (! $tenant || ! $tenant->hasApp(Tenant::APP_ROOT_CAUSE)) {
            return [];
        }

        $open = [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS];
        $rows = Investigation::query()->where('tenant_id', $tenantId)->whereIn('status', $open)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('primary_sku', $sku)->where('primary_store_id', $storeId))
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('investigation_entities as ie')
                    ->whereColumn('ie.investigation_id', 'investigations.id')
                    ->where('ie.entity_type', 'sku')->where('ie.entity_key', $sku)->where('ie.store_id', $storeId)))
            ->latest('id')->limit(3)->get(['id', 'title', 'status']);

        return $rows->map(fn ($i) => [
            'label'  => (string) ($i->title ?: 'Investigation #' . $i->id),
            'status' => (string) $i->status,
            'url'    => $this->url($i->id, $tenant),
        ])->all();
    }

    private function url(int $id, Tenant $tenant): ?string
    {
        try {
            return \App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $id, 'tenant' => $tenant], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }
}
