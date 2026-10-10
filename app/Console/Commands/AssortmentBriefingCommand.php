<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Assortment\AssortmentNotifier;
use App\Services\Assortment\BriefingService;
use Illuminate\Console\Command;

/**
 * v2.1 — build this week's assortment briefing for each live tenant and send
 * it to head office (once a week; built again, it replaces the week's and is
 * not sent twice unless --resend).
 */
class AssortmentBriefingCommand extends Command
{
    protected $signature = 'assortment:briefing {--tenant= : Only this tenant id} {--no-ai : Rules only} {--resend : Send again this week}';

    protected $description = 'Build and send the weekly Assortment briefing (rules decide, AI only narrates).';

    public function handle(BriefingService $svc, AssortmentNotifier $notifier): int
    {
        $tenants = Tenant::query()->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $b = $svc->generate($tenant, ! $this->option('no-ai'));
            if ($b === null) {
                continue;
            }
            if ($b->sent_at === null || $this->option('resend')) {
                $notifier->briefing($b);
            }
            $this->info("#{$tenant->id} {$tenant->name}: " . $b->headline() . ($b->narrated() ? ' (narrated)' : ' (rules)'));
        }

        return self::SUCCESS;
    }
}
