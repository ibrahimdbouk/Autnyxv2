<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Models\JobRun;
use App\Services\AI\AnthropicClient;
use App\Services\InvestigationNarratorService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * W13 — the nightly narrate run sends its model calls several at a time; the
 * budget, metering, retries and failure log still apply to each.
 */
class ParallelNarrationTest extends TestCase
{
    private function investigation(int $tenantId, string $sku, float $risk): Investigation
    {
        $inv = Investigation::factory()->create(['tenant_id' => $tenantId, 'status' => Investigation::STATUS_OPEN, 'revenue_at_risk' => $risk,
            'primary_sku' => $sku, 'ai_generated_at' => null]);
        $a = Anomaly::create(['tenant_id' => $tenantId, 'investigation_id' => $inv->id, 'rule_type' => 'demand_erosion', 'severity' => 'medium',
            'sku' => $sku, 'description' => "Demand erosion {$sku}", 'detected_at' => now()->subDay(), 'context' => ['revenue_impact' => $risk]]);
        InvestigationEvidence::create(['investigation_id' => $inv->id, 'anomaly_id' => $a->id, 'evidence_type' => InvestigationEvidence::TYPE_SNAPSHOT,
            'source' => 'inventory_levels', 'label' => 'On hand', 'value_numeric' => 3, 'unit' => 'units',
            'direction' => InvestigationEvidence::DIRECTION_SUPPORTS, 'strength' => InvestigationEvidence::STRENGTH_STRONG, 'observed_at' => now()->subDays(2)]);

        return $inv;
    }

    private function reply(string $headline): array
    {
        return ['content' => [['type' => 'text', 'text' => json_encode(['headline' => $headline, 'summary' => $headline, 'root_cause' => 'x'])]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50], 'stop_reason' => 'end_turn'];
    }

    public function test_the_nightly_run_narrates_every_investigation_through_parallel_calls(): void
    {
        Sleep::fake();
        config(['services.anthropic.key' => 'test-key', 'ai.narrate_concurrency' => 3]);
        $tenant = $this->createTenant();
        $invs = collect(range(1, 7))->map(fn ($i) => $this->investigation($tenant->id, "S{$i}", 1000 * $i));

        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            $prompt = json_decode($request->body(), true)['messages'][0]['content'];
            $sku = preg_match('/\b(S\d+)\b/', $prompt, $m) ? $m[1] : '?';

            return Http::response($this->reply("Narrative for {$sku}"), 200);
        });

        app(InvestigationNarratorService::class)->narrateForTenant($tenant->id);

        $this->assertSame(7, $calls);
        foreach ($invs as $i => $inv) {
            $this->assertSame('Narrative for S' . ($i + 1), $inv->fresh()->ai_headline, 'each reply lands on its own investigation');
        }
        $usage = app(\App\Services\AI\AiBudget::class)->today($tenant->id);
        $this->assertSame([7, 700, 350], [(int) $usage->calls, (int) $usage->input_tokens, (int) $usage->output_tokens], 'every call is metered');
    }

    public function test_a_throttled_call_is_retried_alone_and_a_refused_one_is_logged(): void
    {
        Sleep::fake();
        config(['services.anthropic.key' => 'test-key']);
        $tenant = $this->createTenant();
        $seen = [];
        Http::fake(function (Request $request) use (&$seen) {
            $p = json_decode($request->body(), true)['messages'][0]['content'];
            $seen[$p] = ($seen[$p] ?? 0) + 1;

            return match (true) {
                $p === 'busy' && $seen[$p] === 1 => Http::response(['error' => 'overloaded'], 529),
                $p === 'bad'                     => Http::response(['error' => 'invalid'], 400),
                default                          => Http::response($this->reply($p), 200),
            };
        });

        $r = app(AnthropicClient::class)->messages([
            'a' => ['tenant_id' => $tenant->id, 'feature' => 'narrator', 'prompt' => 'fine'],
            'b' => ['tenant_id' => $tenant->id, 'feature' => 'narrator', 'prompt' => 'busy'],
            'c' => ['tenant_id' => $tenant->id, 'feature' => 'narrator', 'prompt' => 'bad'],
        ], 3);

        $this->assertSame(['a', 'b', 'c'], array_keys($r));
        $this->assertTrue($r['a']->ok);
        $this->assertTrue($r['b']->ok, 'the 529 was retried');
        $this->assertSame(2, $seen['busy']);
        $this->assertFalse($r['c']->ok);
        $this->assertSame('api_400', $r['c']->error);
        $this->assertSame(1, $seen['bad'], 'a refused call is not retried');
        $this->assertTrue(JobRun::where('command', 'ai:narrator')->where('status', JobRun::STATUS_FAILED)->exists());
    }

    public function test_without_a_key_nothing_is_called(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();
        $r = app(AnthropicClient::class)->messages([['tenant_id' => null, 'feature' => 'narrator', 'prompt' => 'x']]);
        $this->assertSame('ai_disabled', $r[0]->error);
        Http::assertNothingSent();
    }
}
