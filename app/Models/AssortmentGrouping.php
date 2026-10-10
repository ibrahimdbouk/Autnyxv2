<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v2.4 — a versioned set of demand-based store groups. Proposed from what the
 * stores sell; used as the Assortment peer groups only once an admin approves
 * it; every decision made under it carries its version in its peer group key
 * (range:v<version>:<n>).
 */
class AssortmentGrouping extends Model
{
    public const PROPOSED = 'proposed';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const SUPERSEDED = 'superseded';
    public const RETIRED = 'retired';

    public const STATUSES = [
        self::PROPOSED   => 'Proposed',
        self::APPROVED   => 'In force',
        self::REJECTED   => 'Rejected',
        self::SUPERSEDED => 'Replaced',
        self::RETIRED    => 'Retired',
    ];

    protected $fillable = ['tenant_id', 'version', 'status', 'method', 'groups', 'assignment', 'comparison', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = [
        'groups'     => 'array',
        'assignment' => 'array',
        'comparison' => 'array',
        'decided_at' => 'datetime',
    ];

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function label(): string
    {
        return 'groups-' . $this->version;
    }
}
