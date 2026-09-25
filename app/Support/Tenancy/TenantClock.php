<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WP5.2 (D3) — a tenant's own day. "Today", "overdue" and "month to date" are
 * judged on the tenant's wall clock (default Asia/Dubai), not the server's UTC.
 *
 * Boundaries for TIMESTAMP columns (today(), startOfMonth(), dayRange(),
 * weekRange()) are returned in the APP timezone: query bindings are formatted
 * without a zone, so the instant has to be expressed in the zone the column is
 * stored in. DATE columns compare with localDate() / localMonthStart().
 */
final class TenantClock
{
    public const DEFAULT_TZ = 'Asia/Dubai';

    /** @var array<int,string> */
    private static array $zones = [];

    public static function timezone(?int $tenantId): string
    {
        if (! $tenantId) {
            return self::DEFAULT_TZ;
        }
        if (! isset(self::$zones[$tenantId])) {
            $tz = null;
            try {
                $tz = DB::table('tenants')->where('id', $tenantId)->value('timezone');
            } catch (\Throwable) {
                // column not migrated yet
            }
            self::$zones[$tenantId] = self::valid($tz) ? $tz : self::DEFAULT_TZ;
        }

        return self::$zones[$tenantId];
    }

    public static function now(?int $tenantId): Carbon
    {
        return Carbon::now(self::timezone($tenantId));
    }

    /** Start of the tenant's today, as an instant in the app timezone. */
    public static function today(?int $tenantId): Carbon
    {
        return self::app(self::now($tenantId)->startOfDay());
    }

    /** Start of the tenant's month, as an instant in the app timezone. */
    public static function startOfMonth(?int $tenantId): Carbon
    {
        return self::app(self::now($tenantId)->startOfMonth());
    }

    /** @return array{0:Carbon,1:Carbon} the tenant's today, app timezone */
    public static function dayRange(?int $tenantId): array
    {
        $local = self::now($tenantId);

        return [self::app($local->copy()->startOfDay()), self::app($local->copy()->endOfDay())];
    }

    /** @return array{0:Carbon,1:Carbon} today → end of the tenant's week, app timezone */
    public static function weekRange(?int $tenantId): array
    {
        $local = self::now($tenantId);

        return [self::app($local->copy()->startOfDay()), self::app($local->copy()->endOfWeek())];
    }

    /** The tenant's current date, for DATE columns. */
    public static function localDate(?int $tenantId): string
    {
        return self::now($tenantId)->toDateString();
    }

    public static function localMonthStart(?int $tenantId): string
    {
        return self::now($tenantId)->startOfMonth()->toDateString();
    }

    /**
     * WP7.1 — "month to date" compared like with like: the start of the
     * tenant's previous month and the same point in it as now (31 March →
     * 28/29 February, never 3 March). Both as instants in the app timezone.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function samePeriodLastMonth(?int $tenantId): array
    {
        $prev = self::now($tenantId)->subMonthNoOverflow();

        return [self::app($prev->copy()->startOfMonth()), self::app($prev)];
    }

    /**
     * WP7.3 — SQL for the tenant-local calendar date of a TIMESTAMP column
     * (stored in the app timezone), for grouping by the tenant's day. Both
     * zones are validated identifiers, so inlining them is safe.
     */
    public static function localDateSql(string $column, ?int $tenantId): string
    {
        $app = config('app.timezone', 'UTC');
        $app = self::valid($app) ? $app : 'UTC';

        return "(({$column} AT TIME ZONE '{$app}') AT TIME ZONE '" . self::timezone($tenantId) . "')::date";
    }

    private static function app(Carbon $c): Carbon
    {
        return $c->setTimezone(config('app.timezone', 'UTC'));
    }

    /**
     * WP7.3 — a stored timestamp shown on the current tenant's wall clock
     * (the Filament tenant; app timezone outside a tenant panel).
     */
    public static function display(?\DateTimeInterface $at): ?Carbon
    {
        if ($at === null) {
            return null;
        }

        return Carbon::instance($at)->setTimezone(self::displayTimezone());
    }

    public static function displayTimezone(): string
    {
        $tenantId = rescue(fn () => \Filament\Facades\Filament::getTenant()?->getKey(), null, false);

        return $tenantId ? self::timezone((int) $tenantId) : (self::valid(config('app.timezone')) ? config('app.timezone') : 'UTC');
    }

    public static function valid(?string $tz): bool
    {
        return is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true);
    }

    public static function forget(): void
    {
        self::$zones = [];
    }
}
