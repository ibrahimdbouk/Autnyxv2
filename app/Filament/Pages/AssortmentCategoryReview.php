<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Filament\Resources\AssortmentDecisionResource;
use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Support\ExportAudit;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Category review — the range review, category by category: what is open to
 * decide (adds, delists, stockout-hidden and their value), what was accepted
 * and what it has measured so far, with the category's review pack to take into
 * the meeting (Excel).
 */
class AssortmentCategoryReview extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_categories';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Category Review';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'assortment-categories';

    protected string $view = 'filament.pages.assortment-category-review';

    public function getTitle(): string
    {
        return 'Category review';
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        $rows = AssortmentDecisionResource::getEloquentQuery()->setEagerLoads([])
            ->join('products as p', 'p.id', '=', 'assortment_gaps.product_id')
            ->groupBy('p.category')
            ->selectRaw("p.category,
                SUM(CASE WHEN assortment_gaps.status = 'open' AND assortment_gaps.type = 'add' THEN 1 ELSE 0 END) AS adds,
                SUM(CASE WHEN assortment_gaps.status = 'open' AND assortment_gaps.type = 'delist' THEN 1 ELSE 0 END) AS delists,
                SUM(CASE WHEN assortment_gaps.status = 'open' AND assortment_gaps.type = 'stockout_hidden' THEN 1 ELSE 0 END) AS stockouts,
                SUM(CASE WHEN assortment_gaps.status = 'open' THEN assortment_gaps.value_mid ELSE 0 END) AS open_value,
                SUM(CASE WHEN assortment_gaps.status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN assortment_gaps.measured_at IS NOT NULL THEN CAST(assortment_gaps.measurement->>'uplift_per_year' AS numeric) ELSE 0 END) AS measured")
            ->orderByDesc('open_value')
            ->get();

        return $rows->map(fn ($r) => [
            'category'   => (string) ($r->category ?? 'No category'),
            'adds'       => (int) $r->adds,
            'delists'    => (int) $r->delists,
            'stockouts'  => (int) $r->stockouts,
            'open_value' => (float) $r->open_value,
            'accepted'   => (int) $r->accepted,
            'measured'   => (float) $r->measured,
            'url'        => AssortmentDecisionResource::getUrl('index', ['filters' => ['category' => ['value' => $r->category]]]),
        ])->all();
    }

    /** The review pack for the i-th category row on screen. */
    public function downloadPackAt(int $index): StreamedResponse
    {
        $category = $this->categories()[$index]['category'] ?? null;
        abort_if($category === null, 404);

        return $this->downloadPack($category);
    }

    /** The category's review pack: every open and decided decision, with why. */
    public function downloadPack(string $category): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $tenant   = Filament::getTenant();
        $currency = $tenant->currencyCode();
        $gaps = AssortmentDecisionResource::getEloquentQuery()
            ->whereHas('product', fn ($q) => $q->where('category', $category))
            ->orderByRaw("CASE assortment_gaps.status WHEN 'open' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END")
            ->orderByRaw('assortment_gaps.value_mid * assortment_gaps.confidence DESC')
            ->limit(5000)->get();

        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Decisions');
        $sheet->fromArray([["Range review — {$category}", '', '', '', 'Generated ' . now()->format('j M Y')]], null, 'A1');
        $sheet->fromArray([['Decision', 'Product', 'SKU', 'Store', "Value a year, low ({$currency})", "Middle ({$currency})", "High ({$currency})",
            'Confidence', 'Status', 'Owner', 'Why']], null, 'A3');
        $row = 4;
        foreach ($gaps as $g) {
            $sheet->fromArray([[
                AssortmentGap::TYPES[$g->type] ?? $g->type, $g->product?->name, $g->sku, $g->store?->name,
                round($g->value_low), round($g->value_mid), round($g->value_high),
                ucfirst($g->confidence_tier), ucfirst($g->status), $g->assignee?->name,
                implode(' ', $g->explanation['evidence'] ?? []),
            ]], null, "A{$row}");
            $row++;
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3:K3')->getFont()->setBold(true);
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('K')->setWidth(90);

        ExportAudit::log((int) $tenant->id, "the {$category} range review pack", 'xlsx');
        $name = 'range-review-' . \Illuminate\Support\Str::slug($category) . '-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    protected function getViewData(): array
    {
        return ['rows' => $this->categories(), 'currency' => Filament::getTenant()?->currencyCode()];
    }
}
