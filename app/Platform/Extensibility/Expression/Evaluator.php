<?php

namespace App\Platform\Extensibility\Expression;

use InvalidArgumentException;

/**
 * P3.2 — a SAFE expression evaluator for tenant-authored formulas and rule
 * conditions. It walks a small JSON AST against a variable bag; it NEVER calls
 * eval() or executes tenant-supplied PHP, so a tenant can define KPIs and rules
 * without being able to run code. This is what makes "customer-defined rules and
 * KPIs" safe to store and evaluate on a shared platform.
 *
 * AST node shapes (every node has a "type"):
 *   {"type":"const","value":10}
 *   {"type":"var","name":"units_sold"}          // looked up in $vars, null if absent
 *   {"type":"op","op":"/","args":[nodeA,nodeB]} // see OPS below
 *
 * Supported ops:
 *   arithmetic (binary):  + - * /        → number, or null if any operand null / divide-by-zero
 *   comparison (binary):  > >= < <= == != → bool (false if an operand is null)
 *   boolean:              and, or (n-ary), not (unary) → bool (null treated as false)
 */
class Evaluator
{
    private const ARITH      = ['+', '-', '*', '/'];
    private const COMPARISON = ['>', '>=', '<', '<=', '==', '!='];
    private const BOOLEAN    = ['and', 'or', 'not'];

    /**
     * @param  array<string,mixed>  $node
     * @param  array<string,mixed>  $vars
     */
    public function evaluate(array $node, array $vars = []): int|float|bool|null
    {
        $type = $node['type'] ?? null;

        return match ($type) {
            'const' => $this->constValue($node),
            'var'   => $this->varValue($node, $vars),
            'op'    => $this->opValue($node, $vars),
            default => throw new InvalidArgumentException("Unknown node type: " . var_export($type, true)),
        };
    }

    private function constValue(array $node): int|float|bool|null
    {
        $v = $node['value'] ?? null;

        return is_int($v) || is_float($v) || is_bool($v) || $v === null
            ? $v
            : throw new InvalidArgumentException('const value must be number, bool or null.');
    }

    private function varValue(array $node, array $vars): int|float|bool|null
    {
        $name = $node['name'] ?? throw new InvalidArgumentException('var node requires a name.');
        $v = $vars[$name] ?? null;

        // Only scalars flow through the evaluator.
        return is_int($v) || is_float($v) || is_bool($v) ? $v : ($v === null ? null : (float) $v);
    }

    private function opValue(array $node, array $vars): int|float|bool|null
    {
        $op = $node['op'] ?? throw new InvalidArgumentException('op node requires an op.');
        $args = $node['args'] ?? [];
        if (! is_array($args)) {
            throw new InvalidArgumentException('op args must be an array.');
        }

        if (in_array($op, self::BOOLEAN, true)) {
            return $this->boolean($op, $args, $vars);
        }

        if (in_array($op, self::ARITH, true) || in_array($op, self::COMPARISON, true)) {
            if (count($args) !== 2) {
                throw new InvalidArgumentException("Operator '{$op}' expects 2 args.");
            }
            $left  = $this->evaluate($args[0], $vars);
            $right = $this->evaluate($args[1], $vars);

            return in_array($op, self::ARITH, true)
                ? $this->arithmetic($op, $left, $right)
                : $this->comparison($op, $left, $right);
        }

        throw new InvalidArgumentException("Unsupported operator: {$op}");
    }

    private function arithmetic(string $op, int|float|bool|null $l, int|float|bool|null $r): int|float|null
    {
        if (! is_numeric($l) || ! is_numeric($r)) {
            return null; // null-propagating: a missing input yields no value, not a crash
        }
        $l = $l + 0;
        $r = $r + 0;

        return match ($op) {
            '+' => $l + $r,
            '-' => $l - $r,
            '*' => $l * $r,
            '/' => $r == 0 ? null : $l / $r,
        };
    }

    private function comparison(string $op, int|float|bool|null $l, int|float|bool|null $r): bool
    {
        if ($l === null || $r === null) {
            return $op === '!='; // null != x is true; every other comparison with null is false
        }

        return match ($op) {
            '>'  => $l >  $r,
            '>=' => $l >= $r,
            '<'  => $l <  $r,
            '<=' => $l <= $r,
            '==' => $l == $r,
            '!=' => $l != $r,
        };
    }

    private function boolean(string $op, array $args, array $vars): bool
    {
        if ($op === 'not') {
            if (count($args) !== 1) {
                throw new InvalidArgumentException("'not' expects 1 arg.");
            }
            return ! $this->truthy($this->evaluate($args[0], $vars));
        }

        // and / or — n-ary, short-circuit.
        foreach ($args as $arg) {
            $val = $this->truthy($this->evaluate($arg, $vars));
            if ($op === 'and' && ! $val) {
                return false;
            }
            if ($op === 'or' && $val) {
                return true;
            }
        }

        return $op === 'and'; // all-true for and; no-true for or
    }

    private function truthy(int|float|bool|null $v): bool
    {
        return $v !== null && $v !== false && $v !== 0 && $v !== 0.0;
    }
}
