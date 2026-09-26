<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** W13 — one event on a tenant's retail calendar (see App\Services\Calendar\RetailCalendar). */
class RetailEvent extends Model
{
    public const KINDS = [
        'religious'  => 'Religious',
        'seasonal'   => 'Seasonal',
        'commercial' => 'Commercial',
        'national'   => 'National day',
        'custom'     => 'Your own',
    ];

    protected $fillable = [
        'tenant_id', 'key', 'year', 'name', 'kind', 'starts_on', 'ends_on', 'lead_days', 'tail_days',
        'countries', 'categories', 'active', 'source', 'notes',
    ];

    protected $casts = [
        'starts_on'  => 'date',
        'ends_on'    => 'date',
        'countries'  => 'array',
        'categories' => 'array',
        'active'     => 'boolean',
        'year'       => 'integer',
        'lead_days'  => 'integer',
        'tail_days'  => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
