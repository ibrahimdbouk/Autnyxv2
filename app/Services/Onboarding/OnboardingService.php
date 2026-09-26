<?php

namespace App\Services\Onboarding;

use App\Models\Import;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
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
     * Platform core: setup in sections — what every app needs (the platform:
     * stores, structure, people, products, alerts), then one section per app
     * the tenant has. A Task-Execution-only tenant never sees data feeds; a
     * Root-Cause tenant sees them under Root Cause.
     *
     * @return array<int,array{key:string, title:string, intro:string, steps:array<int,array{key:string, title:string, why:string, level:string, done:bool, detail:string, type:?string}>}>
     */
    public function sections(int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        $rootCause = $tenant?->hasApp(Tenant::APP_ROOT_CAUSE) ?? true;
        $tasks = $tenant?->hasApp(Tenant::APP_TASK_EXECUTION) ?? false;

        $sections = [[
            'key' => 'platform', 'title' => 'Your organisation',
            'intro' => 'Shared by every Autnyx app: set it up once.',
            'steps' => $this->platformSteps($tenantId, $tenant, $rootCause),
        ]];
        if ($rootCause) {
            $sections[] = ['key' => Tenant::APP_ROOT_CAUSE, 'title' => Tenant::APP_LABELS[Tenant::APP_ROOT_CAUSE],
                'intro' => 'Your data feeds, then the first detection run and the first investigation.',
                'steps' => $this->rootCauseSteps($tenantId)];
        }
        if ($tasks) {
            $sections[] = ['key' => Tenant::APP_TASK_EXECUTION, 'title' => Tenant::APP_LABELS[Tenant::APP_TASK_EXECUTION],
                'intro' => 'Where work happens in each store, and who does it.',
                'steps' => $this->taskSteps($tenantId)];
        }

        return $sections;
    }

    /**
     * Every step of every section, in order.
     *
     * @return array<int,array{key:string, title:string, why:string, level:string, done:bool, detail:string, type:?string}>
     */
    public function steps(int $tenantId): array
    {
        return array_merge(...array_map(fn ($s) => $s['steps'], $this->sections($tenantId)));
    }

    private function exists(string $table, int $tenantId, ?\Closure $where = null): bool
    {
        $q = DB::table($table)->where('tenant_id', $tenantId);

        return ($where ? $where($q) : $q)->exists();
    }

    private function platformSteps(int $tenantId, ?Tenant $tenant, bool $rootCause): array
    {
        $stores = (int) DB::table('stores')->where('tenant_id', $tenantId)->count();
        $withRegion = (int) DB::table('stores')->where('tenant_id', $tenantId)->whereNotNull('region')->where('region', '<>', '')->count();
        $managedUnits = (int) DB::table('location_node_managers')->where('tenant_id', $tenantId)->distinct()->count('location_node_id');
        $users = (int) DB::table('users')->where('tenant_id', $tenantId)->whereNull('deactivated_at')->count();
        $linked = (int) DB::table('store_user')->where('tenant_id', $tenantId)->distinct()->count('user_id');
        $products = $this->exists('products', $tenantId);

        return [
            ['key' => 'stores', 'type' => Import::TYPE_STORES, 'level' => 'required', 'done' => $stores > 0,
                'title' => 'Load your stores', 'why' => 'Everything is tied to a store: findings, work, people. Upload the store file or add them one by one (Data → Stores).',
                'detail' => $stores ? number_format($stores) . ' stores' : 'No stores yet.'],
            ['key' => 'structure', 'type' => null, 'level' => 'recommended', 'done' => $stores > 0 && $withRegion === $stores && $managedUnits > 0,
                'title' => 'Set up regions, areas and their managers',
                'why' => 'Give each store a Region (and optionally an Area); then name who manages each one. That is who sees those stores and who problems escalate to.',
                'detail' => $stores ? "{$withRegion} of {$stores} stores have a region; {$managedUnits} region(s) / area(s) have a manager" : 'Load stores first.'],
            ['key' => 'people', 'type' => null, 'level' => 'required', 'done' => $users > 1 && $linked > 0,
                'title' => 'Invite your people with their position and stores',
                'why' => 'Each person gets a position (head office, area manager, store manager, associate) and the stores they work in: that decides what they see and what reaches them. Add them in Users, or upload a users file with Role and Stores columns.',
                'detail' => $users . ' active user(s), ' . $linked . ' linked to stores'],
            ['key' => 'products', 'type' => Import::TYPE_PRODUCTS, 'level' => $rootCause ? 'required' : 'recommended', 'done' => $products,
                'title' => 'Load your product master',
                'why' => $rootCause ? 'Prices and costs turn units into money at risk; categories and departments group the findings.'
                    : 'Lets work point at a product and be checked by scanning its barcode.',
                'detail' => $products ? number_format((int) DB::table('products')->where('tenant_id', $tenantId)->count()) . ' products' : 'No products yet.'],
            ['key' => 'alerts', 'type' => null, 'level' => 'recommended', 'done' => ! empty($tenant?->notification_email),
                'title' => 'Set the company alert address',
                'why' => 'Where company-wide alerts and the monthly report go (ask your Autnyx contact).',
                'detail' => empty($tenant?->notification_email) ? 'Not set.' : 'Alerts go to ' . $tenant->notification_email],
        ];
    }

    private function rootCauseSteps(int $tenantId): array
    {
        $span = DB::selectOne('SELECT MIN(date) AS a, MAX(date) AS b FROM sales_daily WHERE tenant_id = ?', [$tenantId]);
        // Days spanned by the sales history (read from the (tenant, date) index, not a scan of every day).
        $salesDays = $span && $span->a ? (int) Carbon::parse($span->a)->diffInDays(Carbon::parse($span->b)) + 1 : 0;
        $invLatest = DB::table('inventory_current')->where('tenant_id', $tenantId)->max('as_of_date');
        $inv = $invLatest !== null;
        $pos = $this->exists('purchase_orders', $tenantId);
        $promos = $this->exists('promotions', $tenantId) || $this->exists('sales_transactions', $tenantId, fn ($q) => $q->whereNotNull('promotion_ref'));
        $returns = $this->exists('sales_returns', $tenantId);
        $waste = $this->exists('waste_events', $tenantId);
        $anomalies = $this->exists('anomalies', $tenantId);
        $investigations = $this->exists('investigations', $tenantId);
        $worked = $this->exists('investigations', $tenantId, fn ($q) => $q->where(fn ($w) => $w->whereIn('status', ['in_progress', 'resolved', 'closed'])->orWhereNotNull('assigned_user_id')));

        return [
            ['key' => 'sales', 'type' => Import::TYPE_SALES, 'level' => 'required', 'done' => $salesDays >= self::MIN_SALES_DAYS,
                'title' => 'Load sales history', 'why' => 'Detection compares each week with the weeks before it: at least 5 weeks, ideally 13 or more.',
                'detail' => $salesDays ? "{$salesDays} days of sales (" . substr((string) $span->a, 0, 10) . ' to ' . substr((string) $span->b, 0, 10) . ')'
                    . ($salesDays < self::MIN_SALES_DAYS ? ' — need at least ' . self::MIN_SALES_DAYS : ($salesDays < self::GOOD_SALES_DAYS ? ' — works; 90+ days sharpens it' : '')) : 'No sales yet.'],
            ['key' => 'inventory', 'type' => Import::TYPE_INVENTORY, 'level' => 'required', 'done' => $inv,
                'title' => 'Load current stock', 'why' => 'Stock-outs, phantom stock, overstock and shrink all start from what is on hand.',
                'detail' => $inv ? 'Stock loaded, latest ' . substr((string) $invLatest, 0, 10) : 'No stock yet.'],
            ['key' => 'purchase_orders', 'type' => Import::TYPE_PURCHASE_ORDERS, 'level' => 'recommended', 'done' => $pos,
                'title' => 'Add purchase orders', 'why' => 'Explains stock-outs by late or short supplier deliveries — the most common root cause.',
                'detail' => $pos ? 'Purchase orders loaded' : 'Not loaded — supplier findings are off.'],
            ['key' => 'promotions', 'type' => Import::TYPE_PROMOTIONS, 'level' => 'recommended', 'done' => $promos,
                'title' => 'Add your promotion calendar', 'why' => 'So a promotion spike, and the dip after it, are not reported as anomalies.',
                'detail' => $promos ? 'Promotions known' : 'Not loaded — promotions may be flagged as demand swings.'],
            ['key' => 'returns', 'type' => Import::TYPE_RETURNS, 'level' => 'optional', 'done' => $returns,
                'title' => 'Add returns', 'why' => 'Return-rate spikes point at quality or listing problems.',
                'detail' => $returns ? 'Returns loaded' : 'Optional.'],
            ['key' => 'waste', 'type' => Import::TYPE_WASTE, 'level' => 'optional', 'done' => $waste,
                'title' => 'Add waste and write-offs', 'why' => 'For fresh and short-life ranges: waste rates by store and item, and what is about to expire.',
                'detail' => $waste ? 'Waste loaded' : 'Optional — needed for the Fresh & Expiry view.'],
            ['key' => 'detection', 'type' => null, 'level' => 'required', 'done' => $anomalies,
                'title' => 'Run the first detection', 'why' => 'Runs every night on its own; run it now to see results today.',
                'detail' => $anomalies ? 'Detection has run' : 'Not run yet.'],
            ['key' => 'investigate', 'type' => null, 'level' => 'required', 'done' => $worked,
                'title' => 'Work your first investigation', 'why' => 'Assign it, start it, resolve it — that is what turns a finding into recovered money.',
                'detail' => $worked ? 'Investigations are being worked' : ($investigations ? 'Investigations are waiting' : 'None yet.')],
        ];
    }

    private function taskSteps(int $tenantId): array
    {
        $departments = $this->exists('departments', $tenantId);
        $zones = (int) DB::table('store_zones')->where('tenant_id', $tenantId)->distinct()->count('store_id');
        $stores = (int) DB::table('stores')->where('tenant_id', $tenantId)->count();
        $located = (int) DB::table('stores')->where('tenant_id', $tenantId)->whereNotNull('latitude')->whereNotNull('longitude')->count();
        $storeManagers = (int) DB::table('users')->where('users.tenant_id', $tenantId)->whereNull('deactivated_at')
            ->where('org_role', \App\Models\User::ORG_STORE_MANAGER)
            ->whereExists(fn ($q) => $q->from('store_user')->whereColumn('store_user.user_id', 'users.id'))->count();

        return [
            ['key' => 'store_managers', 'type' => null, 'level' => 'required', 'done' => $storeManagers > 0,
                'title' => 'Name a store manager for each store', 'why' => 'Store managers check and approve the work done in their store, and are the first stop when something fails.',
                'detail' => $storeManagers . ' store manager(s) linked to stores'],
            ['key' => 'coordinates', 'type' => Import::TYPE_STORES, 'level' => 'recommended', 'done' => $stores > 0 && $located === $stores,
                'title' => 'Add each store\'s location', 'why' => 'Latitude and longitude let on-site work be confirmed as done at the store.',
                'detail' => $stores ? "{$located} of {$stores} stores have coordinates" : 'Load stores first.'],
            ['key' => 'departments', 'type' => null, 'level' => 'recommended', 'done' => $departments,
                'title' => 'Set up departments', 'why' => 'Work is grouped and routed by department (Grocery, Fresh, Beverages…). A product file with a Department column fills them in.',
                'detail' => $departments ? 'Departments set up' : 'None yet.'],
            ['key' => 'zones', 'type' => null, 'level' => 'optional', 'done' => $zones > 0,
                'title' => 'Map the zones in your stores', 'why' => 'Aisles, endcaps, displays, chillers: so work can point at the exact place. Set up one store, then copy it to the others.',
                'detail' => $zones ? "{$zones} store(s) have zones" : 'Optional.'],
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

    /** Required steps left, cached briefly: the navigation badge reads this on every page. */
    public function requiredLeftCached(int $tenantId): int
    {
        return (int) \Illuminate\Support\Facades\Cache::remember("onboarding:{$tenantId}:required_left", 300,
            fn () => $this->progress($tenantId)['required_left']);
    }

    /** A CSV header row a file can be filled into: the canonical field names, required first. */
    public static function template(string $type): string
    {
        $schema = \App\Services\Import\CanonicalSchema::forType($type);
        uasort($schema, fn ($a, $b) => (int) ($b['required'] ?? false) <=> (int) ($a['required'] ?? false));

        return implode(',', array_keys($schema)) . "\n";
    }
}
