<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assortment v1.5 Phase 5 — a versioned calibration of the Assortment rules,
 * proposed from measured results and applied only once an admin approves it.
 */
class AssortmentCalibration extends Model
{
    public const STATUS_PROPOSED   = 'proposed';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_RETIRED    = 'retired';

    public const STATUSES = [
        self::STATUS_PROPOSED   => 'Proposed',
        self::STATUS_APPROVED   => 'Active',
        self::STATUS_REJECTED   => 'Rejected',
        self::STATUS_SUPERSEDED => 'Replaced',
        self::STATUS_RETIRED    => 'Retired',
    ];

    protected $fillable = ['tenant_id', 'version', 'status', 'adjustments', 'previous', 'reasons', 'basis', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = [
        'adjustments' => 'array',
        'previous'    => 'array',
        'reasons'     => 'array',
        'basis'       => 'array',
        'decided_at'  => 'datetime',
    ];

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function label(): string
    {
        return 'cal-' . $this->version;
    }
}
