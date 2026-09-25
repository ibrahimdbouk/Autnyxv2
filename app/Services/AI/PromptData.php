<?php

namespace App\Services\AI;

/**
 * WP5.4 (audit M13) — untrusted values in prompts. Names and notes come from
 * customer files, so they are cleaned (control characters, fence and tag
 * markers stripped, length capped) and whole data sections are fenced in
 * <data> blocks the system prompt tells the model never to obey. Long lists
 * are capped with "+N more" so a big investigation can't blow the context.
 */
final class PromptData
{
    public static function name(?string $value, int $max = 80): string
    {
        $v = (string) $value;
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
        $v = str_replace(['```', '<data', '</data', '<', '>'], ['', '', '', '‹', '›'], $v);
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');

        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }

    public static function block(string $label, string $content): string
    {
        $content = str_replace(['<data', '</data'], ['‹data', '‹/data'], $content);

        return "<data label=\"{$label}\">\n{$content}\n</data>";
    }

    /**
     * @param  array<int,string>  $lines
     */
    public static function capped(array $lines, int $max, string $noun = 'more'): string
    {
        $shown = array_slice($lines, 0, $max);
        $rest = count($lines) - count($shown);
        if ($rest > 0) {
            $shown[] = "  (+{$rest} {$noun})";
        }

        return implode("\n", $shown);
    }
}
