<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assortment: one typed range decision for a store × SKU. Status 'shadow'
 * means computed and stored but not shown to users (validation gate).
 */
class AssortmentGap extends Model
{
    public const TYPE_ADD             = 'add';
    public const TYPE_DELIST          = 'delist';
    public const TYPE_STOCKOUT_HIDDEN = 'stockout_hidden';

    public const TYPES = [
        self::TYPE_ADD             => 'Add',
        self::TYPE_DELIST          => 'Delist',
        self::TYPE_STOCKOUT_HIDDEN => 'Stockout-hidden',
    ];

    public const STATUS_SHADOW   = 'shadow';
    public const STATUS_OPEN     = 'open';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const TIER_ESTABLISHED = 'established';
    public const TIER_LIKELY      = 'likely';
    public const TIER_SPECULATIVE = 'speculative';

    protected $fillable = [
        'tenant_id', 'store_id', 'sku', 'product_id', 'type', 'status', 'peer_group',
        'value_low', 'value_mid', 'value_high', 'confidence', 'confidence_tier',
        'evidence', 'explanation', 'as_of_date', 'first_detected_at', 'last_detected_at',
    ];

    protected $casts = [
        'value_low'         => 'float',
        'value_mid'         => 'float',
        'value_high'        => 'float',
        'confidence'        => 'float',
        'evidence'          => 'array',
        'explanation'       => 'array',
        'as_of_date'        => 'date',
        'first_detected_at' => 'datetime',
        'last_detected_at'  => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
