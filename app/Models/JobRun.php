<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single scheduled-task run, recorded for the /ops Platform Health view.
 */
class JobRun extends Model
{
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'command',
        'status',
        'duration_ms',
        'message',
        'ran_at',
    ];

    protected $casts = [
        'ran_at'      => 'datetime',
        'duration_ms' => 'integer',
    ];

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
