<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Investigation;
use App\Support\Security\Csp;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * WP7.3 (audit L12) — an enforced, nonce-based CSP: every script the app
 * writes carries the request's nonce, data can never gain one, no inline
 * event handlers remain, and violations are reported.
 */
class ContentSecurityPolicyTest extends TestCase
{
    public function test_every_script_on_a_panel_page_carries_the_request_nonce(): void
    {
        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN, 'priority' => 'high']);

        $response = $this->get(Dashboard::getUrl(['tenant' => $t]))->assertOk();
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);
        $nonce = $m[1] ?? null;
        $this->assertNotNull($nonce);

        preg_match_all('/<script\b[^>]*>/i', $response->getContent(), $tags);
        $this->assertNotEmpty($tags[0]);
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bsrc="(?:https?:\/\/localhost)?\//', $tag) || str_contains($tag, 'application/json')) {
                continue;   // same-origin files ('self') and data blocks
            }
            $this->assertStringContainsString('nonce="' . $nonce . '"', $tag, $tag);
        }
        $this->assertDoesNotMatchRegularExpression('/\son(click|keydown|change|submit|load|error|input)\s*=/i', $response->getContent(), 'no inline handlers');
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $response->getContent());
    }

    public function test_script_markup_in_data_never_gains_a_nonce(): void
    {
        $compiled = Csp::precompile("<div>\n    <script>ok()</script>\n    <p>{{ \$name }}</p>\n    <b>x</b><script>mid()</script>\n</div>");

        $this->assertStringContainsString('<script nonce="{{ \App\Support\Security\Csp::nonce() }}">ok()', $compiled);
        $this->assertStringContainsString('<b>x</b><script>mid()', $compiled, 'only tags that start a template line');

        $t = $this->createTenant();
        $this->actingAsTenantAdmin($t);
        Investigation::factory()->create(['tenant_id' => $t->id, 'status' => Investigation::STATUS_OPEN, 'priority' => 'high',
            'primary_sku' => '<script>alert(1)</script>']);
        $html = $this->get(Dashboard::getUrl(['tenant' => $t]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
    }

    public function test_violation_reports_are_logged_without_query_strings(): void
    {
        Log::spy();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'document-uri' => 'https://app.test/admin/acme?token=secret', 'violated-directive' => 'script-src-elem',
            'blocked-uri' => 'inline', 'line-number' => 12,
        ]]))->assertNoContent();

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'csp.violation'
            && $ctx['document'] === 'https://app.test/admin/acme' && $ctx['directive'] === 'script-src-elem');
    }

    public function test_the_upload_bucket_is_the_only_extra_origin(): void
    {
        config()->set('filesystems.default', 'local');
        $this->assertSame([], Csp::uploadOrigins());

        config()->set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'autnyx', 'region' => 'eu-central-1',
            'endpoint' => 'https://abc.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true]);
        config()->set('livewire.temporary_file_upload.disk', 's3');

        $this->assertSame(['https://abc.r2.cloudflarestorage.com'], Csp::uploadOrigins());
        $this->assertStringContainsString("connect-src 'self' https://abc.r2.cloudflarestorage.com", Csp::policy('n'));
    }
}
