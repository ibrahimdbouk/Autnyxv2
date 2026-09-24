<?php

namespace Tests\Feature;

use App\Filament\Pages\Account;
use App\Filament\Pages\FinancialBreakdown;
use App\Filament\Resources\StoreClusterResource;
use App\Filament\Resources\StoreResource;
use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\ExportAudit;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP2.4 — screen gating holes (audit M9), exports (L1/L2), account email
 * re-authentication (L4), audit coverage (L3), inbound email secret (L8).
 */
class AuthorizationMatrixTest extends TestCase
{
    private function restricted(\App\Models\Tenant $t, array $screens): User
    {
        $u = $this->createUser($t);
        $u->forceFill(['visible_screens' => $screens])->save();
        $this->actingAs($u);

        return $u;
    }

    public function test_financial_breakdown_follows_the_investigations_permission(): void
    {
        $t = $this->createTenant();
        $this->restricted($t, ['anomalies']);
        $this->get(FinancialBreakdown::getUrl(['tenant' => $t]))->assertForbidden();

        $this->restricted($t, ['investigations']);
        $this->get(FinancialBreakdown::getUrl(['tenant' => $t]))->assertOk();
    }

    public function test_stores_and_clusters_are_gated_by_screen(): void
    {
        $t = $this->createTenant();
        $this->restricted($t, []);
        $this->get(StoreResource::getUrl('index', ['tenant' => $t]))->assertForbidden();
        $this->get(StoreClusterResource::getUrl('index', ['tenant' => $t]))->assertForbidden();

        $this->restricted($t, ['stores', 'store_clusters']);
        $this->get(StoreResource::getUrl('index', ['tenant' => $t]))->assertOk();
        $this->get(StoreClusterResource::getUrl('index', ['tenant' => $t]))->assertOk();
    }

    public function test_pdf_downloads_respect_screen_permissions(): void
    {
        $t = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $t->id]);
        $a = Anomaly::factory()->create(['tenant_id' => $t->id, 'investigation_id' => $inv->id]);

        $this->restricted($t, ['reports']);
        $this->get(route('investigation.report.pdf', $inv->id))->assertForbidden();
        $this->get(route('anomaly.report.pdf', $a->id))->assertForbidden();
    }

    public function test_tenant_panel_offers_no_raw_tenant_delete(): void
    {
        $t = $this->createTenant();
        auth()->logout();
        $super = User::factory()->create(['tenant_id' => $t->id, 'is_super_admin' => true]);
        $this->actingAs($super);

        $html = $this->get(\App\Filament\Resources\TenantResource::getUrl('edit', ['record' => $t, 'tenant' => $t]))->assertOk()->getContent();
        $this->assertStringNotContainsString("mountAction('delete'", $html);
    }

    public function test_changing_email_requires_the_current_password_and_is_audited(): void
    {
        $t = $this->createTenant();
        $u = $this->createUser($t);
        $u->forceFill(['password' => bcrypt('Correct-Horse-9')])->save();
        $this->actingAs($u);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($t);

        Livewire::test(Account::class)
            ->callAction('edit_profile', ['name' => $u->name, 'email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertHasActionErrors(['current_password']);
        $this->assertNotSame('new@example.com', $u->fresh()->email);

        Livewire::test(Account::class)
            ->callAction('edit_profile', ['name' => $u->name, 'email' => 'new@example.com', 'current_password' => 'Correct-Horse-9'])
            ->assertHasNoActionErrors();
        $this->assertSame('new@example.com', $u->fresh()->email);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $t->id, 'event_type' => 'credentials_changed']);

        // Name-only edits don't need the password.
        Livewire::test(Account::class)
            ->callAction('edit_profile', ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertHasNoActionErrors();
        $this->assertSame('New Name', $u->fresh()->name);
    }

    public function test_security_configuration_changes_are_audited_without_values(): void
    {
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        $c = SsoConnection::create(['tenant_id' => $t->id, 'issuer' => 'https://idp.test', 'client_id' => 'c', 'client_secret' => 'SUPER-SECRET']);
        $c->update(['client_secret' => 'ROTATED-SECRET']);

        $logs = AuditLog::where('tenant_id', $t->id)->where('event_type', 'config_changed')->pluck('description')->implode("\n");
        $this->assertStringContainsString('SsoConnection #' . $c->id . ' created', $logs);
        $this->assertStringContainsString('client_secret', $logs, 'which field changed is recorded');
        $this->assertStringNotContainsString('SECRET', str_replace('client_secret', '', $logs), 'the value is never recorded');
    }

    public function test_formula_injection_is_neutralised_in_exports(): void
    {
        $row = ExportAudit::safeRow(['=HYPERLINK("http://evil")', '+cmd', '@SUM(1)', '-2+3', 'SKU-1', -12.5, '-12.5', 42]);

        $this->assertSame(["'=HYPERLINK(\"http://evil\")", "'+cmd", "'@SUM(1)", "'-2+3", 'SKU-1', -12.5, '-12.5', 42], $row);
    }

    public function test_inbound_email_secret_is_not_accepted_in_the_query_string(): void
    {
        config(['inbound.secret' => 'shh']);

        $this->post('/webhooks/inbound-email?secret=shh', [])->assertForbidden();
        $this->post('/webhooks/inbound-email', [], ['X-Webhook-Secret' => 'shh'])->assertOk();
    }
}
