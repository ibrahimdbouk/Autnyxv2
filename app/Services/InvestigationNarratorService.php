<?php

namespace App\Services;

use App\Services\AI\PromptData;

use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Services\Anomaly\EvidenceCollectorService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;

/**
 * InvestigationNarratorService — M19
 *
 * Replaces the 7-question sequential AI pattern (M8) with:
 *   1. Assemble a full evidence package from investigation_evidence rows.
 *   2. One Claude call with the entire package.
 *   3. Parse the structured JSON response.
 *   4. Persist narrative fields on the Investigation model.
 *
 * AI is a narrator over deterministic evidence — not an interrogator.
 */
class InvestigationNarratorService
{

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Generate (or regenerate) the AI narrative for an investigation.
     * Skips if ai_generated_at is set and evidence hasn't changed since then —
     * unless $force = true.
     */
    public function narrate(Investigation $investigation, bool $force = false): Investigation
    {
        $prompt = $this->prepare($investigation, $force);
        if ($prompt === null) {
            return $investigation;
        }
        // WP5.4: through AnthropicClient — budget, retries, circuit breaker,
        // metering, model from config, data-is-not-instructions system prompt.
        $r = app(\App\Services\AI\AnthropicClient::class)
            ->message((int) $investigation->tenant_id, 'narrator', $prompt, 'fast', 1024, null, 45);

        return $this->apply($investigation, $r);
    }

    /**
     * W13 — the nightly run: prompts are built one by one (database work), the
     * model calls go out several at a time (ai.narrate_concurrency), each reply
     * is stored as it is. Budget, retries, circuit breaker and metering are the
     * client's, as for a single call.
     *
     * @param  iterable<Investigation>  $investigations
     * @return int narratives written
     */
    public function narrateMany(iterable $investigations, bool $force = false): int
    {
        $client = app(\App\Services\AI\AnthropicClient::class);
        $concurrency = max(1, (int) config('ai.narrate_concurrency', 5));
        $written = 0;

        $batch = [];
        $flush = function () use (&$batch, $client, $concurrency, &$written) {
            if ($batch === []) {
                return;
            }
            $results = $client->messages(array_map(fn ($b) => [
                'tenant_id' => (int) $b['inv']->tenant_id, 'feature' => 'narrator', 'prompt' => $b['prompt'],
                'tier' => 'fast', 'max_tokens' => 1024, 'timeout' => 45,
            ], $batch), $concurrency);
            foreach ($batch as $i => $b) {
                $before = $b['inv']->ai_generated_at;
                $after = $this->apply($b['inv'], $results[$i]);
                if ($after->ai_generated_at && (! $before || $after->ai_generated_at->ne($before))) {
                    $written++;
                }
            }
            $batch = [];
        };

        foreach ($investigations as $inv) {
            if (($prompt = $this->prepare($inv, $force)) !== null) {
                $batch[] = ['inv' => $inv, 'prompt' => $prompt];
            }
            if (count($batch) >= $concurrency * 2) {
                $flush();
            }
        }
        $flush();

        return $written;
    }

    /** Evidence, the deterministic cause and the prompt — or null when the narrative is current. */
    private function prepare(Investigation $investigation, bool $force): ?string
    {
        // Ensure evidence is collected before narrating
        if ($investigation->evidence()->count() === 0) {
            app(EvidenceCollectorService::class)->collectForInvestigation($investigation);
        }

        // Skip if already narrated recently and not forced
        if (!$force && $investigation->ai_generated_at) {
            // WP4.5: evidence is refreshed in place, so "changed" means updated.
            $latestEvidence = $investigation->evidence()->latest('updated_at')->value('updated_at');
            if (!$latestEvidence || $investigation->ai_generated_at->gte($latestEvidence)) {
                Log::info("[M19] Investigation #{$investigation->id} narrative is current — skipping.");
                return null;
            }
        }

        // WP4.5 (audit H23): the cause is decided deterministically and handed
        // to the model as a fixed fact; its tier sets the confidence shown.
        $cause  = app(\App\Services\Anomaly\RootCauseAnalysisService::class)->record($investigation);

        return $this->buildPrompt($investigation, $cause);
    }

    /** Store one reply on the investigation. */
    private function apply(Investigation $investigation, \App\Services\AI\AiResult $r): Investigation
    {
        try {
            if (! $r->ok || $r->truncated()) {
                if ($r->ok) {
                    $this->recordFailure("Narrative cut off at max_tokens (investigation #{$investigation->id})");
                }
                return $investigation; // budget / disabled / failure already recorded by the client
            }

            $text = $r->text;
            $data = $this->parseResponse($text);

            if (empty($data)) {
                Log::warning('[M19] Could not parse AI response', ['investigation' => $investigation->id, 'raw' => $text]);
                return $investigation;
            }

            $toList = fn ($v) => is_array($v)
                ? array_values(array_filter(
                    array_map(fn ($x) => trim((string) $x), $v),
                    fn ($x) => $x !== '' && $x !== '—'
                ))
                : [];

            $investigation->update([
                'ai_headline'             => $data['headline']             ?? ($data['summary'] ?? null),
                'ai_summary'              => $data['summary']              ?? ($data['headline'] ?? null),
                'ai_root_cause'           => $data['root_cause']           ?? null,
                'ai_evidence'             => $toList($data['evidence']             ?? null),
                'ai_contributing_factors' => $toList($data['contributing_factors'] ?? null),
                'ai_business_impact'      => $data['business_impact']      ?? null,
                'ai_recommended_action'   => $data['immediate_action']     ?? ($data['recommended_action'] ?? null),
                'ai_long_term_fix'        => $data['long_term_fix']        ?? null,
                // WP1.1 (audit C1): the model's money guess is stored ONLY in the
                // labelled ai_revenue_estimate column. revenue_at_risk is
                // deterministic (DeterministicRevenueAtRisk) and drives snoozing,
                // ordering, KPIs and the API — AI must never write it.
                'ai_revenue_estimate'     => $this->numericOrNull($data['revenue_estimate'] ?? ($data['revenue_at_risk'] ?? null)),
                'ai_generated_at'         => now(),
                'ai_model'                => $r->model,
            ]);

            AuditLogger::aiGenerated($investigation);

            Log::info("[M19] Narrative generated for investigation #{$investigation->id}");

        } catch (\Throwable $e) {
            Log::error('[M19] Narrator exception: ' . $e->getMessage(), [
                'investigation' => $investigation->id,
                'trace'         => $e->getTraceAsString(),
            ]);
            $this->recordFailure('Narrator exception: ' . $e->getMessage());
        }

        return $investigation->fresh();
    }

    private function numericOrNull(mixed $v): ?float
    {
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
        }

        return is_numeric($v) && is_finite((float) $v) && (float) $v >= 0 ? round((float) $v, 2) : null;
    }

    /**
     * Surface an AI-call failure in the ops observability layer (job_runs), so a
     * run of bad Anthropic responses shows up on Platform Health / the health
     * check rather than only in the log. Best-effort.
     */
    private function recordFailure(string $message): void
    {
        try {
            \App\Models\JobRun::create([
                'command' => 'ai:narrate',
                'status'  => \App\Models\JobRun::STATUS_FAILED,
                'message' => \Illuminate\Support\Str::limit($message, 500),
                'ran_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort — never let observability break narration
        }
    }

    /**
     * Narrate all investigations for a tenant that are missing a narrative
     * or whose evidence is newer than their last narrative.
     */
    public function narrateForTenant(int $tenantId): void
    {
        // WP5.4: a nightly run narrates at most ai.narrate_per_run investigations,
        // the most money at risk first; the rest wait for the next night.
        $investigations = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->where(function ($q) {
                $q->whereNull('ai_generated_at')
                  ->orWhereExists(function ($sub) {
                      $sub->from('investigation_evidence')
                          ->whereColumn('investigation_evidence.investigation_id', 'investigations.id')
                          ->whereColumn('investigation_evidence.updated_at', '>', 'investigations.ai_generated_at');
                  });
            })
            ->orderByRaw('revenue_at_risk DESC NULLS LAST')
            ->limit(max(1, (int) config('ai.narrate_per_run', 50)))
            ->get();

        $written = $this->narrateMany($investigations);

        Log::info("[M19] Narrated {$written} of {$investigations->count()} investigation(s) for tenant {$tenantId}");
    }

    // =========================================================================
    // PROMPT BUILDER
    // =========================================================================

    private function buildPrompt(Investigation $investigation, ?array $cause = null): string
    {
        $causeText = $cause
            ? "{$cause['root_label']} (" . ($cause['tier'] === 'corroborated' ? 'a full cause-and-effect chain in the data' : ($cause['tier'] === 'likely' ? 'a direct cause-and-effect link in the data' : 'signals that occur together but show no known cause-and-effect link')) . ").\n"
                . 'Chain: ' . implode(' → ', array_map(fn ($c) => $c['label'], $cause['chain'])) . "\n" . $cause['explanation']
            : 'A single signal — there is no cause-and-effect chain to report. Describe what was detected; do not speculate about a cause.';

        $anomalies = $investigation->anomalies()->with(['product', 'store'])->get();
        $currency  = $investigation->tenant?->currencyCode() ?? 'AED';

        // Human-readable names — never ship raw codes to the reader.
        $product     = $anomalies->map(fn ($a) => $a->product)->filter()->first();
        $productName = PromptData::name($product?->name ?? $investigation->primary_sku ?? 'this product');
        $storeNames  = $anomalies->map(fn ($a) => $a->store?->name ?? ($a->store_id ? "store {$a->store_id}" : null))
            ->filter()->unique()->take(10)->map(fn ($n) => PromptData::name($n))->implode(', ') ?: 'multiple stores';

        // Rule lines — store names, not codes. WP5.4: names come from customer
        // files, so they are cleaned; the list is capped (+N more).
        $ruleLines = PromptData::capped($anomalies->sortByDesc(fn ($a) => (float) ($a->context['revenue_impact'] ?? 0))->map(function ($a) {
            $label = AnomalySetting::RULES[$a->rule_type]['label'] ?? $a->rule_type;
            $desc  = AnomalySetting::RULES[$a->rule_type]['description'] ?? '';
            $store = PromptData::name($a->store?->name ?? ($a->store_id ? "store {$a->store_id}" : 'chain-wide'));
            return "  - [{$a->severity}] {$label} @ {$store}: {$desc}";
        })->values()->all(), (int) config('ai.prompt_max_anomalies', 25), 'more signals');

        // Evidence package — passed RAW; the model RESTATES it in plain language.
        $evidence      = $investigation->evidence()->orderBy('evidence_type')->limit((int) config('ai.prompt_max_evidence', 30))->get();
        $moreEvidence  = max(0, $investigation->evidence()->count() - $evidence->count());
        $supporting    = $evidence->where('direction', InvestigationEvidence::DIRECTION_SUPPORTS);
        $contradicting = $evidence->where('direction', InvestigationEvidence::DIRECTION_CONTRADICTS);
        $neutral       = $evidence->where('direction', InvestigationEvidence::DIRECTION_NEUTRAL);

        $evidenceText = '';
        if ($supporting->isNotEmpty()) {
            $evidenceText .= "\nSUPPORTING (confirms the problem is real):\n";
            foreach ($supporting as $e) { $evidenceText .= "  {$e->label}: {$e->getFormattedValue()}\n"; }
        }
        if ($contradicting->isNotEmpty()) {
            $evidenceText .= "\nCONTRADICTING (may indicate a false positive — weigh honestly):\n";
            foreach ($contradicting as $e) { $evidenceText .= "  {$e->label}: {$e->getFormattedValue()}\n"; }
        }
        if ($neutral->isNotEmpty()) {
            $evidenceText .= "\nCONTEXT:\n";
            foreach ($neutral as $e) { $evidenceText .= "  {$e->label}: {$e->getFormattedValue()}\n"; }
        }

        if ($moreEvidence > 0) {
            $evidenceText .= "\n(+{$moreEvidence} more evidence items not shown)\n";
        }
        $priority = strtoupper($investigation->priority);
        $title    = PromptData::name($investigation->title, 160);
        $facts    = PromptData::block('investigation', "INVESTIGATION: {$title}\nPRODUCT: {$productName}\nSTORES INVOLVED: {$storeNames}\nPRIORITY: {$priority}\nCURRENCY: {$currency}\n\nDETECTED ANOMALIES ({$anomalies->count()}):\n{$ruleLines}");
        $evidenceBlock = PromptData::block('evidence', trim($evidenceText));

        return <<<PROMPT
You are a retail operations analyst writing for a busy store or category manager who is NOT technical and is often reading on a phone. Turn the deterministic findings below into a clear, honest narrative they can understand in seconds.

{$facts}

EVIDENCE:
{$evidenceBlock}

ROOT CAUSE — decided by Autnyx from the data. Restate it in plain language; do NOT replace it, add a different cause, or claim more certainty than it states:
{$causeText}

WRITING RULES — follow every one:
1. Use PRODUCT and STORE NAMES, never codes (never "SKU00236" or "ST005").
2. Whole units only — write "45 units", never "45.00 units".
3. Money in {$currency}, sensibly rounded (e.g. "{$currency} 4,000"). Never a dollar sign unless the currency is USD.
4. Never output a blank, a dash, or an em dash. If you do not have a value, leave that fact out.
5. Keep EVIDENCE (what is true) separate from ACTION (what to do). No recommendations in the evidence or the contributing factors.
6. Match the evidence — do NOT overstate. If the item is a slow mover badly spread across stores, call it a DISTRIBUTION IMBALANCE; do not inflate it into "demand outrunning supply / the supply chain cannot keep up".
7. Be honest about size. If the money at stake is small, say so plainly.
8. Group repetition — "7 other stores show the same pattern", not seven near-identical lines.
9. Interpret; do not dump the raw evidence table back.

Respond with ONLY this JSON object (no markdown, no code fences):
{
  "headline": "ONE plain sentence: what is happening, to which product, and where. The 5-second version.",
  "summary": "2-3 plain sentences: what happened, why it matters, and how big it is. Do not repeat the headline word for word.",
  "root_cause": "The ROOT CAUSE above, restated in plain language. Never a different cause.",
  "evidence": ["3-5 short plain-language facts that support the conclusion, each a complete phrase"],
  "contributing_factors": ["0-4 short plain bullets for what amplified it; group repeats; no recommendations"],
  "business_impact": "One sentence: the money at stake in {$currency}, whether it is large or small, and what worsens if ignored.",
  "immediate_action": "The single most important action right now — concrete, done by the user in their own ERP (Autnyx recommends, it never executes).",
  "long_term_fix": "One sentence on the systemic fix, or null if none is clear.",
  "revenue_estimate": null
}

For revenue_estimate: your own rough estimate as a number if the evidence supports one (e.g. days_of_cover x daily_revenue), otherwise null. It is shown to users labelled as an AI estimate and never replaces the system's calculated value. Keep every field tight.
PROMPT;
    }

    // =========================================================================
    // RESPONSE PARSER
    // =========================================================================

    private function parseResponse(string $text): array
    {
        // Strip markdown fences if present
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        // Extract the first JSON object
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                // Validate confidence value
                $validConfidences = [
                    Investigation::CONFIDENCE_ESTABLISHED,
                    Investigation::CONFIDENCE_PROBABLE,
                    Investigation::CONFIDENCE_SUSPECTED,
                    Investigation::CONFIDENCE_UNKNOWN,
                ];
                if (!in_array($decoded['confidence'] ?? '', $validConfidences)) {
                    $decoded['confidence'] = Investigation::CONFIDENCE_UNKNOWN;
                }
                return $decoded;
            }
        }

        return [];
    }
}
