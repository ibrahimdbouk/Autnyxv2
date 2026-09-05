<?php

namespace Tests\Feature;

use App\Models\CalendarNode;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * P1.1 — the canonical Calendar dimension: the generator builds a correct
 * year→quarter→month→day tree, it is idempotent, and a node rolls up to the
 * dates it covers.
 */
class CalendarDimensionTest extends TestCase
{
    public function test_build_generates_a_correct_tree_and_rolls_up_dates(): void
    {
        $tenant = $this->createTenant();

        // One clean month: March 2026 (31 days).
        Artisan::call('calendar:build', [
            '--tenant' => $tenant->id,
            '--from'   => '2026-03-01',
            '--to'     => '2026-03-31',
        ]);

        $this->assertSame(1, CalendarNode::where('tenant_id', $tenant->id)->where('type', 'year')->count());
        $this->assertSame(1, CalendarNode::where('tenant_id', $tenant->id)->where('type', 'quarter')->count());
        $this->assertSame(1, CalendarNode::where('tenant_id', $tenant->id)->where('type', 'month')->count());
        $this->assertSame(31, CalendarNode::where('tenant_id', $tenant->id)->where('type', 'day')->count());

        $month = CalendarNode::where('tenant_id', $tenant->id)->where('type', 'month')->where('code', '2026-03')->firstOrFail();

        // Month rolls up to its 31 dates, and its span is the whole month.
        $this->assertCount(31, $month->dates());
        $this->assertSame(['2026-03-01', '2026-03-31'], $month->dateRange());

        // Drill up: a day's ancestry is month → quarter → year.
        $day = CalendarNode::where('tenant_id', $tenant->id)->where('type', 'day')->where('code', '2026-03-15')->firstOrFail();
        $this->assertSame(['month', 'quarter', 'year'], $day->ancestors()->pluck('type')->all());
    }

    public function test_build_is_idempotent(): void
    {
        $tenant = $this->createTenant();

        $args = ['--tenant' => $tenant->id, '--from' => '2026-03-01', '--to' => '2026-03-31'];
        Artisan::call('calendar:build', $args);
        Artisan::call('calendar:build', $args); // second run must not duplicate

        $this->assertSame(31, CalendarNode::where('tenant_id', $tenant->id)->where('type', 'day')->count());
    }
}
