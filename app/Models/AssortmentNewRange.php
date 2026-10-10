<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** v2.3 — a starting range for a new store or a remodel (a proposal; nothing is changed). */
class AssortmentNewRange extends Model
{
    public const NEW = 'new';
    public const REMODEL = 'remodel';

    protected $fillable = ['tenant_id', 'name', 'kind', 'store_id', 'format', 'region', 'donors', 'metres', 'result', 'as_of_date', 'created_by'];

    protected $casts = [
        'donors'     => 'array',
        'metres'     => 'array',
        'result'     => 'array',
        'as_of_date' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
