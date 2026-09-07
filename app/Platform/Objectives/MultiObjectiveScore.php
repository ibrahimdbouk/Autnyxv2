<?php

namespace App\Platform\Objectives;

/**
 * P4.6 — a candidate scored against a weighted blend of objectives. `blended` is
 * the single number used to rank; `contributions` shows how much each objective
 * added (the tradeoff, made explicit — a big availability win partly cancelled by
 * a working-capital cost is visible, not hidden). `dominated` flags a candidate
 * that another beats on every objective (off the Pareto frontier).
 *
 * Convention: impacts are *benefit* values — higher is better on every objective
 * (so a cost like waste or cash tied up is passed in as a negative or as its
 * reduction). This keeps the blend and the domination test uniform.
 */
class MultiObjectiveScore
{
    /**
     * @param  array<string,float>  $contributions  objective => weighted contribution
     * @param  array<string,float>  $impacts        objective => raw benefit
     */
    public function __construct(
        public readonly ?string $key,
        public readonly float $blended,
        public readonly array $contributions,
        public readonly array $impacts,
        public readonly bool $dominated = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key'           => $this->key,
            'blended'       => $this->blended,
            'contributions' => $this->contributions,
            'impacts'       => $this->impacts,
            'dominated'     => $this->dominated,
        ];
    }
}
