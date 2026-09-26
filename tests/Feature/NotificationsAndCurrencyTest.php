<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Investigation;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app (bell) notifications are delivered again, and on-screen amounts use
 * the currency sign (the new UAE dirham ⃃ and Saudi riyal ⃁ included) while
 * tables, stored text and documents keep the ISO code.
 */
class NotificationsAndCurrencyTest extends TestCase
{
    public function test_the_bell_receives_notifications_with_their_open_link(): void
    {
        $t = $this->createTenant();
        $a = $this->createUser($t);
        $b = $this->createUser($t);

        NotificationDispatcher::toUsers([$a->id, $b->id, $a->id], 'Assigned to you', 'Stock-out risk at Marina', 'https://example.test/x', alsoEmail: false);

        foreach ([$a, $b] as $u) {
            $this->assertSame(1, $u->notifications()->count(), 'one per person, deduplicated');
        }
        $data = $a->notifications()->first()->data;
        $this->assertSame('Assigned to you', $data['title']);
        $this->assertSame('Stock-out risk at Marina', $data['body']);
        $this->assertSame('https://example.test/x', $data['actions'][0]['url'] ?? null, 'the "Open" button survives');

        // The bell itself renders the notification for its owner.
        $this->actingAs($a);
        \Livewire\Livewire::test(\Filament\Livewire\DatabaseNotifications::class)->assertSee('Assigned to you');
    }

    public function test_documents_keep_the_code_and_the_screen_shows_the_sign(): void
    {
        $this->assertSame('AED 1,234.50', Money::format(1234.5, 'AED'));
        $this->assertSame('AED 1.2K', Money::compact(1234.5, 'AED'));
        $this->assertSame('AED -400', Money::compact(-400, 'AED'), 'stored text is unchanged');

        $this->assertSame("\u{20C3}\u{202F}1.2K", Money::displayCompact(1234.5, 'AED'));
        $this->assertSame("\u{20C1}\u{202F}2.5M", Money::displayCompact(2_500_000, 'SAR'));
        $this->assertSame("-\u{20C3}\u{202F}400.00", Money::displayFormat(-400, 'AED'));
        $this->assertSame('$980', Money::displayCompact(980, 'USD'));
        $this->assertSame('KWD 1.5K', Money::displayCompact(1500, 'KWD'), 'no well-known sign: the code stays');
        $this->assertSame("\u{20C3}\u{202F}", Money::displayPrefix('AED'));
    }

    public function test_the_dashboard_cards_use_the_sign_and_its_table_the_code(): void
    {
        $t = $this->createTenant(['currency' => 'AED']);
        $inv = Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN, 'priority' => 'high', 'opened_at' => now(), 'revenue_at_risk' => 52_000]);
        $this->actingAsTenantAdmin($t);

        $html = $this->get(Dashboard::getUrl(['tenant' => $t]))->assertOk()->getContent();
        $this->assertStringContainsString("\u{20C3}\u{202F}52.0K", $html, 'revenue-at-risk card');
        $this->assertStringContainsString('AED 52.0K', $html, 'the investigations table keeps the code');
        $this->assertStringContainsString("font-family:'AxCurrency'", $html, 'the sign\'s font is loaded');
        $this->assertStringContainsString('unicode-range:U+20C3', $html);
        $this->assertFileExists(public_path('vendor/currency/dirham.woff2'));
        $this->assertFileExists(public_path('vendor/currency/riyal-regular.woff2'));
    }
}
