<?php

namespace App\Services\DataQuality;

use App\Models\ImportQuality;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

/**
 * Data-Quality Firewall — a RED batch must never be silent.
 *
 * When a feed is blocked (a materially-broken batch, or a duplicate upload), this
 * raises an in-app notification to the people who can act on it — the affected
 * tenant's admins plus the platform super admins — and emails the owner. It never
 * freezes a feed silently, which is the firewall's stated guardrail.
 *
 * Best-effort by contract: every path is guarded so an alert failure can NEVER
 * break ingestion. Fired once per batch (on the transition into RED), not per chunk.
 */
class ReadinessAlerter
{
    public function redBatch(ImportQuality $q): void
    {
        try {
            $label = ucwords(str_replace('_', ' ', (string) $q->data_type));
            $title = "Data feed blocked — {$label}";
            $body = $q->is_duplicate_file
                ? "A duplicate {$label} upload was detected and skipped — nothing was re-imported. If this was a genuine new file, re-export it from the source and upload again."
                : "The latest {$label} batch was blocked: {$q->rows_promoted} row(s) promoted, {$q->rows_quarantined} quarantined. Detection rules for this dataset are paused until a clean batch arrives or the block is overridden. Review the quarantine queue to clear it.";

            $this->bell($q->tenant_id, $title, $body);
            $this->emailOwner($title, $body);
        } catch (\Throwable) {
            // best-effort — never break ingestion on an alert failure.
        }
    }

    /**
     * W9: a data-trust alert (feed late / short / re-shaped, a critical data
     * finding). Same audience as a RED batch, plus the feed's own owner.
     */
    public function alert(?int $tenantId, string $title, string $body, ?string $feedOwnerEmail = null, string $level = 'danger'): void
    {
        try {
            $this->bell($tenantId, $title, $body, $level);
            $this->emailOwner($title, $body);
            if ($feedOwnerEmail && filter_var($feedOwnerEmail, FILTER_VALIDATE_EMAIL) && $feedOwnerEmail !== config('autnyx.owner_email')) {
                Mail::raw($body, fn ($m) => $m->to($feedOwnerEmail)->subject("[Autnyx] {$title}"));
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** In-app bell for the tenant's own admins + every platform super admin. */
    private function bell(?int $tenantId, string $title, string $body, string $level = 'danger'): void
    {
        $recipients = User::query()
            ->where(function ($w) use ($tenantId): void {
                $w->where('is_super_admin', true);
                if ($tenantId !== null) {
                    $w->orWhere(fn ($t) => $t->where('tenant_id', $tenantId)->where('is_tenant_admin', true));
                }
            })
            ->get();

        foreach ($recipients as $user) {
            try {
                Notification::make()
                    ->title($title)
                    ->body($body)
                    ->status($level === 'warning' ? 'warning' : 'danger')
                    ->sendToDatabase($user);
            } catch (\Throwable) {
                // skip a single failed recipient; keep alerting the rest.
            }
        }
    }

    /** Owner email — best-effort, a no-op if MAIL is not configured. */
    private function emailOwner(string $title, string $body): void
    {
        $ownerEmail = config('autnyx.owner_email');
        if (empty($ownerEmail)) {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($ownerEmail, $title): void {
                $message->to($ownerEmail)->subject("[Autnyx] {$title}");
            });
        } catch (\Throwable) {
            // best-effort.
        }
    }
}
