<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v2.1 — the weekly assortment briefing for a tenant. The rules choose and
 * rank what is in it (content, complete on its own); AI, when on, only writes
 * the headline and summary from those facts (narrative), and a narrative that
 * names a number not in the facts is dropped.
 */
class AssortmentBriefing extends Model
{
    protected $fillable = ['tenant_id', 'week_of', 'content', 'narrative', 'narrated_model', 'sent_at'];

    protected $casts = [
        'week_of'   => 'date',
        'content'   => 'array',
        'narrative' => 'array',
        'sent_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function headline(): string
    {
        return (string) (($this->narrative['headline'] ?? null) ?: ($this->content['headline'] ?? 'Assortment briefing'));
    }

    public function summary(): ?string
    {
        return ($this->narrative['summary'] ?? null) ?: ($this->content['summary'] ?? null);
    }

    public function narrated(): bool
    {
        return ! empty($this->narrative['headline']);
    }
}
