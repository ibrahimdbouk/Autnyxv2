<?php

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * NotificationDispatcher — M23
 *
 * Single entry point for in-app (Filament database) notifications. Email can be
 * layered on later once MAIL_* is configured; callers do not change.
 * Best-effort: notification failures never break the originating flow.
 */
class NotificationDispatcher
{
    /**
     * Send an in-app notification to a set of users (deduplicated).
     *
     * @param  array<int>  $userIds
     */
    public static function toUsers(
        array $userIds,
        string $title,
        ?string $body = null,
        ?string $url = null,
        string $icon = 'heroicon-o-bell',
        string $color = 'primary'
    ): void {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return;
        }

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
            Log::error('[NotificationDispatcher] ' . $e->getMessage());
        }
    }
}
