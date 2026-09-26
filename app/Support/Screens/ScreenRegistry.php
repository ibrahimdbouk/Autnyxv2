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
        'anomalies'       => ['label' => 'Anomalies',        'group' => 'Root Cause'],
        'investigations'  => ['label' => 'Investigations',   'group' => 'Root Cause'],
        'action_center'   => ['label' => 'Action Center',    'group' => 'Root Cause'],
        'action_queue'    => ['label' => 'Action Queue',     'group' => 'Root Cause'],
        'daily_briefing'  => ['label' => 'Daily Briefing',   'group' => 'Root Cause'],
        'weekly_briefing' => ['label' => 'Weekly Briefing',  'group' => 'Root Cause'],
        'follow_ups'      => ['label' => 'Follow-Ups',       'group' => 'Root Cause'],
        'supplier_prep'   => ['label' => 'Supplier Prep',    'group' => 'Root Cause'],
        // W11
        'count_lists'        => ['label' => 'Count Lists',        'group' => 'Root Cause'],
        'fresh_expiry'       => ['label' => 'Fresh & Expiry',     'group' => 'Root Cause'],
        'supplier_scorecard' => ['label' => 'Supplier Scorecard', 'group' => 'Root Cause'],
        'data_quality'    => ['label' => 'AI data-quality check (on Data Health)', 'group' => 'Data'],
        'watched'         => ['label' => 'Watched',          'group' => 'Root Cause'],
        'replenishment'   => ['label' => 'Replenishment',    'group' => 'Root Cause'],
        'reports'         => ['label' => 'Reports',          'group' => 'Root Cause'],
        // Data
        'products'        => ['label' => 'Products',         'group' => 'Data'],
        'sales'           => ['label' => 'Sales',            'group' => 'Data'],
        'inventory'       => ['label' => 'Inventory',        'group' => 'Data'],
        'returns'         => ['label' => 'Returns',          'group' => 'Data'],
        'purchase_orders' => ['label' => 'Purchase Orders',  'group' => 'Data'],
        'suppliers'       => ['label' => 'Suppliers',        'group' => 'Data'],
        'data_health'     => ['label' => 'Data Health',      'group' => 'Data'],
        // WP2.4 (audit M9): previously ungated — reachable by URL for anyone.
        'stores'          => ['label' => 'Stores',           'group' => 'Data'],
        'store_clusters'  => ['label' => 'Store Clustering', 'group' => 'Data'],
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
     *   ['Root Cause' => ['anomalies' => 'Anomalies', ...], 'Data' => [...]]
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
