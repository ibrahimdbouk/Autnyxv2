<?php

namespace App\Platform\Extensibility\Expression;

use InvalidArgumentException;

/**
 * W10 (WP10.6) — the formula language tenants type, compiled to the safe AST
 * the Evaluator runs. Never eval()'d; a formula can only name the variables
 * it is given and use these operators:
 *
 *   arithmetic   +  -  *  /   (and unary minus)
 *   comparison   >  >=  <  <=  =  ==  !=  <>
 *   logic        and  or  not   (also && || !)
 *   grouping     ( … )
 *   values       numbers (12, 0.5), true, false, null, variable names
 *
 *   e.g.  days_of_cover < 3 and units_7d > 20
 *         (revenue_7d / 7) / (revenue_28d / 28) * 100 - 100
 */
final class Formula
{
    private array $tokens = [];
    private int $pos = 0;
    /** @var array<string,true> */
    private array $used = [];

    /**
     * @param  array<int,string>|null  $allowed  variable names that may be used (null = any)
     * @return array{ast: array, variables: array<int,string>}
     */
    public static function compile(string $text, ?array $allowed = null): array
    {
        $p = new self();
        $p->tokens = self::tokenize($text);
        if ($p->tokens === []) {
            throw new InvalidArgumentException('The formula is empty.');
        }
        $ast = $p->parseOr();
        if ($p->pos < count($p->tokens)) {
            throw new InvalidArgumentException('Unexpected "' . $p->tokens[$p->pos][1] . '" — check the operators and brackets.');
        }
        if ($allowed !== null) {
            $unknown = array_values(array_diff(array_keys($p->used), $allowed));
            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown variable: ' . implode(', ', $unknown) . '. Use one of the listed variables.');
            }
        }

        return ['ast' => $ast, 'variables' => array_keys($p->used)];
    }

    /** @return array<int,array{0:string,1:string}> [type, text] */
    private static function tokenize(string $text): array
    {
        $out = [];
        $i = 0;
        $n = strlen($text);
        while ($i < $n) {
            $c = $text[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($text[$i + 1]))) {
                preg_match('/\G\d*\.?\d+(?:[eE][+-]?\d+)?/', $text, $m, 0, $i);
                $out[] = ['num', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $text, $m, 0, $i);
                $word = strtolower($m[0]);
                $out[] = in_array($word, ['and', 'or', 'not', 'true', 'false', 'null'], true) ? ['kw', $word] : ['id', $word];
                $i += strlen($m[0]);
                continue;
            }
            $two = substr($text, $i, 2);
            if (in_array($two, ['>=', '<=', '==', '!=', '<>', '&&', '||'], true)) {
                $out[] = ['op', match ($two) { '<>' => '!=', '&&' => 'and', '||' => 'or', default => $two }];
                $i += 2;
                continue;
            }
            if (str_contains('+-*/()<>=!', $c)) {
                $out[] = ['op', match ($c) { '=' => '==', '!' => 'not', default => $c }];
                $i++;
                continue;
            }
            throw new InvalidArgumentException("Unexpected character \"{$c}\" at position " . ($i + 1) . '.');
        }

        return $out;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function accept(string $value): bool
    {
        $t = $this->peek();
        if ($t !== null && in_array($t[0], ['op', 'kw'], true) && $t[1] === $value) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function parseOr(): array
    {
        $args = [$this->parseAnd()];
        while ($this->accept('or')) {
            $args[] = $this->parseAnd();
        }

        return count($args) === 1 ? $args[0] : ['type' => 'op', 'op' => 'or', 'args' => $args];
    }

    private function parseAnd(): array
    {
        $args = [$this->parseNot()];
        while ($this->accept('and')) {
            $args[] = $this->parseNot();
        }

        return count($args) === 1 ? $args[0] : ['type' => 'op', 'op' => 'and', 'args' => $args];
    }

    private function parseNot(): array
    {
        if ($this->accept('not')) {
            return ['type' => 'op', 'op' => 'not', 'args' => [$this->parseNot()]];
        }

        return $this->parseComparison();
    }

    private function parseComparison(): array
    {
        $left = $this->parseSum();
        foreach (['>=', '<=', '==', '!=', '>', '<'] as $op) {
            if ($this->accept($op)) {
                return ['type' => 'op', 'op' => $op, 'args' => [$left, $this->parseSum()]];
            }
        }

        return $left;
    }

    private function parseSum(): array
    {
        $node = $this->parseTerm();
        while (true) {
            if ($this->accept('+')) {
                $node = ['type' => 'op', 'op' => '+', 'args' => [$node, $this->parseTerm()]];
            } elseif ($this->accept('-')) {
                $node = ['type' => 'op', 'op' => '-', 'args' => [$node, $this->parseTerm()]];
            } else {
                return $node;
            }
        }
    }

    private function parseTerm(): array
    {
        $node = $this->parseUnary();
        while (true) {
            if ($this->accept('*')) {
                $node = ['type' => 'op', 'op' => '*', 'args' => [$node, $this->parseUnary()]];
            } elseif ($this->accept('/')) {
                $node = ['type' => 'op', 'op' => '/', 'args' => [$node, $this->parseUnary()]];
            } else {
                return $node;
            }
        }
    }

    private function parseUnary(): array
    {
        if ($this->accept('-')) {
            return ['type' => 'op', 'op' => '-', 'args' => [['type' => 'const', 'value' => 0], $this->parseUnary()]];
        }
        if ($this->accept('+')) {
            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $t = $this->peek();
        if ($t === null) {
            throw new InvalidArgumentException('The formula ends too early — something is missing after the last operator.');
        }
        if ($this->accept('(')) {
            $inner = $this->parseOr();
            if (! $this->accept(')')) {
                throw new InvalidArgumentException('A bracket is not closed.');
            }

            return $inner;
        }
        $this->pos++;

        return match ($t[0]) {
            'num' => ['type' => 'const', 'value' => str_contains($t[1], '.') || stripos($t[1], 'e') !== false ? (float) $t[1] : (int) $t[1]],
            'kw'  => match ($t[1]) {
                'true'  => ['type' => 'const', 'value' => true],
                'false' => ['type' => 'const', 'value' => false],
                'null'  => ['type' => 'const', 'value' => null],
                default => throw new InvalidArgumentException('"' . $t[1] . '" needs something on each side.'),
            },
            'id'  => (function () use ($t) {
                $this->used[$t[1]] = true;

                return ['type' => 'var', 'name' => $t[1]];
            })(),
            default => throw new InvalidArgumentException('Unexpected "' . $t[1] . '".'),
        };
    }
}
