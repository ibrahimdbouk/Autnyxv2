<?php

namespace App\Services;

use App\Models\AnomalySetting;
use App\Models\Investigation;
use App\Models\InvestigationEvidence;
use App\Services\Anomaly\EvidenceCollectorService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Http;
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
    private const HAIKU_MODEL = 'claude-haiku-4-5';
    private const API_URL     = 'https://api.anthropic.com/v1/messages';

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
        // Ensure evidence is collected before narrating
        if ($investigation->evidence()->count() === 0) {
            app(EvidenceCollectorService::class)->collectForInvestigation($investigation);
        }

        // Skip if already narrated recently and not forced
        if (!$force && $investigation->ai_generated_at) {
            $latestEvidence = $investigation->evidence()->latest('created_at')->value('created_at');
            if (!$latestEvidence || $investigation->ai_generated_at->gte($latestEvidence)) {
                Log::info("[M19] Investigation #{$investigation->id} narrative is current — skipping.");
                return $investigation;
            }
        }

        $prompt = $this->buildPrompt($investigation);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(45)->post(self::API_URL, [
                'model'      => self::HAIKU_MODEL,
                'max_tokens' => 1024,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::error('[M19] Anthropic API error', [
                    'status'         => $response->status(),
                    'body'           => $response->body(),
                    'investigation'  => $investigation->id,
                ]);
                return $investigation;
            }

            $text = $response->json('content.0.text', '');
            $data = $this->parseResponse($text);

            if (empty($data)) {
                Log::warning('[M19] Could not parse AI response', ['investigation' => $investigation->id, 'raw' => $text]);
                return $investigation;
            }

            $investigation->update([
                'ai_summary'            => $data['summary']            ?? null,
                'ai_root_cause'         => $data['root_cause']         ?? null,
                'ai_confidence'         => $data['confidence']         ?? Investigation::CONFIDENCE_UNKNOWN,
                'ai_recommended_action' => $data['recommended_action'] ?? null,
                'revenue_at_risk'       => isset($data['revenue_at_risk']) && is_numeric($data['revenue_at_risk'])
                    ? (float) $data['revenue_at_risk']
                    : $investigation->revenue_at_risk,
                'ai_generated_at'       => now(),
            ]);

            AuditLogger::aiGenerated($investigation);

            Log::info("[M19] Narrative generated for investigation #{$investigation->id}");

        } catch (\Throwable $e) {
            Log::error('[M19] Narrator exception: ' . $e->getMessage(), [
                'investigation' => $investigation->id,
                'trace'         => $e->getTraceAsString(),
            ]);
        }

        return $investigation->fresh();
    }

    /**
     * Narrate all investigations for a tenant that are missing a narrative
     * or whose evidence is newer than their last narrative.
     */
    public function narrateForTenant(int $tenantId): void
    {
        $investigations = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->where(function ($q) {
                $q->whereNull('ai_generated_at')
                  ->orWhereExists(function ($sub) {
                      $sub->from('investigation_evidence')
                          ->whereColumn('investigation_evidence.investigation_id', 'investigations.id')
                          ->whereColumn('investigation_evidence.created_at', '>', 'investigations.ai_generated_at');
                  });
            })
            ->get();

        foreach ($investigations as $investigation) {
            $this->narrate($investigation);
        }

        Log::info("[M19] Narrated {$investigations->count()} investigation(s) for tenant {$tenantId}");
    }

    // =========================================================================
    // PROMPT BUILDER
    // =========================================================================

    private function buildPrompt(Investigation $investigation): string
    {
        $anomalies = $investigation->anomalies()->get();

        // Rule labels
        $ruleLines = $anomalies->map(function ($a) {
            $label = AnomalySetting::RULES[$a->rule_type]['label'] ?? $a->rule_type;
            $desc  = AnomalySetting::RULES[$a->rule_type]['description'] ?? '';
            $loc   = $a->store_id ? " (store #{$a->store_id})" : '';
            return "  - [{$a->severity}] {$label}{$loc}: {$desc}";
        })->implode("\n");

        // Evidence package
        $evidence = $investigation->evidence()->orderBy('evidence_type')->get();

        $supporting   = $evidence->where('direction', InvestigationEvidence::DIRECTION_SUPPORTS);
        $contradicting = $evidence->where('direction', InvestigationEvidence::DIRECTION_CONTRADICTS);
        $neutral       = $evidence->where('direction', InvestigationEvidence::DIRECTION_NEUTRAL);

        $evidenceText = '';

        if ($supporting->isNotEmpty()) {
            $evidenceText .= "\nSUPPORTING EVIDENCE (confirms the anomaly is real):\n";
            foreach ($supporting as $e) {
                $evidenceText .= "  [{$e->strength}] {$e->label}: {$e->getFormattedValue()}\n";
            }
        }

        if ($contradicting->isNotEmpty()) {
            $evidenceText .= "\nCONTRADICTING EVIDENCE (suggests possible false positive):\n";
            foreach ($contradicting as $e) {
                $evidenceText .= "  [{$e->strength}] {$e->label}: {$e->getFormattedValue()}\n";
            }
        }

        if ($neutral->isNotEmpty()) {
            $evidenceText .= "\nCONTEXT:\n";
            foreach ($neutral as $e) {
                $evidenceText .= "  {$e->label}: {$e->getFormattedValue()}\n";
            }
        }

        $sku      = $investigation->primary_sku ?? 'N/A';
        $priority = strtoupper($investigation->priority);
        $opened   = $investigation->opened_at?->format('Y-m-d H:i') ?? 'Unknown';
        $team     = $investigation->assignedTeam?->name ?? 'Unassigned';

        return <<<PROMPT
You are a retail operations analyst reviewing an investigation. Your job is to synthesize the evidence below into a concise, actionable narrative for the operations team.

INVESTIGATION: {$investigation->title}
Priority: {$priority} | SKU: {$sku} | Opened: {$opened} | Assigned to: {$team}

ANOMALIES DETECTED ({$anomalies->count()}):
{$ruleLines}

EVIDENCE PACKAGE:
{$evidenceText}

Respond with ONLY a valid JSON object in this exact format (no markdown, no code blocks):
{
  "summary": "ONE sentence: what is happening and why it matters.",
  "root_cause": "1-2 sentences naming the single most likely cause and the reasoning. Use 'Unknown' if evidence is insufficient.",
  "confidence": "one of: established | probable | suspected | unknown",
  "recommended_action": "ONE concrete next step the team should take now — an imperative sentence.",
  "revenue_at_risk": null
}

Confidence guidance:
- established: multiple strong corroborating evidence points leave little doubt
- probable: evidence points in one direction but some gaps remain
- suspected: limited evidence; plausible but unconfirmed
- unknown: contradicting signals or insufficient data

For revenue_at_risk: estimate a number if you can (e.g. days_of_cover × daily_revenue), otherwise null.

WRITING RULES — the UI already shows the raw evidence table, so:
- INTERPRET the evidence; do NOT list, restate, or enumerate the raw numbers.
- No preamble, no restating the question, no filler. Get straight to the point.
- Keep every field tight: at most 2 sentences. Shorter is better.
- Write for a busy ops manager who wants the answer, not the working.
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
