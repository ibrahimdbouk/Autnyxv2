<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Teams\TeamsUserResolver;
use Illuminate\Console\Command;

/**
 * Backfill users' teams_aad_user_id from their email via Microsoft Graph, so the
 * Teams activity-feed channel can reach them without manual mapping. Only touches
 * tenants that have an active Teams connection (the resolver is dormant otherwise).
 */
class ResyncTeamsUsersCommand extends Command
{
    protected $signature = 'teams:resync-users
        {--tenant= : Only this tenant ID}
        {--force : Also re-resolve users that already have a mapping}';

    protected $description = 'Resolve users\' Microsoft Teams (Azure AD) ids from their email addresses';

    public function handle(TeamsUserResolver $resolver): int
    {
        $force   = (bool) $this->option('force');
        $tenants = $this->option('tenant')
            ? [Tenant::findOrFail((int) $this->option('tenant'))]
            : Tenant::all()->all();

        $totUpdated = 0;
        $totFailed  = 0;

        foreach ($tenants as $tenant) {
            $r = $resolver->resyncTenant($tenant->id, $force);
            $totUpdated += $r['updated'];
            $totFailed  += $r['failed'];

            if ($r['updated'] || $r['failed']) {
                $this->line("Tenant {$tenant->id}: {$r['updated']} mapped, {$r['skipped']} skipped, {$r['failed']} failed.");
            }
        }

        $this->info("Done. {$totUpdated} user(s) mapped" . ($totFailed ? ", {$totFailed} failed — check logs." : '.'));

        return $totFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
