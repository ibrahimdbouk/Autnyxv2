<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses Claude Sonnet to map source file headers to canonical schema fields.
 *
 * Falls back to fuzzy string matching if ANTHROPIC_API_KEY is not set,
 * so the pipeline works in dev without an API key.
 */
class ColumnMappingService
{
    private const CLAUDE_MODEL = 'claude-sonnet-4-5';
    private const API_URL      = 'https://api.anthropic.com/v1/messages';

    /**
     * Map source headers to canonical fields.
     *
     * @param  string[] $headers     Column names from the uploaded file
     * @param  array[]  $sampleRows  First N rows keyed by header (for context)
     * @param  string   $dataType    One of Import::TYPE_*
     * @return array[]  Each item: [source_header, target_field|null, confidence, reasoning]
     */
    public function map(array $headers, array $sampleRows, string $dataType): array
    {
        $schema = CanonicalSchema::forType($dataType);

        if (empty($schema) || empty($headers)) {
            return [];
        }

        $apiKey = config('services.anthropic.key');

        if ($apiKey) {
            try {
                return $this->mapWithClaude($headers, $sampleRows, $schema, $apiKey);
            } catch (\Throwable $e) {
                Log::warning('Claude column mapping failed, falling back to fuzzy match', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->mapWithFuzzy($headers, $schema);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Claude Sonnet implementation
    // ─────────────────────────────────────────────────────────────────────────

    private function mapWithClaude(array $headers, array $sampleRows, array $schema, string $apiKey): array
    {
        $schemaDescription = collect($schema)->map(function ($field, $key) {
            $req = $field['required'] ? '(required)' : '(optional)';
            return "  - {$key}: {$field['description']} {$req}";
        })->implode("\n");

        $sampleJson = json_encode(array_slice($sampleRows, 0, 5), JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
You are a data integration expert helping map spreadsheet columns to a retail analytics system.

## Target schema fields:
{$schemaDescription}

## Source file headers (to map):
{$this->formatHeaders($headers)}

## Sample data (first 5 rows):
{$sampleJson}

## Task:
For each source header, determine the best matching target field.
- Use the sample data values to help understand what each column contains.
- Set confidence between 0.0 and 1.0 based on how certain you are.
- If a header clearly doesn't belong in this schema, set target_field to null and confidence to 0.
- Be generous with confidence when the data makes the mapping obvious, even if the header name is unusual.
- Provide a brief one-line reasoning for each mapping.

Respond with a JSON array only — no markdown, no explanation outside the JSON:
[
  {
    "source_header": "original column name",
    "target_field": "canonical_field_name or null",
    "confidence": 0.95,
    "reasoning": "one-line explanation"
  },
  ...
]
PROMPT;

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post(self::API_URL, [
            'model'      => self::CLAUDE_MODEL,
            'max_tokens' => 1024,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Anthropic API error: ' . $response->status());
        }

        $text = $response->json('content.0.text', '');
        $mappings = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($mappings)) {
            throw new \RuntimeException('Failed to parse Claude response as JSON');
        }

        // Ensure every source header is represented
        $mapped = collect($mappings)->keyBy('source_header');
        $result = [];

        foreach ($headers as $i => $header) {
            $entry = $mapped->get($header, [
                'source_header' => $header,
                'target_field'  => null,
                'confidence'    => 0.0,
                'reasoning'     => 'Not mapped by AI',
            ]);

            $result[] = [
                'source_header' => $header,
                'target_field'  => $entry['target_field'] ?? null,
                'confidence'    => (float) ($entry['confidence'] ?? 0.0),
                'reasoning'     => $entry['reasoning'] ?? '',
                'sort_order'    => $i,
            ];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fuzzy fallback (no API key required)
    // ─────────────────────────────────────────────────────────────────────────

    private function mapWithFuzzy(array $headers, array $schema): array
    {
        $targetFields = array_keys($schema);
        $result       = [];

        foreach ($headers as $i => $header) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '_', $header));

            $bestField      = null;
            $bestScore      = 0;

            foreach ($targetFields as $field) {
                // Exact match
                if ($normalized === $field) {
                    $bestField = $field;
                    $bestScore = 1.0;
                    break;
                }

                // Alias matching
                $aliases = $this->aliases($field);
                foreach ($aliases as $alias) {
                    similar_text($normalized, $alias, $pct);
                    $score = $pct / 100;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestField = $field;
                    }
                }
            }

            $result[] = [
                'source_header' => $header,
                'target_field'  => $bestScore >= 0.5 ? $bestField : null,
                'confidence'    => round($bestScore, 2),
                'reasoning'     => $bestScore >= 0.5
                    ? "Fuzzy matched '{$header}' → '{$bestField}'"
                    : "No confident match found for '{$header}'",
                'sort_order'    => $i,
            ];
        }

        return $result;
    }

    private function aliases(string $field): array
    {
        return [
            'date'           => ['date', 'transaction_date', 'sale_date', 'order_date', 'txn_date', 'trans_date'],
            'sku'            => ['sku', 'item_code', 'product_code', 'product_id', 'item_id', 'article_number', 'item_no'],
            'product_name'   => ['product_name', 'product', 'item_name', 'description', 'item_description', 'name'],
            'location'       => ['location', 'store', 'store_id', 'outlet', 'channel', 'region', 'site', 'branch'],
            'quantity'       => ['quantity', 'qty', 'units', 'qty_sold', 'units_sold', 'sales_qty', 'amount_sold'],
            'unit_price'     => ['unit_price', 'price', 'selling_price', 'retail_price', 'unit_retail', 'price_each'],
            'total_amount'   => ['total_amount', 'total', 'revenue', 'sales', 'net_sales', 'gross_sales', 'total_sales', 'amount'],
            'transaction_id' => ['transaction_id', 'txn_id', 'trans_id', 'receipt_no', 'receipt_number', 'order_id'],
            'on_hand_qty'    => ['on_hand_qty', 'on_hand', 'qty_on_hand', 'stock', 'stock_qty', 'inventory', 'qty_available', 'available'],
            'reorder_point'  => ['reorder_point', 'reorder_level', 'min_stock', 'min_qty', 'safety_stock'],
            'as_of_date'     => ['as_of_date', 'as_of', 'snapshot_date', 'report_date', 'effective_date', 'date'],
            'category'       => ['category', 'dept', 'department', 'class', 'product_class', 'product_type'],
            'subcategory'    => ['subcategory', 'sub_category', 'subclass', 'sub_class', 'sub_dept', 'subdivision'],
            'unit_cost'      => ['unit_cost', 'cost', 'cost_price', 'purchase_price', 'landed_cost', 'cogs'],
            'selling_price'  => ['selling_price', 'retail', 'sale_price', 'list_price', 'msrp', 'rrp'],
            'supplier'       => ['supplier', 'vendor', 'vendor_name', 'supplier_name', 'manufacturer', 'brand'],
            'barcode'        => ['barcode', 'upc', 'ean', 'gtin', 'barcode_number', 'upc_code'],
            'po_number'      => ['po_number', 'po_no', 'po_num', 'purchase_order', 'order_number', 'po_ref'],
            'qty_ordered'    => ['qty_ordered', 'ordered_qty', 'order_qty', 'quantity_ordered', 'qty_order'],
            'qty_received'   => ['qty_received', 'received_qty', 'quantity_received', 'qty_recv', 'received'],
            'order_date'     => ['order_date', 'po_date', 'created_date', 'placed_date', 'purchase_date'],
            'expected_date'  => ['expected_date', 'due_date', 'promised_date', 'eta', 'delivery_date', 'est_arrival'],
            'received_date'  => ['received_date', 'receipt_date', 'arrival_date', 'delivery_received', 'actual_receipt'],
            'name'           => ['name', 'product_name', 'item_name', 'description', 'product_description', 'title', 'store_name', 'supplier_name', 'vendor_name', 'full_name'],
            // Stores / Suppliers / Users master data (M24)
            'code'           => ['code', 'store_code', 'store_no', 'store_number', 'supplier_code', 'vendor_code', 'location_code', 'site_code'],
            'address'        => ['address', 'street', 'street_address', 'address_line', 'addr'],
            'city'           => ['city', 'town', 'municipality'],
            'region'         => ['region', 'state', 'province', 'area', 'territory', 'zone', 'district'],
            'country'        => ['country', 'country_code', 'nation'],
            'lead_time_days' => ['lead_time_days', 'lead_time', 'leadtime', 'lead_days', 'delivery_days', 'contracted_lead_time'],
            'contact_email'  => ['contact_email', 'email', 'supplier_email', 'vendor_email', 'contact_mail', 'e_mail'],
            'contact_phone'  => ['contact_phone', 'phone', 'telephone', 'tel', 'supplier_phone', 'vendor_phone', 'mobile', 'contact_number'],
            'email'          => ['email', 'e_mail', 'login', 'user_email', 'username', 'mail'],
            'role'           => ['role', 'user_role', 'access_level', 'permission', 'access', 'type'],
        ][$field] ?? [$field];
    }

    private function formatHeaders(array $headers): string
    {
        return implode("\n", array_map(fn($h, $i) => "  {$i}. {$h}", $headers, array_keys($headers)));
    }
}
