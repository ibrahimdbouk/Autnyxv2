<?php

namespace Tests\Feature;

use App\Filament\Pages\ActionCenter;
use App\Filament\Pages\ActionQueue;
use App\Filament\Pages\WatchedInvestigations;
use App\Filament\Resources\InvestigationResource\Pages\ListInvestigations;
use App\Filament\Resources\TenantResource;
use App\Models\Action;
use App\Models\AgentRun;
use App\Models\Anomaly;
use App\Models\ApiKey;
use App\Models\Import;
use App\Models\InventoryLevel;
use App\Models\Investigation;
use App\Models\SalesTransaction;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP2.5 (audit: cross-tenant regression net).
 *
 * Generic, route-driven checks — a new resource, record page or panel page is
 * covered automatically the moment it is registered. A model with no fixture
 * FAILS the suite (add one to fixtureAttributes()) rather than being skipped.
 *
 *  1. Every record-bound admin route, opened by tenant B's admin with tenant A's
 *     record, answers 403/404 — and the same URL works for A (positive control).
 *  2. Every tenant-scoped admin route, opened by B's admin under A's tenant slug,
 *     answers 403/404.
 *  3. Every resource table query, built in B's panel, never contains A's rows.
 *  4. Livewire method tampering: every public method that takes an id, called by
 *     B with A's id, changes nothing and reveals nothing.
 */
class CrossTenantMatrixTest extends TestCase
{
    private Tenant $a;
    private Tenant $b;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createTenant(['name' => 'Tenant A']);
        $this->b = $this->createTenant(['name' => 'Tenant B']);
        $this->adminA = $this->createUser($this->a, admin: true);
        $this->adminB = $this->createUser($this->b, admin: true);
    }

    private function inPanel(Tenant $tenant): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($tenant);
        $panel->boot();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @var array<string, array<int, Model>> */
    private array $fixtures = [];

    /** Per-model attributes; any other NOT NULL column is filled generically. */
    private function fixtureAttributes(string $model, Tenant $t): array
    {
        $tag = 'XT-' . $t->id;

        return match ($model) {
            \App\Models\AnomalySetting::class => ['rule_type' => 'sales_drop', 'enabled' => true],
            \App\Models\ApiConnection::class  => ['name' => "Conn $tag", 'provider' => 'generic', 'base_url' => 'https://api.example.com', 'auth_type' => 'bearer', 'auth_config' => ['token' => 't'], 'is_active' => true, 'status' => 'never'],
            \App\Models\CleansingRule::class  => ['data_type' => 'sales', 'field' => 'sku', 'rule_type' => 'trim'],
            \App\Models\EntityAlias::class    => ['entity_type' => 'store', 'alias' => "alias-$tag", 'canonical' => "canon-$tag"],
            \App\Models\Import::class         => ['original_filename' => "$tag.csv", 'disk' => 'local', 'path' => "$tag.csv", 'data_type' => 'sales', 'status' => Import::STATUS_COMPLETED],
            \App\Models\SftpConnection::class => ['name' => "Sftp $tag", 'host' => 'sftp.example.com', 'port' => 22, 'username' => 'u', 'auth_type' => 'password', 'password' => 'p', 'base_path' => '/in'],
            \App\Models\SsoConnection::class  => ['enabled' => false, 'issuer' => 'https://idp.example.com', 'client_id' => 'c', 'client_secret' => 's', 'allowed_domains' => []],
            \App\Models\StoreCluster::class   => ['method' => 'attribute', 'objective' => 'general', 'key' => "k-$tag", 'label' => "Cluster $tag", 'params' => []],
            \App\Models\Suppression::class    => ['scope_type' => 'rule_sku', 'rule_type' => 'sales_drop', 'sku' => "SKU-$tag", 'reason' => 'known_issue', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay(), 'active' => true],
            \App\Models\Team::class           => ['name' => "Team $tag"],
            \App\Models\FxRate::class         => ['currency' => 'EUR', 'valid_from' => now()->toDateString(), 'rate' => 4.1],
            \App\Models\RetailEvent::class    => ['key' => 'own_xt', 'year' => 2026, 'name' => "Event $tag", 'kind' => 'custom', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-05', 'source' => 'tenant'],
            \App\Models\TeamsConnection::class => ['channel_webhook_url' => 'https://acme.webhook.office.com/webhookb2/x', 'post_to_channel' => false, 'notify_users' => false, 'is_active' => false],
            \App\Models\Store::class          => ['name' => "Store $tag", 'code' => "S-$tag"],
            \App\Models\LocationNode::class   => ['type' => 'region', 'name' => "Region $tag"],
            \App\Models\Department::class     => ['name' => "Department $tag"],
            \App\Models\Supplier::class       => ['name' => "Supplier $tag"],
            \App\Models\Product::class        => ['sku' => "SKU-$tag", 'name' => "Product $tag"],
            \App\Models\PurchaseOrder::class  => ['sku' => "SKU-$tag", 'po_number' => "PO-$tag", 'supplier' => 'S', 'qty_ordered' => 1, 'order_date' => now()->toDateString()],
            \App\Models\SalesReturn::class    => ['sku' => "SKU-$tag", 'date' => now()->toDateString(), 'quantity' => 1],
            \App\Models\SkuReplenishment::class => ['sku' => "SKU-$tag", 'store_id' => 0, 'reorder_point' => 1, 'source' => 'computed', 'computed_at' => now()],
            \App\Models\AuditLog::class       => ['event_type' => 'test', 'description' => "Audit $tag"],
            \App\Models\ApiKey::class         => ['name' => "Key $tag", 'prefix' => substr(md5($tag), 0, 8), 'key_hash' => hash('sha256', $tag)],
            default => [],
        };
    }

    private function fixture(string $model, Tenant $t): Model
    {
        if (isset($this->fixtures[$model][$t->id])) {
            return $this->fixtures[$model][$t->id];
        }

        // Model hooks (and Filament's tenancy listener) stamp new rows with the
        // *current* panel tenant — create each fixture inside its own tenant.
        $current = Filament::getTenant();
        Filament::setTenant($t, isQuiet: true);
        try {
            $record = $this->makeFixture($model, $t);
        } finally {
            Filament::setTenant($current, isQuiet: true);
        }
        $this->assertSame((int) $t->id, (int) ($record->tenant_id ?? $t->id), "Fixture {$model} landed in the wrong tenant.");

        return $this->fixtures[$model][$t->id] = $record;
    }

    private function makeFixture(string $model, Tenant $t): Model
    {
        $record = match ($model) {
            User::class            => $this->createUser($t),
            Anomaly::class         => Anomaly::factory()->create(['tenant_id' => $t->id, 'sku' => 'SKU-XT-' . $t->id]),
            Investigation::class   => Investigation::factory()->create(['tenant_id' => $t->id, 'title' => 'Investigation XT-' . $t->id, 'status' => Investigation::STATUS_OPEN]),
            InventoryLevel::class  => InventoryLevel::factory()->create(['tenant_id' => $t->id]),
            SalesTransaction::class => SalesTransaction::factory()->create(['tenant_id' => $t->id]),
            default                => $this->genericRecord($model, $t),
        };

        if ($model === \App\Models\TeamsConnection::class) {
            $record->forceFill(['aad_tenant_id' => 'aad-' . $t->id, 'aad_verified_at' => now()])->save();
        }

        return $record;
    }

    private function genericRecord(string $model, Tenant $t): Model
    {
        /** @var Model $instance */
        $instance = new $model;
        $table = $instance->getTable();
        $attrs = ['tenant_id' => $t->id] + $this->fixtureAttributes($model, $t);

        $required = DB::select(
            "select column_name, data_type from information_schema.columns
             where table_schema = current_schema() and table_name = ? and is_nullable = 'NO' and column_default is null",
            [$table]
        );
        foreach ($required as $col) {
            $name = $col->column_name;
            if (array_key_exists($name, $attrs) || $name === $instance->getKeyName()) {
                continue;
            }
            $attrs[$name] = match (true) {
                $name === 'import_id'        => $this->fixture(Import::class, $t)->id,
                $name === 'investigation_id' => $this->fixture(Investigation::class, $t)->id,
                $name === 'user_id'          => $this->adminFor($t)->id,
                str_contains($col->data_type, 'int'), str_contains($col->data_type, 'numeric'), str_contains($col->data_type, 'double') => 1,
                $col->data_type === 'boolean' => false,
                str_contains($col->data_type, 'timestamp') => now(),
                $col->data_type === 'date'   => now()->toDateString(),
                str_contains($col->data_type, 'json') => '{}',
                default => 'xt-' . $t->id . '-' . Str::random(4),
            };
        }

        $record = $instance->newInstance();
        $record->forceFill($attrs);
        $model::withoutEvents(fn () => $record->save());

        return $record->fresh() ?? $record;
    }

    /**
     * Each production request starts with no Filament tenant. The test app is
     * shared between requests, so clear the previous request's tenant first —
     * otherwise implicit route binding runs under a stale tenant scope and the
     * results would not reflect production.
     */
    private function visit(string $url): array
    {
        Filament::setTenant(null, isQuiet: true);
        $resp = $this->get($url);

        return [$resp->baseResponse->getStatusCode(), $resp->exception];
    }

    private function adminFor(Tenant $t): User
    {
        return $t->is($this->a) ? $this->adminA : $this->adminB;
    }

    // ── 1. record-bound routes ───────────────────────────────────────────────

    /** @return array<int, array{resource: class-string, page: string}> */
    private function recordRoutes(): array
    {
        $out = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if ($resource === TenantResource::class) {
                continue; // super-admin only; tenant admins get 403 (SmokeTest)
            }
            $slug = $resource::getSlug();
            foreach (array_keys($resource::getPages()) as $page) {
                $route = Route::getRoutes()->getByName("filament.admin.resources.{$slug}.{$page}");
                if ($route && str_contains($route->uri(), '{record}')) {
                    $out[] = ['resource' => $resource, 'page' => $page];
                }
            }
        }

        return $out;
    }

    public function test_record_routes_never_open_another_tenants_record(): void
    {
        $routes = $this->recordRoutes();
        $this->assertGreaterThan(10, count($routes), 'Route discovery found suspiciously few record routes.');

        $failures = [];
        foreach ($routes as ['resource' => $resource, 'page' => $page]) {
            $recordA = $this->fixture($resource::getModel(), $this->a);

            // Positive control: tenant A's own admin can open it (so a 404 below
            // really is the tenancy guard, not a broken fixture or route).
            $this->actingAs($this->adminA);
            [$own, $e] = $this->visit($resource::getUrl($page, ['record' => $recordA, 'tenant' => $this->a]));
            if (! in_array($own, [200, 302], true)) {
                $failures[] = "$resource::$page — positive control returned HTTP $own for tenant A" . ($e ? ' [' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']' : '');
                continue;
            }

            // Tenant B's admin, inside B's panel, with A's record id.
            $this->actingAs($this->adminB);
            $url = $resource::getUrl($page, ['record' => $recordA, 'tenant' => $this->b]);
            [$status] = $this->visit($url);
            if (! in_array($status, [403, 404], true)) {
                $failures[] = "$resource::$page — tenant B got HTTP $status for tenant A's record ($url)";
            }

            // Tenant B's admin, under A's tenant slug.
            $url = $resource::getUrl($page, ['record' => $recordA, 'tenant' => $this->a]);
            [$status] = $this->visit($url);
            if (! in_array($status, [403, 404], true)) {
                $failures[] = "$resource::$page — tenant B got HTTP $status under tenant A's slug ($url)";
            }
        }

        $this->assertSame([], $failures, "Cross-tenant record access:\n" . implode("\n", $failures));
    }

    // ── 2. every tenant-scoped route under a foreign tenant slug ─────────────

    public function test_no_admin_route_opens_under_a_tenant_the_user_does_not_belong_to(): void
    {
        $this->actingAs($this->adminB);
        $failures = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'filament.admin.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (! str_contains($route->uri(), '{tenant}') || str_contains($route->uri(), '{record}')) {
                continue; // record routes are covered above
            }
            $url = url(str_replace('{tenant}', $this->a->slug, $route->uri()));
            [$status] = $this->visit($url);
            $checked++;
            if (! in_array($status, [403, 404], true)) {
                $failures[] = "$name — HTTP $status ($url)";
            }
        }

        $this->assertGreaterThan(40, $checked, 'Route discovery found suspiciously few tenant routes.');
        $this->assertSame([], $failures, "Tenant B reached tenant A's panel:\n" . implode("\n", $failures));
    }

    // ── 3. table queries ─────────────────────────────────────────────────────

    public function test_resource_queries_never_include_another_tenants_rows(): void
    {
        $this->actingAs($this->adminB);
        $this->inPanel($this->b);
        $failures = [];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if ($resource === TenantResource::class) {
                continue;
            }
            $model = $resource::getModel();
            $recordA = $this->fixture($model, $this->a);
            $recordB = $this->fixture($model, $this->b);

            $ids = $resource::getEloquentQuery()->pluck((new $model)->getQualifiedKeyName())->all();
            if (in_array($recordA->getKey(), $ids)) {
                $failures[] = "$resource — getEloquentQuery() in tenant B includes tenant A's {$model} #{$recordA->getKey()}";
            }
            if ($model !== User::class && ! in_array($recordB->getKey(), $ids)) {
                // Positive control: the query is not simply empty. (Users: B's
                // own fixture is the admin itself, filtered by some resources.)
                $failures[] = "$resource — positive control: tenant B's own {$model} #{$recordB->getKey()} missing";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    // ── 4. Livewire method tampering ─────────────────────────────────────────

    private function actionFor(Tenant $t, string $title): Action
    {
        return Action::create([
            'investigation_id' => $this->fixture(Investigation::class, $t)->id,
            'action_type' => Action::TYPE_REORDER, 'title' => $title,
            'status' => Action::STATUS_ASSIGNED, 'priority' => Action::PRIORITY_HIGH, 'due_at' => now()->addDay(),
        ]);
    }

    public function test_action_center_methods_ignore_another_tenants_action(): void
    {
        $foreign = $this->actionFor($this->a, 'Tenant A confidential action');
        $before = $foreign->fresh()->only(['status', 'completion_notes', 'assigned_to', 'assigned_team_id', 'priority']);
        // W11: the finding behind it must not take tenant B's feedback either.
        $foreignAnomaly = \App\Models\Anomaly::create(['tenant_id' => $this->a->id, 'investigation_id' => $foreign->investigation_id,
            'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => 'XA', 'description' => 'x', 'detected_at' => now()]);

        $this->actingAs($this->adminB);
        $this->inPanel($this->b);

        $lw = Livewire::test(ActionCenter::class)
            ->call('selectAction', $foreign->id)
            ->call('markInProgress', $foreign->id)
            ->call('markAcknowledged', $foreign->id)
            ->call('cancelAction', $foreign->id)
            ->call('feedbackOnAction', $foreign->id, 'not_real')
            ->call('openCompleteModal', $foreign->id)
            ->set('completionNotes', 'hijacked')
            ->call('confirmComplete')
            ->set('selectedActionId', $foreign->id);

        $this->assertNull($lw->instance()->getSelectedAction());
        $lw->assertDontSee('Tenant A confidential action');

        // Bulk paths with a tampered selection.
        $lw->set('selectedActions', [$foreign->id])
            ->set('bulkAssigneeId', $this->adminB->id)->call('bulkAssignActions')
            ->set('selectedActions', [$foreign->id])
            ->set('bulkPriorityAc', 'low')->call('bulkChangeActionPriority')
            ->set('selectedActions', [$foreign->id])
            ->set('bulkTeamIdAc', $this->fixture(Team::class, $this->b)->id)->call('bulkReassignActionTeam')
            ->set('selectedActions', [$foreign->id])
            ->call('bulkEscalateActions');

        $this->assertSame($before, $foreign->fresh()->only(array_keys($before)));
        $this->assertNull($foreignAnomaly->fresh()->feedback);
        $this->assertNull($foreignAnomaly->fresh()->dismissed_at);
    }

    public function test_investigation_list_methods_ignore_another_tenants_investigation(): void
    {
        $foreign = $this->fixture(Investigation::class, $this->a);
        $teamB = $this->fixture(Team::class, $this->b);
        $before = $foreign->fresh()->only(['status', 'priority', 'assigned_team_id', 'assigned_user_id', 'snoozed_until']);

        $this->actingAs($this->adminB);
        $this->inPanel($this->b);

        $lw = Livewire::test(ListInvestigations::class)
            ->call('selectInvestigation', $foreign->id);
        $this->assertNull($lw->instance()->getSelectedInvestigation());
        $lw->set('selectedInvestigationId', $foreign->id);
        $this->assertNull($lw->instance()->getSelectedInvestigation());
        $lw->assertDontSee($foreign->title);

        foreach ([
            fn ($c) => $c->call('bulkAssignToMe'),
            fn ($c) => $c->set('bulkTeamId', $teamB->id)->call('bulkReassignTeam'),
            fn ($c) => $c->set('bulkPriority', 'low')->call('bulkChangePriority'),
            fn ($c) => $c->call('bulkSnooze'),
            fn ($c) => $c->call('bulkDismiss'),
        ] as $step) {
            $step($lw->call('toggleSelected', $foreign->id));
        }

        $this->assertSame($before, $foreign->fresh()->only(array_keys($before)));

        // Export with a tampered selection contains nothing of tenant A.
        $csv = $this->streamed($lw->instance()->bulkExport(...), [$foreign->id], $lw);
        $this->assertStringNotContainsString($foreign->title, $csv);
    }

    private function streamed(callable $export, array $selected, $lw): string
    {
        $lw->instance()->selected = $selected;
        ob_start();
        $export()->sendContent();

        return (string) ob_get_clean();
    }

    public function test_action_queue_plan_methods_ignore_another_tenants_run(): void
    {
        $campaign = array_key_first(ActionQueue::campaignsMap());
        $this->assertNotNull($campaign);

        $run = AgentRun::create([
            'tenant_id' => $this->a->id, 'agent_key' => AgentRun::KEY_CAMPAIGN_PLAN,
            'subject_type' => 'campaign', 'subject_id' => $campaign, 'title' => 'Tenant A plan',
            'status' => AgentRun::STATUS_PROPOSED, 'input' => [], 'output' => ['po_draft' => ['lines' => [['sku' => 'SECRET-SKU']]]],
        ]);

        $this->actingAs($this->adminB);
        $this->inPanel($this->b);

        $lw = Livewire::test(ActionQueue::class)
            ->set('campaign', $campaign)
            ->call('dismissPlan', $run->id)
            ->call('acceptPlan', $run->id);

        $this->assertNull($lw->instance()->downloadPoDraft($run->id));
        $this->assertSame(AgentRun::STATUS_PROPOSED, $run->fresh()->status);
    }

    public function test_unwatch_and_import_row_methods_are_scoped(): void
    {
        $foreign = $this->fixture(Investigation::class, $this->a);
        app(\App\Services\Watch\WatchService::class)->watchForUser($foreign, $this->adminA->id);

        $this->actingAs($this->adminB);
        $this->inPanel($this->b);
        Livewire::test(WatchedInvestigations::class)->call('unwatch', $foreign->id);

        $this->assertTrue(\App\Models\InvestigationWatch::where('investigation_id', $foreign->id)
            ->where('user_id', $this->adminA->id)->where('active', true)->exists());
    }

    public function test_record_property_of_a_resource_page_cannot_be_swapped(): void
    {
        $own = $this->fixture(Team::class, $this->b);
        $foreign = $this->fixture(Team::class, $this->a);

        $this->actingAs($this->adminB);
        $this->inPanel($this->b);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(\App\Filament\Resources\TeamResource\Pages\EditTeam::class, ['record' => $own->getKey()])
            ->set('record', $foreign->getKey());
    }

    public function test_table_actions_cannot_target_another_tenants_row(): void
    {
        $foreign = $this->fixture(Team::class, $this->a);

        $this->actingAs($this->adminB);
        $this->inPanel($this->b);

        try {
            Livewire::test(\App\Filament\Resources\TeamResource\Pages\ListTeams::class)
                ->callTableAction('delete', $foreign->getKey());
        } catch (\Throwable) {
            // Filament refuses to resolve a record outside the table query.
        }

        $this->assertNotNull(Team::withoutGlobalScopes()->find($foreign->getKey()));
    }

    public function test_paging_state_cannot_be_tampered_into_a_heavy_or_broken_query(): void
    {
        $this->actingAs($this->adminB);
        $this->inPanel($this->b);

        foreach ([ListInvestigations::class, ActionCenter::class] as $page) {
            $lw = Livewire::test($page)->set('currentPage', -5)->assertOk();

            try {
                $lw->set('perPage', 10_000_000);
                $this->fail("$page::perPage must be locked.");
            } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
                $this->assertSame(25, $lw->get('perPage'));
            }
        }
    }

    // ── controllers and API ──────────────────────────────────────────────────

    public function test_pdf_and_api_endpoints_never_serve_another_tenants_record(): void
    {
        $anomaly = $this->fixture(Anomaly::class, $this->a);
        $investigation = $this->fixture(Investigation::class, $this->a);
        $import = $this->fixture(Import::class, $this->a);

        $this->actingAs($this->adminB);
        $this->assertContains($this->get(route('anomaly.report.pdf', $anomaly->id))->baseResponse->getStatusCode(), [403, 404]);
        $this->assertContains($this->get(route('investigation.report.pdf', $investigation->id))->baseResponse->getStatusCode(), [403, 404]);
        $this->assertContains($this->get(route('teams.consent.connect', $this->a))->baseResponse->getStatusCode(), [403, 404]);

        auth()->logout();
        [$plain] = $this->apiKeyFor($this->b);
        $headers = ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'];
        $this->getJson("/api/v1/anomalies/{$anomaly->id}", $headers)->assertNotFound();
        $this->getJson("/api/v1/investigations/{$investigation->id}", $headers)->assertNotFound();
        $this->getJson("/api/v1/imports/{$import->id}", $headers)->assertNotFound();
    }

    /** @return array{0: string, 1: ApiKey} */
    private function apiKeyFor(Tenant $t): array
    {
        [$key, $token] = ApiKey::generate($t->id, 'xt', [ApiKey::SCOPE_READ_ANOMALIES, ApiKey::SCOPE_READ_INVESTIGATIONS, ApiKey::SCOPE_WRITE_INGEST]);

        return [$token, $key];
    }
}
