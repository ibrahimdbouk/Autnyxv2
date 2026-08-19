<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommentMention — Feature 10
 */
class CommentMention extends Model
{
    protected $fillable = [
        'comment_id',
        'tenant_id',
        'investigation_id',
        'mentioned_user_id',
        'mentioned_team_id',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(InvestigationComment::class, 'comment_id');
    }

    public function mentionedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentioned_user_id');
    }

    public function mentionedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'mentioned_team_id');
    }
}
