<?php

namespace Tests\Feature;

use App\Filament\Pages\ActionCenter;
use App\Filament\Resources\ImportResource\Pages\ReviewMapping;
use App\Filament\Resources\ImportResource\Pages\ViewImport;
use App\Filament\Resources\TeamResource;
use App\Filament\Resources\UserResource;
use App\Jobs\BulkActionCenterJob;
use App\Jobs\BulkInvestigationActionJob;
use App\Models\Action;
use App\Models\Import;
use App\Models\ImportColumnMap;
use App\Models\ImportRow;
use App\Models\Investigation;
use App\Models\Team;
use App\Models\Tenant;
use App\Services\Import\ImportProcessorService;
use App\Services\Noise\SnoozeService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP1.3 — cross-tenant and missing-authorization hotfixes
 * (audit C6, H8, H9, H10, H11, stuck-import recovery, bulk id validation).
 * Every test tampers with Livewire state or ids the way an attacker would.
 */
class TenancyAuthorizationHotfixTest extends TestCase
{
    private function inPanel(Tenant $tenant): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);
    }

    private function actionFor(Tenant $tenant, string $title = 'Secret action'): Action
    {
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => Investigation::STATUS_OPEN]);

        return Action::create([
            'investigation_id' => $inv->id, 'action_type' => Action::TYPE_REORDER, 'title' => $title,
            'status' => Action::STATUS_ASSIGNED, 'priority' => Action::PRIORITY_HIGH,
        ]);
    }

    private function importFor(Tenant $tenant, string $status = Import::STATUS_MAPPING_REVIEW): Import
    {
        return Import::create([
            'tenant_id' => $tenant->id, 'original_filename' => 'x.csv', 'disk' => 'local', 'path' => 'imports/pending/x.csv',
            'data_type' => Import::TYPE_RETURNS, 'status' => $status, 'total_rows' => 1,
        ]);
    }

    // ── C6: Action Center ────────────────────────────────────────────────────

    public function test_action_center_cannot_select_or_render_another_tenants_action(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $foreign = $this->actionFor($b, 'Tenant B confidential plan');
        $this->actingAsAnalyst($a);
        $this->inPanel($a);

        $lw = Livewire::test(ActionCenter::class)->call('selectAction', $foreign->id);
        $this->assertNull($lw->get('selectedActionId'));

        // Even if the property is forced client-side, nothing renders.
        $lw->set('selectedActionId', $foreign->id);
        $this->assertNull($lw->instance()->getSelectedAction());
        $lw->assertDontSee('Tenant B confidential plan');
    }

    public function test_action_center_still_selects_own_action(): void
    {
        $a = $this->createTenant();
        $own = $this->actionFor($a, 'Own plan');
        $this->actingAsAnalyst($a);
        $this->inPanel($a);

        $lw = Livewire::test(ActionCenter::class)->call('selectAction', $own->id);
        $this->assertSame($own->id, $lw->get('selectedActionId'));
        $this->assertSame($own->id, $lw->instance()->getSelectedAction()?->id);
    }

    // ── H10: imports ─────────────────────────────────────────────────────────

    public function test_import_record_pages_404_for_another_tenant(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $foreign = $this->importFor($b, Import::STATUS_COMPLETED);
        $this->actingAsTenantAdmin($a);

        $this->get(ViewImport::getUrl(['record' => $foreign, 'tenant' => $a]))->assertNotFound();
        $this->get(ReviewMapping::getUrl(['record' => $foreign, 'tenant' => $a]))->assertNotFound();
    }

    public function test_view_import_cannot_reject_another_tenants_row(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $own = $this->importFor($a, Import::STATUS_COMPLETED_WITH_ERRORS);
        $foreignRow = ImportRow::create([
            'import_id' => $this->importFor($b, Import::STATUS_COMPLETED_WITH_ERRORS)->id,
            'tenant_id' => $b->id, 'row_number' => 2, 'raw_data' => ['x' => 1], 'status' => ImportRow::STATUS_PENDING, 'error_message' => 'bad',
        ]);
        $this->actingAsTenantAdmin($a);
        $this->inPanel($a);

        Livewire::test(ViewImport::class, ['record' => $own])
            ->call('rejectRow', $foreignRow->id)
            ->call('approveRow', $foreignRow->id);

        $this->assertSame(ImportRow::STATUS_PENDING, $foreignRow->fresh()->status);
    }

    public function test_review_mapping_cannot_rewrite_another_tenants_mapping_and_rejects_unknown_fields(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/pending/x.csv', "Date,SKU\n2026-08-01,S1\n");
        $a = $this->createTenant();
        $b = $this->createTenant();
        $own = $this->importFor($a);
        $ownMap = $own->columnMaps()->create(['source_header' => 'SKU', 'target_field' => 'sku', 'is_skipped' => false, 'is_confirmed' => false]);
        $foreignMap = $this->importFor($b)->columnMaps()->create(['source_header' => 'Date', 'target_field' => 'date', 'is_skipped' => false, 'is_confirmed' => false]);
        $this->actingAsTenantAdmin($a);
        $this->inPanel($a);

        Livewire::test(ReviewMapping::class, ['record' => $own])
            ->set('mappings', [
                ['id' => $ownMap->id, 'source_header' => 'SKU', 'target_field' => 'is_tenant_admin', 'confidence' => null, 'reasoning' => null, 'is_skipped' => false], // not a canonical field
                ['id' => $foreignMap->id, 'source_header' => 'Date', 'target_field' => 'sku', 'confidence' => null, 'reasoning' => null, 'is_skipped' => false],       // another tenant's map
            ])
            ->call('confirmAndImport');

        $this->assertSame('date', $foreignMap->fresh()->target_field);
        $this->assertFalse((bool) $foreignMap->fresh()->is_confirmed);
        $this->assertNotSame('is_tenant_admin', $ownMap->fresh()->target_field, 'unknown target fields are never stored');
    }

    public function test_stuck_import_recovery_is_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $mine = $this->importFor($a, Import::STATUS_IMPORTING);
        $theirs = $this->importFor($b, Import::STATUS_IMPORTING);
        Import::whereIn('id', [$mine->id, $theirs->id])->update(['updated_at' => now()->subMinutes(20)]);

        ImportProcessorService::recoverStuckImports(10, $a->id);

        $this->assertSame(Import::STATUS_FAILED, $mine->fresh()->status);
        $this->assertSame(Import::STATUS_IMPORTING, $theirs->fresh()->status);
    }

    // ── H8: users ────────────────────────────────────────────────────────────

    public function test_tenant_admin_cannot_edit_a_super_admin_in_their_tenant(): void
    {
        $a = $this->createTenant();
        $super = $this->createUser($a, superAdmin: true);
        $colleague = $this->createUser($a);
        $this->actingAsTenantAdmin($a);

        $this->assertFalse(UserResource::canEdit($super));
        $this->assertTrue(UserResource::canEdit($colleague));
    }

    // ── H9: investigation lifecycle ──────────────────────────────────────────

    /** Header actions render as mountAction('<name>') buttons on the page. */
    private function visibleActions(Investigation $inv, Tenant $tenant): array
    {
        $html = $this->get(\App\Filament\Resources\InvestigationResource::getUrl('investigate', ['record' => $inv->id, 'tenant' => $tenant]))
            ->assertOk()->getContent();
        preg_match_all("/mountAction\\('([a-z_]+)'/", $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_plain_user_cannot_close_or_record_outcomes_or_move_unassigned_work(): void
    {
        $a = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $a->id, 'status' => Investigation::STATUS_OPEN]);
        $resolved = Investigation::factory()->create(['tenant_id' => $a->id, 'status' => Investigation::STATUS_RESOLVED]);
        $this->actingAsAnalyst($a);

        $open = $this->visibleActions($inv, $a);
        $this->assertNotContains('mark_in_progress', $open);
        $this->assertNotContains('mark_resolved', $open);

        $done = $this->visibleActions($resolved, $a);
        $this->assertNotContains('close', $done);
        $this->assertNotContains('record_outcome', $done);
    }

    public function test_assignee_can_work_their_investigation_and_admin_can_close(): void
    {
        $a = $this->createTenant();
        $analyst = $this->createUser($a);
        $inv = Investigation::factory()->create(['tenant_id' => $a->id, 'status' => Investigation::STATUS_OPEN, 'assigned_user_id' => $analyst->id]);
        $this->actingAs($analyst);

        $this->assertContains('mark_in_progress', $this->visibleActions($inv, $a));
        $this->assertContains('mark_resolved', $this->visibleActions($inv, $a));

        $inv->update(['status' => Investigation::STATUS_RESOLVED]);
        $this->assertNotContains('close', $this->visibleActions($inv, $a), 'assignees resolve; only admins close');

        $this->actingAsTenantAdmin($a);
        $admin = $this->visibleActions($inv, $a);
        $this->assertContains('close', $admin);
        $this->assertContains('record_outcome', $admin);
    }

    public function test_team_member_can_work_team_investigation(): void
    {
        $a = $this->createTenant();
        $analyst = $this->createUser($a);
        $team = Team::create(['tenant_id' => $a->id, 'name' => 'Ops']);
        $team->members()->attach($analyst->id, ['role' => 'member']);
        $inv = Investigation::factory()->create(['tenant_id' => $a->id, 'status' => Investigation::STATUS_OPEN, 'assigned_team_id' => $team->id]);

        $this->assertTrue($analyst->canWorkInvestigation($inv));
        $this->assertFalse($this->createUser($a)->canWorkInvestigation($inv));
    }

    // ── H11: teams ───────────────────────────────────────────────────────────

    public function test_team_management_is_admin_only_and_edit_page_renders(): void
    {
        $a = $this->createTenant();
        $team = Team::create(['tenant_id' => $a->id, 'name' => 'Ops']);
        $member = $this->createUser($a);
        $team->members()->attach($member->id, ['role' => 'member']);

        $this->actingAsAnalyst($a);
        $this->assertFalse(TeamResource::canCreate());
        $this->assertFalse(TeamResource::canEdit($team));
        $this->assertFalse(TeamResource::canDelete($team));

        $this->actingAsTenantAdmin($a);
        $this->assertTrue(TeamResource::canEdit($team));
        // Was a SQL error: users.current_team_id does not exist.
        $this->get(TeamResource::getUrl('edit', ['record' => $team, 'tenant' => $a]))->assertOk();
    }

    // ── Bulk jobs: ids from client state ─────────────────────────────────────

    public function test_bulk_jobs_refuse_foreign_team_and_assignee_and_unknown_priority(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $foreignTeam = Team::create(['tenant_id' => $b->id, 'name' => 'Theirs']);
        $foreignUser = $this->createUser($b);
        $me = $this->createUser($a, admin: true);

        $act = $this->actionFor($a);
        $inv = $act->investigation;

        BulkActionCenterJob::apply($a->id, $me->id, 'reassign_team', [$act->id], ['team_id' => $foreignTeam->id]);
        BulkActionCenterJob::apply($a->id, $me->id, 'assign', [$act->id], ['assigned_to' => $foreignUser->id]);
        BulkActionCenterJob::apply($a->id, $me->id, 'change_priority', [$act->id], ['priority' => '<script>']);
        $act->refresh();
        $this->assertNull($act->assigned_team_id);
        $this->assertNotSame($foreignUser->id, $act->assigned_to);
        $this->assertSame(Action::PRIORITY_HIGH, $act->priority);

        $snooze = app(SnoozeService::class);
        BulkInvestigationActionJob::apply($a->id, $me->id, 'reassign_team', [$inv->id], ['team_id' => $foreignTeam->id], $snooze);
        BulkInvestigationActionJob::apply($a->id, $me->id, 'change_priority', [$inv->id], ['priority' => 'urgent!!'], $snooze);
        $inv->refresh();
        $this->assertNull($inv->assigned_team_id);
        $this->assertNotSame('urgent!!', $inv->priority);
    }
}
