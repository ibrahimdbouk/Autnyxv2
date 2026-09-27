<?php

namespace App\Services\Assortment;

use App\Models\Tenant;

/**
 * v1.5 Phase 2 — the one strategy choice per category: its ROLE and its
 * OBJECTIVE, with strong defaults (Autnyx-owned presets, not a knob farm).
 *
 * Role — how wide the range should be and how hard the tail is cut:
 *   destination  wide range; delists need twice the case; adds favoured; +15% room
 *   routine      the default; +10% room
 *   convenience  a tight range of top sellers; the tail cut harder; no extra room
 *   seasonal     judged in season (lifecycle already protects seasonal products); +10% room
 *
 * Objective — how changes are ranked; each measure is turned into a % change of
 * the category at the store before it is weighted (platform MultiObjectiveScorer):
 *   sales · margin · availability · working capital (stock investment) ·
 *   space productivity (margin per product on the shelf) · balanced
 *
 * "Room" is how many products the shelf may grow by, as a share of today's
 * count, until real shelf space is known (Phase 4). 0 = hold the count: every
 * add must be paid for by a delist (a swap). An optional sales floor keeps a
 * plan from buying margin or stock savings with lost sales.
 */
final class CategoryStrategy
{
    public const ROLES = [
        'destination' => 'Destination — wide range, highest bar to delist',
        'routine'     => 'Routine (default)',
        'convenience' => 'Convenience — a tight range of top sellers',
        'seasonal'    => 'Seasonal or occasional',
    ];

    public const OBJECTIVES = [
        'sales'           => ['label' => 'Sales',              'weights' => ['sales' => 1.0]],
        'margin'          => ['label' => 'Margin',             'weights' => ['margin' => 1.0]],
        'availability'    => ['label' => 'Availability',       'weights' => ['availability' => 1.0, 'sales' => 0.25]],
        'working_capital' => ['label' => 'Working capital',    'weights' => ['stock' => 1.0, 'margin' => 0.25]],
        'space'           => ['label' => 'Space productivity', 'weights' => ['space' => 1.0]],
        'balanced'        => ['label' => 'Balanced',           'weights' => ['sales' => 0.4, 'margin' => 0.3, 'availability' => 0.2, 'stock' => 0.1]],
    ];

    public const DEFAULT_OBJECTIVE = [
        'destination' => 'sales',
        'routine'     => 'balanced',
        'convenience' => 'margin',
        'seasonal'    => 'sales',
    ];

    /** Room to grow, as a share of today's count. */
    public const DEFAULT_ROOM = ['destination' => 0.15, 'routine' => 0.10, 'convenience' => 0.0, 'seasonal' => 0.10];

    public const ROOM_OPTIONS = ['0' => 'Hold the count (swaps only)', '0.05' => '+5%', '0.1' => '+10%', '0.15' => '+15%', '0.2' => '+20%'];

    public const SALES_FLOORS = ['' => 'No floor', '-0.01' => 'Sales no worse than −1%', '-0.03' => 'Sales no worse than −3%'];

    /** How strongly the role weighs each kind of change. */
    public const ROLE_FACTORS = [
        'destination' => ['add' => 1.2, 'delist' => 0.5],
        'routine'     => ['add' => 1.0, 'delist' => 1.0],
        'convenience' => ['add' => 0.8, 'delist' => 1.25],
        'seasonal'    => ['add' => 1.0, 'delist' => 1.0],
    ];

    /**
     * The strategy for one category, defaults filled in.
     *
     * @return array{role:string, objective:string, room:float, sales_floor:?float, max_size:?int, set:bool}
     */
    public static function for(Tenant $tenant, string $category): array
    {
        $saved = TenantAssortment::settings($tenant)['categories'][$category] ?? [];
        $role = array_key_exists($saved['role'] ?? '', self::ROLES) ? $saved['role'] : 'routine';
        $objective = array_key_exists($saved['objective'] ?? '', self::OBJECTIVES) ? $saved['objective'] : self::DEFAULT_OBJECTIVE[$role];
        $room = isset($saved['room']) && is_numeric($saved['room']) ? max(0.0, min(0.5, (float) $saved['room'])) : self::DEFAULT_ROOM[$role];
        $floor = isset($saved['sales_floor']) && is_numeric($saved['sales_floor']) ? max(-0.5, min(0.0, (float) $saved['sales_floor'])) : null;
        if ($floor === null && $objective === 'working_capital' && ! array_key_exists('sales_floor', $saved)) {
            $floor = -0.01;   // saving stock should not quietly cost sales
        }
        $max = isset($saved['max_size']) && is_numeric($saved['max_size']) && (int) $saved['max_size'] > 0 ? (int) $saved['max_size'] : null;

        return ['role' => $role, 'objective' => $objective, 'room' => $room, 'sales_floor' => $floor, 'max_size' => $max, 'set' => $saved !== []];
    }

    public static function save(Tenant $tenant, string $category, array $values): void
    {
        $all = TenantAssortment::settings($tenant)['categories'] ?? [];
        $all[$category] = array_filter([
            'role'        => array_key_exists($values['role'] ?? '', self::ROLES) ? $values['role'] : 'routine',
            'objective'   => array_key_exists($values['objective'] ?? '', self::OBJECTIVES) ? $values['objective'] : null,
            'room'        => isset($values['room']) && $values['room'] !== '' ? (float) $values['room'] : null,
            'sales_floor' => isset($values['sales_floor']) && $values['sales_floor'] !== '' ? (float) $values['sales_floor'] : 'none',
            'max_size'    => isset($values['max_size']) && (int) $values['max_size'] > 0 ? (int) $values['max_size'] : null,
        ], fn ($v) => $v !== null);
        TenantAssortment::update($tenant, ['categories' => $all]);
    }

    public static function objectiveLabel(string $key): string
    {
        return self::OBJECTIVES[$key]['label'] ?? $key;
    }

    public static function roleLabel(string $key): string
    {
        return strtok(self::ROLES[$key] ?? $key, ' —(') ?: $key;
    }
}
