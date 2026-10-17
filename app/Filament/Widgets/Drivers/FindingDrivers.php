<?php

namespace App\Filament\Widgets\Drivers;

use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Services\Anomaly\EvidenceCollectorService;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Livewire\Attributes\Locked;

/**
 * On an investigation: what moved the sales of each sales finding and why
 * (stored as evidence when the evidence is collected), as a waterfall, with
 * the label when stock or the calendar explains most of it.
 */
class FindingDrivers extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.drivers.finding';

    #[Locked]
    public ?int $investigation = null;

    protected function getViewData(): array
    {
        $tenantId = (int) Filament::getTenant()?->id;
        $inv = Investigation::where('tenant_id', $tenantId)->find($this->investigation);
        $currency = Money::normalize(Filament::getTenant()?->currency);
        $signed = fn (float $v) => ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . Money::displayCompact(abs($v), $currency);
        $items = [];
        if ($inv) {
            $rows = InvestigationEvidence::where('investigation_id', $inv->id)->where('label', EvidenceCollectorService::DRIVER_LABEL)
                ->with('anomaly:id,rule_type,sku,store_id')->get();
            foreach ($rows as $e) {
                $v = (array) $e->value_json;
                if (! isset($v['why'], $v['totals'])) {
                    continue;
                }
                $price = collect($v['what'] ?? [])->firstWhere('key', 'price');
                $steps = [];
                foreach (array_merge($v['why'], $price ? [$price] : []) as $i) {
                    if (abs((float) $i['value']) >= 0.005 || $i['key'] === 'unexplained') {
                        $steps[] = ['label' => $i['label'], 'value' => (float) $i['value'], 'text' => $signed((float) $i['value'])];
                    }
                }
                $items[] = [
                    'title'     => $e->anomaly?->getRuleLabel() . ($e->anomaly?->sku ? ' · ' . $e->anomaly->sku : ''),
                    'headline'  => $v['headline'] ?? '',
                    'text'      => $e->value_text,
                    'explained' => $v['explained']['by'] ?? null,
                    'share'     => (float) ($v['explained']['share'] ?? 0),
                    'steps'     => $steps,
                    'total'     => ['label' => 'Change', 'value' => (float) $v['totals']['change'], 'text' => $signed((float) $v['totals']['change'])],
                    'events'    => array_column((array) ($v['detail']['events'] ?? []), 'name'),
                ];
            }
        }

        return ['items' => $items, 'fmt' => fn ($x) => Money::displayCompact((float) $x, $currency)];
    }
}
