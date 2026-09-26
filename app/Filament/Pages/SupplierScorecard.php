<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Services\Suppliers\SupplierScorecardService;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * W11 — Supplier Scorecard: every supplier graded on fill rate, on-time
 * delivery, lead time and cost, with the stock-outs on its items. Worst
 * first. Opening a supplier shows its worst-filled SKUs and overdue lines,
 * and links to its Supplier Prep negotiation pack.
 */
class SupplierScorecard extends Page
{
    use GatesPageByScreen;
    use \App\Filament\Concerns\SanitizesUrlState;

    const SCREEN_KEY = 'supplier_scorecard';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Supplier Scorecard';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'supplier-scorecard';

    protected string $view = 'filament.pages.supplier-scorecard';

    #[Url]
    public $days = '90';

    #[Url]
    public $supplier = '';

    protected function urlRules(): array
    {
        return ['days' => ['30', '90', '180'], 'supplier' => 'int'];
    }

    public function getTitle(): string
    {
        return 'Supplier Scorecard';
    }

    private function tenantId(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    private function window(): int
    {
        return in_array((string) $this->days, ['30', '90', '180'], true) ? (int) $this->days : 90;
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(): array
    {
        $t = $this->tenantId();
        $d = $this->window();

        return Cache::remember("supplier-scorecard:{$t}:{$d}", 300, fn () => app(SupplierScorecardService::class)->scorecard($t, $d));
    }

    public function detail(): array
    {
        return $this->supplier !== '' && $this->supplier !== null
            ? app(SupplierScorecardService::class)->detail($this->tenantId(), (int) $this->supplier, $this->window())
            : [];
    }

    /** Cards and headings: the currency sign (e.g. ⃃). */
    public function money(float $v): string
    {
        return Money::displayCompact($v, Money::normalize(Filament::getTenant()?->currency));
    }

    /** Table cells: the ISO code (e.g. AED). */
    public function tableMoney(float $v): string
    {
        return Money::compact($v, Money::normalize(Filament::getTenant()?->currency));
    }

    public function prepUrl(?int $supplierId): ?string
    {
        return $supplierId && SupplierPrep::canAccess() ? SupplierPrep::getUrl(['supplier' => $supplierId]) : null;
    }

    public function download(): StreamedResponse
    {
        $rows = $this->rows();
        \App\Support\ExportAudit::log($this->tenantId(), 'supplier scorecard', 'csv');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            \App\Support\ExportAudit::putcsv($out, ['supplier', 'grade', 'score', 'po_lines', 'pos', 'ordered_value', 'fill_rate_pct', 'on_time_pct',
                'lead_days', 'prev_lead_days', 'cost_change_pct', 'overdue_lines', 'overdue_value', 'stockout_findings', 'lost_revenue']);
            foreach ($rows as $r) {
                \App\Support\ExportAudit::putcsv($out, [$r['name'], $r['grade'], $r['score'], $r['lines'], $r['pos'], $r['ordered_value'], $r['fill_rate'],
                    $r['on_time'], $r['lead_days'], $r['prev_lead_days'], $r['cost_change'], $r['overdue_lines'], $r['overdue_value'], $r['stockouts'], $r['lost_revenue']]);
            }
            fclose($out);
        }, 'supplier-scorecard-' . $this->window() . 'd-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
