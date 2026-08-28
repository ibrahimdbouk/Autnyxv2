<?php

namespace App\Filament\Pages;
use App\Filament\Concerns\GatesPageByScreen;

use App\Services\Reporting\ReportDataService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Reports — download PDF (executive summary) and Excel (full detail) reports for
 * Recovery & Financial, Investigations, Anomalies & Detection, and Data Health &
 * Quality, over a chosen date range (defaults to all available data, clamped to
 * the tenant's earliest record → today). Downloads are served by ReportController
 * via signed-in web routes.
 */
class Reports extends Page
{
    use GatesPageByScreen;

    const SCREEN_KEY = 'reports';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports';

    public string $from = '';
    public string $to   = '';

    public function getTitle(): string
    {
        return 'Reports';
    }

    public function mount(): void
    {
        [$from, $to] = $this->availableRange();
        $this->from = $from;
        $this->to   = $to;
    }

    /** @return array{0:string,1:string} [earliest, today] as Y-m-d */
    public function availableRange(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return [now()->subDays(30)->toDateString(), now()->toDateString()];
        }
        [$from, $to] = app(ReportDataService::class)->availableRange($tenantId);
        return [$from->toDateString(), $to->toDateString()];
    }

    public function setPreset(string $key): void
    {
        [$availFrom, $availTo] = $this->availableRange();
        $today = Carbon::parse($availTo);

        $this->to = $today->toDateString();
        $this->from = match ($key) {
            '7d'    => $today->copy()->subDays(7)->toDateString(),
            '30d'   => $today->copy()->subDays(30)->toDateString(),
            '90d'   => $today->copy()->subDays(90)->toDateString(),
            'mtd'   => $today->copy()->startOfMonth()->toDateString(),
            'ytd'   => $today->copy()->startOfYear()->toDateString(),
            default => $availFrom, // all time
        };

        // Never start before the earliest available record
        if (Carbon::parse($this->from)->lt(Carbon::parse($availFrom))) {
            $this->from = $availFrom;
        }
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getReports(): array
    {
        return [
            [
                'type'        => 'recovery',
                'label'       => 'Recovery & Financial',
                'description' => 'Revenue at risk, observed recovery, recovery rate and net impact — with breakdowns by cause, store and action, plus response times.',
                'accent'      => '#16a34a',
            ],
            [
                'type'        => 'investigations',
                'label'       => 'Investigations',
                'description' => 'The full investigation register plus lifecycle stats: counts by status and priority, resolution rate and average time to resolution.',
                'accent'      => '#6d28d9',
            ],
            [
                'type'        => 'anomalies',
                'label'       => 'Anomalies & Detection',
                'description' => 'Detections by rule and severity, dismissals, correlation and false-positive rate — how the detection engine performed.',
                'accent'      => '#dc2626',
            ],
            [
                'type'        => 'data-health',
                'label'       => 'Data Health & Quality',
                'description' => 'Current dataset freshness, completeness and validity, plus period anomaly/investigation volume and resolution rate.',
                'accent'      => '#2563eb',
            ],
        ];
    }

    public function downloadUrl(string $type, string $format): string
    {
        return route('reports.download', ['type' => $type, 'format' => $format]) . '?' . http_build_query([
            'tenant' => Filament::getTenant()?->id,
            'from'   => $this->from,
            'to'     => $this->to,
        ]);
    }
}
