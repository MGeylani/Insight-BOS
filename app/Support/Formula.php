<?php

namespace App\Support;

/**
 * A tiny, safe calculator for formula fields: numbers, + - * /, parentheses and references written as
 * {12} (another field's value by id) or {estimate} (estimate in hours). No functions, no eval().
 * Returns null on a syntax error or a division by zero.
 */
class Formula
{
    /** @param array<string, float|int|null> $vars */
    public static function evaluate(string $expr, array $vars): ?float
    {
        $tokens = self::tokenize($expr, $vars);
        if ($tokens === null) {
            return null;
        }
        $pos = 0;
        try {
            $v = self::expr($tokens, $pos);
        } catch (\RuntimeException) {
            return null;
        }

        return $pos === count($tokens) && is_finite($v) ? round($v, 6) : null;
    }

    /** @return list<int> field ids used */
    public static function references(string $expr): array
    {
        preg_match_all('/\{(\d+)\}/', $expr, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** @return list<array{0: string, 1: mixed}>|null */
    private static function tokenize(string $expr, array $vars): ?array
    {
        $out = [];
        $i = 0;
        $n = strlen($expr);
        while ($i < $n) {
            $c = $expr[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif (ctype_digit($c) || $c === '.') {
                $j = $i;
                while ($j < $n && (ctype_digit($expr[$j]) || $expr[$j] === '.')) {
                    $j++;
                }
                $num = substr($expr, $i, $j - $i);
                if (! is_numeric($num)) {
                    return null;
                }
                $out[] = ['n', (float) $num];
                $i = $j;
            } elseif ($c === '{') {
                $j = strpos($expr, '}', $i);
                if ($j === false) {
                    return null;
                }
                $key = substr($expr, $i + 1, $j - $i - 1);
                $out[] = ['n', (float) ($vars[$key] ?? 0)];
                $i = $j + 1;
            } elseif (str_contains('+-*/()', $c)) {
                $out[] = ['o', $c];
                $i++;
            } else {
                return null;
            }
        }

        return $out;
    }

    private static function expr(array $t, int &$p): float
    {
        $v = self::term($t, $p);
        while (isset($t[$p]) && $t[$p][0] === 'o' && in_array($t[$p][1], ['+', '-'], true)) {
            $op = $t[$p++][1];
            $r = self::term($t, $p);
            $v = $op === '+' ? $v + $r : $v - $r;
        }

        return $v;
    }

    private static function term(array $t, int &$p): float
    {
        $v = self::factor($t, $p);
        while (isset($t[$p]) && $t[$p][0] === 'o' && in_array($t[$p][1], ['*', '/'], true)) {
            $op = $t[$p++][1];
            $r = self::factor($t, $p);
            if ($op === '/' && $r == 0.0) {
                throw new \RuntimeException('div0');
            }
            $v = $op === '*' ? $v * $r : $v / $r;
        }

        return $v;
    }

    private static function factor(array $t, int &$p): float
    {
        $tok = $t[$p] ?? throw new \RuntimeException('eof');
        $p++;
        if ($tok[0] === 'n') {
            return $tok[1];
        }
        if ($tok[1] === '-') {
            return -self::factor($t, $p);
        }
        if ($tok[1] === '(') {
            $v = self::expr($t, $p);
            if (($t[$p][1] ?? null) !== ')') {
                throw new \RuntimeException('paren');
            }
            $p++;

            return $v;
        }
        throw new \RuntimeException('unexpected');
    }
}
