<?php

namespace App\Services\Calendar;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * W13 — the default retail calendar every tenant starts with, for two years
 * back and one ahead. Islamic dates come from the Umm al-Qura calendar (ICU);
 * the actual day can move by one on moon sighting — a tenant edits its copy.
 * Defaults are inserted once per (event, year) and never overwritten: a
 * tenant's edits, deactivations and own events stay as they are.
 */
class RetailCalendarDefaults
{
    /** @var array<int,bool> tenants already ensured in this process */
    private static array $done = [];

    public function ensure(int $tenantId, ?int $fromYear = null, ?int $toYear = null): int
    {
        $y = (int) now()->year;
        $fromYear ??= $y - 2;
        $toYear ??= $y + 1;
        $key = $tenantId * 10000 + $fromYear;
        if (isset(self::$done[$key]) && $fromYear === $y - 2 && $toYear === $y + 1) {
            return 0;
        }
        self::$done[$key] = true;

        $rows = [];
        for ($year = $fromYear; $year <= $toYear; $year++) {
            foreach ($this->eventsFor($year) as $e) {
                $rows[] = array_merge($e, ['tenant_id' => $tenantId, 'year' => $year, 'source' => 'default',
                    'countries' => isset($e['countries']) ? json_encode($e['countries']) : null,
                    'categories' => null, 'notes' => $e['notes'] ?? null,
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $rows = array_map(fn ($r) => array_merge(['active' => true, 'lead_days' => 0, 'tail_days' => 0, 'kind' => 'seasonal'], $r), $rows);

        return $rows === [] ? 0 : DB::table('retail_events')->insertOrIgnore($rows);
    }

    public static function forget(): void
    {
        self::$done = [];
    }

    /** @return array<int,array<string,mixed>> */
    public function eventsFor(int $year): array
    {
        $out = [];
        // Islamic events: every Hijri year whose date falls in this Gregorian year.
        foreach ([$year - 580, $year - 579, $year - 578] as $hy) {
            $ramadan = $this->hijri($hy, 8, 1);
            $fitr    = $this->hijri($hy, 9, 1);
            $adha    = $this->hijri($hy, 11, 10);
            if ($ramadan && $fitr && (int) $ramadan->year === $year) {
                $out[] = ['key' => 'ramadan', 'name' => 'Ramadan', 'kind' => 'religious', 'starts_on' => $ramadan->toDateString(),
                    'ends_on' => $fitr->copy()->subDay()->toDateString(), 'lead_days' => 7, 'tail_days' => 0,
                    'notes' => 'Umm al-Qura dates; the start can move a day on moon sighting.'];
            }
            if ($fitr && (int) $fitr->year === $year) {
                $out[] = ['key' => 'eid_al_fitr', 'name' => 'Eid al-Fitr', 'kind' => 'religious', 'starts_on' => $fitr->toDateString(),
                    'ends_on' => $fitr->copy()->addDays(3)->toDateString(), 'lead_days' => 7, 'tail_days' => 7];
            }
            if ($adha && (int) $adha->year === $year) {
                $out[] = ['key' => 'eid_al_adha', 'name' => 'Eid al-Adha', 'kind' => 'religious', 'starts_on' => $adha->copy()->subDay()->toDateString(),
                    'ends_on' => $adha->copy()->addDays(3)->toDateString(), 'lead_days' => 7, 'tail_days' => 5];
            }
        }

        $d = fn (int $m, int $day) => Carbon::create($year, $m, $day)->toDateString();
        $nov1 = Carbon::create($year, 11, 1);
        $firstFri = $nov1->isFriday() ? $nov1->copy() : $nov1->copy()->next(Carbon::FRIDAY);
        $whiteFriday = $firstFri->copy()->addWeeks(3);   // the fourth Friday of November

        $out[] = ['key' => 'back_to_school', 'name' => 'Back to school', 'kind' => 'commercial', 'starts_on' => $d(8, 15), 'ends_on' => $d(9, 10),
            'notes' => 'GCC schools reopen late August; set your own dates per market.'];
        $out[] = ['key' => 'summer', 'name' => 'Summer holidays', 'kind' => 'seasonal', 'starts_on' => $d(7, 1), 'ends_on' => $d(8, 20),
            'notes' => 'Many residents travel; demand usually dips.'];
        $out[] = ['key' => 'christmas_new_year', 'name' => 'Christmas & New Year', 'kind' => 'commercial', 'starts_on' => $d(12, 18), 'ends_on' => $d(12, 31), 'lead_days' => 7, 'tail_days' => 1];
        $out[] = ['key' => 'white_friday', 'name' => 'White Friday / Black Friday', 'kind' => 'commercial',
            'starts_on' => $whiteFriday->toDateString(), 'ends_on' => $whiteFriday->copy()->addDays(3)->toDateString(), 'lead_days' => 3];
        $out[] = ['key' => 'uae_national_day', 'name' => 'UAE National Day', 'kind' => 'national', 'starts_on' => $d(12, 2), 'ends_on' => $d(12, 3), 'lead_days' => 2, 'countries' => ['AE']];
        $out[] = ['key' => 'saudi_national_day', 'name' => 'Saudi National Day', 'kind' => 'national', 'starts_on' => $d(9, 23), 'ends_on' => $d(9, 23), 'lead_days' => 2, 'countries' => ['SA']];
        $out[] = ['key' => 'saudi_founding_day', 'name' => 'Saudi Founding Day', 'kind' => 'national', 'starts_on' => $d(2, 22), 'ends_on' => $d(2, 22), 'lead_days' => 1, 'countries' => ['SA']];
        $out[] = ['key' => 'kuwait_national_days', 'name' => 'Kuwait National & Liberation Days', 'kind' => 'national', 'starts_on' => $d(2, 25), 'ends_on' => $d(2, 26), 'lead_days' => 2, 'countries' => ['KW']];
        $out[] = ['key' => 'qatar_national_day', 'name' => 'Qatar National Day', 'kind' => 'national', 'starts_on' => $d(12, 18), 'ends_on' => $d(12, 18), 'lead_days' => 2, 'countries' => ['QA']];
        $out[] = ['key' => 'bahrain_national_day', 'name' => 'Bahrain National Day', 'kind' => 'national', 'starts_on' => $d(12, 16), 'ends_on' => $d(12, 17), 'lead_days' => 2, 'countries' => ['BH']];
        // Gift occasions: narrow — off until the tenant says which categories they move.
        $out[] = ['key' => 'valentines_day', 'name' => "Valentine's Day", 'kind' => 'commercial', 'starts_on' => $d(2, 14), 'ends_on' => $d(2, 14), 'lead_days' => 7,
            'active' => false, 'notes' => 'Off by default: turn on and set the categories it moves (flowers, chocolate, gifts).'];
        $out[] = ['key' => 'mothers_day', 'name' => "Mother's Day", 'kind' => 'commercial', 'starts_on' => $d(3, 21), 'ends_on' => $d(3, 21), 'lead_days' => 7,
            'active' => false, 'notes' => 'Off by default: turn on and set the categories it moves.'];

        return $out;
    }

    private function hijri(int $hy, int $month0, int $day): ?Carbon
    {
        if (! class_exists(\IntlCalendar::class)) {
            return null;
        }
        $c = \IntlCalendar::createInstance('UTC', '@calendar=islamic-umalqura');
        $c->clear();
        // PHP 8.5: set() with 3 arguments is deprecated; setDate() where available.
        if (method_exists($c, 'setDate')) {
            $c->setDate($hy, $month0, $day);
        } else {
            $c->set(\IntlCalendar::FIELD_YEAR, $hy);
            $c->set(\IntlCalendar::FIELD_MONTH, $month0);
            $c->set(\IntlCalendar::FIELD_DAY_OF_MONTH, $day);
        }

        return Carbon::createFromTimestampUTC((int) floor($c->getTime() / 1000))->startOfDay();
    }
}
