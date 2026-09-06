<?php

namespace App\Platform\Traceability;

use App\Models\Batch;
use App\Models\BatchMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * P3.3 — the traceability engine. Records a batch's chain of custody and answers
 * the two questions a recall or audit needs: where did this lot go, and how much
 * is still on hand. This is the "traceability supported end-to-end" half of the
 * P3.3 definition of done.
 */
class BatchTraceability
{
    private const INBOUND  = [BatchMovement::TYPE_RECEIPT, BatchMovement::TYPE_RETURN];
    private const OUTBOUND = [BatchMovement::TYPE_SALE, BatchMovement::TYPE_DISPOSAL];

    public function record(
        Batch $batch,
        string $type,
        float $quantity,
        ?string $from = null,
        ?string $to = null,
        ?string $reference = null,
        ?CarbonInterface $occurredAt = null,
    ): BatchMovement {
        return $batch->movements()->create([
            'tenant_id'     => $batch->tenant_id,
            'movement_type' => $type,
            'from_location' => $from,
            'to_location'   => $to,
            'quantity'      => $quantity,
            'reference'     => $reference,
            'occurred_at'   => $occurredAt ?? now(),
        ]);
    }

    /**
     * The full chain of custody for a batch, oldest first.
     *
     * @return Collection<int,BatchMovement>
     */
    public function trace(Batch $batch): Collection
    {
        return $batch->movements()->orderBy('occurred_at')->orderBy('id')->get();
    }

    /**
     * Quantity still on hand for a batch: inbound (receipt/return) minus outbound
     * (sale/disposal). Transfers move location, not quantity, so they net zero.
     */
    public function onHand(Batch $batch): float
    {
        return (float) $this->trace($batch)->reduce(function (float $carry, BatchMovement $m) {
            if (in_array($m->movement_type, self::INBOUND, true)) {
                return $carry + $m->quantity;
            }
            if (in_array($m->movement_type, self::OUTBOUND, true)) {
                return $carry - $m->quantity;
            }
            return $carry; // transfer / adjustment: location change, not stock change
        }, 0.0);
    }

    /** Current location: the destination of the most recent movement that set one. */
    public function locate(Batch $batch): ?string
    {
        return $this->trace($batch)
            ->reverse()
            ->firstWhere(fn (BatchMovement $m) => $m->to_location !== null)
            ?->to_location;
    }
}
