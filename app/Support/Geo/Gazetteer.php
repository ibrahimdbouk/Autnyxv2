<?php

namespace App\Support\Geo;

/**
 * v2.5 — where the main GCC (and nearby) cities are, so a store with a city
 * but no coordinates can still be placed on the schematic map: at its city,
 * drawn as approximate. City centres, to about a kilometre; matched on the
 * store's city (or region) name, ignoring case, "Al" / "El", spaces, hyphens
 * and a trailing "city".
 */
final class Gazetteer
{
    /** @var array<string, array{0: float, 1: float}> name => [latitude, longitude] */
    public const CITIES = [
        // United Arab Emirates
        'Dubai' => [25.2048, 55.2708], 'Abu Dhabi' => [24.4539, 54.3773], 'Sharjah' => [25.3463, 55.4209], 'Ajman' => [25.4052, 55.5136],
        'Umm Al Quwain' => [25.5647, 55.5552], 'Ras Al Khaimah' => [25.8007, 55.9762], 'Fujairah' => [25.1288, 56.3265], 'Al Ain' => [24.1302, 55.8023],
        'Khor Fakkan' => [25.3390, 56.3560], 'Dibba' => [25.6196, 56.2729], 'Kalba' => [25.0742, 56.3553], 'Ruwais' => [24.1100, 52.7300],
        'Madinat Zayed' => [23.6833, 53.7000], 'Hatta' => [24.7964, 56.1168], 'Jebel Ali' => [25.0110, 55.0610],
        // Saudi Arabia
        'Riyadh' => [24.7136, 46.6753], 'Jeddah' => [21.4858, 39.1925], 'Makkah' => [21.3891, 39.8579], 'Mecca' => [21.3891, 39.8579],
        'Madinah' => [24.5247, 39.5692], 'Medina' => [24.5247, 39.5692], 'Dammam' => [26.4207, 50.0888], 'Khobar' => [26.2172, 50.1971],
        'Dhahran' => [26.2361, 50.0393], 'Jubail' => [27.0046, 49.6460], 'Taif' => [21.2703, 40.4158], 'Tabuk' => [28.3835, 36.5662],
        'Abha' => [18.2164, 42.5053], 'Khamis Mushait' => [18.3000, 42.7333], 'Buraidah' => [26.3260, 43.9750], 'Hail' => [27.5114, 41.7208],
        'Najran' => [17.4924, 44.1277], 'Jazan' => [16.8892, 42.5511], 'Yanbu' => [24.0890, 38.0618], 'Al Ahsa' => [25.3833, 49.5833], 'Hofuf' => [25.3833, 49.5833],
        // Qatar, Kuwait, Bahrain
        'Doha' => [25.2854, 51.5310], 'Al Wakrah' => [25.1659, 51.5976], 'Al Rayyan' => [25.2919, 51.4244], 'Lusail' => [25.4200, 51.4900],
        'Kuwait City' => [29.3759, 47.9774], 'Kuwait' => [29.3759, 47.9774], 'Hawalli' => [29.3328, 48.0286], 'Salmiya' => [29.3339, 48.0761],
        'Jahra' => [29.3375, 47.6581], 'Ahmadi' => [29.0769, 48.0839], 'Farwaniya' => [29.2775, 47.9586],
        'Manama' => [26.2285, 50.5860], 'Muharraq' => [26.2572, 50.6119], 'Riffa' => [26.1300, 50.5550], 'Isa Town' => [26.1736, 50.5478],
        // Oman
        'Muscat' => [23.5880, 58.3829], 'Seeb' => [23.6703, 58.1890], 'Salalah' => [17.0151, 54.0924], 'Sohar' => [24.3470, 56.7436],
        'Nizwa' => [22.9333, 57.5333], 'Sur' => [22.5667, 59.5289], 'Ibri' => [23.2257, 56.5157], 'Buraimi' => [24.2508, 55.7931],
        // Levant and Egypt
        'Beirut' => [33.8938, 35.5018], 'Tripoli' => [34.4367, 35.8497], 'Saida' => [33.5571, 35.3729], 'Amman' => [31.9454, 35.9284],
        'Irbid' => [32.5556, 35.8500], 'Zarqa' => [32.0728, 36.0880], 'Cairo' => [30.0444, 31.2357], 'Alexandria' => [31.2001, 29.9187], 'Giza' => [30.0131, 31.2089],
    ];

    /** @return array{0: float, 1: float}|null [latitude, longitude] of a city name, or null when unknown */
    public static function find(?string $name): ?array
    {
        $key = self::key((string) $name);
        if ($key === '') {
            return null;
        }
        static $index = null;
        $index ??= array_combine(array_map([self::class, 'key'], array_keys(self::CITIES)), array_values(self::CITIES));

        return $index[$key] ?? null;
    }

    /** The canonical name of a known city (for map labels), or null. */
    public static function label(?string $name): ?string
    {
        $key = self::key((string) $name);
        foreach (array_keys(self::CITIES) as $city) {
            if (self::key($city) === $key) {
                return $city;
            }
        }

        return null;
    }

    private static function key(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+city$/u', '', $s) ?? $s;
        $s = preg_replace('/^(el|al)[\s-]+/u', 'al ', $s) ?? $s;
        $s = str_replace(['-', '_', '.', "'"], ' ', $s);

        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }
}
