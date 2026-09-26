<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The nightly analytics chain, queue:tidy-tail and the morning AI agents (daily
// briefing, action follow-up; weekly briefing and data-quality on Mondays) are
// started per tenant, in the tenant's timezone, by nightly:dispatch — see
// AppServiceProvider and App\Services\Pipeline\NightlyChain (WP5.2). Tidy-tail
// runs inside the chain after detection; the agents start once the chain is done.

// Pull data from configured source-system APIs (SAP, Dynamics, Shopify, …) into
// the import pipeline — the API sibling of sftp:poll. Only tenants with an
// active api_connection do any work.
Schedule::command('api:poll')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(55);

// W13: webhook deliveries retry on the queue; this re-queues any whose retry
// job was lost (worker restart, deploy).
Schedule::command('webhooks:findings --retry')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(14);

// WP1.2 (audit C3) — retire uploads left awaiting review so they can never hold
// detection back indefinitely (detection only waits for them for 24h anyway).
Schedule::command('imports:expire-abandoned')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(55);
