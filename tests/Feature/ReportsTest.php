<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Services\Reporting\ReportDataService;
use App\Services\Reporting\ReportExcelWriter;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    public function test_every_report_type_builds_on_empty_tenant(): void
    {
        $tenant = $this->createTenant();
        $svc = app(ReportDataService::class);
        [$from, $to] = $svc->availableRange($tenant->id);

        foreach (ReportDataService::TYPES as $type) {
            $payload = $svc->build($type, $tenant->id, $from, $to);
            $this->assertArrayHasKey('kpis', $payload);
            $this->assertArrayHasKey('summary_sections', $payload);
            $this->assertArrayHasKey('detail_sheets', $payload);
            $this->assertNotEmpty($payload['title']);
        }
    }

    public function test_recovery_report_reflects_seeded_outcome(): void
    {
        $tenant = $this->createTenant();

        $inv = Investigation::factory()->create([
            'tenant_id' => $tenant->id, 'status' => 'resolved',
            'opened_at' => now()->subDays(3), 'resolved_at' => now()->subDay(),
            'primary_sku' => 'SKU-R',
        ]);
        Anomaly::factory()->create(['tenant_id' => $tenant->id, 'investigation_id' => $inv->id, 'detected_at' => now()->subDays(4)]);
        InvestigationOutcome::create([
            'investigation_id'  => $inv->id,
            'tenant_id'         => $tenant->id,
            'revenue_at_risk'   => 10000,
            'observed_recovery' => 7500,
            'recovery_method'   => 'sales_rebound',
            'recorded_at'       => now(),
        ]);

        $svc = app(ReportDataService::class);
        [$from, $to] = $svc->availableRange($tenant->id);
        $payload = $svc->build('recovery', $tenant->id, $from, $to);

        $labels = collect($payload['kpis'])->pluck('value', 'label');
        $this->assertSame('$10,000.00', $labels['Revenue at Risk']);
        $this->assertSame('$7,500.00', $labels['Observed Recovery']);

        // Detail sheet has the outcome row
        $outcomeSheet = collect($payload['detail_sheets'])->firstWhere('name', 'Outcomes');
        $this->assertNotNull($outcomeSheet);
        $this->assertCount(1, $outcomeSheet['rows']);
    }

    public function test_excel_writer_produces_a_streamed_download(): void
    {
        $tenant = $this->createTenant();
        $svc = app(ReportDataService::class);
        [$from, $to] = $svc->availableRange($tenant->id);
        $payload = $svc->build('investigations', $tenant->id, $from, $to);

        $response = app(ReportExcelWriter::class)->download($payload, 'test.xlsx');

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        // .xlsx is a zip archive — starts with "PK"
        $this->assertNotEmpty($body);
        $this->assertSame('PK', substr($body, 0, 2));
    }
}
