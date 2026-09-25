<?php

namespace App\Services\AI;

/** WP5.4 — the outcome of one AI call. Never throws; failures are ok=false with a reason. */
final class AiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $text = '',
        public readonly ?string $error = null,
        public readonly ?string $model = null,
        public readonly ?string $stopReason = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    public static function fail(string $error, ?string $model = null): self
    {
        return new self(false, '', $error, $model);
    }

    /** The model stopped because it hit max_tokens — the JSON is cut off. */
    public function truncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }

    /** First JSON object in the text (code fences / leading prose tolerated), or []. */
    public function json(): array
    {
        $text = preg_replace('/```(?:json)?\s*/i', '', $this->text);
        $text = trim((string) preg_replace('/```\s*$/', '', (string) $text));
        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
