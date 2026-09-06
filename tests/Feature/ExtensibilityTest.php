<?php

namespace Tests\Feature;

use App\Models\CustomAttributeDefinition;
use App\Models\CustomMetricDefinition;
use App\Models\CustomRuleDefinition;
use App\Platform\Extensibility\AttributeStore;
use App\Platform\Extensibility\CustomMetricService;
use App\Platform\Extensibility\Expression\Evaluator;
use App\Platform\Extensibility\ExtensibilityRegistry;
use App\Platform\Extensibility\RuleEvaluator;
use RuntimeException;
use Tests\TestCase;

/**
 * P3.2 — extensibility without migrations: a tenant adds a KPI, a rule and a
 * dimension as data (no schema change) and they resolve/compute/fire. Plus the
 * safe evaluator that makes tenant-authored expressions safe to run.
 */
class ExtensibilityTest extends TestCase
{
    // ---- the safe evaluator (no DB) ------------------------------------------

    public function test_evaluator_arithmetic_and_divide_by_zero(): void
    {
        $e = new Evaluator();

        // (10 + 5) * 2 = 30
        $ast = ['type' => 'op', 'op' => '*', 'args' => [
            ['type' => 'op', 'op' => '+', 'args' => [['type' => 'const', 'value' => 10], ['type' => 'const', 'value' => 5]]],
            ['type' => 'const', 'value' => 2],
        ]];
        $this->assertSame(30, $e->evaluate($ast));

        // divide by zero → null, not a crash
        $div = ['type' => 'op', 'op' => '/', 'args' => [['type' => 'const', 'value' => 5], ['type' => 'const', 'value' => 0]]];
        $this->assertNull($e->evaluate($div));
    }

    public function test_evaluator_boolean_and_comparison_with_vars(): void
    {
        $e = new Evaluator();

        // waste_rate > 0.1 AND objective_is_waste
        $ast = ['type' => 'op', 'op' => 'and', 'args' => [
            ['type' => 'op', 'op' => '>', 'args' => [['type' => 'var', 'name' => 'waste_rate'], ['type' => 'const', 'value' => 0.1]]],
            ['type' => 'var', 'name' => 'objective_is_waste'],
        ]];

        $this->assertTrue($e->evaluate($ast, ['waste_rate' => 0.2, 'objective_is_waste' => true]));
        $this->assertFalse($e->evaluate($ast, ['waste_rate' => 0.05, 'objective_is_waste' => true]));
        // missing var → comparison false → and false
        $this->assertFalse($e->evaluate($ast, ['objective_is_waste' => true]));
    }

    // ---- custom KPI ----------------------------------------------------------

    public function test_tenant_defined_metric_computes_from_a_var_bag(): void
    {
        $tenant = $this->createTenant();

        CustomMetricDefinition::create([
            'tenant_id'  => $tenant->id,
            'key'        => 'waste_rate',
            'label'      => 'Waste rate',
            'unit'       => 'ratio',
            'expression' => ['type' => 'op', 'op' => '/', 'args' => [
                ['type' => 'var', 'name' => 'units_wasted'],
                ['type' => 'var', 'name' => 'units_received'],
            ]],
            'active'     => true,
        ]);

        $service = app(CustomMetricService::class);
        $value = $service->compute($tenant->id, 'waste_rate', ['units_wasted' => 30, 'units_received' => 200]);

        $this->assertSame(0.15, $value);
        $this->assertNull($service->compute($tenant->id, 'does_not_exist', []));
    }

    // ---- custom rule ---------------------------------------------------------

    public function test_tenant_defined_rule_fires_only_when_condition_holds(): void
    {
        $tenant = $this->createTenant();

        CustomRuleDefinition::create([
            'tenant_id' => $tenant->id,
            'key'       => 'high_waste',
            'label'     => 'High waste',
            'severity'  => CustomRuleDefinition::SEVERITY_CRITICAL,
            'condition' => ['type' => 'op', 'op' => '>', 'args' => [
                ['type' => 'var', 'name' => 'waste_rate'],
                ['type' => 'const', 'value' => 0.1],
            ]],
            'active'    => true,
        ]);

        $evaluator = app(RuleEvaluator::class);

        $fired = $evaluator->fired($tenant->id, ['waste_rate' => 0.2]);
        $this->assertCount(1, $fired);
        $this->assertSame('high_waste', $fired->first()['key']);
        $this->assertSame('critical', $fired->first()['severity']);

        $this->assertCount(0, $evaluator->fired($tenant->id, ['waste_rate' => 0.05]));
    }

    // ---- custom dimension ----------------------------------------------------

    public function test_declared_dimension_stores_and_coerces_a_value(): void
    {
        $tenant = $this->createTenant();
        $store = app(AttributeStore::class);

        $store->declare($tenant->id, 'product', 'shelf_life_days', 'Shelf life (days)', CustomAttributeDefinition::TYPE_NUMBER);
        $store->set($tenant->id, 'product', 555, 'shelf_life_days', 14);

        // coerced to a number on read
        $this->assertSame(14, $store->get($tenant->id, 'product', 555, 'shelf_life_days'));
        $this->assertNull($store->get($tenant->id, 'product', 999, 'shelf_life_days'));
    }

    public function test_value_on_undeclared_dimension_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $store = app(AttributeStore::class);

        $this->expectException(RuntimeException::class);
        $store->set($tenant->id, 'product', 1, 'not_declared', 'x');
    }

    // ---- tenant isolation ----------------------------------------------------

    public function test_extensions_are_tenant_scoped(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();

        CustomMetricDefinition::create([
            'tenant_id' => $a->id, 'key' => 'only_a', 'label' => 'Only A', 'unit' => 'count',
            'expression' => ['type' => 'const', 'value' => 1], 'active' => true,
        ]);

        $registry = app(ExtensibilityRegistry::class);
        $this->assertSame(1, $registry->counts($a->id)['metrics']);
        $this->assertSame(0, $registry->counts($b->id)['metrics']);
        $this->assertNull(app(CustomMetricService::class)->compute($b->id, 'only_a', []));
    }
}
