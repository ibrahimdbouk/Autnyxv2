<?php

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\Import;
use App\Models\ImportColumnMap;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Import\CanonicalSchema;
use Carbon\Carbon;

/**
 * Onboarding / Data-Quality Agent (Agent #3).
 *
 * Runs deterministic completeness and freshness checks over a tenant's mapped
 * data — missing costs, stale inventory, unmapped required import fields,
 * suppliers without lead times — then narrates them in plain language so a new
 * customer understands what's weak BEFORE detection runs and reads too much (or
 * too little) into thin data. It flags; it never edits the data.
 *
 * Stored as an AgentRun (subject = tenant). Surfaced on the Data Health page and
 * runnable on demand or by command.
 */
class DataQualityAgent extends AgentService
{
    /** Inventory older than this many days is called out as stale. */
    private const STALE_DAYS = 21;

    protected function agentKey(): string
    {
        return AgentRun::KEY_DATA_QUALITY;
    }

    public function checkTenant(int $tenantId, ?int $requestedBy = null): AgentRun
    {
        $this->callTenant = $tenantId;
        $checks = $this->dataChecks($tenantId);

        $result = $this->callClaude($this->buildPrompt($checks), $this->fastModel(), 1400);

        if (! $result['ok'] || empty($result['data'])) {
            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'tenant',
                'subject_id'   => (string) $tenantId,
                'title'        => 'Data health check',
                'status'       => AgentRun::STATUS_FAILED,
                'input'        => $checks,
                'error'        => $result['error'] ?? 'empty_response',
                'model'        => $this->fastModel(),
                'requested_by' => $requestedBy,
            ]);
        }

        $d = $result['data'];
        $issues = [];
        if (is_array($d['issues'] ?? null)) {
            foreach ($d['issues'] as $i) {
                if (! is_array($i)) {
                    continue;
                }
                $issues[] = [
                    'severity' => in_array(($i['severity'] ?? ''), ['high', 'medium', 'low'], true) ? $i['severity'] : 'low',
                    'area'     => trim((string) ($i['area'] ?? '')),
                    'finding'  => trim((string) ($i['finding'] ?? '')),
                    'impact'   => trim((string) ($i['impact'] ?? '')),
                    'fix'      => trim((string) ($i['fix'] ?? '')),
                ];
            }
        }

        // WP5.4: readiness is computed from the checks, never taken from the model.
        $readiness = self::readiness($checks);

        $output = [
            'headline'       => (string) ($d['headline'] ?? ''),
            'summary'        => (string) ($d['summary'] ?? ''),
            'data_readiness' => $readiness,
            'issues'         => $issues,
            'checks'         => $checks,
        ];

        return $this->record([
            'tenant_id'     => $tenantId,
            'subject_type'  => 'tenant',
            'subject_id'    => (string) $tenantId,
            'title'         => 'Data health check — ' . now()->format('d M Y'),
            'status'        => AgentRun::STATUS_COMPLETE,
            'input'         => $checks,
            'output'        => $output,
            'model'         => $this->fastModel(),
            'confidence'    => $this->confidence($d['confidence'] ?? 'probable'),
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
            'requested_by'  => $requestedBy,
        ]);
    }

    // =========================================================================
    // DETERMINISTIC CHECKS
    // =========================================================================

    /**
     * WP5.4 — good / fair / poor from the checks themselves:
     *   poor = no products, or a recent import missing a required field, or
     *          more than half the products without a cost, or inventory stale;
     *   fair = some products without a cost/price, failed rows in recent
     *          imports, or POs without an expected date;
     *   good = otherwise.
     */
    public static function readiness(array $c): string
    {
        $missingRequired = collect($c['recent_imports'] ?? [])->contains(fn ($i) => ! empty($i['unmapped_required']));
        $failedRows      = collect($c['recent_imports'] ?? [])->sum('failed_rows');

        if (($c['products'] ?? 0) === 0 || $missingRequired || ($c['products_missing_cost_pct'] ?? 0) > 50 || ! empty($c['inventory_is_stale'])) {
            return 'poor';
        }
        if (($c['products_missing_cost'] ?? 0) > 0 || ($c['products_missing_price'] ?? 0) > 0 || $failedRows > 0 || ($c['po_missing_expected_date'] ?? 0) > 0) {
            return 'fair';
        }

        return 'good';
    }

    /**
     * @return array<string,mixed>
     */
    public function dataChecks(int $tenantId): array
    {
        // Products — completeness of the master that gates money and margin.
        $products      = Product::where('tenant_id', $tenantId)->count();
        $missingCost   = Product::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('unit_cost')->orWhere('unit_cost', 0))->count();
        $missingPrice  = Product::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('selling_price')->orWhere('selling_price', 0))->count();
        $missingCat    = Product::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', ''))->count();

        // Inventory — freshness of the snapshot detection reads.
        $inventory   = InventoryLevel::where('tenant_id', $tenantId)->count();
        $latestAsOf  = InventoryLevel::where('tenant_id', $tenantId)->max('as_of_date');
        $staleDays   = $latestAsOf ? Carbon::parse($latestAsOf)->diffInDays(Carbon::now()) : null;
        $noReorder   = InventoryLevel::where('tenant_id', $tenantId)->whereNull('reorder_point')->count();

        // Suppliers — lead-time coverage (drives supply-risk rules).
        $suppliers        = Supplier::where('tenant_id', $tenantId)->count();
        $missingLeadTime  = Supplier::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereNull('lead_time_days')->orWhere('lead_time_days', 0))->count();

        // Purchase orders — dates that supply-risk rules need.
        $pos             = PurchaseOrder::where('tenant_id', $tenantId)->count();
        $poNoExpected    = PurchaseOrder::where('tenant_id', $tenantId)->whereNull('expected_date')->count();

        // Recent imports — unmapped REQUIRED fields, and failed rows.
        $imports = [];
        $recent  = Import::where('tenant_id', $tenantId)->latest('id')->limit(6)->get();
        foreach ($recent as $imp) {
            $required = array_keys(array_filter(
                CanonicalSchema::forType($imp->data_type),
                fn ($f) => ! empty($f['required'])
            ));
            $mapped = ImportColumnMap::where('import_id', $imp->id)
                ->where('is_skipped', false)
                ->pluck('target_field')->filter()->unique()->all();
            $unmappedRequired = array_values(array_diff($required, $mapped));

            $imports[] = [
                'data_type'         => $imp->data_type,
                'status'            => $imp->status,
                'total_rows'        => (int) $imp->total_rows,
                'imported_rows'     => (int) $imp->imported_rows,
                'failed_rows'       => (int) $imp->failed_rows,
                'unmapped_required' => $unmappedRequired,
            ];
        }

        $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100) : 0;

        return [
            'products'              => $products,
            'products_missing_cost' => $missingCost,
            'products_missing_cost_pct'  => $pct($missingCost, $products),
            'products_missing_price'     => $missingPrice,
            'products_missing_category'  => $missingCat,
            'inventory_rows'        => $inventory,
            'inventory_latest_as_of'=> $latestAsOf ? Carbon::parse($latestAsOf)->toDateString() : null,
            'inventory_stale_days'  => $staleDays,
            'inventory_is_stale'    => $staleDays !== null && $staleDays > self::STALE_DAYS,
            'inventory_no_reorder'  => $noReorder,
            'suppliers'             => $suppliers,
            'suppliers_missing_lead_time' => $missingLeadTime,
            'purchase_orders'       => $pos,
            'po_missing_expected_date' => $poNoExpected,
            'recent_imports'        => $imports,
        ];
    }

    private function buildPrompt(array $c): string
    {
        $imports = '';
        foreach ($c['recent_imports'] as $i) {
            $unmapped = empty($i['unmapped_required']) ? 'all required fields mapped' : ('MISSING required: ' . implode(', ', $i['unmapped_required']));
            $imports .= "  - {$i['data_type']} ({$i['status']}): {$i['imported_rows']}/{$i['total_rows']} rows imported, {$i['failed_rows']} failed; {$unmapped}\n";
        }
        $imports = $imports ?: "  (no imports yet)\n";

        $stale = $c['inventory_latest_as_of']
            ? "latest snapshot {$c['inventory_latest_as_of']} ({$c['inventory_stale_days']} days old)"
            : 'no inventory loaded';

        return <<<PROMPT
You are a data-onboarding analyst reviewing a retail tenant's data readiness before the anomaly engine runs. Explain, in plain language a non-technical operator understands, what is solid and what is weak — so they trust the results and know what to fix. Be honest and specific; do not alarm without cause, and do not invent problems the numbers don't show.

DATA PROFILE:
  Products: {$c['products']} total; {$c['products_missing_cost']} missing unit cost ({$c['products_missing_cost_pct']}%), {$c['products_missing_price']} missing selling price, {$c['products_missing_category']} missing category.
  Inventory: {$c['inventory_rows']} rows; {$stale}; {$c['inventory_no_reorder']} rows with no reorder point.
  Suppliers: {$c['suppliers']} total; {$c['suppliers_missing_lead_time']} missing lead time.
  Purchase orders: {$c['purchase_orders']} total; {$c['po_missing_expected_date']} missing expected date.
RECENT IMPORTS:
{$imports}

GUIDANCE:
- Missing unit cost blinds margin rules. Stale inventory (weeks old) makes availability rules unreliable. Missing supplier lead times weaken supply-risk rules. Unmapped required import fields mean that dataset is incomplete.
- Rank issues by how much they distort detection. If the data is healthy, say so plainly.

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "headline": "ONE sentence on overall data readiness.",
  "summary": "2-4 plain sentences: what's solid, what's weak, and why it matters for the results.",
  "issues": [
    {"severity": "high|medium|low", "area": "e.g. Product costs", "finding": "what is wrong, with the number", "impact": "which results it distorts", "fix": "the concrete step to fix it"}
  ],
  "confidence": "one of: established | probable | suspected | unknown"
}
PROMPT;
    }
}
