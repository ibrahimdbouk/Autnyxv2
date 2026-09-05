<?php

/*
|--------------------------------------------------------------------------
| Module boundary manifest (platform-first)
|--------------------------------------------------------------------------
|
| Autnyx is a PLATFORM with apps on top (see docs/platform-architecture in
| the project). This file declares the logical modules, maps the existing
| App\* code to them, and states the ONE hard dependency rule:
|
|     Apps\*  ->  Platform\*      ALLOWED
|     Apps\A  ->  Apps\B          FORBIDDEN  (apps never depend on each other)
|     Platform\* -> Apps\*        FORBIDDEN  (the platform never depends on an app)
|
| tests/Feature/ArchitectureBoundaryTest.php enforces this. The point is to
| stop NEW coupling from forming as apps 2 and 3 (Assortment, Tasks) get
| built, WITHOUT a big-bang move of existing code. Files are classified in
| place; nothing is physically relocated yet. Existing violations are
| enumerated in `baseline` below as documented debt to burn down — each one
| is a real extraction on the platform roadmap, not a thing to hide.
|
| SCOPE (v1): the service + model layer (`scan_paths`). This is the platform
| spine — signal -> recommendation -> task -> outcome. The UI layer
| (Filament/Http) has looser, higher-churn coupling and is a deliberate later
| expansion of this test, not an omission.
|
| LIMITATION (v1): enforcement reads `use App\...;` imports (the doc's
| "grep namespace imports"). Same-namespace references without a `use`
| (e.g. App\Models\* referencing a sibling model via `::class`) are not seen.
| Cross-NAMESPACE service coupling — where the real spine wiring lives — is
| fully covered.
|
| WHERE NEW CODE GOES: pick the module by what the code IS, not where it's
| convenient. A new app (Assortment) is an Apps\* signal producer that reads
| Platform\Data + Platform\Intelligence and emits Platform\Recommendation /
| Platform\Tasks. It must never `use` an Apps\RootCause class.
|
*/

return [

    // Only these trees are enforced in v1. Everything else is unclassified
    // and exempt until the test is widened.
    'scan_paths' => [
        'app/Services',
        'app/Models',
        'app/Platform',
    ],

    /*
    | module => list of path specs.
    |   - a spec ending in '/' is a directory prefix (all files under it)
    |   - anything else is an exact file path
    | Most-specific (longest) match wins, so a single file can be pulled out
    | of a directory that belongs to another module.
    */
    'modules' => [

        // ---- Platform ------------------------------------------------------

        // Operational read model shared by every app.
        'Platform\\Data' => [
            'app/Models/Product.php',
            'app/Models/Store.php',
            'app/Models/LocationNode.php',
            'app/Models/SalesTransaction.php',
            'app/Models/SalesDaily.php',
            'app/Models/SalesReturn.php',
            'app/Models/InventoryLevel.php',
            'app/Models/InventorySnapshot.php',
            'app/Models/PurchaseOrder.php',
            'app/Models/Supplier.php',
            'app/Services/Sales/',
        ],

        // Getting operational data in and keeping it clean.
        'Platform\\Ingestion' => [
            'app/Services/Import/',
            'app/Services/Sftp/',
            'app/Services/DataHealth/',
            'app/Models/Import.php',
            'app/Models/ImportColumnMap.php',
            'app/Models/ImportRow.php',
            'app/Models/IngestionRun.php',
            'app/Models/SftpConnection.php',
            'app/Models/SftpFeed.php',
            'app/Models/SftpIngestedFile.php',
            'app/Models/DataHealthSetting.php',
            'app/Models/DataHealthSnapshot.php',
        ],

        // Shared analytics: demand characterisation, seasonality, clustering.
        'Platform\\Intelligence' => [
            'app/Services/Anomaly/SkuProfilerService.php',
            'app/Services/Anomaly/SeasonalityService.php',
            'app/Models/SkuProfile.php',
            'app/Models/StoreCluster.php',
            'app/Models/StoreFeature.php',
            'app/Models/ClusterSet.php',
            'app/Models/ClusterPin.php',
            'app/Platform/Intelligence/',   // clustering + store feature layer
        ],

        // The unified "suggested response" layer.
        'Platform\\Recommendation' => [
            'app/Services/Anomaly/ReplenishmentRecommendationService.php',
            'app/Services/Anomaly/ReplenishmentService.php',
            'app/Services/Anomaly/ThresholdRecommenderService.php',
            'app/Models/SkuReplenishment.php',
        ],

        // Adopted recommendations -> work with an owner, status, outcome, ROI.
        // This is App 3's core; today it is rooted in root-cause (the debt in
        // `baseline`), to be generalised with a polymorphic source (design 4.4).
        'Platform\\Tasks' => [
            'app/Services/OutcomeService.php',
            'app/Services/Outcome/',
            'app/Models/Action.php',
            'app/Models/OutcomeMeasurement.php',
        ],

        // Control plane + observability.
        'Platform\\Ops' => [
            'app/Services/Ops/',
            'app/Models/JobRun.php',
        ],

        'Platform\\Tenancy' => [
            'app/Models/Tenant.php',
            'app/Models/Team.php',
            'app/Models/TeamMember.php',
        ],

        'Platform\\Identity' => [
            'app/Services/Sso/',
            'app/Models/User.php',
            'app/Models/SsoConnection.php',
        ],

        'Platform\\Storage' => [
            'app/Services/Storage/',
        ],

        'Platform\\Audit' => [
            'app/Services/AuditLogger.php',
            'app/Models/AuditLog.php',
        ],

        // Cross-cutting delivery of notifications. App-neutral fan-out.
        'Platform\\Notifications' => [
            'app/Services/NotificationDispatcher.php',
        ],

        // ---- Apps ----------------------------------------------------------

        // Root-Cause Intelligence: detection, investigation, recovery. The one
        // built app. Its domain logic stays here and is NOT platformised.
        'Apps\\RootCause' => [
            'app/Services/Anomaly/',        // detection + investigation engine
            'app/Services/Detection/',      // incremental detection
            'app/Services/Recovery/',       // anomaly lifecycle / recovery
            'app/Services/Noise/',          // suppression / snooze
            'app/Services/Watch/',          // watched investigations
            'app/Services/Collaboration/',  // investigation comments
            'app/Services/Quality/',        // detection precision/recall
            'app/Services/Reporting/',      // anomaly / investigation reports
            'app/Services/EscalationService.php',
            'app/Services/InvestigationNarratorService.php',
            'app/Models/Anomaly.php',
            'app/Models/AnomalySetting.php',
            'app/Models/Investigation.php',
            'app/Models/InvestigationComment.php',
            'app/Models/InvestigationEntity.php',
            'app/Models/InvestigationEvidence.php',
            'app/Models/InvestigationOutcome.php',
            'app/Models/InvestigationWatch.php',
            'app/Models/WatchNotification.php',
            'app/Models/CommentMention.php',
            'app/Models/Suppression.php',
            'app/Models/SkuBaseline.php',
            'app/Models/EscalationEvent.php',
            'app/Models/EscalationRule.php',
            'app/Models/DetectionDirtyKey.php',
        ],
    ],

    /*
    | Existing cross-boundary imports, baselined as documented debt. The test
    | FAILS on any NEW violation but tolerates these. Burn the list down as the
    | primitives are extracted; do not add to it. Format: 'sourceRelPath => Used\\Class'.
    */
    'baseline' => [
        // ---- Platform\Tasks depends on RootCause investigations -------------
        // The task/outcome layer is rooted in root-cause (created from an
        // investigation). RESOLVES: generalise Action + outcomes with a
        // polymorphic source (anomaly | gap | rec), design 4.4. Highest-value
        // burn-down — it is what makes App 3 (Tasks) cross-app.
        'app/Services/OutcomeService.php => App\\Models\\Investigation',
        'app/Services/OutcomeService.php => App\\Models\\InvestigationOutcome',
        'app/Services/Outcome/OutcomeMeasurementService.php => App\\Models\\Investigation',
        'app/Services/Outcome/OutcomeMeasurementService.php => App\\Models\\InvestigationOutcome',
        'app/Services/Outcome/RecoveryReportService.php => App\\Models\\Investigation',
        'app/Services/Outcome/RecoveryReportService.php => App\\Models\\InvestigationOutcome',

        // ---- Platform\Recommendation reaches back into RootCause -----------
        // RESOLVES: first-class `recommendations` primitive that apps emit into
        // (design 4.3); root-cause becomes just another emitter.
        'app/Services/Anomaly/ReplenishmentRecommendationService.php => App\\Models\\Anomaly',
        'app/Services/Anomaly/ReplenishmentRecommendationService.php => App\\Models\\Investigation',
        'app/Services/Anomaly/ThresholdRecommenderService.php => App\\Models\\AnomalySetting',

        // ---- Platform\Ingestion calls RootCause directly -------------------
        // ImportProcessor kicks detection inline; DataHealth types on Investigation.
        // RESOLVES (cheap early win): emit an "import completed" domain event that
        // root-cause subscribes to, instead of a direct call.
        'app/Services/Import/ImportProcessorService.php => App\\Services\\Anomaly\\AnomalyDetectionService',
        'app/Services/DataHealth/DataHealthService.php => App\\Models\\Investigation',

        // ---- Platform\Audit types on a RootCause model ---------------------
        // RESOLVES (cheap early win): audit logs a polymorphic subject, not Investigation.
        'app/Services/AuditLogger.php => App\\Models\\Investigation',

        // ---- Platform\Ops observability reads RootCause counts -------------
        // Control-plane metrics count anomalies/investigations. Lowest priority —
        // arguably legitimate observability. RESOLVES: apps publish usage counters
        // the platform reads, rather than Ops querying app tables.
        'app/Services/Ops/GrowthMetricsService.php => App\\Models\\Anomaly',
        'app/Services/Ops/TenantUsageService.php => App\\Models\\Anomaly',
        'app/Services/Ops/TenantUsageService.php => App\\Models\\Investigation',
        'app/Services/Ops/TenantUsageService.php => App\\Services\\Recovery\\AnomalyRecoveryService',
    ],
];
