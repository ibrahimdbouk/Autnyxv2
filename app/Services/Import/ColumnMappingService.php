<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Uses Claude Sonnet to map source file headers to canonical schema fields.
 *
 * Falls back to fuzzy string matching if ANTHROPIC_API_KEY is not set,
 * so the pipeline works in dev without an API key.
 */
class ColumnMappingService
{

    /**
     * Map source headers to canonical fields.
     *
     * @param  string[] $headers     Column names from the uploaded file
     * @param  array[]  $sampleRows  First N rows keyed by header (for context)
     * @param  string   $dataType    One of Import::TYPE_*
     * @return array[]  Each item: [source_header, target_field|null, confidence, reasoning]
     */
    /** WP3.1: fields matched only by their exact header (a fuzzy "Line Total" must never become line_no). */
    private const EXACT_ONLY = ['line_no'];

    /** WP3.3 (audit H24): a mapping below this confidence is never applied automatically. */
    public const THRESHOLD = 0.75;

    /** Headers whose sample values never leave the platform (AI mapper prompt). */
    private const MASK_HEADERS = '/(ref|customer|member|loyalty|email|e_mail|phone|mobile|card|iban|account)/i';

    public function map(array $headers, array $sampleRows, string $dataType, ?int $tenantId = null): array
    {
        $schema = CanonicalSchema::forType($dataType);

        if (empty($schema) || empty($headers)) {
            return [];
        }

        // Slice 5 — learned mapping memory takes precedence over AI: if this tenant has
        // already confirmed a mapping for this header signature, reuse it deterministically
        // (drifted/new columns come back flagged for one-time confirmation). Best-effort.
        if ($tenantId !== null) {
            try {
                $learned = \App\Models\MappingMemory::recall($tenantId, $dataType, $headers);
                if ($learned !== null) {
                    return $learned;
                }
            } catch (\Throwable $e) {
                Log::warning('Mapping memory recall failed', ['error' => $e->getMessage()]);
            }
        }

        $apiKey = config('services.anthropic.key');

        if ($apiKey) {
            try {
                return $this->finalise($this->mapWithClaude($headers, $sampleRows, $schema, $tenantId), $schema);
            } catch (\Throwable $e) {
                Log::warning('Claude column mapping failed, falling back to fuzzy match', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->finalise($this->mapWithFuzzy($headers, $schema), $schema);
    }

    /**
     * WP3.3: why an automatic (SFTP / API) import must wait for a person, or
     * null when the mapping is safe to apply unattended: every required field
     * mapped, no target claimed twice, no column left on an uncertain match.
     */
    public function reviewReason(array $mappings, string $dataType): ?string
    {
        $mapped = [];
        foreach ($mappings as $m) {
            $target = $m['target_field'] ?? null;
            if ($target !== null && $target !== '') {
                $mapped[$target] = true;
            } elseif ((float) ($m['confidence'] ?? 0) >= 0.5) {
                return "Column '{$m['source_header']}' has an uncertain match ({$m['reasoning']}).";
            }
        }

        foreach (CanonicalSchema::forType($dataType) as $field => $def) {
            if (($def['required'] ?? false) && ! isset($mapped[$field])) {
                return "Required field '{$def['label']}' is not mapped to any column.";
            }
        }

        return null;
    }

    /**
     * WP3.3: every entry targets a real field of this schema, only confident
     * matches are applied, and each target is claimed by one column at most —
     * the most confident wins; a tie leaves both for review (never "last wins").
     */
    private function finalise(array $entries, array $schema): array
    {
        foreach ($entries as &$e) {
            $target = $e['target_field'] ?? null;
            if ($target !== null && ! array_key_exists($target, $schema)) {
                $e['target_field'] = null;
                $e['confidence'] = 0.0;
                $e['reasoning'] = "Suggested field '{$target}' does not exist for this data type";
            } elseif ($target !== null && (float) $e['confidence'] < self::THRESHOLD) {
                $e['reasoning'] = "Low-confidence match to '{$target}' — please confirm";
                $e['target_field'] = null;
            }
        }
        unset($e);

        $byTarget = [];
        foreach ($entries as $i => $e) {
            if ($e['target_field'] !== null) {
                $byTarget[$e['target_field']][] = $i;
            }
        }
        foreach ($byTarget as $target => $idx) {
            if (count($idx) < 2) {
                continue;
            }
            usort($idx, fn ($a, $b) => $entries[$b]['confidence'] <=> $entries[$a]['confidence']);
            $best = $entries[$idx[0]]['confidence'];
            $tie = $entries[$idx[1]]['confidence'] >= $best;
            $headers = implode("', '", array_map(fn ($i) => $entries[$i]['source_header'], $idx));
            foreach ($idx as $n => $i) {
                if ($n === 0 && ! $tie) {
                    continue;
                }
                $entries[$i]['target_field'] = null;
                $entries[$i]['confidence'] = max(0.5, (float) $entries[$i]['confidence']);
                $entries[$i]['reasoning'] = "Conflict: '{$headers}' all match '{$target}' — choose one";
            }
        }

        return array_values($entries);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Claude implementation
    // ─────────────────────────────────────────────────────────────────────────

    private function mapWithClaude(array $headers, array $sampleRows, array $schema, ?int $tenantId = null): array
    {
        $schemaDescription = collect($schema)->map(function ($field, $key) {
            $req = $field['required'] ? '(required)' : '(optional)';
            return "  - {$key}: {$field['description']} {$req}";
        })->implode("\n");

        $sampleJson = json_encode($this->maskSamples(array_slice($sampleRows, 0, 5)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are a data integration expert helping map spreadsheet columns to a retail analytics system.

## Target schema fields:
{$schemaDescription}

## Source file headers (to map):
{$this->formatHeaders($headers)}

## Sample data (first 5 rows; some values are masked for privacy):
{$sampleJson}

## Task:
For each source header, determine the best matching target field.
- Only use target field names from the list above, exactly as written.
- Each target field may be used by at most ONE source header.
- Use the sample data values to help understand what each column contains.
- Set confidence between 0.0 and 1.0 based on how certain you are. Below 0.75 means a person must confirm.
- If a header clearly doesn't belong in this schema, set target_field to null and confidence to 0.
- Provide a brief one-line reasoning for each mapping.

Respond with a JSON array only — no markdown, no explanation outside the JSON:
[
  {
    "source_header": "original column name",
    "target_field": "canonical_field_name or null",
    "confidence": 0.95,
    "reasoning": "one-line explanation"
  }
]
PROMPT;

        // WP5.4: through AnthropicClient (budget, retries, circuit breaker, metering).
        // WP3.3: room for every column (was a flat 1024 → wide files truncated).
        $r = app(\App\Services\AI\AnthropicClient::class)
            ->message($tenantId, 'mapping', $prompt, 'reasoning', min(8000, 256 + 96 * count($headers)), null, 30);

        if (! $r->ok) {
            throw new \RuntimeException('AI mapping unavailable: ' . $r->error);
        }
        if ($r->truncated()) {
            throw new \RuntimeException('AI mapping response was truncated');
        }

        $mappings = self::decodeJsonArray($r->text);

        // Ensure every source header is represented
        $mapped = collect($mappings)->filter(fn ($m) => is_array($m) && isset($m['source_header']))->keyBy('source_header');
        $result = [];

        foreach ($headers as $i => $header) {
            $entry = $mapped->get($header, [
                'source_header' => $header,
                'target_field'  => null,
                'confidence'    => 0.0,
                'reasoning'     => 'Not mapped by AI',
            ]);

            $target = $entry['target_field'] ?? null;
            $result[] = [
                'source_header' => $header,
                'target_field'  => is_string($target) && $target !== '' ? $target : null,
                'confidence'    => max(0.0, min(1.0, (float) ($entry['confidence'] ?? 0.0))),
                'reasoning'     => mb_substr((string) ($entry['reasoning'] ?? ''), 0, 250),
                'sort_order'    => $i,
            ];
        }

        return $result;
    }

    /** WP3.3: strict JSON — tolerate a ```json fence, nothing else. */
    public static function decodeJsonArray(string $text): array
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            $text = $m[1];
        }
        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \RuntimeException('Failed to parse the AI mapping response as a JSON array');
        }

        return $decoded;
    }

    /** Sample values of reference / personal columns are masked before they reach the AI. */
    private function maskSamples(array $rows): array
    {
        return array_map(function ($row) {
            $out = [];
            foreach ((array) $row as $header => $value) {
                $out[$header] = preg_match(self::MASK_HEADERS, (string) $header)
                    ? '***'
                    : mb_substr((string) $value, 0, 60);
            }

            return $out;
        }, $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Deterministic fallback (no API key)
    // ─────────────────────────────────────────────────────────────────────────

    public static function normaliseHeader(string $header): string
    {
        return trim(preg_replace('/_+/', '_', strtolower(preg_replace('/[^a-z0-9]/i', '_', trim($header)) ?? '')) ?? '', '_');
    }

    /**
     * WP3.3 (audit H24): conservative matching — an exact field or alias, the
     * same words in another order, or a near-typo of one. Nothing looser: a
     * wrong mapping silently corrupts data, an unmapped column only asks a
     * person. (The old 50% similar_text matched "Latitude" to a name.)
     */
    private function mapWithFuzzy(array $headers, array $schema): array
    {
        $result = [];

        foreach ($headers as $i => $header) {
            $normalized = self::normaliseHeader((string) $header);
            $tokens = $this->tokens($normalized);

            $bestField = null;
            $bestScore = 0.0;
            $how = '';

            foreach (array_keys($schema) as $field) {
                if ($normalized === $field) {
                    [$bestField, $bestScore, $how] = [$field, 1.0, 'exact field name'];
                    break;
                }
                if (in_array($field, self::EXACT_ONLY, true)) {
                    continue;
                }

                foreach ($this->aliases($field) as $alias) {
                    $score = 0.0;
                    $why = '';
                    if ($normalized === $alias) {
                        [$score, $why] = [0.95, "alias '{$alias}'"];
                    } elseif ($tokens !== [] && $tokens === $this->tokens($alias)) {
                        [$score, $why] = [0.9, "same words as '{$alias}'"];
                    } elseif (strlen($normalized) >= 5 && strlen($alias) >= 5 && $normalized[0] === $alias[0]) {
                        similar_text($normalized, $alias, $pct);
                        if ($pct >= 88) {
                            [$score, $why] = [round($pct / 100 - 0.1, 2), "near-spelling of '{$alias}'"];
                        }
                    }
                    if ($score > $bestScore) {
                        [$bestField, $bestScore, $how] = [$field, $score, $why];
                    }
                }
            }

            $result[] = [
                'source_header' => $header,
                'target_field'  => $bestField,
                'confidence'    => round($bestScore, 2),
                'reasoning'     => $bestField !== null
                    ? "Matched '{$header}' → '{$bestField}' ({$how})"
                    : "No confident match found for '{$header}'",
                'sort_order'    => $i,
            ];
        }

        return $result;
    }

    /** @return string[] sorted tokens */
    private function tokens(string $normalized): array
    {
        $t = array_values(array_filter(explode('_', $normalized), fn ($x) => $x !== ''));
        sort($t);

        return $t;
    }

    private function aliases(string $field): array
    {
        return [
            'date'           => ['date', 'transaction_date', 'sale_date', 'order_date', 'txn_date', 'trans_date'],
            'sku'            => ['sku', 'item_code', 'product_code', 'product_id', 'item_id', 'article_number', 'item_no'],
            'product_name'   => ['product_name', 'product', 'item_name', 'description', 'item_description', 'name'],
            'location'       => ['location', 'store', 'store_id', 'store_name', 'outlet', 'outlet_name', 'site', 'branch', 'branch_name'],
            'quantity'       => ['quantity', 'qty', 'units', 'qty_sold', 'units_sold', 'sales_qty', 'amount_sold'],
            'unit_price'     => ['unit_price', 'price', 'selling_price', 'retail_price', 'unit_retail', 'price_each'],
            'total_amount'   => ['total_amount', 'total', 'revenue', 'sales', 'net_sales', 'gross_sales', 'total_sales', 'amount'],
            'transaction_id' => ['transaction_id', 'txn_id', 'trans_id', 'receipt_no', 'receipt_number', 'order_id'],
            'on_hand_qty'    => ['on_hand_qty', 'on_hand', 'qty_on_hand', 'stock', 'stock_qty', 'inventory', 'qty_available', 'available'],
            'reorder_point'  => ['reorder_point', 'reorder_level', 'min_stock', 'min_qty'],
            'as_of_date'     => ['as_of_date', 'as_of', 'snapshot_date', 'report_date', 'effective_date', 'date'],
            'category'       => ['category', 'class', 'product_class', 'product_type', 'product_category'],
            'subcategory'    => ['subcategory', 'sub_category', 'subclass', 'sub_class', 'sub_dept', 'subdivision'],
            'unit_cost'      => ['unit_cost', 'cost', 'cost_price', 'purchase_price', 'landed_cost', 'cogs'],
            'selling_price'  => ['selling_price', 'retail', 'sale_price', 'list_price', 'msrp', 'rrp'],
            'supplier'       => ['supplier', 'vendor', 'vendor_name', 'supplier_name', 'manufacturer'],
            'barcode'        => ['barcode', 'upc', 'ean', 'barcode_number', 'upc_code'],
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
            'contact_email'  => ['contact_email', 'supplier_email', 'vendor_email', 'contact_mail'],
            'contact_phone'  => ['contact_phone', 'supplier_phone', 'vendor_phone', 'contact_number'],
            'email'          => ['email', 'e_mail', 'login', 'user_email', 'username', 'mail', 'store_email'],
            'role'           => ['role', 'user_role', 'access_level', 'permission', 'access', 'type'],
            // WP3.4 — hardening fields + dimensions (exact / reordered / near-typo matches only).
            'channel'        => ['channel', 'sales_channel', 'order_channel', 'return_channel'],
            'cost_amount'    => ['cost_amount', 'line_cost', 'cost_of_goods', 'cogs_amount', 'total_cost'],
            'currency'       => ['currency', 'currency_code', 'ccy', 'curr'],
            'customer_ref'   => ['customer_ref', 'customer_id', 'customer_number', 'customer_no', 'loyalty_id', 'member_id'],
            'promotion_ref'  => ['promotion_ref', 'promotion_id', 'promo_id', 'promo_code', 'promotion_code', 'campaign_id'],
            'safety_stock'   => ['safety_stock', 'safety_qty', 'buffer_stock'],
            'allocated_qty'  => ['allocated_qty', 'allocated', 'reserved_qty', 'reserved', 'committed_qty'],
            'in_transit_qty' => ['in_transit_qty', 'in_transit', 'transit_qty', 'intransit_qty'],
            'batch_ref'      => ['batch_ref', 'batch', 'batch_no', 'batch_number', 'lot', 'lot_no', 'lot_number'],
            'expiry_date'    => ['expiry_date', 'expiry', 'expiration_date', 'exp_date', 'best_before', 'use_by'],
            'status'         => ['status', 'po_status', 'order_status', 'line_status', 'product_status', 'item_status', 'store_status', 'supplier_status'],
            'buyer'          => ['buyer', 'buyer_name', 'purchaser', 'planner'],
            'condition'      => ['condition', 'item_condition', 'return_condition'],
            // W10 — promotion calendar.
            'start_date'     => ['start_date', 'promo_start', 'promotion_start', 'valid_from', 'from_date', 'begin_date', 'start'],
            'end_date'       => ['end_date', 'promo_end', 'promotion_end', 'valid_to', 'to_date', 'finish_date', 'end'],
            'promotion_name' => ['promotion_name', 'promo_name', 'campaign_name', 'deal_name', 'promotion_description'],
            'mechanic'       => ['mechanic', 'promo_type', 'promotion_type', 'deal_type', 'offer_type'],
            'discount_pct'   => ['discount_pct', 'discount_percent', 'discount_percentage', 'pct_off', 'percent_off'],
            'promo_price'    => ['promo_price', 'promotion_price', 'deal_price', 'offer_price', 'sale_price'],
            // W12 — store managers (users import).
            'stores'         => ['stores', 'store', 'store_codes', 'store_code', 'branch', 'branches', 'outlet', 'outlets', 'store_name'],
            // W11 — waste / write-offs.
            'waste_ref'      => ['waste_ref', 'write_off_ref', 'writeoff_no', 'write_off_no', 'waste_document', 'adjustment_no', 'document_no'],
            'original_transaction_ref' => ['original_transaction_ref', 'original_receipt', 'original_receipt_no', 'original_transaction_id', 'original_order_id'],
            'department'     => ['department', 'dept', 'division'],
            'uom'            => ['uom', 'unit_of_measure', 'base_unit', 'sales_unit', 'selling_unit', 'order_unit', 'order_uom', 'purchase_unit', 'po_uom'],
            'units_per_case' => ['units_per_case', 'case_size', 'case_qty', 'pack_qty', 'units_per_carton', 'case_pack', 'inner_qty', 'outer_qty', 'pcs_per_case'],
            'weight_grams'   => ['weight_grams', 'weight', 'weight_g', 'weight_kg', 'net_weight', 'gross_weight', 'unit_weight'],
            'tax_rate'       => ['tax_rate', 'vat_rate', 'vat', 'vat_percent', 'tax_percent', 'tax'],
            'gtin'           => ['gtin', 'gtin13', 'gtin_13', 'gtin14', 'gtin_14'],
            'season'         => ['season', 'season_code', 'collection'],
            'length_mm'      => ['length_mm', 'length', 'length_cm', 'depth', 'depth_mm', 'depth_cm'],
            'width_mm'       => ['width_mm', 'width', 'width_cm'],
            'height_mm'      => ['height_mm', 'height', 'height_cm'],
            'volume_cm3'     => ['volume_cm3', 'volume', 'volume_ml', 'volume_l', 'cm3'],
            'postal_code'    => ['postal_code', 'postcode', 'post_code', 'zip', 'zip_code', 'po_box'],
            'latitude'       => ['latitude', 'lat'],
            'longitude'      => ['longitude', 'lng', 'lon', 'long'],
            'phone'          => ['phone', 'telephone', 'tel', 'store_phone', 'phone_number'],
            'timezone'       => ['timezone', 'time_zone', 'tz'],
            'banner'         => ['banner', 'fascia', 'chain', 'store_banner'],
            'opened_on'      => ['opened_on', 'open_date', 'opening_date', 'store_open_date', 'opened'],
            'sales_area_sqm' => ['sales_area_sqm', 'sales_area', 'selling_area', 'area_sqm', 'floor_area', 'sqm'],
            'payment_terms'  => ['payment_terms', 'terms', 'pay_terms', 'credit_terms'],
            'min_order_value'=> ['min_order_value', 'minimum_order_value', 'min_order', 'mov'],
            'website'        => ['website', 'url', 'web', 'web_site'],
        ][$field] ?? [$field];
    }

    private function formatHeaders(array $headers): string
    {
        return implode("\n", array_map(fn($h, $i) => "  {$i}. {$h}", $headers, array_keys($headers)));
    }
}
