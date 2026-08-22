<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\User;
use Filament\Notifications\Actions\Action;
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
        bool $alsoEmail = true
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
