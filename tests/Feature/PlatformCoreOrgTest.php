<?php

namespace Tests\Feature;

use App\Filament\Pages\GettingStarted;
use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\OrgUnitResource;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\RelationManagers\ZonesRelationManager;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\Department;
use App\Models\Import;
use App\Models\LocationNode;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreZone;
use App\Models\Tenant;
use App\Models\TenantNightlyRun;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Import\ColumnMappingService;
use App\Services\Import\ImportProcessorService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Org\DeviceRegistry;
use App\Services\Org\OrgDirectory;
use App\Services\Org\UserLifecycle;
use App\Services\Platform\HierarchySync;
use App\Services\Stores\StoreDigestService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Platform core — the organisation every app shares: region → area → store,
 * managers on regions and areas, the operating role ladder (separate from
 * admin rights), departments and store zones, on-site radius, working
 * language, leavers, push devices, and setup split into platform and apps.
 */
class PlatformCoreOrgTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant(['currency' => 'AED']);
    }

    private function store(string $name, string $code, ?string $region = null, ?string $area = null, array $attrs = []): Store
    {
        return Store::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'code' => $code, 'region' => $region, 'area' => $area] + $attrs);
    }

    private function sync(): void
    {
        app(HierarchySync::class)->syncTenant($this->tenant->id);
    }

    private function node(string $type, string $name): LocationNode
    {
        return LocationNode::where('tenant_id', $this->tenant->id)->where('type', $type)->where('name', $name)->sole();
    }

    private function user(?string $role = null, array $attrs = []): User
    {
        $u = $this->createUser($this->tenant);
        $u->forceFill(['org_role' => $role] + $attrs)->save();

        return $u->fresh();
    }

    private function asAdminInPanel(): User
    {
        $admin = $this->actingAsTenantAdmin($this->tenant);
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($this->tenant);
        $panel->boot();

        return $admin;
    }

    private function importFile(string $type, string $csv, array $map, User $by): Import
    {
        Storage::fake('local');
        Queue::fake();
        $path = 'imports/pending/o-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create(['tenant_id' => $this->tenant->id, 'user_id' => $by->id, 'original_filename' => "{$type}.csv", 'disk' => 'local', 'path' => $path,
            'data_type' => $type, 'status' => Import::STATUS_UPLOADED, 'total_rows' => substr_count($csv, "\n") - 1,
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, $type)]);
        foreach ($map as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 20);

        return $import->fresh();
    }

    // ── Structure ────────────────────────────────────────────────────────────

    public function test_the_store_tree_is_region_then_area_then_store_and_follows_edits(): void
    {
        $marina = $this->store('Marina', 'MAR', 'Dubai', 'New Dubai');
        $jbr = $this->store('JBR', 'JBR', ' dubai ', 'new  dubai');     // case and spacing do not split the tree
        $deira = $this->store('Deira', 'DEI', 'Dubai');                  // no area: hangs off the region
        $ajman = $this->store('Ajman', 'AJM', null, 'Ajman City');        // an area with no region sits at the top
        $this->sync();

        $dubai = $this->node('region', 'Dubai');
        $newDubai = $this->node('area', 'New Dubai');
        $this->assertSame($dubai->id, $newDubai->parent_id);
        $this->assertSame($newDubai->id, $marina->locationNode->parent_id);
        $this->assertSame($newDubai->id, $jbr->locationNode->parent_id);
        $this->assertSame($dubai->id, $deira->locationNode->parent_id);
        $this->assertNull($this->node('area', 'Ajman City')->parent_id);
        $this->assertSame(1, LocationNode::where('tenant_id', $this->tenant->id)->where('type', 'region')->count());
        $this->assertSame(3, OrgUnitResource::storeCount($dubai));

        // A manager keeps an empty area alive; an unmanaged empty one goes.
        $am = $this->user(User::ORG_AREA_MANAGER);
        $am->managedNodes()->attach($newDubai->id, ['tenant_id' => $this->tenant->id]);
        $marina->update(['area' => 'Old Dubai']);
        $jbr->update(['area' => 'Old Dubai']);
        $ajman->update(['area' => null, 'region' => 'Northern']);
        $this->sync();
        $this->assertSame($this->node('area', 'Old Dubai')->id, $marina->fresh()->locationNode->parent_id);
        $this->assertSame(0, OrgUnitResource::storeCount($newDubai->fresh()), 'still listed because someone manages it');
        $this->assertFalse(LocationNode::where('tenant_id', $this->tenant->id)->where('name', 'Ajman City')->exists());
        $this->assertSame($this->node('region', 'Northern')->id, $ajman->fresh()->locationNode->parent_id);
    }

    public function test_the_escalation_chain_is_store_area_region_head_office_and_skips_leavers(): void
    {
        $marina = $this->store('Marina', 'MAR', 'Dubai', 'New Dubai');
        $this->sync();
        $sm = $this->user(User::ORG_STORE_MANAGER);
        $legacy = $this->user(null);                 // linked before the role ladder: still the store's manager
        $assoc = $this->user(User::ORG_ASSOCIATE);   // never an escalation stop
        foreach ([$sm, $legacy, $assoc] as $u) {
            $u->stores()->attach($marina->id, ['tenant_id' => $this->tenant->id]);
        }
        $am = $this->user(User::ORG_AREA_MANAGER);
        $am->managedNodes()->attach($this->node('area', 'New Dubai')->id, ['tenant_id' => $this->tenant->id]);
        $rm = $this->user(User::ORG_AREA_MANAGER);
        $rm->managedNodes()->attach($this->node('region', 'Dubai')->id, ['tenant_id' => $this->tenant->id]);
        $hq = $this->user(User::ORG_HQ);
        $admin = $this->createUser($this->tenant, admin: true);

        $chain = app(OrgDirectory::class)->escalationChain($marina);
        $this->assertSame(['store', 'area', 'region', 'hq'], array_column($chain, 'level'));
        $this->assertEqualsCanonicalizing([$sm->id, $legacy->id], $chain[0]['users']->pluck('id')->all());
        $this->assertSame([$am->id], $chain[1]['users']->pluck('id')->all());
        $this->assertSame([$rm->id], $chain[2]['users']->pluck('id')->all());
        $this->assertSame([$hq->id], $chain[3]['users']->pluck('id')->all(), 'head office people, not the admins, when there are any');

        app(UserLifecycle::class)->deactivate($am->fresh());
        $hq->forceFill(['org_role' => null])->save();
        $chain = app(OrgDirectory::class)->escalationChain($marina);
        $this->assertSame(['store', 'region', 'hq'], array_column($chain, 'level'), 'a leaver drops out; an empty level is skipped');
        $this->assertContains($admin->id, $chain[2]['users']->pluck('id')->all(), 'no head-office role set: the admins');
    }

    public function test_which_stores_a_person_sees_follows_their_operating_role(): void
    {
        $marina = $this->store('Marina', 'MAR', 'Dubai', 'New Dubai');
        $jbr = $this->store('JBR', 'JBR', 'Dubai', 'New Dubai');
        $deira = $this->store('Deira', 'DEI', 'Dubai', 'Old Dubai');
        $sharjah = $this->store('Sharjah', 'SHJ', 'Northern');
        $this->sync();
        $dir = app(OrgDirectory::class);

        $this->assertNull($dir->storeScope($this->user(User::ORG_HQ)));
        $this->assertNull($dir->storeScope($this->createUser($this->tenant, admin: true)));

        $am = $this->user(User::ORG_AREA_MANAGER);
        $am->managedNodes()->attach($this->node('area', 'New Dubai')->id, ['tenant_id' => $this->tenant->id]);
        $am->stores()->attach($sharjah->id, ['tenant_id' => $this->tenant->id]);
        $this->assertEqualsCanonicalizing([$marina->id, $jbr->id, $sharjah->id], $dir->storeScope($am));
        $rm = $this->user(User::ORG_AREA_MANAGER);
        $rm->managedNodes()->attach($this->node('region', 'Dubai')->id, ['tenant_id' => $this->tenant->id]);
        $this->assertEqualsCanonicalizing([$marina->id, $jbr->id, $deira->id], $dir->storeScope($rm), 'a region covers every area under it');
        $this->assertSame([], $dir->storeScope($this->user(User::ORG_AREA_MANAGER)), 'managing nothing yet: sees nothing');

        $sm = $this->user(User::ORG_STORE_MANAGER);
        $sm->stores()->attach($deira->id, ['tenant_id' => $this->tenant->id]);
        $this->assertSame([$deira->id], $dir->storeScope($sm));
        $this->assertSame([], $dir->storeScope($this->user(User::ORG_ASSOCIATE)), 'a store role with no store sees none (it used to see all)');
        $this->assertNull($dir->storeScope($this->user(null)), 'no role set, no store: unchanged from before');
        $this->assertSame([$deira->id], $sm->storeScope(), 'the model delegates to the directory');
    }

    // ── Imports ──────────────────────────────────────────────────────────────

    public function test_a_role_cell_is_read_as_a_position_and_admin_rights_separately(): void
    {
        $p = fn (string $v) => ImportProcessorService::parseRole($v);
        $this->assertSame(['admin' => false, 'org' => User::ORG_STORE_MANAGER], $p('Manager'), '"Manager" never grants admin rights');
        $this->assertSame(['admin' => false, 'org' => User::ORG_STORE_MANAGER], $p('Store_Manager'));
        $this->assertSame(['admin' => false, 'org' => User::ORG_AREA_MANAGER], $p('Regional Manager'));
        $this->assertSame(['admin' => false, 'org' => User::ORG_HQ], $p('Head Office'));
        $this->assertSame(['admin' => false, 'org' => User::ORG_ASSOCIATE], $p('merchandiser'));
        $this->assertSame(['admin' => true, 'org' => null], $p('Tenant Admin'));
        $this->assertSame(['admin' => true, 'org' => User::ORG_HQ], $p('Admin; Head office'));
        $this->assertSame(['admin' => false, 'org' => null], $p(''));
        $this->assertNull($p('Wizard'));
        $this->assertSame('ar', ImportProcessorService::parseLocale('Arabic'));
        $this->assertSame('ur', ImportProcessorService::parseLocale('اردو'));
        $this->assertNull(ImportProcessorService::parseLocale('Klingon'));
    }

    public function test_the_users_file_sets_positions_languages_regions_and_never_unlinks_on_a_bad_store(): void
    {
        $marina = $this->store('Marina', 'MAR', 'Dubai', 'New Dubai');
        $mall = $this->store('Mall', '007', 'Dubai', 'Old Dubai');
        $admin = $this->createUser($this->tenant, admin: true);
        $omar = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'omar@example.com', 'is_tenant_admin' => true]);
        $omar->stores()->attach($mall->id, ['tenant_id' => $this->tenant->id]);
        $lina = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'lina@example.com']);
        $lina->stores()->attach($mall->id, ['tenant_id' => $this->tenant->id]);

        $csv = "Name,Email,Role,Stores,Language,Manages\n"
            . "Sara,sara@example.com,Manager,mar,Arabic,\n"                       // store manager, not admin
            . "Omar,omar@example.com,Store Manager,MAR; 7,English,\n"             // "7" is not 007: nothing removed
            . "Lina,lina@example.com,Associate,,Urdu,\n"                          // blank stores: unlinked on purpose
            . "Rami,rami@example.com,Area Manager,,Hindi,Dubai > New Dubai; Nowhere\n"
            . "Huda,huda@example.com,Wizard,MAR,Klingon,\n";                      // unknown role / language: warned, unchanged
        $import = $this->importFile(Import::TYPE_USERS, $csv, ['Name' => 'name', 'Email' => 'email', 'Role' => 'role',
            'Stores' => 'stores', 'Language' => 'language', 'Manages' => 'manages'], $admin);
        $this->assertSame(Import::STATUS_COMPLETED, $import->status, (string) $import->error_message);

        $sara = User::where('email', 'sara@example.com')->sole();
        $this->assertSame([User::ORG_STORE_MANAGER, false, 'ar'], [$sara->org_role, $sara->is_tenant_admin, $sara->locale]);
        $this->assertSame([$marina->id], $sara->stores()->pluck('stores.id')->all());

        $omar = $omar->fresh();
        $this->assertSame([User::ORG_STORE_MANAGER, false], [$omar->org_role, $omar->is_tenant_admin], 'the role column is the truth for admin rights');
        $this->assertEqualsCanonicalizing([$marina->id, $mall->id], $omar->stores()->pluck('stores.id')->all(), 'a bad code adds the good ones and removes nothing');

        $this->assertSame([], $lina->fresh()->stores()->pluck('stores.id')->all());
        $this->assertSame('ur', $lina->fresh()->locale);

        $rami = User::where('email', 'rami@example.com')->sole();
        $this->assertSame([User::ORG_AREA_MANAGER, 'hi'], [$rami->org_role, $rami->locale]);
        $this->assertSame(['New Dubai'], $rami->managedNodes()->pluck('name')->all(), 'the tree is built on the fly; an unknown name is not guessed');
        $this->assertSame([$marina->id], app(OrgDirectory::class)->storeScope($rami));

        $huda = User::where('email', 'huda@example.com')->sole();
        $this->assertSame([null, false, null], [$huda->org_role, $huda->is_tenant_admin, $huda->locale]);
        $this->assertGreaterThan(0, (int) ($import->quality_summary['warnings'] ?? $import->warning_rows ?? 1));
    }

    public function test_the_store_file_carries_area_and_on_site_radius_and_an_area_column_is_not_a_region(): void
    {
        $mapped = collect(app(ColumnMappingService::class)->map(['Store Name', 'Region', 'Area', 'Geofence Radius'], [], Import::TYPE_STORES))
            ->pluck('target_field', 'source_header')->all();
        $this->assertSame(['Store Name' => 'name', 'Region' => 'region', 'Area' => 'area', 'Geofence Radius' => 'geofence_radius_m'], $mapped);

        $admin = $this->createUser($this->tenant, admin: true);
        $csv = "Store Name,Code,Region,Area,Radius\nMarina,MAR,Dubai,New Dubai,250\nDeira,DEI,Dubai,,5\n";
        $this->importFile(Import::TYPE_STORES, $csv, ['Store Name' => 'name', 'Code' => 'code', 'Region' => 'region', 'Area' => 'area', 'Radius' => 'geofence_radius_m'], $admin);

        $marina = Store::where('code', 'MAR')->sole();
        $this->assertSame(['Dubai', 'New Dubai', 250], [$marina->region, $marina->area, $marina->geofence_radius_m]);
        $this->assertNull(Store::where('code', 'DEI')->sole()->geofence_radius_m, 'out of range (20–2000 m): left empty with a warning');
    }

    public function test_an_old_learned_store_mapping_is_retired_but_other_file_types_keep_theirs(): void
    {
        $mem = fn (string $type, array $headers, array $maps) => \App\Models\MappingMemory::create(['tenant_id' => $this->tenant->id, 'data_type' => $type,
            'signature' => \App\Models\MappingMemory::signature($headers), 'mappings' => $maps, 'header_count' => count($headers),
            'last_used_at' => now(), 'schema_version' => 3]);
        $mem('stores', ['Store', 'Area'], [['source_header' => 'Store', 'target_field' => 'name'], ['source_header' => 'Area', 'target_field' => 'region']]);
        $mem('sales', ['Date', 'Item', 'Qty'], [['source_header' => 'Date', 'target_field' => 'date'], ['source_header' => 'Item', 'target_field' => 'sku'], ['source_header' => 'Qty', 'target_field' => 'quantity']]);

        $this->assertNull(\App\Models\MappingMemory::recall($this->tenant->id, 'stores', ['Store', 'Area']), 'learned when Area meant Region');
        $this->assertNotNull(\App\Models\MappingMemory::recall($this->tenant->id, 'sales', ['Date', 'Item', 'Qty']), 'a sales feed keeps its memory');
    }

    // ── Store attributes ─────────────────────────────────────────────────────

    public function test_on_site_means_within_the_store_radius_allowing_for_a_capped_gps_accuracy(): void
    {
        $s = $this->store('Marina', 'MAR', attrs: ['latitude' => 25.0800000, 'longitude' => 55.1400000]);
        $north = fn (float $m) => 25.08 + $m / 111_195;     // metres north as latitude

        $this->assertEqualsWithDelta(100, $s->distanceTo($north(100), 55.14), 1);
        $this->assertTrue($s->isOnSite($north(140), 55.14));
        $this->assertFalse($s->isOnSite($north(170), 55.14));
        $this->assertTrue($s->isOnSite($north(170), 55.14, 30), 'the phone\'s accuracy is allowed for');
        $this->assertFalse($s->isOnSite($north(400), 55.14, 5000), '…but capped at 100 m');

        $this->tenant->update(['settings' => ['geofence_radius_m' => 300]]);
        $this->assertTrue($s->fresh()->isOnSite($north(250), 55.14), 'tenant default');
        $s->update(['geofence_radius_m' => 50]);
        $this->assertFalse($s->fresh()->isOnSite($north(80), 55.14), 'the store\'s own radius wins');
        $this->assertNull($this->store('Nowhere', 'NOW')->isOnSite(25, 55), 'no coordinates: cannot be checked');
    }

    public function test_departments_come_from_the_product_file_and_products_find_their_department(): void
    {
        foreach ([['A', 'Grocery', 'Soft Drinks'], ['B', ' grocery ', 'Water'], ['C', 'Fresh', 'Dairy'], ['D', null, 'Misc']] as [$sku, $dept, $cat]) {
            Product::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku, 'department' => $dept, 'category' => $cat]);
        }
        $this->sync();
        $this->assertEqualsCanonicalizing(['Grocery', 'Fresh'], Department::where('tenant_id', $this->tenant->id)->pluck('name')->all());
        $this->sync();
        $this->assertSame(2, Department::where('tenant_id', $this->tenant->id)->count(), 'idempotent');

        $grocery = Department::where('tenant_id', $this->tenant->id)->where('name', 'Grocery')->sole();
        $bev = Department::create(['tenant_id' => $this->tenant->id, 'parent_id' => $grocery->id, 'name' => 'Beverages', 'categories' => ['Soft Drinks', 'Water']]);
        $this->assertSame($bev->id, Department::forProduct($this->tenant->id, 'Grocery', 'Soft Drinks')->id, 'the section, not just the department');
        $this->assertSame($grocery->id, Department::forProduct($this->tenant->id, 'Grocery', 'Rice')->id);
        $this->assertNull(Department::forProduct($this->tenant->id, null, 'Misc'));
        $this->assertSame('Grocery › Beverages', $bev->fullName());
    }

    // ── Leavers and devices ──────────────────────────────────────────────────

    public function test_deactivating_a_leaver_cuts_sign_in_mail_links_and_devices_and_reactivation_restores(): void
    {
        $marina = $this->store('Marina', 'MAR');
        $leaver = $this->user(User::ORG_STORE_MANAGER);
        $leaver->stores()->attach($marina->id, ['tenant_id' => $this->tenant->id]);
        $stays = $this->user(User::ORG_STORE_MANAGER);
        $phone = app(DeviceRegistry::class)->register($leaver, 'android', 'token-abc');
        $sheet = app(StoreDigestService::class)->sheetUrl($leaver);
        $admin = $this->createUser($this->tenant, admin: true);

        $this->actingAs($leaver)->get("/admin/{$this->tenant->slug}")->assertSuccessful();
        app(UserLifecycle::class)->deactivate($leaver, $admin);
        $leaver = $leaver->fresh();

        $this->assertFalse($leaver->canAccessPanel(Filament::getPanel('admin')));
        $this->actingAs($leaver)->get("/admin/{$this->tenant->slug}")->assertForbidden();
        $this->assertNotNull($phone->fresh()->revoked_at);
        $this->assertSame('deactivated', $phone->fresh()->revoked_reason);
        $this->get($sheet)->assertForbidden();
        $this->assertSame([$marina->id], $leaver->stores()->pluck('stores.id')->all(), 'links kept for when they come back');
        $this->assertTrue(DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('new_value', 'like', '%deactivated_at%')->exists(), 'audited');

        config(['mail.default' => 'array']);
        Mail::raw('hello', fn ($m) => $m->to([$leaver->email, $stays->email])->subject('Both'));
        Mail::raw('hello', fn ($m) => $m->to($leaver->email)->subject('Leaver only'));
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent, 'a message left with nobody is not sent');
        $this->assertSame([$stays->email], array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo()));

        $this->assertNotContains($leaver->id, app(OrgDirectory::class)->storeManagers($marina)->pluck('id')->all());
        try {
            app(DeviceRegistry::class)->register($leaver, 'ios', 'token-new');
            $this->fail('a leaver cannot register a device');
        } catch (\InvalidArgumentException) {
        }

        app(UserLifecycle::class)->reactivate($leaver, $admin);
        $this->assertTrue($leaver->fresh()->canAccessPanel(Filament::getPanel('admin')));
        $this->get($sheet)->assertOk();
    }

    public function test_leavers_are_guarded_against_misuse(): void
    {
        $admin = $this->createUser($this->tenant, admin: true);
        $other = $this->createTenant();
        $foreignAdmin = $this->createUser($other, admin: true);
        $u = $this->user(User::ORG_ASSOCIATE);
        $life = app(UserLifecycle::class);

        foreach ([[$admin, $admin], [$u, $foreignAdmin], [$u, $this->user(User::ORG_STORE_MANAGER)], [$this->createUser($this->tenant, superAdmin: true), $admin]] as [$target, $actor]) {
            try {
                $life->deactivate($target, $actor);
                $this->fail('should have been refused');
            } catch (\InvalidArgumentException) {
                $this->assertNull($target->fresh()->deactivated_at);
            }
        }
    }

    public function test_a_push_token_moves_to_its_new_owner_and_is_stored_encrypted(): void
    {
        $reg = app(DeviceRegistry::class);
        $a = $this->user(User::ORG_ASSOCIATE, ['locale' => 'ur']);
        $b = $this->user(User::ORG_ASSOCIATE);
        $d1 = $reg->register($a, 'android', 'shared-handheld', ['app_version' => '1.0.0']);
        $this->assertSame('ur', $d1->locale);
        $d2 = $reg->register($b, 'android', 'shared-handheld');
        $this->assertSame($d1->id, $d2->id);
        $this->assertSame($b->id, $d2->fresh()->user_id);
        $this->assertSame(1, UserDevice::count());
        $this->assertNotSame('shared-handheld', DB::table('user_devices')->value('token'), 'encrypted at rest');
        $this->assertSame('shared-handheld', UserDevice::first()->token);
        $this->assertSame([$d2->id], $reg->activeFor([$a->id, $b->id])->pluck('id')->all());
        $this->assertArrayNotHasKey('token', $d2->toArray());

        $this->expectException(\InvalidArgumentException::class);
        $reg->register($a, 'blackberry', 'x');
    }

    // ── Screens ──────────────────────────────────────────────────────────────

    public function test_admins_add_stores_zones_and_managers_and_set_positions(): void
    {
        $this->asAdminInPanel();

        Livewire::test(StoreResource\Pages\CreateStore::class)
            ->fillForm(['name' => 'Marina', 'code' => 'MAR', 'region' => 'Dubai', 'area' => 'New Dubai', 'latitude' => 25.08, 'longitude' => 55.14, 'geofence_radius_m' => 200])
            ->call('create')->assertHasNoFormErrors();
        $marina = Store::where('tenant_id', $this->tenant->id)->where('code', 'MAR')->sole();
        $this->assertSame([200, 'New Dubai'], [$marina->geofence_radius_m, $marina->area]);
        Livewire::test(StoreResource\Pages\CreateStore::class)->fillForm(['name' => ' marina ', 'code' => 'x'])->call('create')
            ->assertHasFormErrors(['name']);
        $jbr = $this->store('JBR', 'JBR', 'Dubai', 'New Dubai');
        app(HierarchySync::class)->syncTenant($this->tenant->id);

        $dairy = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Fresh']);
        Livewire::test(ZonesRelationManager::class, ['ownerRecord' => $marina, 'pageClass' => StoreResource\Pages\EditStore::class])
            ->callTableAction('create', data: ['name' => 'Endcap 3', 'zone_type' => 'endcap'])
            ->callTableAction('create', data: ['name' => 'Dairy chiller', 'zone_type' => 'chiller', 'department_id' => $dairy->id])
            ->callTableAction('copyZones', data: ['stores' => [$jbr->id]]);
        $this->assertSame(['Dairy chiller', 'Endcap 3'], StoreZone::where('store_id', $jbr->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame($this->tenant->id, (int) StoreZone::where('store_id', $jbr->id)->value('tenant_id'));
        $this->assertSame(2, ZonesRelationManager::copyZones($marina, [$jbr->id]) + 2, 'copying again adds nothing');

        $area = $this->node('area', 'New Dubai');
        $am = $this->user(User::ORG_AREA_MANAGER);
        Livewire::test(OrgUnitResource\Pages\ListOrgUnits::class)
            ->callTableAction('managers', $area, data: ['managers' => [$am->id]])->assertHasNoTableActionErrors();
        $this->assertSame([$am->id], $area->managers()->pluck('users.id')->all());

        $u = $this->createUser($this->tenant);
        Livewire::test(EditUser::class, ['record' => $u->getRouteKey()])
            ->fillForm(['org_role' => User::ORG_AREA_MANAGER, 'locale' => 'ar', 'managed_nodes' => [$this->node('region', 'Dubai')->id]])
            ->call('save')->assertHasNoFormErrors();
        $u = $u->fresh();
        $this->assertSame([User::ORG_AREA_MANAGER, 'ar'], [$u->org_role, $u->locale]);
        $this->assertEqualsCanonicalizing([$marina->id, $jbr->id], $u->storeScope());

        Livewire::test(UserResource\Pages\ListUsers::class)->callTableAction('deactivate', $u);
        $this->assertNotNull($u->fresh()->deactivated_at);
        Livewire::test(UserResource\Pages\ListUsers::class)->assertCanNotSeeTableRecords([$u])
            ->filterTable('active', false)->assertCanSeeTableRecords([$u])->callTableAction('reactivate', $u);
        $this->assertNull($u->fresh()->deactivated_at);
    }

    public function test_only_admins_reach_the_organisation_screens(): void
    {
        $this->actingAs($this->createUser($this->tenant));
        $this->get(OrgUnitResource::getUrl('index', ['tenant' => $this->tenant]))->assertForbidden();
        $this->get(DepartmentResource::getUrl('index', ['tenant' => $this->tenant]))->assertForbidden();
        $this->get(StoreResource::getUrl('create', ['tenant' => $this->tenant]))->assertForbidden();
    }

    // ── Setup ────────────────────────────────────────────────────────────────

    public function test_a_task_execution_only_tenant_sets_up_without_any_data_feed(): void
    {
        $this->tenant->update(['apps' => [Tenant::APP_TASK_EXECUTION]]);
        $svc = app(OnboardingService::class);
        $sections = $svc->sections($this->tenant->id);
        $this->assertSame(['platform', Tenant::APP_TASK_EXECUTION], array_column($sections, 'key'));
        $keys = array_column($svc->steps($this->tenant->id), 'key');
        $this->assertNotContains('sales', $keys);
        $this->assertNotContains('detection', $keys);
        $steps = collect($svc->steps($this->tenant->id))->keyBy('key');
        $this->assertSame('recommended', $steps['products']['level'], 'products help (barcodes) but are not required');

        $marina = $this->store('Marina', 'MAR', 'Dubai', attrs: ['latitude' => 25.08, 'longitude' => 55.14]);
        $this->createUser($this->tenant, admin: true);
        $sm = $this->user(User::ORG_STORE_MANAGER);
        $sm->stores()->attach($marina->id, ['tenant_id' => $this->tenant->id]);
        $this->assertSame(0, $svc->progress($this->tenant->id)['required_left'], 'stores + people + a store manager: done');

        $this->tenant->update(['apps' => [Tenant::APP_ROOT_CAUSE, Tenant::APP_TASK_EXECUTION]]);
        $this->assertSame(['platform', Tenant::APP_ROOT_CAUSE, Tenant::APP_TASK_EXECUTION], array_column($svc->sections($this->tenant->id), 'key'));
    }

    public function test_run_detection_now_refuses_while_a_run_is_going(): void
    {
        $this->asAdminInPanel();
        Queue::fake();
        TenantNightlyRun::create(['tenant_id' => $this->tenant->id, 'local_date' => now()->toDateString(), 'status' => TenantNightlyRun::STATUS_RUNNING]);
        Livewire::test(GettingStarted::class)->callAction('runDetection');
        Queue::assertNothingPushed();

        TenantNightlyRun::query()->update(['status' => TenantNightlyRun::STATUS_DONE]);
        Livewire::test(GettingStarted::class)->callAction('runDetection');
        Livewire::test(GettingStarted::class)->callAction('runDetection');
        Queue::assertPushed(\Illuminate\Foundation\Console\QueuedCommand::class, 1);
    }
}
