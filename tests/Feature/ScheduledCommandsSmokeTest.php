<?php

namespace Tests\Feature;

use App\Services\Pipeline\NightlyChain;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * WP5.1 (audit M27) — every scheduled command, and every command the nightly
 * chain and the morning agents run, exits 0 on an ordinary tenant; and the
 * schedule itself lists.
 */
class ScheduledCommandsSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => null]);
        Mail::fake();
        Notification::fake();
    }

    public function test_the_schedule_lists(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();
    }

    public function test_every_scheduled_command_runs_cleanly(): void
    {
        Bus::fake();
        $this->createTenant(['status' => 'active']);

        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => preg_match("/artisan['\"]?\\s+(\\S+)/", (string) $e->command, $m) ? $m[1] : null)
            ->filter()->unique()->values();

        $this->assertNotEmpty($commands);
        foreach ($commands as $command) {
            $this->assertSame(0, Artisan::call($command), "{$command}: " . Artisan::output());
        }
    }

    public function test_every_nightly_and_morning_command_runs_cleanly_for_a_tenant(): void
    {
        $tenant = $this->createTenant(['status' => 'active']);
        $commands = array_merge(
            ...array_values((new \ReflectionClassConstant(NightlyChain::class, 'COMMANDS'))->getValue()),
        );
        $commands = array_merge($commands, ['agents:action-followup', 'agents:daily-briefing', 'agents:data-quality', 'agents:weekly-briefing']);

        foreach (array_unique($commands) as $command) {
            $this->assertSame(0, Artisan::call($command, ['--tenant' => $tenant->id]), "{$command}: " . Artisan::output());
        }
    }
}
