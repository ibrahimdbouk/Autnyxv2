<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a messy inbound value to its canonical form (SKU / store / supplier). See
 * claude/data-quality-firewall.md.
 */
class EntityAlias extends Model
{
    public const TYPE_SKU      = 'sku';
    public const TYPE_STORE    = 'store';
    public const TYPE_SUPPLIER = 'supplier';

    protected $fillable = ['tenant_id', 'entity_type', 'alias', 'canonical'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
