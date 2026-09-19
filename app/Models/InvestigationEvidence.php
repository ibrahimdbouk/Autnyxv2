<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationEvidence extends Model
{
    // Evidence type constants
    const TYPE_DATA_POINT        = 'data_point';
    const TYPE_STAT              = 'stat';
    const TYPE_SNAPSHOT          = 'snapshot';
    const TYPE_IMPORT_RUN        = 'import_run';
    const TYPE_CALCULATION       = 'calculation';
    const TYPE_THRESHOLD_BREACH  = 'threshold_breach';

    // Direction constants
    const DIRECTION_SUPPORTS     = 'supports';
    const DIRECTION_CONTRADICTS  = 'contradicts';
    const DIRECTION_NEUTRAL      = 'neutral';

    // Strength constants
    const STRENGTH_STRONG        = 'strong';
    const STRENGTH_MODERATE      = 'moderate';
    const STRENGTH_WEAK          = 'weak';

    protected $table = 'investigation_evidence';

    protected $fillable = [
        'investigation_id',
        'anomaly_id',
        'evidence_type',
        'source',
        'label',
        'value_numeric',
        'value_text',
        'value_json',
        'unit',
        'direction',
        'strength',
        'observed_at',
    ];

    protected $casts = [
        'value_numeric' => 'float',
        'value_json'    => 'array',
        'observed_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function anomaly(): BelongsTo
    {
        return $this->belongsTo(Anomaly::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getDirectionColor(): string
    {
        return match ($this->direction) {
            self::DIRECTION_SUPPORTS    => 'danger',   // confirms something is wrong
            self::DIRECTION_CONTRADICTS => 'success',  // suggests the signal may be a false positive
            default                     => 'gray',
        };
    }

    public function getFormattedValue(): string
    {
        if ($this->value_numeric !== null) {
            $n = (float) $this->value_numeric;
            // Whole numbers render without decimals ("45 units", not "45.00 units");
            // fractional values keep up to 2 dp with trailing zeros trimmed.
            $v = (floor($n) === $n)
                ? number_format($n, 0)
                : rtrim(rtrim(number_format($n, 2), '0'), '.');
            return $this->unit ? "{$v} {$this->unit}" : $v;
        }
        if ($this->value_text !== null && $this->value_text !== '') {
            return $this->value_text;
        }
        return '—';
    }
}
