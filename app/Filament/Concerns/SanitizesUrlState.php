<?php

namespace App\Filament\Concerns;

/**
 * WP7.2 (audit M8) — URL-bound (#[Url]) page state is user input: a crafted
 * link (?store=abc, ?status[]=x, ?from=yesterday) must fall back to the
 * default, never reach a query as the wrong type or throw a TypeError.
 *
 * A page lists its URL properties and their rule in urlRules():
 *   'string'            — a scalar, trimmed, at most 200 characters
 *   'int'               — digits only (kept as a string if the default is one)
 *   'date'              — Y-m-d
 *   'numeric'           — a number
 *   ['a', 'b', …]       — one of these values
 *
 * The properties are declared untyped so an array can arrive without a
 * TypeError; every value is cleaned before mount() (initialize hook) and
 * after every client update.
 */
trait SanitizesUrlState
{
    /** @return array<string,string|array<int,string>> */
    abstract protected function urlRules(): array;

    public function initializeSanitizesUrlState(): void
    {
        $this->sanitizeUrlState();
    }

    public function updatedSanitizesUrlState(string $name): void
    {
        if (array_key_exists(explode('.', $name)[0], $this->urlRules())) {
            $this->sanitizeUrlState();
        }
    }

    protected function sanitizeUrlState(): void
    {
        foreach ($this->urlRules() as $prop => $rule) {
            $default = (new \ReflectionProperty($this, $prop))->getDefaultValue();
            $this->{$prop} = self::cleanUrlValue($this->{$prop} ?? null, $rule, $default);
        }
    }

    public static function cleanUrlValue(mixed $value, string|array $rule, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default === null ? null : ($value ?? $default);
        }
        if (! is_scalar($value)) {
            return $default;
        }
        $v = trim((string) $value);

        if (is_array($rule)) {
            return in_array($v, $rule, true) ? $v : $default;
        }

        return match ($rule) {
            'int'     => ctype_digit($v) && strlen($v) <= 18 ? (is_string($default) ? $v : (int) $v) : $default,
            'numeric' => is_numeric($v) ? $v : $default,
            'date'    => (($d = \DateTime::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v) ? $v : $default,
            default   => mb_substr($v, 0, 200),
        };
    }
}
