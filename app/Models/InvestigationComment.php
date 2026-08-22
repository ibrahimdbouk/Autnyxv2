<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * InvestigationComment — Feature 10
 */
class InvestigationComment extends Model
{
    use SoftDeletes;

    const SOURCE_WEB   = 'web';
    const SOURCE_EMAIL = 'email';

    protected $fillable = [
        'tenant_id',
        'investigation_id',
        'user_id',
        'parent_id',
        'body',
        'source',
        'external_ref',
        'edited_at',
        'deleted_by',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(InvestigationComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(InvestigationComment::class, 'parent_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class, 'comment_id');
    }

    public function getAuthorLabel(): string
    {
        return $this->user?->name ?? 'Unknown';
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isFromEmail(): bool
    {
        return $this->source === self::SOURCE_EMAIL;
    }
}
