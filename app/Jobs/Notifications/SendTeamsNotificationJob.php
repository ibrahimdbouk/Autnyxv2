<?php

namespace App\Jobs\Notifications;

use App\Models\User;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * WP5.4 (audit M16) — the Teams counterpart of a notification, sent from the
 * queue after the originating transaction commits (a rolled-back action never
 * pings anyone). A personal notice (assigned to you, you were mentioned, your
 * bulk job finished) goes only to the person's activity feed — never to the
 * team channel. The same event to the same people within 10 minutes is sent once.
 */
class SendTeamsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /** @param array<int> $userIds */
    public function __construct(
        public int $tenantId,
        public array $userIds,
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public bool $personal = true,
    ) {
    }

    public function handle(TeamsNotifier $notifier): void
    {
        sort($this->userIds);
        $key = 'teams-event:' . sha1($this->tenantId . '|' . $this->title . '|' . $this->url . '|' . implode(',', $this->userIds) . '|' . (int) $this->personal);
        if (! Cache::store(config('pipeline.lock_store', 'database'))->add($key, 1, now()->addMinutes(10))) {
            return; // already sent
        }

        $users = User::where('tenant_id', $this->tenantId)->whereIn('id', $this->userIds)->get();
        $notifier->notify($this->tenantId, $users, $this->title, $this->body, $this->url, [], toChannel: ! $this->personal);
    }
}
