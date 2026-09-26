<?php

namespace App\Services\Assortment;

/**
 * Keeps only the N most valuable decisions per store × category while the
 * decisions stream in chunk by chunk — so memory is bounded by stores ×
 * categories × N, not by the size of the range, and a review stays a few
 * calls per shelf instead of thousands.
 */
final class TopPerShelf
{
    /** @var array<string,array<int,array<string,mixed>>> shelf => gaps (sorted high → low) */
    private array $shelves = [];

    private int $dropped = 0;

    public function __construct(private readonly int $limit) {}

    public function offer(string $shelf, array $gap): void
    {
        $list = $this->shelves[$shelf] ?? [];
        if (count($list) >= $this->limit && $gap['value_mid'] <= end($list)['value_mid']) {
            $this->dropped++;

            return;
        }
        $list[] = $gap;
        usort($list, fn ($a, $b) => $b['value_mid'] <=> $a['value_mid']);
        if (count($list) > $this->limit) {
            array_pop($list);
            $this->dropped++;
        }
        $this->shelves[$shelf] = $list;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return array_merge([], ...array_values($this->shelves));
    }

    public function dropped(): int
    {
        return $this->dropped;
    }
}
