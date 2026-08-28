<?php

namespace App\Support\Screens;

/**
 * 1a — the registry of gate-able screens.
 *
 * Single source of truth for (a) the admin checkbox matrix on the User form and
 * (b) the per-screen `canSeeScreen()` gate each Resource/Page consults. Each
 * entry is a stable key → label + navigation group. The key is what gets stored
 * in `users.visible_screens`, so keys must never change once shipped.
 *
 * Only screens a non-admin user could reasonably be granted live here. Purely
 * administrative screens (Users, Tenants, Audit Log, Detection Rules,
 * Suppressions, SFTP, Teams) stay role-gated and are intentionally NOT listed —
 * a "user" never sees them regardless of this matrix. The Dashboard is always on
 * (a user is never locked out of a landing page), so it is not listed either.
 */
final class ScreenRegistry
{
    /**
     * key => ['label' => string, 'group' => string]
     *
     * @var array<string,array{label:string,group:string}>
     */
    public const SCREENS = [
        // Intelligence
        'anomalies'       => ['label' => 'Anomalies',        'group' => 'Intelligence'],
        'investigations'  => ['label' => 'Investigations',   'group' => 'Intelligence'],
        'action_center'   => ['label' => 'Action Center',    'group' => 'Intelligence'],
        'watched'         => ['label' => 'Watched',          'group' => 'Intelligence'],
        'replenishment'   => ['label' => 'Replenishment',    'group' => 'Intelligence'],
        'reports'         => ['label' => 'Reports',          'group' => 'Intelligence'],
        // Data
        'products'        => ['label' => 'Products',         'group' => 'Data'],
        'sales'           => ['label' => 'Sales',            'group' => 'Data'],
        'inventory'       => ['label' => 'Inventory',        'group' => 'Data'],
        'returns'         => ['label' => 'Returns',          'group' => 'Data'],
        'purchase_orders' => ['label' => 'Purchase Orders',  'group' => 'Data'],
        'suppliers'       => ['label' => 'Suppliers',        'group' => 'Data'],
        'data_health'     => ['label' => 'Data Health',      'group' => 'Data'],
    ];

    /** @return array<int,string> every valid screen key */
    public static function keys(): array
    {
        return array_keys(self::SCREENS);
    }

    /** True if $key is a registered gate-able screen. */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::SCREENS);
    }

    /**
     * Options for a Filament CheckboxList, grouped by navigation group:
     *   ['Intelligence' => ['anomalies' => 'Anomalies', ...], 'Data' => [...]]
     *
     * @return array<string,array<string,string>>
     */
    public static function groupedOptions(): array
    {
        $out = [];
        foreach (self::SCREENS as $key => $meta) {
            $out[$meta['group']][$key] = $meta['label'];
        }

        return $out;
    }

    /**
     * Flat key => label options.
     *
     * @return array<string,string>
     */
    public static function options(): array
    {
        return array_map(fn ($m) => $m['label'], self::SCREENS);
    }
}
