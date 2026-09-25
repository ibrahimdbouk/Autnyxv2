<?php

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\Anomaly;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Support\Money;

/**
 * Supplier-Negotiation Prep Agent (Agent #5).
 *
 * Assembles the evidence pack for a supplier conversation: contracted vs actual
 * lead time, fill-rate history, late/overdue POs, the supply anomalies tied to
 * this supplier's SKUs, and the money at stake. The reasoning model turns that
 * into talking points, asks and leverage — a brief a buyer can walk into a
 * meeting with. It drafts; the buyer negotiates.
 *
 * Stored as an AgentRun (subject = supplier). Built on demand from the Supplier
 * Prep page.
 */
class SupplierPrepAgent extends AgentService
{
    /** Anomaly rules that reflect on a supplier's performance. */
    private const SUPPLY_RULES = [
        'supplier_fill_rate', 'supplier_lead_time_drift',
        'po_overdue', 'po_late_receipt', 'receiving_discrepancy',
    ];

    protected function agentKey(): string
    {
        return AgentRun::KEY_SUPPLIER_PREP;
    }

    public function prepare(int $tenantId, int $supplierId, ?int $requestedBy = null): AgentRun
    {
        $this->callTenant = $tenantId;
        $supplier = Supplier::where('tenant_id', $tenantId)->find($supplierId);
        if (! $supplier) {
            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'supplier',
                'subject_id'   => (string) $supplierId,
                'title'        => 'Supplier prep',
                'status'       => AgentRun::STATUS_FAILED,
                'error'        => 'supplier_not_found',
                'requested_by' => $requestedBy,
            ]);
        }

        $pack = $this->evidencePack($tenantId, $supplier);

        // WP5.4: earlier packs for this supplier are retired only once this one succeeds.

        $result = $this->callClaude($this->buildPrompt($pack), $this->reasoningModel(), 1600);

        if (! $result['ok'] || empty($result['data'])) {
            return $this->record([
                'tenant_id'    => $tenantId,
                'subject_type' => 'supplier',
                'subject_id'   => (string) $supplierId,
                'title'        => $supplier->name,
                'status'       => AgentRun::STATUS_FAILED,
                'input'        => $pack,
                'error'        => $result['error'] ?? 'empty_response',
                'model'        => $this->reasoningModel(),
                'requested_by' => $requestedBy,
            ]);
        }

        $d = $result['data'];
        $output = [
            'headline'      => (string) ($d['headline'] ?? ''),
            'summary'       => (string) ($d['summary'] ?? ''),
            'talking_points'=> $this->toList($d['talking_points'] ?? null),
            'asks'          => $this->toList($d['asks'] ?? null),
            'leverage'      => $this->toList($d['leverage'] ?? null),
            'data_points'   => $this->toList($d['data_points'] ?? null),
            'pack'          => $pack,
        ];

        return $this->recordReplacing([
            'tenant_id'     => $tenantId,
            'subject_type'  => 'supplier',
            'subject_id'    => (string) $supplierId,
            'title'         => $supplier->name,
            'status'        => AgentRun::STATUS_COMPLETE,
            'input'         => $pack,
            'output'        => $output,
            'model'         => $this->reasoningModel(),
            'confidence'    => $this->confidence($d['confidence'] ?? 'probable'),
            'tokens_input'  => $result['tokens_input'],
            'tokens_output' => $result['tokens_output'],
            'requested_by'  => $requestedBy,
        ], fn ($q) => $q->where('tenant_id', $tenantId)->where('subject_type', 'supplier')
            ->where('subject_id', (string) $supplierId)->where('status', AgentRun::STATUS_COMPLETE));
    }

    /**
     * Deterministic supplier scorecard + linked supply anomalies.
     *
     * @return array<string,mixed>
     */
    public function evidencePack(int $tenantId, Supplier $supplier): array
    {
        $currency = Tenant::find($tenantId)?->currencyCode() ?? 'AED';

        $pos = PurchaseOrder::where('tenant_id', $tenantId)
            ->where('supplier_id', $supplier->id)
            ->get(['sku', 'qty_ordered', 'qty_received', 'unit_cost', 'open_qty', 'late_days', 'fill_rate', 'expected_date', 'received_date']);

        $poCount    = $pos->count();
        $late       = $pos->filter(fn ($p) => (int) $p->late_days > 0);
        $lateCount  = $late->count();
        $avgLate    = $lateCount > 0 ? round($late->avg('late_days'), 1) : 0;
        $fillVals   = $pos->filter(fn ($p) => $p->fill_rate !== null)->pluck('fill_rate');
        $avgFill    = $fillVals->count() > 0 ? round($fillVals->avg(), 1) : null;
        $openPos    = $pos->filter(fn ($p) => (float) $p->open_qty > 0)->count();
        $poValue    = $pos->sum(fn ($p) => (float) $p->qty_ordered * (float) $p->unit_cost);

        // Supplier's SKUs → linked active supply anomalies.
        $skus = $pos->pluck('sku')->filter()->unique()->values()->all();
        $anoms = collect();
        if (! empty($skus)) {
            $anoms = Anomaly::where('tenant_id', $tenantId)->active()
                ->whereIn('rule_type', self::SUPPLY_RULES)
                ->whereIn('sku', $skus)
                ->get(['rule_type', 'sku', 'severity', 'context']);
        }
        $anomValue = (float) $anoms->sum(fn ($a) => (float) ($a->context['revenue_impact'] ?? 0));

        $skuNames = Product::where('tenant_id', $tenantId)->pluck('name', 'sku');
        $topSkus  = $anoms->groupBy('sku')->map(function ($group, $sku) use ($skuNames, $currency) {
            $val = (float) $group->sum(fn ($a) => (float) ($a->context['revenue_impact'] ?? 0));

            return [
                'sku'       => $skuNames[$sku] ?? $sku,
                'issues'    => $group->pluck('rule_type')->unique()->values()->all(),
                'value_fmt' => Money::compact($val, $currency),
                '_v'        => $val,
            ];
        })->sortByDesc('_v')->take(6)->map(fn ($x) => ['sku' => $x['sku'], 'issues' => $x['issues'], 'value_fmt' => $x['value_fmt']])->values()->all();

        return [
            'currency'          => $currency,
            'supplier_name'     => $supplier->name,
            'supplier_type'     => $supplier->type,
            'specialization'    => $supplier->specialization,
            'contracted_lead'   => $supplier->lead_time_days,
            'po_count'          => $poCount,
            'late_count'        => $lateCount,
            'late_pct'          => $poCount > 0 ? round($lateCount / $poCount * 100) : 0,
            'avg_late_days'     => $avgLate,
            'avg_fill_rate'     => $avgFill,
            'open_pos'          => $openPos,
            'po_value_fmt'      => Money::compact($poValue, $currency),
            'anomaly_count'     => $anoms->count(),
            'anomaly_value_fmt' => Money::compact($anomValue, $currency),
            'top_skus'          => $topSkus,
        ];
    }

    private function buildPrompt(array $p): string
    {
        $skus = '';
        foreach ($p['top_skus'] as $s) {
            $issues = implode(', ', array_map(fn ($r) => str_replace('_', ' ', $r), $s['issues']));
            $skus .= "  - {$s['sku']}: {$issues} ({$s['value_fmt']} at risk)\n";
        }
        $skus = $skus ?: "  (no linked supply anomalies)\n";

        $fill = $p['avg_fill_rate'] !== null ? "{$p['avg_fill_rate']}%" : 'not recorded';
        $lead = $p['contracted_lead'] !== null ? "{$p['contracted_lead']} days" : 'not on file';

        return <<<PROMPT
You are a retail buyer's analyst preparing a brief for a supplier performance conversation. Turn the scorecard below into a tight, factual negotiation prep the buyer can walk in with. Be firm but fair and strictly evidence-based — every point must trace to a number here. Do not invent figures or accuse beyond the data.

SUPPLIER: {$p['supplier_name']} (type: {$p['supplier_type']}, focus: {$p['specialization']})
CONTRACTED LEAD TIME: {$lead}
PURCHASE ORDERS: {$p['po_count']} total; {$p['late_count']} late ({$p['late_pct']}%), average {$p['avg_late_days']} days late; average fill rate {$fill}; {$p['open_pos']} still open; {$p['po_value_fmt']} ordered value.
LINKED SUPPLY ISSUES: {$p['anomaly_count']} active anomalies, {$p['anomaly_value_fmt']} at risk.
MOST AFFECTED SKUS:
{$skus}

WRITING RULES:
1. Money in {$p['currency']}, sensibly rounded. No dollar sign unless the currency is USD.
2. Every talking point and ask must reference a specific number above.
3. Be honest about size; if performance is actually fine, say so and keep the asks light.
4. No blanks or dashes as values.

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "headline": "ONE sentence framing the conversation.",
  "summary": "2-3 sentences: the supplier's performance picture and what to achieve.",
  "talking_points": ["3-5 factual points to raise, each tied to a number"],
  "asks": ["2-4 specific, reasonable asks (e.g. lead-time commitment, fill-rate target, expedite)"],
  "leverage": ["1-3 points of leverage or context, honest"],
  "data_points": ["3-5 headline numbers to have on hand"],
  "confidence": "one of: established | probable | suspected | unknown"
}
PROMPT;
    }
}
