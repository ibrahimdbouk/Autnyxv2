<?php

namespace Tests\Feature;

use App\Platform\Objectives\ObjectiveScorer;
use Tests\TestCase;

/**
 * P2.2 — objective-driven intelligence: an objective re-weights rule types, and
 * the same list re-ranks when the objective changes.
 */
class ObjectiveScoringTest extends TestCase
{
    public function test_objective_reweights_the_same_rule_type(): void
    {
        $scorer = app(ObjectiveScorer::class);

        $availability   = $scorer->score('availability', 'stockout_risk', 1000);
        $workingCapital = $scorer->score('working_capital', 'stockout_risk', 1000);

        $this->assertGreaterThan($workingCapital, $availability, 'a stockout weighs more under availability');
    }

    public function test_ranking_flips_with_the_objective(): void
    {
        $scorer = app(ObjectiveScorer::class);

        $items = [
            ['rule' => 'stockout_risk', 'impact' => 1000],
            ['rule' => 'overstock',     'impact' => 1000],
        ];

        $availabilityTop = $scorer->rank('availability', $items, fn ($i) => $i['rule'], fn ($i) => $i['impact'])
            ->first()['item']['rule'];
        $workingCapitalTop = $scorer->rank('working_capital', $items, fn ($i) => $i['rule'], fn ($i) => $i['impact'])
            ->first()['item']['rule'];

        $this->assertSame('stockout_risk', $availabilityTop, 'availability surfaces the stockout first');
        $this->assertSame('overstock', $workingCapitalTop, 'working-capital surfaces the overstock first');
    }

    public function test_tenant_active_objective_defaults_to_general(): void
    {
        $this->assertSame('general', $this->createTenant()->activeObjective());
    }
}
