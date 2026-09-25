<?php

namespace App\Services\Detection;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * WP4.1 (audit H17) — one definition of a detection window.
 *
 * A window is a half-open range of calendar days [from, until): `from` is the
 * first day in the window and `until` the first day after it. A trailing window
 * of N days ending on the data clock covers exactly N days — clock−N+1 … clock —
 * and the window before it abuts it without sharing a day. Rates divide by
 * days(), the real day count, so a 7-day and a 28-day window compare like with
 * like.
 */
final class Window
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $until,
    ) {}

    /** The N days ending on (and including) $clock. */
    public static function trailing(CarbonInterface $clock, int $days): self
    {
        $days  = max(1, $days);
        $until = Carbon::instance($clock)->startOfDay()->addDay();

        return new self($until->copy()->subDays($days), $until);
    }

    /** The N days immediately before this window (no overlap). */
    public function before(int $days): self
    {
        $days = max(1, $days);

        return new self($this->from->copy()->subDays($days), $this->from->copy());
    }

    /** The same window one year earlier. */
    public function yearEarlier(): self
    {
        return new self($this->from->copy()->subYear(), $this->until->copy()->subYear());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->until, absolute: true);
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function untilDate(): string
    {
        return $this->until->format('Y-m-d');
    }

    /** The last day inside the window (for display). */
    public function lastDate(): string
    {
        return $this->until->copy()->subDay()->format('Y-m-d');
    }

    public function contains(string $date): bool
    {
        $d = substr($date, 0, 10);

        return $d >= $this->fromDate() && $d < $this->untilDate();
    }

    /** Constrain a query builder: $column in [from, until). */
    public function apply($query, string $column = 'date')
    {
        return $query->where($column, '>=', $this->fromDate())->where($column, '<', $this->untilDate());
    }

    /**
     * A raw SQL fragment + bindings for the same range.
     *
     * @return array{0:string,1:array<int,string>}
     */
    public function sql(string $column = 'date'): array
    {
        return ["{$column} >= ? AND {$column} < ?", [$this->fromDate(), $this->untilDate()]];
    }
}
