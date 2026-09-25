<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Integrations\ApiPollService;
use Illuminate\Console\Command;

/**
 * Pull data from every active API connection (all tenants, or one) through the
 * standard import pipeline. The API sibling of sftp:poll; scheduled hourly.
 */
class PollApiConnectionsCommand extends Command
{
    protected $signature = 'api:poll {--tenant= : Only this tenant ID}';

    protected $description = 'Pull data from configured source-system APIs into the import pipeline';

    public function handle(ApiPollService $poller): int
    {
        $tenants = $this->option('tenant')
            ? [Tenant::findOrFail((int) $this->option('tenant'))]
            : Tenant::where('status', Tenant::STATUS_ACTIVE)->get()->all();   // WP6.7: never feed a closed tenant

        $total = 0;
        foreach ($tenants as $tenant) {
            $total += $poller->pollTenant($tenant->id);
        }

        $this->info("Done. {$total} feed(s) ingested.");

        return Command::SUCCESS;
    }
}
