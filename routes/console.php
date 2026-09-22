<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── AI agent schedules ───────────────────────────────────────────────────────
// These fire only when the environment's scheduler (schedule:run each minute) is
// enabled; every agent is also runnable on demand from its page / by command.

// Agent #2 — Weekly Briefing: every Monday at 07:00.
Schedule::command('agents:weekly-briefing')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping();

// Daily Briefing: every morning at 06:15 (after detection/agents settle, before
// the working day). Runnable on demand from the Daily Briefing page too.
Schedule::command('agents:daily-briefing')
    ->dailyAt('06:15')
    ->withoutOverlapping();

// Agent #3 — Data-Quality / readiness: every Monday at 06:30.
Schedule::command('agents:data-quality')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping();

// Agent #6 — Action Follow-Up: daily at 06:00.
Schedule::command('agents:action-followup')
    ->dailyAt('06:00')
    ->withoutOverlapping();

// Auto-tidy the low-value trend tail (conservative: only purely-trend, below the
// materiality floor, open, no actions, not already snoozed) — daily at 05:45,
// before detection/agents run, so the active queue reflects what's worth working.
Schedule::command('queue:tidy-tail')
    ->dailyAt('05:45')
    ->withoutOverlapping();

// Pull data from configured source-system APIs (SAP, Dynamics, Shopify, …) into
// the import pipeline — the API sibling of sftp:poll (which is scheduled hourly in
// AppServiceProvider). Only tenants with an active api_connection do any work.
Schedule::command('api:poll')
    ->hourly()
    ->withoutOverlapping();
