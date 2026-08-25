<?php

namespace App\Services\Tax;

final class Decimal
{
    private const SCALE = 6;

    public static function add(string|int|float $a, string|int|float $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function mul(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        return bcmul(self::normalize($a), self::normalize($b), $scale);
    }

    public static function div(string|int|float $a, string|int|float $b): string
    {
        $denominator = self::normalize($b);
        if (bccomp($denominator, '0', self::SCALE) === 0) return '0.000000000000';
        // Keep guard digits for rates/ratios; monetary results are rounded only
        // after multiplication, avoiding inclusive-tax truncation drift.
        return bcdiv(self::normalize($a), $denominator, 12);
    }

    public static function round(string|int|float $value, int $scale = 2): string
    {
        $normalized = self::normalize($value, max($scale + 1, self::SCALE));
        $increment = '0.'.str_repeat('0', $scale).'5';
        if (str_starts_with($normalized, '-')) {
            return bcsub($normalized, $increment, $scale);
        }
        return bcadd($normalized, $increment, $scale);
    }

    private static function normalize(string|int|float $value, int $scale = self::SCALE): string
    {
        if (is_float($value)) return number_format($value, $scale, '.', '');
        $value = trim((string) $value);
        return preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? $value : '0';
    }
}
