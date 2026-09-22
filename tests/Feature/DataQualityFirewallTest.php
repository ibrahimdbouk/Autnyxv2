<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportQuality;
use App\Models\Product;
use App\Models\QuarantinedRow;
use App\Services\DataQuality\CleansingEngine;
use App\Services\DataQuality\DataQualityFirewall;
use App\Services\DataQuality\Reasons;
use App\Services\DataQuality\RowValidator;
use Tests\TestCase;

/**
 * The Data Quality Firewall — deterministic cleansing, hard-gate validation,
 * dedup, referential warnings, and per-import quality accounting. See
 * claude/data-quality-firewall.md.
 */
class DataQualityFirewallTest extends TestCase
{
    public function test_cleansing_normalizes_common_garbage(): void
    {
        $ctx = [
            'aliases'    => ['sku' => ['old-1' => 'SKU-1'], 'store' => [], 'supplier' => []],
            'value_maps' => ['payment_method' => ['cc' => 'card']],
            'rules'      => [],
            'upper_keys' => true,
            'strip_zeros'=> false,
        ];

        $out = app(CleansingEngine::class)->clean('sales_transactions', [
            'sku'            => '  old-1 ',      // trim + upper + alias → SKU-1
            'date'           => '01/02/2026',    // → ISO
            'quantity'       => '1,234',         // thousands sep → 1234
            'unit_price'     => '$1,234.50',     // currency + thousands → 1234.50
            'payment_method' => 'CC',            // value map → card
            'location'       => "Downtown\u{00A0} Store  ",
        ], $ctx);

        $this->assertTrue($out['changed']);
        $this->assertSame('SKU-1', $out['data']['sku']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $out['data']['date']);
        $this->assertSame('1234', $out['data']['quantity']);
        $this->assertSame('1234.50', $out['data']['unit_price']);
        $this->assertSame('card', $out['data']['payment_method']);
        $this->assertSame('Downtown Store', $out['data']['location']);
    }

    public function test_validator_flags_structural_garbage(): void
    {
        $validator = app(RowValidator::class);
        $skus = ['SKU-1' => true];

        // Missing identity key.
        $this->assertSame(Reasons::MISSING_KEY,
            $validator->check('sales_transactions', ['sku' => '', 'date' => '2026-01-01', 'quantity' => '1'], [])['reason']);

        // Unparseable required date (post-cleanse must be ISO).
        $this->assertSame(Reasons::INVALID_DATE,
            $validator->check('sales_transactions', ['sku' => 'SKU-1', 'date' => 'not-a-date', 'quantity' => '1'], [])['reason']);

        // Orphan SKU: warns when the gate is off, rejects when on.
        $off = $validator->check('sales_transactions', ['sku' => 'NOPE', 'date' => '2026-01-01', 'quantity' => '1'],
            ['product_skus' => $skus, 'referential_gate' => false]);
        $this->assertNull($off['reason']);
        $this->assertContains(Reasons::WARN_ORPHAN_SKU, $off['warnings']);

        $on = $validator->check('sales_transactions', ['sku' => 'NOPE', 'date' => '2026-01-01', 'quantity' => '1'],
            ['product_skus' => $skus, 'referential_gate' => true]);
        $this->assertSame(Reasons::ORPHAN_REFERENCE, $on['reason']);
    }

    public function test_firewall_screens_promotes_quarantines_and_records_quality(): void
    {
        $tenant = $this->createTenant();
        Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-1', 'name' => 'Widget']);

        $import = Import::create([
            'tenant_id' => $tenant->id, 'data_type' => Import::TYPE_SALES,
            'disk' => 'local', 'path' => 'imports/none.csv', 'status' => Import::STATUS_IMPORTING,
            'original_filename' => 'none.csv',
        ]);

        $fw = app(DataQualityFirewall::class);
        $fw->begin($import);

        // Clean row → promotes.
        $clean = $fw->screen($import, ['sku' => 'SKU-1', 'date' => '2026-01-01', 'quantity' => '5']);
        $this->assertNull($clean['reason']);

        // Exact duplicate → rejected.
        $dup = $fw->screen($import, ['sku' => 'SKU-1', 'date' => '2026-01-01', 'quantity' => '5']);
        $this->assertSame(Reasons::DUPLICATE_ROW, $dup['reason']);
        $fw->quarantine($import, ['SKU-1', '2026-01-01', '5'], $dup['data'], $dup['reason'], 3);

        // Missing key → rejected.
        $bad = $fw->screen($import, ['sku' => '', 'date' => '2026-01-01', 'quantity' => '1']);
        $this->assertSame(Reasons::MISSING_KEY, $bad['reason']);
        $fw->quarantine($import, ['', '2026-01-01', '1'], $bad['data'], $bad['reason'], 4);

        // Orphan SKU → promotes (gate off) but is recorded as a warning.
        $orphan = $fw->screen($import, ['sku' => 'NOPE', 'date' => '2026-01-02', 'quantity' => '2']);
        $this->assertNull($orphan['reason']);

        $fw->recordChunk($import);

        $q = ImportQuality::where('import_id', $import->id)->first();
        $this->assertNotNull($q);
        $this->assertSame(2, $q->rows_promoted);          // clean + orphan
        $this->assertSame(2, $q->rows_quarantined);        // dup + missing
        $this->assertSame(1, (int) ($q->reason_counts[Reasons::DUPLICATE_ROW] ?? 0));
        $this->assertSame(1, (int) ($q->reason_counts[Reasons::MISSING_KEY] ?? 0));
        $this->assertSame(1, (int) ($q->reason_counts[Reasons::WARN_ORPHAN_SKU] ?? 0));

        $this->assertSame(2, QuarantinedRow::where('import_id', $import->id)->count());
        $this->assertNotEmpty($q->column_profile);         // first-chunk profile captured
    }
}
