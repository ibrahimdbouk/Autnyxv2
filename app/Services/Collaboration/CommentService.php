<?php

namespace App\Services\Collaboration;

use App\Models\AuditLog;
use App\Models\CommentMention;
use App\Models\Investigation;
use App\Models\InvestigationComment;
use App\Models\InvestigationWatch;
use App\Models\Team;
use App\Models\User;
use App\Models\WatchNotification;
use App\Services\AuditLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * CommentService — Feature 10
 *
 * Posts lightweight comments on the canonical investigation, resolves @mentions,
 * records them in the existing timeline (audit log), and dispatches in-app
 * notifications to mentioned users and to watchers who want comment updates.
 */
class CommentService
{
    /**
     * Post a comment.
     *
     * @param  array<int>  $mentionedUserIds  explicitly selected in the UI
     * @param  array<int>  $mentionedTeamIds  explicitly selected in the UI
     */
    public function post(
        Investigation $investigation,
        int $userId,
        string $body,
        array $mentionedUserIds = [],
        array $mentionedTeamIds = [],
        ?int $parentId = null,
        string $source = InvestigationComment::SOURCE_WEB,
        ?string $externalRef = null
    ): InvestigationComment {
        $body = trim($body);

        // De-duplicate inbound emails (same provider message-id).
        if ($externalRef) {
            $existing = InvestigationComment::where('investigation_id', $investigation->id)
                ->where('external_ref', $externalRef)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($investigation, $userId, $body, $mentionedUserIds, $mentionedTeamIds, $parentId, $source, $externalRef) {
            $comment = InvestigationComment::create([
                'tenant_id'        => $investigation->tenant_id,
                'investigation_id' => $investigation->id,
                'user_id'          => $userId,
                'parent_id'        => $parentId,
                'body'             => $body,
                'source'           => $source,
                'external_ref'     => $externalRef,
            ]);

            // Merge explicit mentions with light text parsing
            $userIds = array_values(array_unique(array_merge(
                $mentionedUserIds,
                $this->parseUserMentions($investigation->tenant_id, $body)
            )));
            $teamIds = array_values(array_unique($mentionedTeamIds));

            // Expand team mentions to member user IDs
            $expandedFromTeams = [];
            foreach ($teamIds as $teamId) {
                $team = Team::where('tenant_id', $investigation->tenant_id)->find($teamId);
                if ($team) {
                    $expandedFromTeams = array_merge($expandedFromTeams, $team->members()->pluck('users.id')->all());
                    CommentMention::create([
                        'comment_id'        => $comment->id,
                        'tenant_id'         => $investigation->tenant_id,
                        'investigation_id'  => $investigation->id,
                        'mentioned_team_id' => $teamId,
                    ]);
                }
            }

            $allMentionedUserIds = array_values(array_unique(array_merge($userIds, $expandedFromTeams)));
            foreach ($userIds as $uid) {
                CommentMention::create([
                    'comment_id'        => $comment->id,
                    'tenant_id'         => $investigation->tenant_id,
                    'investigation_id'  => $investigation->id,
                    'mentioned_user_id' => $uid,
                ]);
            }

            // Timeline (audit) — captures the comment text + how it arrived (web/email)
            AuditLogger::commentAdded($investigation, $userId, $body, $source, $comment->id);

            $url = $this->investigationUrl($investigation);
            $authorName = User::find($userId)?->name ?? 'Someone';

            // Notify mentioned users (excluding the author)
            $mentionTargets = array_values(array_diff($allMentionedUserIds, [$userId]));
            if (! empty($mentionTargets)) {
                NotificationDispatcher::toUsers(
                    $mentionTargets,
                    "{$authorName} mentioned you",
                    \Illuminate\Support\Str::limit($body, 120),
                    $url,
                    'heroicon-o-at-symbol'
                );
                CommentMention::where('comment_id', $comment->id)->update(['notified_at' => now()]);
            }

            // Notify watchers who want comment updates (excluding author + already-mentioned)
            $this->notifyWatchers($investigation, $comment, $userId, array_merge($mentionTargets, [$userId]), $url, $authorName);

            return $comment;
        });
    }

    public function edit(InvestigationComment $comment, string $body, int $userId): InvestigationComment
    {
        $comment->update([
            'body'      => trim($body),
            'edited_at' => now(),
        ]);
        return $comment;
    }

    public function delete(InvestigationComment $comment, int $userId): void
    {
        $comment->update(['deleted_by' => $userId]);
        $comment->delete(); // soft delete — history preserved
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function notifyWatchers(
        Investigation $investigation,
        InvestigationComment $comment,
        int $authorId,
        array $exclude,
        string $url,
        string $authorName
    ): void {
        $watches = InvestigationWatch::where('investigation_id', $investigation->id)
            ->where('active', true)
            ->with('team')
            ->get();

        foreach ($watches as $watch) {
            if (! $watch->wantsTrigger(InvestigationWatch::TRIGGER_COMMENT)) {
                continue;
            }

            // Dedup per comment
            $signature = 'comment:' . $comment->id;
            $ledger = WatchNotification::firstOrNew([
                'watch_id'        => $watch->id,
                'event_signature' => $signature,
            ]);
            if ($ledger->exists) {
                continue;
            }

            $recipients = array_values(array_diff($watch->recipientUserIds(), $exclude));
            if (empty($recipients)) {
                continue;
            }

            $ledger->fill([
                'tenant_id'        => $watch->tenant_id,
                'investigation_id' => $investigation->id,
                'event_type'       => InvestigationWatch::TRIGGER_COMMENT,
                'message'          => 'New comment on a watched investigation',
                'sent_at'          => now(),
            ])->save();

            NotificationDispatcher::toUsers(
                $recipients,
                "New comment on a watched investigation",
                $authorName . ': ' . \Illuminate\Support\Str::limit($comment->body, 100),
                $url,
                'heroicon-o-chat-bubble-left-right'
            );
        }
    }

    /**
     * Light @mention parsing: matches @Name tokens against tenant user names.
     * Explicit UI selection is the primary path; this is a convenience fallback.
     *
     * @return array<int>
     */
    private function parseUserMentions(int $tenantId, string $body): array
    {
        if (! preg_match_all('/@([A-Za-z][A-Za-z0-9._\-]{1,50})/', $body, $m)) {
            return [];
        }

        $tokens = array_map('strtolower', $m[1]);
        if (empty($tokens)) {
            return [];
        }

        $ids = [];
        $users = User::where('tenant_id', $tenantId)->get(['id', 'name', 'email']);
        foreach ($users as $user) {
            $nameKey  = strtolower(str_replace(' ', '', $user->name ?? ''));
            $firstKey = strtolower(explode(' ', trim($user->name ?? ''))[0] ?? '');
            $emailKey = strtolower(explode('@', $user->email ?? '')[0] ?? '');
            foreach ($tokens as $t) {
                if ($t !== '' && ($t === $nameKey || $t === $firstKey || $t === $emailKey)) {
                    $ids[] = $user->id;
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function investigationUrl(Investigation $investigation): string
    {
        $slug = $investigation->tenant?->slug ?? optional(\App\Models\Tenant::find($investigation->tenant_id))->slug ?? '';
        return url('/admin/' . $slug . '/investigations/' . $investigation->id . '/investigate');
    }
}
