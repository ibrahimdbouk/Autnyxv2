<?php

namespace App\Services\Import;

/**
 * Defines the canonical field schemas for each data type.
 * Used by the column mapping service as the target vocabulary.
 */
class CanonicalSchema
{
    /**
     * WP3.3: bump whenever fields, their meaning or the aliases change — learned
     * mapping memories from an older version are ignored (they could replay a
     * mapping made under different rules).
     *   v2 — WP3.3 conservative mapper.  v3 — WP3.4 hardening fields + dimensions.
     */
    public const VERSION = 3;

    /**
     * Returns field definitions for the given data type.
     * Each field: ['label' => string, 'description' => string, 'required' => bool]
     */
    public static function forType(string $dataType): array
    {
        return match ($dataType) {
            'sales_transactions'  => self::salesTransactions(),
            'inventory_levels'    => self::inventoryLevels(),
            'products'            => self::products(),
            'purchase_orders'     => self::purchaseOrders(),
            'stores'              => self::stores(),
            'suppliers'           => self::suppliers(),
            'users'               => self::users(),
            'returns'             => self::returns(),
            'promotions'          => self::promotions(),
            'waste'               => self::waste(),
            default               => [],
        };
    }

    public static function fieldNames(string $dataType): array
    {
        return array_keys(self::forType($dataType));
    }

    private static function salesTransactions(): array
    {
        return [
            'date'           => ['label' => 'Date',           'description' => 'Transaction date (any common date format)', 'required' => true],
            'sku'            => ['label' => 'SKU',            'description' => 'Product SKU, item code, or product ID',     'required' => true],
            'product_name'   => ['label' => 'Product Name',   'description' => 'Name or description of the product',        'required' => false],
            'location'       => ['label' => 'Location',       'description' => 'Store or outlet the sale happened at (name or code) — not a sales channel or region', 'required' => false],
            'quantity'       => ['label' => 'Quantity',       'description' => 'Number of units sold',                      'required' => true],
            'unit_price'     => ['label' => 'Unit Price',     'description' => 'Price per unit sold',                       'required' => false],
            'total_amount'   => ['label' => 'Total Amount',   'description' => 'Total net revenue or sales amount for the row', 'required' => false],
            'transaction_id' => ['label' => 'Transaction ID', 'description' => 'Receipt / transaction number (shared by every line of the receipt)', 'required' => false],
            'line_no'        => ['label' => 'Line Number',    'description' => 'Line number within the receipt (1, 2, 3…). Optional: derived from file order when not mapped', 'required' => false],
            'discount'       => ['label' => 'Discount',       'description' => 'Discount amount applied on the row',       'required' => false],
            'payment_method' => ['label' => 'Payment Method', 'description' => 'Payment type, e.g. cash, card, wallet',    'required' => false],
            // WP3.4 — hardening fields.
            'channel' => ['label' => 'Channel', 'description' => 'Sales channel, e.g. store, online, delivery app', 'required' => false],
            'cost_amount' => ['label' => 'Cost Amount', 'description' => 'Cost of goods for the row (total, not per unit)', 'required' => false],
            'currency' => ['label' => 'Currency', 'description' => '3-letter currency code, e.g. AED, SAR', 'required' => false],
            'customer_ref' => ['label' => 'Customer Ref', 'description' => 'Customer or loyalty identifier (kept as a reference only)', 'required' => false],
            'promotion_ref' => ['label' => 'Promotion Ref', 'description' => 'Promotion, coupon or campaign identifier', 'required' => false],
        ];
    }

    private static function inventoryLevels(): array
    {
        return [
            'sku'           => ['label' => 'SKU',           'description' => 'Product SKU, item code, or product ID',              'required' => true],
            'product_name'  => ['label' => 'Product Name',  'description' => 'Name or description of the product',                 'required' => false],
            'location'      => ['label' => 'Location',      'description' => 'Store, warehouse, or bin location',                  'required' => false],
            'on_hand_qty'     => ['label' => 'On Hand Qty',     'description' => 'Current stock quantity on hand',                     'required' => true],
            'reorder_point'   => ['label' => 'Reorder Point',   'description' => 'Minimum stock level before reordering is triggered', 'required' => false],
            'as_of_date'      => ['label' => 'As Of Date',      'description' => 'Date this inventory snapshot was taken',             'required' => false],
            'on_order_qty'    => ['label' => 'On Order Qty',    'description' => 'Quantity currently on order (inbound, not yet received)', 'required' => false],
            'inventory_value' => ['label' => 'Inventory Value', 'description' => 'Monetary value of on-hand stock for this row',       'required' => false],
            // WP3.4 — hardening fields.
            'safety_stock' => ['label' => 'Safety Stock', 'description' => 'Safety / buffer stock quantity (not the reorder point)', 'required' => false],
            'allocated_qty' => ['label' => 'Allocated Qty', 'description' => 'Quantity reserved or allocated to orders', 'required' => false],
            'in_transit_qty' => ['label' => 'In-Transit Qty', 'description' => 'Quantity in transit to this location', 'required' => false],
            'unit_cost' => ['label' => 'Unit Cost', 'description' => 'Cost per unit of this stock', 'required' => false],
            'batch_ref' => ['label' => 'Batch / Lot', 'description' => 'Batch or lot number', 'required' => false],
            'expiry_date' => ['label' => 'Expiry Date', 'description' => 'Expiry / best-before date of this batch (not the snapshot date)', 'required' => false],
        ];
    }

    private static function products(): array
    {
        return [
            'sku'           => ['label' => 'SKU',           'description' => 'Product SKU, item code, or product ID',              'required' => true],
            'name'          => ['label' => 'Name',          'description' => 'Product name or description',                        'required' => true],
            'category'      => ['label' => 'Category',      'description' => 'Product category or department',                     'required' => false],
            'subcategory'   => ['label' => 'Subcategory',   'description' => 'Product subcategory or sub-department',              'required' => false],
            'unit_cost'     => ['label' => 'Unit Cost',     'description' => 'Cost to purchase or manufacture one unit',           'required' => false],
            'selling_price' => ['label' => 'Selling Price', 'description' => 'Retail or wholesale selling price per unit',         'required' => false],
            'supplier'      => ['label' => 'Supplier',      'description' => 'Supplier or vendor name',                            'required' => false],
            'barcode'       => ['label' => 'Barcode',       'description' => 'Barcode, UPC, EAN, or GTIN',                         'required' => false],
            'brand'         => ['label' => 'Brand',         'description' => 'Product brand or manufacturer',                      'required' => false],
            'pack_size'     => ['label' => 'Pack Size',     'description' => 'Pack or unit size, e.g. 1L, 500g, 6-pack',           'required' => false],
            // WP3.4 — hardening fields + physical dimensions.
            'department' => ['label' => 'Department', 'description' => 'Department above category, e.g. Fresh, Grocery, Non-Food', 'required' => false],
            'uom' => ['label' => 'Unit of Measure', 'description' => 'Selling unit, e.g. each, kg, case', 'required' => false],
            'weight_grams' => ['label' => 'Weight', 'description' => 'Weight per unit. A unit in the value or header (kg, g, lb, oz) is converted; a plain number is grams', 'required' => false],
            'tax_rate' => ['label' => 'Tax Rate', 'description' => 'VAT / tax rate: 5% or 0.05 both mean five percent', 'required' => false],
            'gtin' => ['label' => 'GTIN', 'description' => 'Global trade item number (GTIN-8/12/13/14)', 'required' => false],
            'season' => ['label' => 'Season', 'description' => 'Season or collection', 'required' => false],
            'status' => ['label' => 'Status', 'description' => 'Product status, e.g. active, discontinued', 'required' => false],
            'length_mm' => ['label' => 'Length', 'description' => 'Length per unit (mm, cm, m or in — converted to mm; plain number = mm)', 'required' => false],
            'width_mm' => ['label' => 'Width', 'description' => 'Width per unit (mm, cm, m or in — converted to mm; plain number = mm)', 'required' => false],
            'height_mm' => ['label' => 'Height', 'description' => 'Height per unit (mm, cm, m or in — converted to mm; plain number = mm)', 'required' => false],
            'volume_cm3' => ['label' => 'Volume', 'description' => 'Volume per unit in cm³ (l / ml converted). Derived from the dimensions when missing', 'required' => false],
        ];
    }

    private static function stores(): array
    {
        return [
            'name'    => ['label' => 'Store Name', 'description' => 'Store, outlet or location name (matches the Location used in sales/inventory)', 'required' => true],
            'code'    => ['label' => 'Store Code', 'description' => 'Store number or code (e.g. ST023)',        'required' => false],
            'format'  => ['label' => 'Format',     'description' => 'Store format, e.g. Hypermarket, Supermarket, Express', 'required' => false],
            'address' => ['label' => 'Address',    'description' => 'Street address',                          'required' => false],
            'city'    => ['label' => 'City',       'description' => 'City',                                    'required' => false],
            'region'  => ['label' => 'Region',     'description' => 'Region, state or area',                   'required' => false],
            'country' => ['label' => 'Country',    'description' => 'Country',                                 'required' => false],
            // WP3.4 — hardening fields.
            'postal_code' => ['label' => 'Postal Code', 'description' => 'Postal / ZIP code or PO box', 'required' => false],
            'latitude' => ['label' => 'Latitude', 'description' => 'Latitude in decimal degrees (-90 to 90)', 'required' => false],
            'longitude' => ['label' => 'Longitude', 'description' => 'Longitude in decimal degrees (-180 to 180)', 'required' => false],
            'phone' => ['label' => 'Phone', 'description' => 'Store phone number', 'required' => false],
            'email' => ['label' => 'Email', 'description' => 'Store email address', 'required' => false],
            'timezone' => ['label' => 'Timezone', 'description' => 'IANA time zone, e.g. Asia/Dubai', 'required' => false],
            'currency' => ['label' => 'Currency', 'description' => '3-letter currency code, e.g. AED', 'required' => false],
            'banner' => ['label' => 'Banner', 'description' => 'Chain / fascia the store trades under', 'required' => false],
            'status' => ['label' => 'Status', 'description' => 'Store status, e.g. active, closed, remodel', 'required' => false],
            'opened_on' => ['label' => 'Opened On', 'description' => 'Store opening date', 'required' => false],
            'sales_area_sqm' => ['label' => 'Sales Area (m²)', 'description' => 'Selling floor area in square metres', 'required' => false],
        ];
    }

    private static function suppliers(): array
    {
        return [
            'name'           => ['label' => 'Supplier Name',   'description' => 'Supplier or vendor name (matches Supplier on purchase orders)', 'required' => true],
            'code'           => ['label' => 'Supplier Code',   'description' => 'Supplier number or external code',           'required' => false],
            'lead_time_days' => ['label' => 'Lead Time (days)','description' => 'Contracted lead time in days',               'required' => false],
            'contact_email'  => ['label' => 'Contact Email',   'description' => 'Supplier contact email address',             'required' => false],
            'contact_phone'  => ['label' => 'Contact Phone',   'description' => 'Supplier contact phone number',              'required' => false],
            'type'           => ['label' => 'Type',            'description' => 'Supplier type, e.g. distributor, manufacturer, importer', 'required' => false],
            'specialization' => ['label' => 'Specialization',  'description' => 'Supplier category focus, e.g. Dairy, Beverages, Frozen', 'required' => false],
            // WP3.4 — hardening fields.
            'country' => ['label' => 'Country', 'description' => 'Country', 'required' => false],
            'region' => ['label' => 'Region', 'description' => 'Region, state or area', 'required' => false],
            'city' => ['label' => 'City', 'description' => 'City', 'required' => false],
            'currency' => ['label' => 'Currency', 'description' => '3-letter currency code the supplier invoices in', 'required' => false],
            'payment_terms' => ['label' => 'Payment Terms', 'description' => 'Payment terms, e.g. NET30', 'required' => false],
            'min_order_value' => ['label' => 'Min Order Value', 'description' => 'Minimum order value', 'required' => false],
            'website' => ['label' => 'Website', 'description' => 'Website URL', 'required' => false],
            'status' => ['label' => 'Status', 'description' => 'Supplier status, e.g. active, blocked', 'required' => false],
        ];
    }

    private static function users(): array
    {
        return [
            'name'  => ['label' => 'Name',  'description' => 'Full name of the user',                                   'required' => true],
            'email' => ['label' => 'Email', 'description' => 'Login email address (must be unique)',                    'required' => true],
            'role'  => ['label' => 'Role',  'description' => 'admin / tenant_admin grants admin rights; anything else is a standard user', 'required' => false],
            // W12: store managers — the store(s) they run, by code or name, separated by ; or ,
            'stores' => ['label' => 'Stores', 'description' => 'Store(s) this person runs — codes or names, separated by ; or , (they get that store\'s daily digest)', 'required' => false],
        ];
    }

    private static function returns(): array
    {
        return [
            'return_id' => ['label' => 'Return ID', 'description' => 'Unique identifier for the return',                    'required' => false],
            'date'     => ['label' => 'Date',     'description' => 'Date the item was returned (any common date format)', 'required' => true],
            'sku'      => ['label' => 'SKU',      'description' => 'Product SKU, item code, or product ID',              'required' => true],
            'quantity' => ['label' => 'Quantity', 'description' => 'Number of units returned',                           'required' => true],
            'value'    => ['label' => 'Value',    'description' => 'Refund or return value for the row',                 'required' => false],
            'location' => ['label' => 'Store',    'description' => 'Store, outlet or location where the return occurred (matches the Store name used elsewhere)', 'required' => false],
            'reason'   => ['label' => 'Reason',   'description' => 'Reason for the return (e.g. defective, wrong size, changed mind)', 'required' => false],
            // WP3.4 — hardening fields.
            'channel' => ['label' => 'Channel', 'description' => 'Channel the return came through, e.g. store, online', 'required' => false],
            'condition' => ['label' => 'Condition', 'description' => 'Condition of the returned item, e.g. resellable, damaged', 'required' => false],
            'original_transaction_ref' => ['label' => 'Original Receipt', 'description' => 'Receipt / transaction number of the original sale', 'required' => false],
        ];
    }

    /** W11 — waste and write-offs: one row per SKU × store × day × reason. */
    private static function waste(): array
    {
        return [
            'date'      => ['label' => 'Date', 'description' => 'Day the stock was thrown away or written off', 'required' => true],
            'sku'       => ['label' => 'SKU', 'description' => 'Product SKU, item code, or product ID', 'required' => true],
            'quantity'  => ['label' => 'Quantity', 'description' => 'Units wasted or written off', 'required' => true],
            'location'  => ['label' => 'Store', 'description' => 'Store the waste happened at (name or code)', 'required' => false],
            'value'     => ['label' => 'Value', 'description' => 'Value written off, at cost (else quantity × unit cost)', 'required' => false],
            'reason'    => ['label' => 'Reason', 'description' => 'Why: expired, damaged, spoiled, recalled, …', 'required' => false],
            'waste_ref' => ['label' => 'Document', 'description' => 'Write-off document or reference number', 'required' => false],
        ];
    }

    /** W10 — the promotion calendar: one row per promotion × SKU (× store). */
    private static function promotions(): array
    {
        return [
            'promotion_ref'  => ['label' => 'Promotion ID', 'description' => 'Promotion, campaign or deal identifier', 'required' => true],
            'sku'            => ['label' => 'SKU', 'description' => 'Product SKU, item code, or product ID on promotion', 'required' => true],
            'start_date'     => ['label' => 'Start Date', 'description' => 'First day of the promotion', 'required' => true],
            'end_date'       => ['label' => 'End Date', 'description' => 'Last day of the promotion', 'required' => true],
            'location'       => ['label' => 'Store', 'description' => 'Store the promotion runs in (blank = every store)', 'required' => false],
            'promotion_name' => ['label' => 'Promotion Name', 'description' => 'Name or description of the promotion', 'required' => false],
            'mechanic'       => ['label' => 'Mechanic', 'description' => 'Type of deal, e.g. price cut, multibuy, bundle, gondola end', 'required' => false],
            'discount_pct'   => ['label' => 'Discount %', 'description' => 'Discount off the regular price, e.g. 20 or 20%', 'required' => false],
            'promo_price'    => ['label' => 'Promo Price', 'description' => 'Promotional selling price per unit', 'required' => false],
        ];
    }

    private static function purchaseOrders(): array
    {
        return [
            'po_number'     => ['label' => 'PO Number',     'description' => 'Purchase order number or reference',                'required' => true],
            'supplier'      => ['label' => 'Supplier',      'description' => 'Supplier or vendor name',                           'required' => true],
            'sku'           => ['label' => 'SKU',           'description' => 'Product SKU, item code, or product ID',             'required' => true],
            'product_name'  => ['label' => 'Product Name',  'description' => 'Name or description of the product',                'required' => false],
            'qty_ordered'   => ['label' => 'Qty Ordered',   'description' => 'Number of units ordered',                          'required' => true],
            'qty_received'  => ['label' => 'Qty Received',  'description' => 'Number of units received so far',                  'required' => false],
            'unit_cost'     => ['label' => 'Unit Cost',     'description' => 'Cost per unit on this order',                      'required' => false],
            'order_date'    => ['label' => 'Order Date',    'description' => 'Date the purchase order was placed',               'required' => true],
            'expected_date' => ['label' => 'Expected Date', 'description' => 'Expected or promised delivery date',               'required' => false],
            'received_date' => ['label' => 'Received Date', 'description' => 'Actual date goods were received',                  'required' => false],
            'location'      => ['label' => 'Store',         'description' => 'Destination store/location for the order (matches the Store name/code)', 'required' => false],
            'open_qty'      => ['label' => 'Open Qty',      'description' => 'Outstanding quantity not yet received',            'required' => false],
            'late_days'     => ['label' => 'Late Days',     'description' => 'Days late vs the expected date',                   'required' => false],
            'fill_rate'     => ['label' => 'Fill Rate',     'description' => 'Fill rate for the order (received ÷ ordered), as a percentage', 'required' => false],
            // WP3.4 — hardening fields.
            'currency' => ['label' => 'Currency', 'description' => '3-letter currency code of the order', 'required' => false],
            'status' => ['label' => 'Status', 'description' => 'Order / line status, e.g. open, received, cancelled', 'required' => false],
            'buyer' => ['label' => 'Buyer', 'description' => 'Buyer or planner who placed the order', 'required' => false],
        ];
    }
}
