<?php

namespace App\Services\Anomaly;

use App\Models\Anomaly;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnomalyInvestigationService
{
    private const HAIKU_MODEL = 'claude-haiku-4-5';
    private const API_URL     = 'https://api.anthropic.com/v1/messages';

    /**
     * Run the full 7-question investigation on an anomaly.
     * Calls Claude Haiku once with a structured JSON prompt, then saves all fields.
     */
    public function investigate(Anomaly $anomaly): Anomaly
    {
        $anomaly->update(['investigation_status' => Anomaly::STATUS_INVESTIGATING]);

        // Find related open anomalies on the same SKU or store (Q7 context)
        $relatedQuery = Anomaly::where('tenant_id', $anomaly->tenant_id)
            ->where('id', '!=', $anomaly->id)
            ->whereNull('dismissed_at');

        if ($anomaly->sku) {
            $relatedQuery->where('sku', $anomaly->sku);
        } elseif ($anomaly->store_id) {
            $relatedQuery->where('store_id', $anomaly->store_id);
        }

        $related = $relatedQuery->orderByDesc('detected_at')->limit(5)->get();

        // Check history (Q6 context)
        $historicalCount = Anomaly::where('tenant_id', $anomaly->tenant_id)
            ->where('rule_type', $anomaly->rule_type)
            ->when($anomaly->sku, fn ($q) => $q->where('sku', $anomaly->sku))
            ->when($anomaly->store_id, fn ($q) => $q->where('store_id', $anomaly->store_id))
            ->where('id', '!=', $anomaly->id)
            ->whereNotNull('detected_at')
            ->count();

        $prompt = $this->buildPrompt($anomaly, $related, $historicalCount);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post(self::API_URL, [
                'model'      => self::HAIKU_MODEL,
                'max_tokens' => 2048,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::error('Anomaly investigation API error', [
                    'anomaly_id' => $anomaly->id,
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                ]);
                $anomaly->update(['investigation_status' => Anomaly::STATUS_DETECTED]);
                return $anomaly;
            }

            $text = $response->json('content.0.text', '');
            $data = $this->parseJson($text);

            if (!$data) {
                Log::warning('Anomaly investigation: could not parse JSON', [
                    'anomaly_id' => $anomaly->id,
                    'raw'        => $text,
                ]);
                $anomaly->update(['investigation_status' => Anomaly::STATUS_DETECTED]);
                return $anomaly;
            }

            $confidence = $data['confidence'] ?? Anomaly::CONFIDENCE_UNKNOWN;
            $gate       = $data['recommendation_gate'] ?? Anomaly::GATE_MONITOR;

            $newStatus = match (true) {
                in_array($confidence, [Anomaly::CONFIDENCE_ESTABLISHED, Anomaly::CONFIDENCE_PROBABLE])
                    => Anomaly::STATUS_CAUSE_ESTABLISHED,
                default => Anomaly::STATUS_INVESTIGATING,
            };

            $anomaly->update([
                'investigation_status'  => $newStatus,
                'ai_what'               => $data['what']              ?? null,
                'ai_why'                => $data['why']               ?? null,
                'ai_confidence'         => $confidence,
                'ai_how_big'            => $data['how_big']           ?? null,
                'ai_trajectory'         => $data['trajectory']        ?? null,
                'ai_action'             => $data['action']            ?? null,
                'ai_recommendation_gate'=> $gate,
                'ai_pattern'            => $data['pattern']           ?? null,
                'ai_is_recurring'       => $data['is_recurring']      ?? false,
                'ai_related_anomaly_ids'=> $related->pluck('id')->toArray(),
                'ai_related_summary'    => $data['related_summary']   ?? null,
                'ai_generated_at'       => now(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Anomaly investigation exception', [
                'anomaly_id' => $anomaly->id,
                'error'      => $e->getMessage(),
            ]);
            $anomaly->update(['investigation_status' => Anomaly::STATUS_DETECTED]);
        }

        return $anomaly->fresh();
    }

    private function buildPrompt(Anomaly $anomaly, $related, int $historicalCount): string
    {
        $context   = json_encode($anomaly->context ?? [], JSON_PRETTY_PRINT);
        $ruleLabel = $anomaly->getRuleLabel();

        $relatedSummary = $related->isEmpty()
            ? 'None.'
            : $related->map(fn ($r) => "- [{$r->severity}] {$r->rule_type}: {$r->description} (detected {$r->detected_at?->diffForHumans()})")->join("\n");

        $historyNote = $historicalCount === 0
            ? 'This is the first time this rule has fired for this SKU/store.'
            : "This rule has fired {$historicalCount} time(s) before for this SKU/store.";

        return <<<PROMPT
You are an AI analyst for a retail inventory management system. Investigate the following anomaly and answer 7 structured questions.

=== ANOMALY ===
Rule: {$ruleLabel} ({$anomaly->rule_type})
Severity: {$anomaly->severity}
SKU: {$anomaly->sku ?? 'N/A'}
Store ID: {$anomaly->store_id ?? 'N/A'}
Description: {$anomaly->description}
Detected: {$anomaly->detected_at?->toDateTimeString()}
Context data:
{$context}

=== HISTORY ===
{$historyNote}

=== RELATED OPEN ANOMALIES (same SKU/store) ===
{$relatedSummary}

=== YOUR TASK ===
Respond ONLY with a single valid JSON object — no markdown, no explanation — with exactly these keys:

{
  "what": "1–2 sentences: What specifically changed and by how much? Include magnitude and timing.",
  "why": "2–3 sentences: Most likely root cause. Be direct. If cause is unclear, say so.",
  "confidence": "one of: established | probable | suspected | unknown",
  "confidence_reason": "1 sentence explaining why you chose this confidence level.",
  "how_big": "1–2 sentences: Revenue at risk or scale of impact. How many SKUs/stores affected?",
  "trajectory": "one of: widening | stable | narrowing",
  "action": "2–3 sentences: Specific recommended actions with priority order.",
  "recommendation_gate": "one of: act | investigate | monitor",
  "pattern": "1–2 sentences: Is this a structural issue or a one-time incident? What does history suggest?",
  "is_recurring": true or false,
  "related_summary": "1 sentence summarizing any connected anomalies, or null if none."
}

Rules:
- confidence=established means you have clear data evidence. probable means strong indirect signals. suspected means plausible but limited data. unknown means cause not established.
- recommendation_gate=act means evidence is strong enough to take action now. investigate means needs more data. monitor means watch and see.
- Be concise and operational. This is read by a retail ops manager, not a data scientist.
- If data is insufficient to answer a question, say "Insufficient data to determine." Do NOT fabricate.
PROMPT;
    }

    private function parseJson(string $text): ?array
    {
        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        // Find the first { ... } block
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start === false || $end === false) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
