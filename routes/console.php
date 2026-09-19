<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── AI agent schedules ───────────────────────────────────────────────────────
// These fire only when the environment's scheduler (schedule:run each minute) is
// enabled; the agents are also runnable on demand from their pages / by command.

// Agent #2 — Weekly Briefing: every Monday at 07:00.
Schedule::command('agents:weekly-briefing')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping();
