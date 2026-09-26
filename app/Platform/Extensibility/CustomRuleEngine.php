<?php

namespace App\Platform\Extensibility;

use App\Models\CustomMetricDefinition;
use App\Models\CustomRuleDefinition;
use App\Platform\Extensibility\Expression\Evaluator;
use App\Platform\Extensibility\Expression\Formula;
use App\Support\Detection\ValueModel;
use InvalidArgumentException;

/**
 * W10 (WP10.6) — tenant rules and KPIs typed as formulas, run on the tenant's
 * own data.
 *
 *   rules    evaluated on every store × SKU position; each position where the
 *            condition holds is a hit (detection turns hits into anomalies of
 *            rule type `custom_rule`, one subject per rule key);
 *   KPIs     evaluated once on the tenant-level variables.
 *
 * Formulas compile to the Evaluator's AST, so nothing a tenant types is ever
 * executed as code, and a formula can only name the catalogue's variables.
 */
final class CustomRuleEngine
{
    public const VALUE_TYPES = [
        ValueModel::LOST_REVENUE => 'Lost revenue (counts toward revenue at risk)',
        ValueModel::CAPITAL      => 'Capital at cost (stock tied up or written off)',
        ValueModel::UPSIDE       => 'Upside (an opportunity)',
        ValueModel::DATA_QUALITY => 'No money figure (a process or data problem)',
    ];

    public function __construct(private readonly Evaluator $evaluator)
    {
    }

    /** @return array{ast:array, variables:array<int,string>} */
    public static function compileRule(string $formula): array
    {
        return Formula::compile($formula, CustomVariables::names('position'));
    }

    /**
     * A rule's condition: a formula over position variables that is a yes/no
     * test ("days_of_cover < 3"), not a number ("days_of_cover").
     *
     * @return array{ast:array, variables:array<int,string>}
     */
    public static function compileCondition(string $formula): array
    {
        $c = self::compileRule($formula);
        $root = $c['ast'];
        $isTest = ($root['type'] ?? null) === 'op' && in_array($root['op'], ['>', '>=', '<', '<=', '==', '!=', 'and', 'or', 'not'], true)
            || (($root['type'] ?? null) === 'const' && is_bool($root['value'] ?? null));
        if (! $isTest) {
            throw new InvalidArgumentException('A rule must be a yes/no test — compare something, e.g. "days_of_cover < 3".');
        }

        return $c;
    }

    /** @return array{ast:array, variables:array<int,string>} */
    public static function compileMetric(string $formula): array
    {
        return Formula::compile($formula, CustomVariables::names('tenant'));
    }

    /**
     * Hits of the given rules, streamed: one position at a time, the rules that
     * hold there. A rule that throws is reported in $failed and skipped from
     * then on (its open anomalies are left alone, not cleared).
     *
     * @param  iterable<CustomRuleDefinition>  $rules
     * @param  array<string,string>  $failed  key => error (out)
     * @return \Generator<int,array{rule:CustomRuleDefinition, position:array, impact:?float, inputs:array}>
     */
    public function hits(int $tenantId, iterable $rules, array &$failed = []): \Generator
    {
        $compiled = [];
        foreach ($rules as $rule) {
            try {
                $cond = $rule->formula ? self::compileCondition($rule->formula) : ['ast' => $rule->condition, 'variables' => []];
                $impact = $rule->impact_formula ? self::compileRule($rule->impact_formula)['ast'] : ($rule->impact ?: null);
                $compiled[$rule->key] = [$rule, $cond['ast'], $impact, $cond['variables']];
            } catch (\Throwable $e) {
                $failed[$rule->key] = $e->getMessage();
            }
        }
        if ($compiled === []) {
            return;
        }

        foreach (CustomVariables::positions($tenantId) as $pos) {
            foreach ($compiled as $key => [$rule, $ast, $impactAst, $vars]) {
                if (isset($failed[$key])) {
                    continue;
                }
                try {
                    if ($this->evaluator->evaluate($ast, $pos['vars']) !== true) {
                        continue;
                    }
                    $impact = $impactAst ? $this->evaluator->evaluate($impactAst, $pos['vars']) : null;
                } catch (\Throwable $e) {
                    $failed[$key] = $e->getMessage();
                    continue;
                }
                yield [
                    'rule'     => $rule,
                    'position' => $pos,
                    'impact'   => is_numeric($impact) ? round((float) $impact, 2) : null,
                    'inputs'   => array_intersect_key($pos['vars'], array_flip($vars)),
                ];
            }
        }
    }

    /**
     * What a formula would flag today — for the "test on my data" button.
     *
     * @return array{count:int, positions:int, impact_total:float, samples:array<int,array>}
     */
    public function preview(int $tenantId, string $formula, ?string $impactFormula = null, int $samples = 8): array
    {
        $rule = new CustomRuleDefinition(['key' => '__preview', 'label' => 'Preview', 'formula' => $formula,
            'impact_formula' => $impactFormula ?: null, 'condition' => [], 'severity' => 'warning']);
        $failed = [];
        $count = 0;
        $total = 0.0;
        $out = [];
        foreach ($this->hits($tenantId, [$rule], $failed) as $hit) {
            $count++;
            $total += (float) ($hit['impact'] ?? 0);
            if (count($out) < $samples) {
                $out[] = ['store_id' => $hit['position']['store_id'], 'sku' => $hit['position']['sku'],
                    'impact' => $hit['impact'], 'inputs' => $hit['inputs']];
            }
        }
        if (isset($failed['__preview'])) {
            throw new InvalidArgumentException($failed['__preview']);
        }

        return ['count' => $count, 'impact_total' => round($total, 2), 'samples' => $out];
    }

    /** One KPI's value now (null when the formula yields no value). */
    public function metricValue(CustomMetricDefinition $m, ?array $vars = null): int|float|bool|null
    {
        $vars ??= CustomVariables::tenant((int) $m->tenant_id);
        $ast = $m->formula ? self::compileMetric($m->formula)['ast'] : $m->expression;

        return $this->evaluator->evaluate($ast, $vars);
    }

    /**
     * Every active KPI of a tenant with its value, for the dashboard.
     *
     * @return array<int,array{key:string,label:string,unit:string,value:int|float|bool|null,description:?string}>
     */
    public function tenantKpis(int $tenantId): array
    {
        $defs = CustomMetricDefinition::where('tenant_id', $tenantId)->where('active', true)->orderBy('label')->get();
        if ($defs->isEmpty()) {
            return [];
        }
        $vars = CustomVariables::tenant($tenantId);

        return $defs->map(function (CustomMetricDefinition $m) use ($vars) {
            try {
                $value = $this->metricValue($m, $vars);
            } catch (\Throwable) {
                $value = null;
            }

            return ['key' => $m->key, 'label' => $m->label, 'unit' => $m->unit, 'value' => $value, 'description' => $m->description];
        })->all();
    }

    public static function formatValue(int|float|bool|null $v, string $unit, string $currency, bool $display = false): string
    {
        if ($v === null) {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }

        return match ($unit) {
            'money'   => $display ? \App\Support\Money::displayCompact((float) $v, $currency) : \App\Support\Money::compact((float) $v, $currency),
            'percent' => number_format((float) $v, 1) . '%',
            'days'    => number_format((float) $v, 1) . ' days',
            'count'   => number_format((float) $v, fmod((float) $v, 1.0) == 0.0 ? 0 : 1),
            default   => number_format((float) $v, 2),
        };
    }
}
