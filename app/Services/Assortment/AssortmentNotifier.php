<?php

namespace App\Services\Assortment;

use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * v1 gap 1 — Assortment tells people when there is something to do, through the
 * platform's one notification path (in-app always; e-mail and Teams when the
 * tenant has them set up):
 *
 *   new decisions   after a run, to head office people who can see range
 *                   decisions — only once the tenant is live, one message a run
 *   task assigned   to the person an accepted decision was given to
 *   due soon/overdue once each, to the task's owner (assortment:remind, nightly)
 *   result measured to the person who decided and the task's owner
 *
 * Best-effort: a failed notice is logged and never stops the run or the action.
 */
class AssortmentNotifier
{
    public const DUE_SOON_DAYS = 2;

    public function newDecisions(Tenant $tenant, int $count, float $value): void
    {
        if ($count <= 0) {
            return;
        }
        $this->send(
            $this->headOffice($tenant),
            $count === 1 ? '1 new range decision' : "{$count} new range decisions",
            'Worth about ' . Money::compact($value, $tenant->currencyCode()) . ' a year in total. Each one shows why, the stores behind it and the value as a range.',
            $this->listUrl($tenant),
            'heroicon-o-squares-2x2',
        );
    }

    public function taskAssigned(AssortmentGap $gap): void
    {
        if (! $gap->assignee_id) {
            return;
        }
        $this->send([(int) $gap->assignee_id], 'Range task for you: ' . $gap->headline(),
            'Due ' . ($gap->due_at?->format('j M') ?? 'soon') . '. Mark it done when the change is made in the store; the result is measured 8 weeks later.',
            $this->decisionUrl($gap), 'heroicon-o-clipboard-document-check', personal: true);
    }

    /** @return array{due_soon:int, overdue:int} */
    public function remind(int $tenantId): array
    {
        $out = ['due_soon' => 0, 'overdue' => 0];
        $open = AssortmentGap::query()->where('tenant_id', $tenantId)
            ->where('status', AssortmentGap::STATUS_ACCEPTED)->where('task_status', AssortmentGap::TASK_TO_DO)
            ->whereNotNull('assignee_id')->whereNotNull('due_at');

        foreach ((clone $open)->whereNull('overdue_notified_at')->where('due_at', '<', now())->limit(500)->get() as $gap) {
            $this->send([(int) $gap->assignee_id], 'Overdue range task: ' . $gap->headline(),
                'It was due ' . $gap->due_at->format('j M') . '. Mark it done, or cancel it with a reason if it will not happen.',
                $this->decisionUrl($gap), 'heroicon-o-exclamation-triangle', 'danger', personal: true);
            $gap->forceFill(['overdue_notified_at' => now(), 'reminded_at' => $gap->reminded_at ?? now()])->save();
            $out['overdue']++;
        }
        foreach ((clone $open)->whereNull('reminded_at')->whereBetween('due_at', [now(), now()->addDays(self::DUE_SOON_DAYS)->endOfDay()])->limit(500)->get() as $gap) {
            $this->send([(int) $gap->assignee_id], 'Range task due ' . $gap->due_at->format('j M') . ': ' . $gap->headline(),
                'Mark it done when the change is made in the store.', $this->decisionUrl($gap), 'heroicon-o-clock', 'warning', personal: true);
            $gap->forceFill(['reminded_at' => now()])->save();
            $out['due_soon']++;
        }

        return $out;
    }

    public function measured(AssortmentGap $gap): void
    {
        $m = $gap->measurement ?? [];
        $tenant = $gap->tenant;
        $uplift = (float) ($m['uplift_per_year'] ?? 0);
        $what = ($m['metric'] ?? '') === 'category_sales' ? 'category sales' : 'product sales';
        $body = 'Measured: ' . ($uplift >= 0 ? '+' : '−') . Money::compact(abs($uplift), $tenant?->currencyCode()) . " a year in {$what} against "
            . (int) ($m['control_stores'] ?? 0) . ' similar stores that did not change'
            . (($m['strength'] ?? '') === 'weak' ? ' (few comparison stores — indicative).' : '.');
        $this->send(array_values(array_filter([(int) $gap->decided_by, (int) $gap->assignee_id])),
            'Result measured: ' . $gap->headline(), $body, $this->decisionUrl($gap), 'heroicon-o-chart-bar');
    }

    /**
     * Head office people who can see range decisions: tenant admins, and active
     * users with no store-level role who are allowed the decisions screen.
     *
     * @return array<int,int>
     */
    public function headOffice(Tenant $tenant): array
    {
        return User::query()->where('tenant_id', $tenant->id)->whereNull('deactivated_at')
            ->where(fn ($q) => $q->whereNull('org_role')->orWhereIn('org_role', [User::ORG_HQ]))
            ->get(['id', 'is_super_admin', 'is_tenant_admin', 'visible_screens'])
            ->filter(fn (User $u) => $u->canSeeScreen('assortment_decisions'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function send(array $userIds, string $title, string $body, ?string $url, string $icon, string $color = 'primary', bool $personal = false): void
    {
        try {
            NotificationDispatcher::toUsers($userIds, $title, $body, $url, $icon, $color, alsoEmail: true, personal: $personal);
        } catch (\Throwable $e) {
            Log::warning('[AssortmentNotifier] ' . $e->getMessage());
        }
    }

    private function decisionUrl(AssortmentGap $gap): ?string
    {
        try {
            return \App\Filament\Pages\AssortmentDecision::getUrl(['decision' => $gap->id, 'tenant' => $gap->tenant], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }

    private function listUrl(Tenant $tenant): ?string
    {
        try {
            return \App\Filament\Resources\AssortmentDecisionResource::getUrl('index', ['tenant' => $tenant], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }
}
