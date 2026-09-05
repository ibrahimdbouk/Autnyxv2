<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P1.3 — bitemporal behaviour for the canonical hierarchies. Two time axes:
 *   - VALID time  (effective_from / effective_to): when the fact was true in the
 *     business world. Intervals are half-open [from, to): a row is effective on a
 *     date when effective_from <= date < effective_to (nulls = open-ended).
 *   - SYSTEM time (recorded_at): when we wrote this version.
 *
 * Updates are non-destructive: {@see supersede()} closes the current version and
 * opens a successor, so history is never overwritten and any past state can be
 * reconstructed with {@see scopeEffectiveOn()} (+ {@see scopeRecordedAsOf()} for
 * the "as we knew it then" axis).
 */
trait HasEffectiveDating
{
    /** Versions valid on the given date (default: today). */
    public function scopeEffectiveOn(Builder $query, Carbon|string|null $date = null): Builder
    {
        $on = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $query
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $on))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $on));
    }

    /** Versions known to the system as of the given moment (system-time axis). */
    public function scopeRecordedAsOf(Builder $query, Carbon|string $systemTime): Builder
    {
        $at = Carbon::parse($systemTime);

        return $query->where(fn ($q) => $q->whereNull('recorded_at')->orWhere('recorded_at', '<=', $at));
    }

    /** Whether this version is valid on the given date (default: today). */
    public function isEffectiveOn(Carbon|string|null $date = null): bool
    {
        $on   = $date ? Carbon::parse($date) : now();
        $from = $this->effective_from;
        $to   = $this->effective_to;

        return ($from === null || $from->lte($on)) && ($to === null || $to->gt($on));
    }

    /**
     * Non-destructive update: close the current version (effective_to = today) and
     * insert a successor carrying the changes, sharing this record's identity.
     * Returns the new current version. Run in a transaction so a version is never
     * left open-ended twice.
     *
     * @param  array<string,mixed>  $changes
     */
    public function supersede(array $changes): static
    {
        return DB::transaction(function () use ($changes) {
            $asOf = now();
            $today = $asOf->toDateString();

            // Close the current version as of today.
            $this->effective_to = $today;
            $this->save();

            // Open the successor with the changes applied.
            $successor = $this->replicate(['id']);
            foreach ($changes as $key => $value) {
                $successor->{$key} = $value;
            }
            $successor->effective_from = $today;
            $successor->effective_to   = null;
            $successor->recorded_at    = $asOf;
            $successor->save();

            return $successor;
        });
    }
}
