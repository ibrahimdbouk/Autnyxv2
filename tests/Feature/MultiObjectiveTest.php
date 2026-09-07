<?php

namespace Tests\Feature;

use App\Platform\Objectives\MultiObjectiveScorer;
use App\Platform\Objectives\WeightingRegistry;
use Tests\TestCase;

/**
 * P4.6 — ranking against a weighted blend of objectives, with per-objective
 * contributions and Pareto domination made explicit.
 */
class MultiObjectiveTest extends TestCase
{
    private function scorer(): MultiObjectiveScorer
    {
        return app(MultiObjectiveScorer::class);
    }

    public function test_blended_score_is_the_weighted_sum(): void
    {
        $score = $this->scorer()->score(
            ['availability' => 0.5, 'margin' => 0.3, 'waste' => 0.2],
            ['availability' => 100, 'margin' => 50, 'waste' => 20],
        );

        // 0.5*100 + 0.3*50 + 0.2*20 = 50 + 15 + 4 = 69
        $this->assertSame(69.0, $score->blended);
        $this->assertSame(50.0, $score->contributions['availability']);
        $this->assertSame(4.0, $score->contributions['waste']);
    }

    public function test_weights_are_normalised(): void
    {
        // weights 2:2 → 0.5:0.5 after normalisation
        $score = $this->scorer()->score(['a' => 2, 'b' => 2], ['a' => 10, 'b' => 0]);
        $this->assertSame(5.0, $score->blended);
    }

    public function test_no_weighting_falls_back_to_equal(): void
    {
        $score = $this->scorer()->score([], ['a' => 10, 'b' => 20]);
        $this->assertSame(15.0, $score->blended); // (10 + 20) / 2
    }

    public function test_the_blend_changes_the_winner(): void
    {
        $candidates = [
            'margin_play'      => ['availability' => 10, 'margin' => 100],
            'availability_play' => ['availability' => 100, 'margin' => 10],
        ];

        // Margin-led tenant prefers the margin play.
        $marginFirst = $this->scorer()->rank(['availability' => 0.2, 'margin' => 0.8], $candidates);
        $this->assertSame('margin_play', $marginFirst->first()->key);

        // Availability-led tenant prefers the availability play — same candidates, different blend.
        $availFirst = $this->scorer()->rank(['availability' => 0.8, 'margin' => 0.2], $candidates);
        $this->assertSame('availability_play', $availFirst->first()->key);
    }

    public function test_pareto_domination_is_flagged(): void
    {
        $candidates = [
            'A' => ['availability' => 100, 'margin' => 50],  // dominates B
            'B' => ['availability' => 80, 'margin' => 40],   // dominated by A
            'C' => ['availability' => 120, 'margin' => 10],  // trades margin for availability — not dominated
        ];

        $frontier = $this->scorer()->paretoFrontier($candidates);

        $this->assertEqualsCanonicalizing(['A', 'C'], $frontier);
        $this->assertContains('B', array_diff(['A', 'B', 'C'], $frontier));
    }

    public function test_weighting_registry_is_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $registry = app(WeightingRegistry::class);

        $registry->setWeight($a->id, 'availability', 0.7);
        $registry->setWeight($a->id, 'margin', 0.3);

        $this->assertEqualsCanonicalizing(['availability' => 0.7, 'margin' => 0.3], $registry->weighting($a->id));
        $this->assertSame([], $registry->weighting($b->id));
    }
}
