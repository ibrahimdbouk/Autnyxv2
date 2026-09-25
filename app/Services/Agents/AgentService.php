<?php

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\JobRun;
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

    /** The tenant the current call is for (budget, metering). Set by each agent's entry point. */
    protected ?int $callTenant = null;

    /**
     * One Claude call through AnthropicClient (WP5.4: budget, retries, circuit
     * breaker, metering, data-is-not-instructions system prompt). Returns:
     *   ['ok' => bool, 'data' => array, 'text' => string,
     *    'tokens_input' => ?int, 'tokens_output' => ?int, 'error' => ?string]
     * A reply cut off at max_tokens is a failure ('truncated') — its JSON is
     * incomplete. Never throws.
     */
    protected function callClaude(
        string $prompt,
        string $model,
        int $maxTokens = 1500,
        ?string $system = null,
        int $timeout = 60
    ): array {
        $tier = $model === $this->reasoningModel() ? 'reasoning' : 'fast';
        $r = app(\App\Services\AI\AnthropicClient::class)
            ->message($this->callTenant, 'agent:' . $this->agentKey(), $prompt, $tier, $maxTokens, $system, $timeout);

        if ($r->ok && $r->truncated()) {
            $this->recordFailure("Reply cut off at max_tokens ({$this->agentKey()})");
            $r = \App\Services\AI\AiResult::fail('truncated', $r->model);
        }

        return [
            'ok'            => $r->ok,
            'data'          => $r->ok ? $r->json() : [],
            'text'          => $r->text,
            'tokens_input'  => $r->ok ? $r->inputTokens : null,
            'tokens_output' => $r->ok ? $r->outputTokens : null,
            'error'         => $r->error,
        ];
    }

    /**
     * WP5.4 — record a SUCCESSFUL run and retire the runs it replaces in one
     * transaction, so a failed call never leaves the page with nothing: the
     * previous good run is only retired once there is a new one.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder): mixed  $previous  narrows AgentRun::query() to the runs replaced
     */
    protected function recordReplacing(array $attrs, \Closure $previous, string $retireTo = AgentRun::STATUS_DISMISSED): AgentRun
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($attrs, $previous, $retireTo) {
            $q = AgentRun::query()->where('agent_key', $this->agentKey());
            $previous($q);
            $q->lockForUpdate()->update(['status' => $retireTo]);

            return $this->record($attrs);
        });
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
