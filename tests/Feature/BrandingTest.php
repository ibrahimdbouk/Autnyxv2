<?php

namespace Tests\Feature;

use App\Support\Branding;
use Tests\TestCase;

/**
 * Branding drives the panel render hook + logo slot on every page, so it must
 * never throw. The logo is optional — absent by default, present when a file is
 * dropped in.
 */
class BrandingTest extends TestCase
{
    public function test_logo_is_null_when_no_file_present(): void
    {
        // No public/images/autnyx-logo.* committed → falls back to brand name.
        $this->assertNull(Branding::logoUrl());
    }

    public function test_css_version_is_a_nonempty_string(): void
    {
        $this->assertIsString(Branding::cssVersion());
        $this->assertNotSame('', Branding::cssVersion());
    }
}
