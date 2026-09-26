<?php

namespace Tests\Feature;

use App\Filament\Pages\CountLists;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Mail\StoreDigestMail;
use App\Models\Anomaly;
use App\Models\CycleCount;
use App\Models\Import;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Counts\CycleCountService;
use App\Services\Import\ImportProcessorService;
use App\Services\Stores\StoreDigestService;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * W12 — store routing: store managers linked to their store(s) get a daily
 * digest of those stores only, and a signed store sheet (no login) to confirm
 * findings and enter counts — for their stores and nobody else's.
 */
class StoreRoutingTest extends TestCase
{
    private Tenant $tenant;
    private Store $marina;
    private Store $mall;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-26 06:00'));
        $this->tenant = $this->createTenant(['settings' => ['detection_rules_v2' => true], 'currency' => 'AED']);
        $this->marina = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Marina', 'code' => 'MAR']);
        $this->mall = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Mall', 'code' => 'MAL']);
        $this->manager = $this->createUser($this->tenant);
        $this->manager->stores()->attach($this->marina->id, ['tenant_id' => $this->tenant->id]);
        config(['autnyx.digest_enabled' => true]);
    }

    private function finding(Store $store, string $sku, float $value, array $attrs = []): Anomaly
    {
        return Anomaly::create(array_merge(['tenant_id' => $this->tenant->id, 'rule_type' => 'stockout_risk', 'severity' => 'high', 'sku' => $sku,
            'store_id' => $store->id, 'description' => "Stock-out risk {$sku} at {$store->name}", 'context' => ['revenue_impact' => $value],
            'detected_at' => now()->subHours(3)], $attrs));
    }

    private function countLine(Store $store, string $sku): CycleCount
    {
        DB::table('inventory_current')->insert(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => $sku, 'as_of_date' => '2026-09-25',
            'on_hand_qty' => 10, 'unit_cost' => 4, 'created_at' => now(), 'updated_at' => now()]);

        return CycleCount::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'sku' => $sku, 'reason' => 'phantom_inventory',
            'system_qty' => 10, 'unit_cost' => 4, 'value_at_risk' => 40, 'rank' => 1, 'status' => CycleCount::STATUS_OPEN]);
    }

    public function test_a_managers_digest_holds_their_stores_only_and_goes_out_once_a_night(): void
    {
        Mail::fake();
        $this->finding($this->marina, 'A', 500);
        $this->finding($this->mall, 'B', 900);            // another store — not theirs
        $this->finding($this->marina, 'C', 50, ['severity' => 'low']);   // low — left out
        $this->countLine($this->marina, 'P1');
        $this->countLine($this->mall, 'P2');
        $other = $this->createUser($this->tenant);          // not linked to a store
        $quiet = $this->createUser($this->tenant);
        $quiet->stores()->attach($this->mall->id, ['tenant_id' => $this->tenant->id]);
        $quiet->forceFill(['store_digest' => false])->save();

        $d = app(StoreDigestService::class)->forUser($this->manager);
        $this->assertSame(['A'], $d['findings']->pluck('sku')->all());
        $this->assertSame(['P1'], $d['counts']->pluck('sku')->all());

        $this->artisan('digest:stores', ['--tenant' => $this->tenant->id])->assertSuccessful();
        Mail::assertQueued(StoreDigestMail::class, 1);
        Mail::assertQueued(StoreDigestMail::class, fn ($m) => $m->hasTo($this->manager->email));
        $this->assertNotNull($this->manager->fresh()->store_digest_sent_at);

        // The e-mail: their store, signed links for them, and no other store's finding.
        $html = urldecode(html_entity_decode((new StoreDigestMail($this->tenant, $this->manager->fresh(), null))->render()));
        $this->assertStringContainsString('Stock-out risk A at Marina', $html);
        $this->assertStringNotContainsString('at Mall', $html);
        $this->assertStringContainsString('/store-sheet/' . $this->manager->id, $html);
        $this->assertStringContainsString('r=user:' . $this->manager->id, $html);

        // Next night: only what is new counts as new.
        $this->travel(1)->days();
        $this->finding($this->marina, 'D', 300);
        $this->assertSame(1, app(StoreDigestService::class)->forUser($this->manager->fresh())['new_count']);
    }

    public function test_the_store_sheet_answers_and_counts_for_their_stores_and_nobody_elses(): void
    {
        $mine = $this->finding($this->marina, 'A', 500);
        $theirs = $this->finding($this->mall, 'B', 900);
        $count = $this->countLine($this->marina, 'P1');
        $foreignCount = $this->countLine($this->mall, 'P2');
        $url = app(StoreDigestService::class)->sheetUrl($this->manager);

        $this->get($url)->assertOk()->assertSee('Stock-out risk A at Marina')->assertDontSee('Stock-out risk B at Mall')
            ->assertSee('P1')->assertDontSee('P2');

        $this->post($url, ['do' => 'feedback', 'anomaly' => $mine->id, 'verdict' => 'real'])->assertRedirect($url);
        $this->assertSame(['real', 'store_sheet', $this->manager->id], [$mine->fresh()->feedback, $mine->fresh()->feedback_via, $mine->fresh()->feedback_by]);

        $this->post($url, ['do' => 'feedback', 'anomaly' => $theirs->id, 'verdict' => 'not_real'])->assertStatus(422);
        $this->assertNull($theirs->fresh()->feedback);

        $this->post($url, ['do' => 'counts', 'counted' => [$count->id => '7', $foreignCount->id => '1']])->assertRedirect($url);
        $this->assertSame([CycleCount::STATUS_COUNTED, -3.0, 'sheet', $this->manager->id],
            [$count->fresh()->status, $count->fresh()->variance_qty, $count->fresh()->count_source, $count->fresh()->counted_by]);
        $this->assertSame(CycleCount::STATUS_OPEN, $foreignCount->fresh()->status, 'another store\'s count is untouched');
        $this->get($url)->assertSee('1 count saved');

        $this->get($url . 'x')->assertForbidden();
        $this->manager->stores()->detach();
        $this->get($url)->assertForbidden();   // no longer linked to a store
    }

    public function test_the_store_digest_can_be_stopped_from_its_own_link(): void
    {
        $this->get(app(StoreDigestService::class)->unsubscribeUrl($this->manager))->assertOk();
        $this->assertFalse($this->manager->fresh()->store_digest);
    }

    public function test_the_users_file_links_managers_to_stores(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->createUser($this->tenant, admin: true);
        $csv = "Name,Email,Stores\nSara,sara@example.com,MAR; mall\nOmar,omar@example.com,NOPE\n";
        $path = 'imports/pending/u-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $csv);
        $import = Import::create(['tenant_id' => $this->tenant->id, 'user_id' => $admin->id, 'original_filename' => 'managers.csv', 'disk' => 'local', 'path' => $path,
            'data_type' => Import::TYPE_USERS, 'status' => Import::STATUS_UPLOADED, 'total_rows' => 2,
            'source' => Import::SOURCE_UPLOAD, 'feed_key' => Import::feedKeyFor(Import::SOURCE_UPLOAD, Import::TYPE_USERS)]);
        foreach (['Name' => 'name', 'Email' => 'email', 'Stores' => 'stores'] as $h => $f) {
            $import->columnMaps()->create(['source_header' => $h, 'target_field' => $f, 'is_skipped' => false, 'is_confirmed' => true]);
        }
        $svc = app(ImportProcessorService::class);
        $svc->startChunkedImport($import);
        $guard = 0;
        do {
            $r = $svc->processChunk($import->fresh(), 100);
        } while (! ($r['done'] ?? false) && ++$guard < 20);

        $sara = User::where('email', 'sara@example.com')->sole();
        $this->assertEqualsCanonicalizing([$this->marina->id, $this->mall->id], $sara->stores()->pluck('stores.id')->all());
        $this->assertSame($this->tenant->id, (int) DB::table('store_user')->where('user_id', $sara->id)->value('tenant_id'));
        $this->assertSame([], User::where('email', 'omar@example.com')->sole()->stores()->pluck('stores.id')->all(), 'an unknown store is not guessed');
    }

    public function test_admins_link_stores_on_the_user_form_and_count_lists_follow_the_link(): void
    {
        $admin = $this->actingAsTenantAdmin($this->tenant);
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        Filament::setTenant($this->tenant);
        $panel->boot();

        $u = $this->createUser($this->tenant);
        Livewire::test(EditUser::class, ['record' => $u->getRouteKey()])
            ->fillForm(['stores' => [$this->mall->id]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$this->mall->id], $u->stores()->pluck('stores.id')->all());
        $this->assertSame($this->tenant->id, (int) DB::table('store_user')->where('user_id', $u->id)->value('tenant_id'));

        $this->countLine($this->marina, 'P1');
        $this->countLine($this->mall, 'P2');
        $this->actingAs($u);
        $this->get(CountLists::getUrl(['tenant' => $this->tenant]))->assertOk()->assertSee('P2')->assertDontSee('P1');
        $this->get(CountLists::getUrl(['tenant' => $this->tenant, 'store' => $this->marina->id]))->assertOk()->assertDontSee('P1');
        $this->actingAs($admin);
        $this->get(CountLists::getUrl(['tenant' => $this->tenant, 'store' => $this->marina->id]))->assertOk()->assertSee('P1');
    }
}
