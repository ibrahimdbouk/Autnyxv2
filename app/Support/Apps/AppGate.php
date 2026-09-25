<?php

namespace App\Support\Apps;

use App\Filament\Pages;
use App\Filament\Resources;
use App\Models\Tenant;

/**
 * WP7.4 (audit L11) — app entitlements decide access, not just navigation.
 *
 * Every tenant screen belongs to an app or is shared by all of them. A class
 * may declare `const APP_KEY` itself (new apps do, through GatedByApp); the
 * Root-Cause screens are listed here. Shared: the dashboard, data (imports,
 * master and fact data, data quality), clustering, and administration.
 * Enforced for every request and Livewire update by EnsureAppEntitlement.
 */
final class AppGate
{
    /** @var array<int,class-string> */
    public const ROOT_CAUSE = [
        Resources\AnomalyResource::class,
        Resources\AnomalySettingResource::class,
        Resources\InvestigationResource::class,
        Resources\SuppressionResource::class,
        Resources\ReplenishmentResource::class,
        Resources\PlanningExceptionResource::class,
        Pages\ActionCenter::class,
        Pages\ActionQueue::class,
        Pages\DailyBriefing::class,
        Pages\WeeklyBriefing::class,
        Pages\FollowUps::class,
        Pages\SupplierPrep::class,
        Pages\WatchedInvestigations::class,
        Pages\InvestigationsByStore::class,
        Pages\FinancialBreakdown::class,
        Pages\QualityCenter::class,
        Pages\Reports::class,
    ];

    /** The app a page, resource or resource page belongs to; null when shared. */
    public static function appFor(?string $class): ?string
    {
        if ($class === null || ! class_exists($class)) {
            return null;
        }
        if (defined($class . '::APP_KEY')) {
            return constant($class . '::APP_KEY');
        }
        // A resource's pages belong to their resource.
        if (is_subclass_of($class, \Filament\Resources\Pages\Page::class)) {
            return self::appFor($class::getResource());
        }

        return in_array($class, self::ROOT_CAUSE, true) ? Tenant::APP_ROOT_CAUSE : null;
    }

    public static function allows(?Tenant $tenant, ?string $class): bool
    {
        $app = self::appFor($class);

        return $app === null || ! $tenant instanceof Tenant || $tenant->hasApp($app);
    }
}
