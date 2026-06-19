<?php

namespace App\Support;

/**
 * Shiply expects local mobile format (e.g. 05xxxxxxxx), not +972 … API storage format.
 */
class ShiplyPhoneFormatter
{
    /**
     * Convert stored phone to Shiply parcel recipient format.
     */
    public static function forParcel(?string $phone): string
    {
        $region = strtolower((string) config('shiply.region', 'palestine'));

        return $region === 'jordan'
            ? self::forJordan($phone)
            : self::forPalestine($phone);
    }

    public static function isValidForParcel(?string $phone): bool
    {
        $formatted = self::forParcel($phone);
        if ($formatted === '') {
            return false;
        }

        $region = strtolower((string) config('shiply.region', 'palestine'));

        return $region === 'jordan'
            ? (bool) preg_match('/^07\d{8}$/', $formatted)
            : (bool) preg_match('/^05\d{8}$/', $formatted);
    }

    private static function forPalestine(?string $phone): string
    {
        $digits = self::digitsOnly($phone);
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '972') || str_starts_with($digits, '970')) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '5')) {
            return '0'.$digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '05')) {
            return $digits;
        }

        return '';
    }

    private static function forJordan(?string $phone): string
    {
        $digits = self::digitsOnly($phone);
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '962')) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '0'.$digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '07')) {
            return $digits;
        }

        return '';
    }

    private static function digitsOnly(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }
}
