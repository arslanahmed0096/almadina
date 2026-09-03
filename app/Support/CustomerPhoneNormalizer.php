<?php

namespace App\Support;

final class CustomerPhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $digits = self::digits($phone);
        if (self::isMissingLeadingZero($phone)) {
            return '0'.$digits;
        }
        if (self::hasDuplicatedLeadingZero($phone)) {
            return substr($digits, 1);
        }
        if (preg_match('/^03\d{9}$/', $digits)) {
            return $digits;
        }

        return $phone;
    }

    public static function isMissingLeadingZero(?string $phone): bool
    {
        $phone = trim((string) $phone);
        if ($phone === '' || str_starts_with($phone, '+') || str_starts_with($phone, '00')) {
            return false;
        }

        return preg_match('/^3\d{9}$/', self::digits($phone)) === 1;
    }

    public static function isLocalMobile(?string $phone): bool
    {
        return preg_match('/^03\d{9}$/', self::digits($phone)) === 1;
    }

    public static function hasDuplicatedLeadingZero(?string $phone): bool
    {
        return preg_match('/^003\d{9}$/', self::digits($phone)) === 1;
    }

    public static function needsCorrection(?string $phone): bool
    {
        return self::isMissingLeadingZero($phone) || self::hasDuplicatedLeadingZero($phone);
    }

    public static function identityKey(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $digits = self::digits($phone);
        if (preg_match('/^003\d{9}$/', $digits)) {
            return substr($digits, 2);
        }
        if (str_starts_with($raw, '+') && ! str_starts_with($digits, '923')) {
            return null;
        }
        if (str_starts_with($raw, '00') && ! str_starts_with($digits, '00923')) {
            return null;
        }
        if (preg_match('/^03\d{9}$/', $digits)) {
            return substr($digits, 1);
        }
        if (preg_match('/^3\d{9}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^923\d{9}$/', $digits)) {
            return substr($digits, 2);
        }
        if (preg_match('/^00923\d{9}$/', $digits)) {
            return substr($digits, 4);
        }

        return null;
    }

    private static function digits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }
}
