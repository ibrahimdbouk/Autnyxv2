<?php

namespace App\Models;

use App\Models\Concerns\HasHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P1.1 — a node in the canonical Promotion hierarchy (campaign → promotion →
 * offer), effective-dated by its active window. Part of Platform\Data. Tree
 * walking is shared via {@see HasHierarchy}; the distinguishing capability is
 * effective-dated filtering ("active on date X").
 */
class PromotionNode extends Model
{
    use HasHierarchy;

    public const TYPE_CAMPAIGN  = 'campaign';
    public const TYPE_PROMOTION = 'promotion';
    public const TYPE_OFFER     = 'offer';

    protected $fillable = [
        'tenant_id',
        'type',
        'code',
        'name',
        'parent_id',
        'mechanic',
        'starts_at',
        'ends_at',
        'attributes',
    ];

    protected $casts = [
        'starts_at'  => 'date',
        'ends_at'    => 'date',
        'attributes' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Nodes whose effective window contains the given date (default: today). A
     * null starts_at means "always started"; a null ends_at means "open-ended".
     */
    public function scopeActiveOn(Builder $query, Carbon|string|null $date = null): Builder
    {
        $on = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $on))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $on));
    }

    /** Whether this promotion node is active on the given date (default: today). */
    public function isActiveOn(Carbon|string|null $date = null): bool
    {
        $on    = $date ? Carbon::parse($date) : now();
        $start = $this->starts_at;
        $end   = $this->ends_at;

        return ($start === null || $start->lte($on)) && ($end === null || $end->gte($on));
    }
}
