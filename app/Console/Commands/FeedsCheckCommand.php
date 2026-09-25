<?php

namespace App\Console\Commands;

use App\Services\DataQuality\FeedMonitor;
use Illuminate\Console\Command;

/**
 * W9 (WP9.2) — hourly: automated feeds (and feeds with an explicit freshness
 * SLA) whose next batch is overdue. Alerts once per late spell; the next
 * batch resolves it.
 */
class FeedsCheckCommand extends Command
{
    protected $signature = 'feeds:check {--tenant= : Specific tenant ID}';

    protected $description = 'Flag data feeds whose next batch is overdue';

    public function handle(FeedMonitor $feeds): int
    {
        $late = $feeds->checkLate($this->option('tenant') ? (int) $this->option('tenant') : null);
        foreach ($late as $c) {
            $this->warn("Late: tenant {$c->tenant_id} {$c->feed_key} — {$c->status_detail}");
        }
        $this->info(count($late) . ' feed(s) newly late.');

        return self::SUCCESS;
    }
}
