<?php

namespace App\Services\Import;

/**
 * WP3.2 — a date / number cell that could not be read under the import's
 * format. Carried to the writer instead of the raw string, so a later step can
 * never "succeed" by re-reading it under different rules.
 */
final class InvalidValue implements \Stringable
{
    public function __construct(
        public readonly string $raw,
        public readonly string $reason,
    ) {
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
