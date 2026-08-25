<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * B1 currency: the central formatter is pure and display-only (no conversion).
 */
class MoneyTest extends TestCase
{
    public function test_normalize_falls_back_and_uppercases(): void
    {
        $this->assertSame('AED', Money::normalize(null));
        $this->assertSame('AED', Money::normalize('not-a-currency'));
        $this->assertSame('USD', Money::normalize('usd'));
        $this->assertSame('EUR', Money::normalize('EUR'));
    }

    public function test_symbol_uses_glyph_or_iso_code(): void
    {
        $this->assertSame('$', Money::symbol('USD'));
        $this->assertSame('€', Money::symbol('EUR'));
        // No glyph → the ISO code IS the symbol.
        $this->assertSame('AED', Money::symbol('AED'));
    }

    public function test_format_glyph_hugs_and_code_spaces(): void
    {
        $this->assertSame('$1,234.50', Money::format(1234.5, 'USD'));
        $this->assertSame('AED 1,000.00', Money::format(1000, 'AED'));
        $this->assertSame('AED 0.00', Money::format(null, 'AED'));
    }

    public function test_prefix_spacing(): void
    {
        $this->assertSame('$', Money::prefix('USD'));
        $this->assertSame('AED ', Money::prefix('AED'));
    }

    public function test_compact(): void
    {
        $this->assertSame('$1.5M', Money::compact(1_500_000, 'USD'));
        $this->assertSame('AED 2.5K', Money::compact(2500, 'AED'));
        $this->assertSame('€900', Money::compact(900, 'EUR'));
    }

    public function test_options_and_validity(): void
    {
        $opts = Money::options();
        $this->assertArrayHasKey('USD', $opts);
        $this->assertStringContainsString('US Dollar', $opts['USD']);
        $this->assertTrue(Money::isValid('AED'));
        $this->assertFalse(Money::isValid('ZZZ'));
    }
}
