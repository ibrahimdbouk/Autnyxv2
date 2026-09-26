<?php

namespace App\Services\Assortment;

use App\Models\Tenant;

/**
 * A tenant's Assortment state, kept in tenants.settings.assortment:
 *
 *   live        — the validation gate passed: decisions are shown and can be acted on
 *   live_at / gate — when, and the review figures it passed with
 *   shadow_run  — run the engine nightly before the app is switched on (trial)
 *   guardrails  — the few settings a tenant may change (§4.5): delist confidence,
 *                 carried window, and the most delists per category per store
 */
final class TenantAssortment
{
    /** Guardrails a tenant may change, with the allowed values. */
    public const GUARDRAILS = [
        'delist_min_tier'       => ['likely', 'established'],
        'carried_window_days'   => [28, 56, 90],
        'max_delists_per_category' => [1, 2, 3, 5, 10],
    ];

    public static function settings(Tenant $tenant): array
    {
        $s = is_array($tenant->settings) ? $tenant->settings : [];

        return is_array($s['assortment'] ?? null) ? $s['assortment'] : [];
    }

    public static function isLive(Tenant $tenant): bool
    {
        return (self::settings($tenant)['live'] ?? false) === true;
    }

    /** @return array{delist_min_tier:string, carried_window_days:int, max_delists_per_category:int} */
    public static function guardrails(Tenant $tenant): array
    {
        $g = self::settings($tenant)['guardrails'] ?? [];
        $pick = function (string $key, $default) use ($g) {
            $v = $g[$key] ?? null;

            return in_array($v, self::GUARDRAILS[$key], false) ? (is_int($default) ? (int) $v : (string) $v) : $default;
        };

        return [
            'delist_min_tier'          => $pick('delist_min_tier', (string) config('assortment.delist_min_tier', 'likely')),
            'carried_window_days'      => $pick('carried_window_days', (int) config('assortment.carried_window_days', 56)),
            'max_delists_per_category' => $pick('max_delists_per_category', (int) config('assortment.max_delists_per_category', 3)),
        ];
    }

    public static function update(Tenant $tenant, array $changes): void
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $settings['assortment'] = array_replace(self::settings($tenant), $changes);
        $tenant->forceFill(['settings' => $settings])->save();
    }
}
