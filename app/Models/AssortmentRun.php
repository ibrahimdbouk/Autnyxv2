<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Assortment: one engine run for a tenant, with what it found and why it stopped. */
class AssortmentRun extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = ['tenant_id', 'as_of_date', 'status', 'stats', 'started_at', 'finished_at'];

    protected $casts = [
        'stats'       => 'array',
        'as_of_date'  => 'date',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
