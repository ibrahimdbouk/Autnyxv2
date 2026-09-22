<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * INC-014 guard — every tenant-scoped Filament resource's model MUST define its
 * ownership relationship (default `tenant`). Filament auto-scopes tenant-aware
 * resources through that relationship, so a model missing it 500s the resource's
 * index page under tenancy ("model has no relationship named [tenant]").
 *
 * This is invisible to the route smoke test, which renders index pages WITHOUT a
 * current tenant set — so tenant scoping never runs there. This structural check
 * closes that gap. See claude/incidents.md (INC-014).
 */
class TenantScopingIntegrityTest extends TestCase
{
    public function test_tenant_scoped_resources_define_ownership_relationship(): void
    {
        $panel = Filament::getPanel('admin');
        $missing = [];

        foreach ($panel->getResources() as $resourceClass) {
            if (! $resourceClass::isScopedToTenant()) {
                continue;
            }
            $model = $resourceClass::getModel();
            $relationship = $resourceClass::getTenantOwnershipRelationshipName();
            if (! method_exists(new $model, $relationship)) {
                $missing[] = "{$resourceClass} → {$model} lacks ownership relationship [{$relationship}]()";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Tenant-scoped resources missing their ownership relationship (index page will 500 under tenancy):\n" . implode("\n", $missing),
        );
    }
}
