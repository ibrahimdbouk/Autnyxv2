<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 3b — baseline security response headers + the env-controlled CSP.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_baseline_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotNull($response->headers->get('Permissions-Policy'));
    }

    public function test_csp_report_mode_never_blocks(): void
    {
        config()->set('autnyx.csp_mode', 'report');

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($csp, 'report-only header set');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertNull($response->headers->get('Content-Security-Policy'), 'not enforced in report mode');
    }

    public function test_csp_is_enforced_by_default_with_a_nonce_and_no_inline_script(): void
    {
        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{20,}' 'unsafe-eval'/", $csp);
        preg_match('/script-src [^;]+/', $csp, $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc[0], 'inline scripts need the nonce');
        $this->assertStringNotContainsString('cdnjs', $csp, 'Chart.js is self-hosted');
        $this->assertStringContainsString("script-src-attr 'none'", $csp);
        $this->assertStringContainsString('report-uri', $csp);
    }

    public function test_csp_enforce_mode_sends_blocking_header(): void
    {
        config()->set('autnyx.csp_mode', 'enforce');

        $response = $this->get('/');

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_csp_off_mode_sends_no_csp(): void
    {
        config()->set('autnyx.csp_mode', 'off');

        $response = $this->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
