<?php

namespace App\Services\Import;

/**
 * Defines the canonical field schemas for each data type.
 * Used by the column mapping service as the target vocabulary.
 */
class CanonicalSchema
{
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
            'location'       => ['label' => 'Location',       'description' => 'Store, outlet, channel, or region name',    'required' => false],
            'quantity'       => ['label' => 'Quantity',       'description' => 'Number of units sold',                      'required' => true],
            'unit_price'     => ['label' => 'Unit Price',     'description' => 'Price per unit sold',                       'required' => false],
            'total_amount'   => ['label' => 'Total Amount',   'description' => 'Total net revenue or sales amount for the row', 'required' => false],
            'transaction_id' => ['label' => 'Transaction ID', 'description' => 'Unique identifier for the transaction',     'required' => false],
            'discount'       => ['label' => 'Discount',       'description' => 'Discount amount applied on the row',       'required' => false],
            'payment_method' => ['label' => 'Payment Method', 'description' => 'Payment type, e.g. cash, card, wallet',    'required' => false],
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
        ];
    }

    private static function users(): array
    {
        return [
            'name'  => ['label' => 'Name',  'description' => 'Full name of the user',                                   'required' => true],
            'email' => ['label' => 'Email', 'description' => 'Login email address (must be unique)',                    'required' => true],
            'role'  => ['label' => 'Role',  'description' => 'admin / tenant_admin grants admin rights; anything else is a standard user', 'required' => false],
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
        ];
    }
}
