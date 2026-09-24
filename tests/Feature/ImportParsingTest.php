<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportRow;
use App\Models\InventoryLevel;
use App\Models\QuarantinedRow;
use App\Models\SalesTransaction;
use App\Models\Tenant;
use App\Services\Import\FileReaderService;
use App\Services\Import\ImportProcessorService;
use App\Services\Import\ValueParser;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * WP3.2 (audit C7, H26) — dates, numbers, CSV dialects and Excel cells are
 * read deterministically under the import's format, never guessed.
 */
class ImportParsingTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        Storage::fake('local');
    }

    private function import(string $contents, string $ext, array $map, array $attrs = [], string $type = Import::TYPE_SALES): Import
    {
        $path = 'imports/pending/f-' . uniqid() . '.' . $ext;
        Storage::disk('local')->put($path, $contents);
        $import = Import::create(array_merge([
            'tenant_id' => $this->tenant->id, 'original_filename' => 'file.' . $ext, 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => 10,
        ], $attrs));
        foreach ($map as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }

        return $import;
    }

    private function runImport(Import $import): Import
    {
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 20);

        return $import->fresh();
    }

    private const SALES_MAP = ['Date' => 'date', 'SKU' => 'sku', 'Store' => 'location', 'Qty' => 'quantity', 'Total' => 'total_amount'];

    // ── the parser itself ────────────────────────────────────────────────────

    public function test_dates_follow_the_configured_order_and_are_never_swapped(): void
    {
        $dmy = new ValueParser('d/m/Y');
        $this->assertSame('2026-04-03', $dmy->date('03/04/2026')['value']);
        $this->assertSame('2026-04-13', $dmy->date('13/04/2026')['value']);          // used to throw
        $this->assertSame(ValueParser::INVALID, $dmy->date('12/31/2026')['reason']);  // not silently swapped
        $this->assertSame('2026-04-03', $dmy->date('2026-04-03T08:00:00Z')['value']);
        $this->assertSame('2026-04-03', $dmy->date('3 Apr 2026')['value']);
        $this->assertSame('2026-04-03', $dmy->date('20260403')['value']);
        $this->assertSame('2026-09-24', $dmy->date('46289')['value']);                // Excel serial
        $this->assertSame('2023-09-01', $dmy->date('/Date(1693526400000)/')['value']); // OData

        $this->assertSame('2026-03-04', (new ValueParser('m/d/Y'))->date('03/04/2026')['value']);

        $auto = new ValueParser('auto');
        $this->assertSame(ValueParser::AMBIGUOUS, $auto->date('03/04/2026')['reason']);
        $this->assertSame('2026-04-13', $auto->date('13/04/2026')['value']);
    }

    public function test_numbers_follow_the_configured_decimal_mark(): void
    {
        $dot = new ValueParser(null, '.');
        $comma = new ValueParser(null, ',');

        $this->assertSame('1234.56', $dot->number('AED 1,234.56'));
        $this->assertSame('-12.50', $dot->number('(12.50)'));          // was +12.5
        $this->assertSame('-12.50', $dot->number('12.50-'));           // SAP trailing minus
        $this->assertSame('1200', $dot->number('1.2E+3'));
        $this->assertSame('0', $dot->number('0'));
        $this->assertNull($dot->number('1.234,56'));                   // was 1.23456 — now a mismatch, rejected
        $this->assertNull($dot->number('1O0'));

        $this->assertSame('1234.56', $comma->number('1.234,56'));
        $this->assertSame('1234.56', $comma->number('1 234,56'));
        $this->assertSame('1.234', $comma->number('1,234'));           // 3-decimal currencies (KWD/BHD)
        $this->assertNull($comma->number('12.5'));
    }

    // ── end to end ───────────────────────────────────────────────────────────

    public function test_a_semicolon_csv_with_comma_decimals_and_day_first_dates_imports_correctly(): void
    {
        $csv = "Date;SKU;Store;Qty;Total\n03/04/2026;SKU-1;Downtown;2;1.234,50\n13/04/2026;SKU-2;Downtown;0;0\n";
        $import = $this->runImport($this->import($csv, 'csv', self::SALES_MAP, ['date_format' => 'd/m/Y', 'decimal_separator' => ',']));

        $this->assertSame(2, (int) $import->imported_rows, 'every row imports (no one-giant-column quarantine)');
        $one = SalesTransaction::where('sku', 'SKU-1')->first();
        $this->assertSame('2026-04-03', $one->date->toDateString());
        $this->assertEqualsWithDelta(1234.5, (float) $one->total_amount, 0.0001);
        // "0" is a value, not a missing quantity.
        $this->assertSame(0.0, (float) SalesTransaction::where('sku', 'SKU-2')->value('quantity'));
        $this->assertSame(';', $import->delimiter ?? (new FileReaderService)->dialect(Storage::disk('local')->path($import->path))['delimiter']);
    }

    public function test_a_three_decimal_amount_is_not_re_read_after_normalising(): void
    {
        // 1,234 with a comma decimal = 1.234 (e.g. KWD). It must not come out 1234.
        $csv = "Date;SKU;Store;Qty;Total\n2026-04-03;SKU-K;Downtown;1;1,234\n";
        $this->runImport($this->import($csv, 'csv', self::SALES_MAP, ['decimal_separator' => ',']));

        $this->assertEqualsWithDelta(1.234, (float) SalesTransaction::where('sku', 'SKU-K')->value('total_amount'), 0.00001);
    }

    public function test_an_ambiguous_date_is_quarantined_and_a_misfit_date_fails_its_row(): void
    {
        // One ambiguous row among clean ones (≥90% clean, so the batch is loaded — WP3.6).
        $csv = "Date,SKU,Store,Qty,Total\n03/04/2026,SKU-A,Downtown,1,1\n";
        for ($i = 0; $i < 10; $i++) {
            $csv .= "13/04/2026,SKU-B{$i},Downtown,1,1\n";
        }
        $import = $this->runImport($this->import($csv, 'csv', self::SALES_MAP, ['date_format' => 'auto']));

        $this->assertSame('ambiguous_date', QuarantinedRow::where('import_id', $import->id)->value('reason_code'));
        $this->assertSame(0, SalesTransaction::where('sku', 'SKU-A')->count());
        $this->assertSame(10, SalesTransaction::where('sku', 'like', 'SKU-B%')->count());

        $csv2 = "Date,SKU,Store,Qty,Total\n12/31/2026,SKU-C,Downtown,1,1\n";
        $import2 = $this->runImport($this->import($csv2, 'csv', self::SALES_MAP, ['date_format' => 'd/m/Y']));
        $this->assertSame(0, SalesTransaction::where('sku', 'SKU-C')->count(), 'month 31 is rejected, not swapped to 31 Dec');
        $this->assertStringContainsString('as a date', (string) ImportRow::where('import_id', $import2->id)->value('error_message'));
    }

    public function test_the_writer_parses_correctly_even_with_the_firewall_off(): void
    {
        config(['data_quality.enabled' => false]);
        $csv = "Date,SKU,Store,Qty,Total\n03/04/2026,SKU-N,Downtown,1,(12.50)\n";
        $this->runImport($this->import($csv, 'csv', self::SALES_MAP, ['date_format' => 'd/m/Y']));

        $row = SalesTransaction::where('sku', 'SKU-N')->first();
        $this->assertSame('2026-04-03', $row->date->toDateString());
        $this->assertEqualsWithDelta(-12.5, (float) $row->total_amount, 0.0001);
    }

    public function test_utf16_and_windows_1252_files_are_read_as_utf8(): void
    {
        $text = "Date,SKU,Store,Qty,Total\n2026-04-03,SKU-U,Café Mall,1,1\n";
        $utf16 = "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $this->runImport($this->import($utf16, 'csv', self::SALES_MAP));
        $this->assertSame('Café Mall', SalesTransaction::where('sku', 'SKU-U')->value('location'));

        $cp1252 = mb_convert_encoding(str_replace('SKU-U', 'SKU-W', $text), 'Windows-1252', 'UTF-8');
        $this->runImport($this->import($cp1252, 'csv', self::SALES_MAP));
        $this->assertSame('Café Mall', SalesTransaction::where('sku', 'SKU-W')->value('location'));
    }

    private function xlsx(): string
    {
        $book = new Spreadsheet();
        $s = $book->getActiveSheet();
        $s->fromArray(['Date', 'SKU', 'Barcode', 'Store', 'Qty', 'Total'], null, 'A1');
        $s->setCellValue('A2', ExcelDate::PHPToExcel(new \DateTime('2026-04-03')));
        $s->getStyle('A2')->getNumberFormat()->setFormatCode('m/d/yyyy'); // a US display format
        $s->setCellValue('B2', 123);
        $s->getStyle('B2')->getNumberFormat()->setFormatCode('00000');
        $s->setCellValue('C2', 6291041500213);
        $s->setCellValue('D2', 'Downtown');
        $s->setCellValue('E2', 2);
        $s->setCellValue('F2', '=E2*10.5');

        $file = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        (new Xlsx($book))->save($file);

        return (string) file_get_contents($file);
    }

    public function test_excel_cells_are_read_by_type_not_by_display_format(): void
    {
        $path = 'imports/pending/book.xlsx';
        Storage::disk('local')->put($path, $this->xlsx());

        $row = (new FileReaderService)->readRange(Storage::disk('local')->path($path), 0, 10)['rows'][0];

        $this->assertSame('2026-04-03', $row['Date']);          // ISO — never "4/3/2026"
        $this->assertSame('00123', $row['SKU']);                // zero-padded code keeps its zeros
        $this->assertSame('6291041500213', $row['Barcode']);    // no scientific notation
        $this->assertSame('21', $row['Total']);                 // formula → its value
    }

    public function test_an_excel_sales_file_imports_with_real_dates(): void
    {
        $import = $this->import($this->xlsx(), 'xlsx', self::SALES_MAP, ['date_format' => 'd/m/Y']);
        $import = $this->runImport($import);

        $this->assertSame(1, (int) $import->imported_rows, "status {$import->status}");
        $this->assertSame('2026-04-03', SalesTransaction::where('sku', '00123')->first()?->date?->toDateString());
    }

    public function test_the_tenant_default_applies_when_the_import_sets_nothing(): void
    {
        $this->tenant->update(['settings' => ['import_date_format' => 'm/d/Y', 'import_decimal_separator' => ',']]);
        $import = $this->import("x\n", 'csv', []);

        $p = ValueParser::forImport($import);
        $this->assertSame('m/d/Y', $p->dateFormat);
        $this->assertSame(',', $p->decimal);

        // Spreadsheets always carry real numbers → dot.
        $this->assertSame('.', ValueParser::forImport($this->import('x', 'xlsx', []))->decimal);
    }

    public function test_retrying_a_row_stored_before_normalisation_reads_it_under_the_import_format(): void
    {
        $import = $this->import("x\n", 'csv', [], ['date_format' => 'd/m/Y', 'decimal_separator' => ','], Import::TYPE_INVENTORY);
        $row = ImportRow::create([
            'import_id' => $import->id, 'tenant_id' => $this->tenant->id, 'row_number' => 2,
            'raw_data' => ['x' => 1], 'error_message' => 'old failure', 'status' => ImportRow::STATUS_PENDING,
            'mapped_data' => ['sku' => 'INV-1', 'location' => 'Downtown', 'on_hand_qty' => '1.250,5', 'as_of_date' => '02/03/2026'],
        ]);

        app(ImportProcessorService::class)->retryRows($import, collect([$row]));

        $inv = InventoryLevel::where('sku', 'INV-1')->first();
        $this->assertEqualsWithDelta(1250.5, (float) $inv->on_hand_qty, 0.0001);
        $this->assertSame('2026-03-02', \Illuminate\Support\Carbon::parse($inv->as_of_date)->toDateString());
    }
}
