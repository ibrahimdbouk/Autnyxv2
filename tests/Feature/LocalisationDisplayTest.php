<?php

namespace Tests\Feature;

use App\Filament\Pages\ActionCenter;
use App\Filament\Support\InitialsAvatarProvider;
use App\Support\Tenancy\TenantClock;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WP7.3 (audit M1 display, L10) — times shown on the tenant's clock, initials
 * by character, avatars drawn locally; WP7.2 page sizes the client can't set.
 */
class LocalisationDisplayTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantClock::forget();
        parent::tearDown();
    }

    public function test_stored_times_are_shown_on_the_tenant_clock(): void
    {
        $t = $this->createTenant(['timezone' => 'Asia/Dubai']);
        Filament::setTenant($t, isQuiet: true);

        $this->assertSame('Asia/Dubai', FilamentTimezone::get(), 'Filament columns and pickers');
        $this->assertSame('2026-09-01 01:30', TenantClock::display(Carbon::parse('2026-08-31 21:30', 'UTC'))->format('Y-m-d H:i'));
        $this->assertNull(TenantClock::display(null));
    }

    public function test_initials_are_characters_not_bytes(): void
    {
        $this->assertSame('مع', InitialsAvatarProvider::initials('مريم علي'));
        $this->assertSame('ÉD', InitialsAvatarProvider::initials('émile dupont'));
        $this->assertSame('SA', InitialsAvatarProvider::initials('[SYSTEM] Admin'));
        $this->assertSame('?', InitialsAvatarProvider::initials('  '));

        $svg = base64_decode(substr(InitialsAvatarProvider::dataUri('<b>Bad</b> Name'), strlen('data:image/svg+xml;base64,')));
        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringStartsWith('<svg', $svg);
    }

    public function test_page_size_cannot_be_set_by_the_client(): void
    {
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        Filament::setTenant($t, isQuiet: true);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(ActionCenter::class)->set('perPage', 1000000);
    }
}
