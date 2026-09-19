<?php

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\JobRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AgentService — shared base for every AI agent in Autnyx.
 *
 * The boundary, written once here so every agent inherits it:
 *   • Agents read DETERMINISTIC engine output (anomalies, investigations,
 *     evidence, imports, supplier rollups) — never raw customer systems.
 *   • They RECOMMEND, DRAFT, or NARRATE. They never decide and never execute
 *     autonomously. Any execution is a separate, human-accepted step that stays
 *     inside Autnyx (Actions, status changes, exports).
 *   • Every call is stored as an AgentRun with a confidence label, the inputs it
 *     saw, and the structured output — so a person can always audit what the AI
 *     was told and what it said.
 *
 * This mirrors the proven InvestigationNarratorService pattern (one call, full
 * package in, structured JSON out) and centralises the HTTP + parse + failure
 * plumbing so the concrete agents only carry their prompt and their inputs.
 */
abstract class AgentService
{
    protected const API_URL = 'https://api.anthropic.com/v1/messages';

    /** The key this agent stamps on its AgentRun rows. */
    abstract protected function agentKey(): string;

    // ── Model selection ─────────────────────────────────────────────────────────
    // Configurable so the models can be tuned per environment without a deploy.
    // Fast = bulk narration/flagging; reasoning = planning/synthesis.

    protected function fastModel(): string
    {
        return config('services.anthropic.model_fast', 'claude-haiku-4-5');
    }

    protected function reasoningModel(): string
    {
        return config('services.anthropic.model_reasoning', 'claude-sonnet-4-5');
    }

    // ── Anthropic call ───────────────────────────────────────────────────────────

    /**
     * One Claude call. Returns a normalised envelope:
     *   ['ok' => bool, 'data' => array, 'text' => string,
     *    'tokens_input' => ?int, 'tokens_output' => ?int, 'error' => ?string]
     *
     * `data` is the parsed JSON object (empty array if the model returned prose
     * we could not parse). Never throws — failures come back as ok=false.
     */
    protected function callClaude(
        string $prompt,
        string $model,
        int $maxTokens = 1500,
        ?string $system = null,
        int $timeout = 60
    ): array {
        $key = config('services.anthropic.key');
        if (empty($key)) {
            $this->recordFailure('Anthropic API key not configured');
            return ['ok' => false, 'data' => [], 'text' => '', 'tokens_input' => null, 'tokens_output' => null, 'error' => 'no_api_key'];
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($system !== null) {
            $payload['system'] = $system;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout($timeout)->post(self::API_URL, $payload);

            if ($response->failed()) {
                Log::error('[agent:' . $this->agentKey() . '] Anthropic API error', [
                    'status' => $response->status(),
                    'body'   => Str::limit($response->body(), 500),
                ]);
                $this->recordFailure("Anthropic API {$response->status()} ({$this->agentKey()})");
                return [
                    'ok' => false, 'data' => [], 'text' => '',
                    'tokens_input' => null, 'tokens_output' => null,
                    'error' => 'api_' . $response->status(),
                ];
            }

            $text = $response->json('content.0.text', '');
            $data = $this->parseJson($text);

            return [
                'ok'            => true,
                'data'          => $data,
                'text'          => $text,
                'tokens_input'  => $response->json('usage.input_tokens'),
                'tokens_output' => $response->json('usage.output_tokens'),
                'error'         => null,
            ];
        } catch (\Throwable $e) {
            Log::error('[agent:' . $this->agentKey() . '] exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $this->recordFailure("Agent exception ({$this->agentKey()}): " . $e->getMessage());
            return ['ok' => false, 'data' => [], 'text' => '', 'tokens_input' => null, 'tokens_output' => null, 'error' => 'exception'];
        }
    }

    /**
     * Extract the first JSON object from a model response, tolerating markdown
     * fences and leading prose. Returns [] if nothing parseable is found.
     * (Lifted from the narrator so the two behave identically.)
     */
    protected function parseJson(string $text): array
    {
        $text = preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/```\s*$/', '', (string) $text);
        $text = trim((string) $text);

        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Normalise a model value to a clean list of non-empty strings — the same
     * guard the narrator uses so no blank/dash bullets ever reach the UI.
     *
     * @return array<int,string>
     */
    protected function toList(mixed $v): array
    {
        if (! is_array($v)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v),
            fn ($x) => $x !== '' && $x !== '—' && $x !== '-'
        ));
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /**
     * Persist a completed run. Concrete agents call this with their assembled
     * input snapshot and parsed output. Best-effort provenance fields.
     */
    protected function record(array $attrs): AgentRun
    {
        return AgentRun::create(array_merge([
            'agent_key' => $this->agentKey(),
            'status'    => AgentRun::STATUS_COMPLETE,
        ], $attrs));
    }

    /**
     * Surface an agent failure in the ops observability layer (job_runs), the
     * same channel the narrator uses, so a run of bad responses shows on
     * Platform Health rather than only in the log. Best-effort.
     */
    protected function recordFailure(string $message): void
    {
        try {
            JobRun::create([
                'command' => 'agent:' . $this->agentKey(),
                'status'  => JobRun::STATUS_FAILED,
                'message' => Str::limit($message, 500),
                'ran_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort — never let observability break an agent
        }
    }

    /**
     * Validate a confidence string against the shared vocabulary, defaulting to
     * 'unknown' so the UI can always render a badge.
     */
    protected function confidence(mixed $value): string
    {
        $valid = ['established', 'probable', 'suspected', 'unknown'];
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, $valid, true) ? $value : 'unknown';
    }
}
