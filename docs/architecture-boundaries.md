# Module boundaries (platform-first)

Autnyx is a **platform** with apps on top: Root-Cause Intelligence (built),
Assortment Intelligence (next), Task Execution. To keep apps 2 and 3 sitting
*on* the platform rather than tangled into each other, we enforce one rule.

## The one rule

```
Apps\*      ->  Platform\*      ALLOWED
Apps\A      ->  Apps\B          FORBIDDEN   (apps never depend on each other)
Platform\*  ->  Apps\*          FORBIDDEN   (the platform never depends on an app)
```

An app is a **signal producer** on the shared spine
(`signal -> recommendation -> task -> outcome`). It reads platform data and
intelligence and emits platform recommendations/tasks. It must never reach
into another app's classes.

## Where new code goes

`config/architecture.php` is the source of truth — it maps every service and
model to a module. Pick the module by what the code *is*:

- Operational data (product, store, sales, inventory, PO, supplier) → `Platform\Data`
- Getting data in / data quality → `Platform\Ingestion`
- Demand profiling, seasonality, **clustering** → `Platform\Intelligence`
- "Suggested response" to any signal → `Platform\Recommendation`
- Adopted work with owner/status/outcome/ROI → `Platform\Tasks`
- Control plane / observability → `Platform\Ops`
- Tenancy / identity / storage / audit → the matching `Platform\*`
- Detection, investigation, recovery → `Apps\RootCause`
- The next app (distribution-gap engine) → `Apps\Assortment` (add it to the manifest)

New app code (e.g. Assortment) reads `Platform\Data` + `Platform\Intelligence`
and emits into `Platform\Recommendation` / `Platform\Tasks`. If you find
yourself writing `use App\Services\Anomaly\...` or `use App\Models\Anomaly`
from a new app, stop — that is the coupling this rule exists to prevent.

## Enforcement

`tests/Feature/ArchitectureBoundaryTest.php` scans `use App\...;` imports across
`app/Services` and `app/Models`, resolves each side to a module, and fails on any
forbidden dependency. It runs in the normal suite (CI's `php artisan test`) with
no database — pure static analysis.

Scope in v1 is the **service + model layer** (the platform spine). The UI layer
(Filament/Http) is a deliberate later expansion. Enforcement reads `use`
imports, so same-namespace references without a `use` (e.g. one `App\Models\*`
naming a sibling via `::class`) are not yet caught — cross-namespace service
wiring, where the spine actually lives, is fully covered.

## Reading a failure

A failure prints each offending line as
`SourceModule (file) --> TargetModule (class)`. Two ways to resolve it:

1. **You added new coupling** (the normal case) → fix the dependency. Route
   through a platform primitive instead of importing an app's class.
2. **It is genuinely pre-existing debt** → add the `path => Class` key to
   `baseline` in `config/architecture.php`. Do this sparingly and only for code
   that already existed; new code must obey the rule.

## The baseline is the burn-down backlog

`baseline` in the manifest holds today's 16 known violations, grouped by cause.
They are the decoupling roadmap, in priority order:

1. **Tasks → investigations** — generalise `Action`/outcomes with a polymorphic
   source so tasks are cross-app (highest value; unlocks App 3).
2. **Recommendation → root-cause** — promote a first-class `recommendations`
   primitive that apps emit into.
3. **Ingestion → detection**, **Audit → Investigation** — cheap early wins via a
   domain event and a polymorphic audit subject.
4. **Ops observability → root-cause counts** — lowest priority; have apps publish
   usage counters the control plane reads.

Do not grow the baseline. Shrink it as primitives are extracted.
