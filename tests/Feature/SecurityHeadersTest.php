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

    public function test_csp_is_report_only_by_default(): void
    {
        config()->set('autnyx.csp_mode', 'report');

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($csp, 'report-only header set');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString('cdnjs.cloudflare.com', $csp);
        $this->assertNull($response->headers->get('Content-Security-Policy'), 'not enforced in report mode');
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
