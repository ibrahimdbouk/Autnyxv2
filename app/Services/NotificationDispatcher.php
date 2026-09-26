<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * NotificationDispatcher — M23
 *
 * Single entry point for notifications. Always writes an in-app (Filament
 * database) notification, and — once a real mail transport is configured
 * (MAIL_MAILER other than "log") — also sends the email counterpart.
 * Callers do not change. Best-effort throughout: neither an in-app nor an
 * email failure ever breaks the originating flow, and one recipient's failed
 * email never blocks the others.
 */
class NotificationDispatcher
{
    /**
     * Send a notification to a set of users (deduplicated): in-app always,
     * plus email when a mail transport is configured and $alsoEmail is true.
     *
     * @param  array<int>  $userIds
     */
    public static function toUsers(
        array $userIds,
        string $title,
        ?string $body = null,
        ?string $url = null,
        string $icon = 'heroicon-o-bell',
        string $color = 'primary',
        bool $alsoEmail = true,
        bool $personal = true,
    ): void {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return;
        }

        $users = null;

        try {
            $users = User::whereIn('id', $userIds)->get();
            if ($users->isEmpty()) {
                return;
            }

            $notification = Notification::make()
                ->title($title)
                ->icon($icon)
                ->iconColor($color);

            if ($body) {
                $notification->body($body);
            }

            if ($url) {
                $notification->actions([
                    Action::make('view')
                        ->label('Open')
                        ->url($url)
                        ->markAsRead(),
                ]);
            }

            foreach ($users as $user) {
                $notification->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            Log::error('[NotificationDispatcher] in-app: ' . $e->getMessage());
        }

        // Email counterpart — dormant until a real transport is set, so nothing
        // is sent (or logged as a fake "log" email) before Resend is configured.
        if ($alsoEmail && $users !== null && config('mail.default') !== 'log') {
            self::emailUsers($users, $title, $body, $url);
        }

        // Teams counterpart — dormant until a tenant configures a connection
        // (TEAMS_ENABLED + an active teams_connections row). Best-effort.
        if ($alsoEmail && $users !== null && config('services.teams.enabled')) {
            self::teamsUsers($users, $title, $body, $url, $personal);
        }
    }

    /**
     * Send the Teams counterpart. Users are grouped by tenant so each tenant's
     * own connection (channel + per-user activity feed) handles its recipients.
     * The dormant guard + all delivery errors are handled inside TeamsNotifier.
     *
     * @param  \Illuminate\Support\Collection<int,User>  $users
     */
    private static function teamsUsers($users, string $title, ?string $body, ?string $url, bool $personal = true): void
    {
        try {
            // WP5.4: queued after commit, de-duplicated, personal notices never to a channel.
            foreach ($users->groupBy('tenant_id') as $tenantId => $group) {
                if (! $tenantId) {
                    continue;
                }
                \App\Jobs\Notifications\SendTeamsNotificationJob::dispatch(
                    (int) $tenantId, $group->pluck('id')->all(), $title, $body, $url, $personal
                )->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::error('[NotificationDispatcher] teams: ' . $e->getMessage());
        }
    }

    /**
     * Send the email counterpart to each user that has an address. Per-user
     * try/catch so one bad address never blocks the rest.
     *
     * @param  \Illuminate\Support\Collection<int,User>  $users
     */
    private static function emailUsers($users, string $title, ?string $body, ?string $url): void
    {
        foreach ($users as $user) {
            if (empty($user->email)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(
                    new NotificationMail($title, $body, $url, $user->name ?? null)
                );
            } catch (\Throwable $e) {
                Log::error('[NotificationDispatcher] email to ' . $user->email . ': ' . $e->getMessage());
            }
        }
    }
}
