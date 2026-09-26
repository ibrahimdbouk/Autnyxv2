<?php

namespace App\Console\Commands;

use App\Mail\StoreDigestMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Stores\StoreDigestService;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * W12 — each store manager's daily digest (their stores only): e-mail, plus a
 * personal Teams ping with the same store-sheet link when the tenant has Teams
 * connected and the person is mapped. Runs in the nightly chain's notify step,
 * once per night. Nobody linked to a store → nothing to send.
 */
class SendStoreDigestsCommand extends Command
{
    protected $signature = 'digest:stores {--tenant= : Only this tenant} {--dry-run : List who would get what, send nothing}';

    protected $description = 'Send each store manager the digest of their own store(s)';

    public function handle(StoreDigestService $svc): int
    {
        if (! config('autnyx.digest_enabled') && ! $this->option('dry-run')) {
            $this->warn('Digests are disabled (ANOMALY_DIGEST_ENABLED=false) — nothing sent.');

            return self::SUCCESS;
        }

        $failures = 0;
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))->get();

        foreach ($tenants as $tenant) {
            $users = User::where('tenant_id', $tenant->id)->where('store_digest', true)->whereNotNull('email')
                ->whereHas('stores')->get();
            foreach ($users as $user) {
                try {
                    $since = $user->store_digest_sent_at;
                    $d = $svc->forUser($user, $since);
                    if ($d === null) {
                        continue;
                    }
                    $this->line("  {$tenant->name} → {$user->email}: {$d['finding_count']} to check, {$d['count_total']} to count");
                    if ($this->option('dry-run')) {
                        continue;
                    }
                    Mail::to($user->email)->queue(new StoreDigestMail($tenant, $user, $since?->toIso8601String()));
                    $this->teams($tenant, $user, $d, $svc);
                    $user->forceFill(['store_digest_sent_at' => now()])->saveQuietly();
                } catch (\Throwable $e) {
                    report($e);
                    Log::error("[digest:stores] user {$user->id}: {$e->getMessage()}");
                    $failures++;
                }
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** A personal Teams ping with the same signed store-sheet link (dormant unless Teams is connected). */
    private function teams(Tenant $tenant, User $user, array $d, StoreDigestService $svc): void
    {
        if (! $user->teams_aad_user_id) {
            return;
        }
        try {
            app(TeamsNotifier::class)->notify($tenant->id, [$user],
                implode(', ', $d['store_names']) . ': ' . $d['finding_count'] . ' to check, ' . $d['count_total'] . ' to count',
                'Confirm the findings and enter your counts on your store sheet.',
                $svc->sheetUrl($user),
                array_filter(['New since last time' => (string) $d['new_count'], 'Not yet answered' => (string) $d['unanswered']]),
                toChannel: false);
        } catch (\Throwable $e) {
            report($e);   // best-effort: the e-mail already went
        }
    }
}
