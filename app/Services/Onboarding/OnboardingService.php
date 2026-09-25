<?php

namespace App\Services\Onboarding;

use App\Models\Import;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * W10 (WP10.5) — first file to first investigation, in order. Every step is
 * read from the data itself (nothing to tick by hand), so the checklist is
 * always true: a step is done when the platform can see it is done.
 */
class OnboardingService
{
    public const MIN_SALES_DAYS = 35;          // 7-day window + the 28 days it is compared with
    public const GOOD_SALES_DAYS = 90;

    /**
     * @return array<int,array{key:string, title:string, why:string, level:string, done:bool, detail:string, type:?string}>
     */
    public function steps(int $tenantId): array
    {
        $count = fn (string $table) => (int) DB::table($table)->where('tenant_id', $tenantId)->count();
        $span  = DB::selectOne('SELECT MIN(date) AS a, MAX(date) AS b, COUNT(DISTINCT date) AS d FROM sales_daily WHERE tenant_id = ?', [$tenantId]);
        $salesDays = (int) ($span->d ?? 0);
        $inv = DB::selectOne('SELECT COUNT(*) AS n, MAX(as_of_date)::text AS latest FROM inventory_current WHERE tenant_id = ?', [$tenantId]);
        $stores = $count('stores');
        $products = $count('products');
        $pos = $count('purchase_orders');
        $promos = $count('promotions') + (int) DB::table('sales_transactions')->where('tenant_id', $tenantId)->whereNotNull('promotion_ref')->limit(1)->count();
        $returns = $count('sales_returns');
        $anomalies = (int) DB::table('anomalies')->where('tenant_id', $tenantId)->count();
        $investigations = (int) DB::table('investigations')->where('tenant_id', $tenantId)->count();
        $worked = (int) DB::table('investigations')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereIn('status', ['in_progress', 'resolved', 'closed'])->orWhereNotNull('assigned_user_id'))->count();
        $users = (int) DB::table('users')->where('tenant_id', $tenantId)->count();
        $tenant = Tenant::find($tenantId);

        return [
            ['key' => 'stores', 'type' => Import::TYPE_STORES, 'level' => 'required', 'done' => $stores > 0,
                'title' => 'Load your stores', 'why' => 'Every sale and stock position is tied to a store; clusters and store comparisons need the list.',
                'detail' => $stores ? number_format($stores) . ' stores' : 'No stores yet — sales files can create them too, but a store file adds region, format and size.'],
            ['key' => 'products', 'type' => Import::TYPE_PRODUCTS, 'level' => 'required', 'done' => $products > 0,
                'title' => 'Load your product master', 'why' => 'Prices and costs turn units into money at risk; categories group the findings.',
                'detail' => $products ? number_format($products) . ' products' : 'No products yet.'],
            ['key' => 'sales', 'type' => Import::TYPE_SALES, 'level' => 'required', 'done' => $salesDays >= self::MIN_SALES_DAYS,
                'title' => 'Load sales history', 'why' => 'Detection compares each week with the weeks before it: at least 5 weeks, ideally 13 or more.',
                'detail' => $salesDays ? "{$salesDays} days of sales (" . substr((string) $span->a, 0, 10) . ' to ' . substr((string) $span->b, 0, 10) . ')'
                    . ($salesDays < self::MIN_SALES_DAYS ? ' — need at least ' . self::MIN_SALES_DAYS : ($salesDays < self::GOOD_SALES_DAYS ? ' — works; 90+ days sharpens it' : '')) : 'No sales yet.'],
            ['key' => 'inventory', 'type' => Import::TYPE_INVENTORY, 'level' => 'required', 'done' => (int) $inv->n > 0,
                'title' => 'Load current stock', 'why' => 'Stock-outs, phantom stock, overstock and shrink all start from what is on hand.',
                'detail' => (int) $inv->n ? number_format($inv->n) . ' stock positions, latest ' . substr((string) $inv->latest, 0, 10) : 'No stock yet.'],
            ['key' => 'purchase_orders', 'type' => Import::TYPE_PURCHASE_ORDERS, 'level' => 'recommended', 'done' => $pos > 0,
                'title' => 'Add purchase orders', 'why' => 'Explains stock-outs by late or short supplier deliveries — the most common root cause.',
                'detail' => $pos ? number_format($pos) . ' PO lines' : 'Not loaded — supplier findings are off.'],
            ['key' => 'promotions', 'type' => Import::TYPE_PROMOTIONS, 'level' => 'recommended', 'done' => $promos > 0,
                'title' => 'Add your promotion calendar', 'why' => 'So a promotion spike, and the dip after it, are not reported as anomalies.',
                'detail' => $promos ? 'Promotions known' : 'Not loaded — promotions may be flagged as demand swings.'],
            ['key' => 'returns', 'type' => Import::TYPE_RETURNS, 'level' => 'optional', 'done' => $returns > 0,
                'title' => 'Add returns', 'why' => 'Return-rate spikes point at quality or listing problems.',
                'detail' => $returns ? number_format($returns) . ' returns' : 'Optional.'],
            ['key' => 'detection', 'type' => null, 'level' => 'required', 'done' => $anomalies > 0,
                'title' => 'Run the first detection', 'why' => 'Runs every night on its own; run it now to see results today.',
                'detail' => $anomalies ? number_format($anomalies) . ' findings so far' : 'Not run yet.'],
            ['key' => 'investigate', 'type' => null, 'level' => 'required', 'done' => $worked > 0,
                'title' => 'Work your first investigation', 'why' => 'Assign it, start it, resolve it — that is what turns a finding into recovered money.',
                'detail' => $investigations ? number_format($investigations) . ' investigations, ' . $worked . ' being worked' : 'None yet.'],
            ['key' => 'team', 'type' => null, 'level' => 'recommended', 'done' => $users > 1 && ! empty($tenant?->notification_email),
                'title' => 'Invite your team and set the alert address', 'why' => 'Findings reach the people who can act on them.',
                'detail' => $users . ' user(s)' . (empty($tenant?->notification_email) ? '; no notification address yet (ask your Autnyx contact)' : '; alerts go to ' . $tenant->notification_email)],
        ];
    }

    /** @return array{done:int, total:int, required_left:int, pct:int} */
    public function progress(int $tenantId): array
    {
        $steps = $this->steps($tenantId);
        $done = count(array_filter($steps, fn ($s) => $s['done']));
        $requiredLeft = count(array_filter($steps, fn ($s) => $s['level'] === 'required' && ! $s['done']));

        return ['done' => $done, 'total' => count($steps), 'required_left' => $requiredLeft, 'pct' => (int) round(100 * $done / max(1, count($steps)))];
    }

    /** A CSV header row a file can be filled into: the canonical field names, required first. */
    public static function template(string $type): string
    {
        $schema = \App\Services\Import\CanonicalSchema::forType($type);
        uasort($schema, fn ($a, $b) => (int) ($b['required'] ?? false) <=> (int) ($a['required'] ?? false));

        return implode(',', array_keys($schema)) . "\n";
    }
}
