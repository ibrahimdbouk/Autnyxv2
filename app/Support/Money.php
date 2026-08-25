<?php

namespace App\Support;

/**
 * Central currency + money formatting.
 *
 * Autnyx is display-only for currency: every stored monetary value is already
 * in the tenant's home currency, so there is NO conversion — a tenant's chosen
 * currency simply relabels the numbers it already holds. This class owns the
 * canonical currency list, symbol map, and formatting so the whole platform
 * (dashboards, anomaly descriptions, Detection Rules thresholds, narration)
 * speaks one currency consistently.
 */
final class Money
{
    /** Fallback when a tenant has not chosen a currency yet. */
    public const DEFAULT = 'AED';

    /**
     * ISO 4217 active currencies (code => human name). Kept broad on purpose so
     * the tenant admin can pick "all currencies". Not exhaustive of every minor
     * code, but covers the world's traded currencies.
     */
    public const CURRENCIES = [
        'AED' => 'UAE Dirham',
        'AFN' => 'Afghan Afghani',
        'ALL' => 'Albanian Lek',
        'AMD' => 'Armenian Dram',
        'ANG' => 'Netherlands Antillean Guilder',
        'AOA' => 'Angolan Kwanza',
        'ARS' => 'Argentine Peso',
        'AUD' => 'Australian Dollar',
        'AWG' => 'Aruban Florin',
        'AZN' => 'Azerbaijani Manat',
        'BAM' => 'Bosnia-Herzegovina Convertible Mark',
        'BBD' => 'Barbadian Dollar',
        'BDT' => 'Bangladeshi Taka',
        'BGN' => 'Bulgarian Lev',
        'BHD' => 'Bahraini Dinar',
        'BIF' => 'Burundian Franc',
        'BMD' => 'Bermudan Dollar',
        'BND' => 'Brunei Dollar',
        'BOB' => 'Bolivian Boliviano',
        'BRL' => 'Brazilian Real',
        'BSD' => 'Bahamian Dollar',
        'BTN' => 'Bhutanese Ngultrum',
        'BWP' => 'Botswanan Pula',
        'BYN' => 'Belarusian Ruble',
        'BZD' => 'Belize Dollar',
        'CAD' => 'Canadian Dollar',
        'CDF' => 'Congolese Franc',
        'CHF' => 'Swiss Franc',
        'CLP' => 'Chilean Peso',
        'CNY' => 'Chinese Yuan',
        'COP' => 'Colombian Peso',
        'CRC' => 'Costa Rican Colón',
        'CUP' => 'Cuban Peso',
        'CVE' => 'Cape Verdean Escudo',
        'CZK' => 'Czech Koruna',
        'DJF' => 'Djiboutian Franc',
        'DKK' => 'Danish Krone',
        'DOP' => 'Dominican Peso',
        'DZD' => 'Algerian Dinar',
        'EGP' => 'Egyptian Pound',
        'ERN' => 'Eritrean Nakfa',
        'ETB' => 'Ethiopian Birr',
        'EUR' => 'Euro',
        'FJD' => 'Fijian Dollar',
        'FKP' => 'Falkland Islands Pound',
        'GBP' => 'British Pound',
        'GEL' => 'Georgian Lari',
        'GHS' => 'Ghanaian Cedi',
        'GIP' => 'Gibraltar Pound',
        'GMD' => 'Gambian Dalasi',
        'GNF' => 'Guinean Franc',
        'GTQ' => 'Guatemalan Quetzal',
        'GYD' => 'Guyanaese Dollar',
        'HKD' => 'Hong Kong Dollar',
        'HNL' => 'Honduran Lempira',
        'HRK' => 'Croatian Kuna',
        'HTG' => 'Haitian Gourde',
        'HUF' => 'Hungarian Forint',
        'IDR' => 'Indonesian Rupiah',
        'ILS' => 'Israeli New Shekel',
        'INR' => 'Indian Rupee',
        'IQD' => 'Iraqi Dinar',
        'IRR' => 'Iranian Rial',
        'ISK' => 'Icelandic Króna',
        'JMD' => 'Jamaican Dollar',
        'JOD' => 'Jordanian Dinar',
        'JPY' => 'Japanese Yen',
        'KES' => 'Kenyan Shilling',
        'KGS' => 'Kyrgystani Som',
        'KHR' => 'Cambodian Riel',
        'KMF' => 'Comorian Franc',
        'KRW' => 'South Korean Won',
        'KWD' => 'Kuwaiti Dinar',
        'KYD' => 'Cayman Islands Dollar',
        'KZT' => 'Kazakhstani Tenge',
        'LAK' => 'Laotian Kip',
        'LBP' => 'Lebanese Pound',
        'LKR' => 'Sri Lankan Rupee',
        'LRD' => 'Liberian Dollar',
        'LSL' => 'Lesotho Loti',
        'LYD' => 'Libyan Dinar',
        'MAD' => 'Moroccan Dirham',
        'MDL' => 'Moldovan Leu',
        'MGA' => 'Malagasy Ariary',
        'MKD' => 'Macedonian Denar',
        'MMK' => 'Myanmar Kyat',
        'MNT' => 'Mongolian Tugrik',
        'MOP' => 'Macanese Pataca',
        'MRU' => 'Mauritanian Ouguiya',
        'MUR' => 'Mauritian Rupee',
        'MVR' => 'Maldivian Rufiyaa',
        'MWK' => 'Malawian Kwacha',
        'MXN' => 'Mexican Peso',
        'MYR' => 'Malaysian Ringgit',
        'MZN' => 'Mozambican Metical',
        'NAD' => 'Namibian Dollar',
        'NGN' => 'Nigerian Naira',
        'NIO' => 'Nicaraguan Córdoba',
        'NOK' => 'Norwegian Krone',
        'NPR' => 'Nepalese Rupee',
        'NZD' => 'New Zealand Dollar',
        'OMR' => 'Omani Rial',
        'PAB' => 'Panamanian Balboa',
        'PEN' => 'Peruvian Sol',
        'PGK' => 'Papua New Guinean Kina',
        'PHP' => 'Philippine Peso',
        'PKR' => 'Pakistani Rupee',
        'PLN' => 'Polish Zloty',
        'PYG' => 'Paraguayan Guarani',
        'QAR' => 'Qatari Rial',
        'RON' => 'Romanian Leu',
        'RSD' => 'Serbian Dinar',
        'RUB' => 'Russian Ruble',
        'RWF' => 'Rwandan Franc',
        'SAR' => 'Saudi Riyal',
        'SBD' => 'Solomon Islands Dollar',
        'SCR' => 'Seychellois Rupee',
        'SDG' => 'Sudanese Pound',
        'SEK' => 'Swedish Krona',
        'SGD' => 'Singapore Dollar',
        'SHP' => 'St. Helena Pound',
        'SLE' => 'Sierra Leonean Leone',
        'SOS' => 'Somali Shilling',
        'SRD' => 'Surinamese Dollar',
        'SSP' => 'South Sudanese Pound',
        'STN' => 'São Tomé & Príncipe Dobra',
        'SYP' => 'Syrian Pound',
        'SZL' => 'Swazi Lilangeni',
        'THB' => 'Thai Baht',
        'TJS' => 'Tajikistani Somoni',
        'TMT' => 'Turkmenistani Manat',
        'TND' => 'Tunisian Dinar',
        'TOP' => 'Tongan Paʻanga',
        'TRY' => 'Turkish Lira',
        'TTD' => 'Trinidad & Tobago Dollar',
        'TWD' => 'New Taiwan Dollar',
        'TZS' => 'Tanzanian Shilling',
        'UAH' => 'Ukrainian Hryvnia',
        'UGX' => 'Ugandan Shilling',
        'USD' => 'US Dollar',
        'UYU' => 'Uruguayan Peso',
        'UZS' => 'Uzbekistani Som',
        'VES' => 'Venezuelan Bolívar',
        'VND' => 'Vietnamese Dong',
        'VUV' => 'Vanuatu Vatu',
        'WST' => 'Samoan Tala',
        'XAF' => 'Central African CFA Franc',
        'XCD' => 'East Caribbean Dollar',
        'XOF' => 'West African CFA Franc',
        'XPF' => 'CFP Franc',
        'YER' => 'Yemeni Rial',
        'ZAR' => 'South African Rand',
        'ZMW' => 'Zambian Kwacha',
        'ZWL' => 'Zimbabwean Dollar',
    ];

    /**
     * Symbols for the common currencies. Any code without an entry renders with
     * its ISO code as the "symbol" (e.g. "AED 1,200"), which is unambiguous and
     * the sensible default for a multi-currency B2B product.
     */
    private const SYMBOLS = [
        'USD' => '$',  'EUR' => '€',  'GBP' => '£',  'JPY' => '¥',  'CNY' => '¥',
        'INR' => '₹',  'KRW' => '₩',  'RUB' => '₽',  'TRY' => '₺',  'BRL' => 'R$',
        'ZAR' => 'R',  'NGN' => '₦',  'THB' => '฿',  'VND' => '₫',  'PHP' => '₱',
        'ILS' => '₪',  'UAH' => '₴',  'PLN' => 'zł', 'CHF' => 'CHF','AUD' => 'A$',
        'CAD' => 'C$', 'HKD' => 'HK$','SGD' => 'S$', 'NZD' => 'NZ$',
    ];

    /** True if the code is a currency we know. */
    public static function isValid(?string $code): bool
    {
        return $code !== null && isset(self::CURRENCIES[strtoupper($code)]);
    }

    /** Normalise to a known code, falling back to the default. */
    public static function normalize(?string $code): string
    {
        $code = strtoupper((string) $code);

        return isset(self::CURRENCIES[$code]) ? $code : self::DEFAULT;
    }

    /** The display symbol for a code (its ISO code if we have no glyph). */
    public static function symbol(?string $code): string
    {
        $code = self::normalize($code);

        return self::SYMBOLS[$code] ?? $code;
    }

    /**
     * A prefix suitable for gluing before a raw number (labels, JS, charts):
     * a glyph hugs the number ("$"), an ISO-code fallback gets a trailing space
     * ("AED ").
     */
    public static function prefix(?string $code): string
    {
        $code = self::normalize($code);
        $sym  = self::symbol($code);

        return $sym === $code ? $sym . ' ' : $sym;
    }

    /**
     * Format an amount in a currency, e.g. "AED 1,234.56" or "$1,234.56".
     * A symbol we have a glyph for hugs the number ("$1,234.56"); an ISO-code
     * "symbol" gets a space ("AED 1,234.56").
     */
    public static function format(float|int|null $amount, ?string $code = null, int $decimals = 2): string
    {
        $code   = self::normalize($code);
        $symbol = self::symbol($code);
        $n      = number_format((float) ($amount ?? 0), $decimals);

        // Glyph symbols hug the number; ISO-code fallbacks get a separating space.
        return $symbol === $code ? "{$symbol} {$n}" : "{$symbol}{$n}";
    }

    /**
     * Compact money for dashboards/charts: "AED 1.2M", "$980K", "€1,200".
     */
    public static function compact(float|int|null $amount, ?string $code = null): string
    {
        $v      = (float) ($amount ?? 0);
        $code   = self::normalize($code);
        $symbol = self::symbol($code);
        $glue   = $symbol === $code ? ' ' : '';

        if (abs($v) >= 1_000_000) {
            return "{$symbol}{$glue}" . number_format($v / 1_000_000, 1) . 'M';
        }
        if (abs($v) >= 1_000) {
            return "{$symbol}{$glue}" . number_format($v / 1_000, 1) . 'K';
        }

        return "{$symbol}{$glue}" . number_format($v, 0);
    }

    /**
     * Options for a Filament/HTML select: code => "AED — UAE Dirham".
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::CURRENCIES as $code => $name) {
            $out[$code] = "{$code} — {$name}";
        }

        return $out;
    }
}
