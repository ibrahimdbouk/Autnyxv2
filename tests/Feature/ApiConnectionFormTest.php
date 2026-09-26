<?php

namespace Tests\Feature;

use App\Filament\Resources\ApiConnectionResource\Pages\CreateApiConnection;
use App\Models\ApiConnection;
use App\Support\Http\HostResolver;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** W13 follow-up: the API connection form saves, and still refuses internal URLs. */
class ApiConnectionFormTest extends TestCase
{
    public function test_the_connection_form_saves_a_public_url_and_refuses_an_internal_one(): void
    {
        config(['autnyx.egress.resolve_dns' => true]);
        $this->app->instance(HostResolver::class, new class extends HostResolver {
            public function resolve(string $host): array
            {
                return $host === 'erp.example.com' ? ['52.10.20.30'] : ['10.0.0.5'];
            }
        });
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($t);

        Livewire::test(CreateApiConnection::class)
            ->fillForm(['name' => 'ERP', 'provider' => 'generic_rest', 'base_url' => 'https://erp.example.com/api', 'auth_type' => 'none', 'feeds' => []])
            ->call('create')->assertHasNoFormErrors();
        $this->assertTrue(ApiConnection::where('tenant_id', $t->id)->where('name', 'ERP')->exists());

        Livewire::test(CreateApiConnection::class)
            ->fillForm(['name' => 'Inside', 'provider' => 'generic_rest', 'base_url' => 'https://intranet.example.com/api', 'auth_type' => 'none', 'feeds' => []])
            ->call('create')->assertHasFormErrors(['base_url']);
    }
}
