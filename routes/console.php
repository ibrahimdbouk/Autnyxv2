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

// Agent #3 — Data-Quality / readiness: every Monday at 06:30.
Schedule::command('agents:data-quality')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping();

// Agent #6 — Action Follow-Up: daily at 06:00.
Schedule::command('agents:action-followup')
    ->dailyAt('06:00')
    ->withoutOverlapping();
