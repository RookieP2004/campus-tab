<?php

namespace App\Modules\Fees\Domain;

use App\Support\Http\ApiException;

/**
 * Money is whole paise (integers). Never floating point: a rupee string
 * typed on screen ("1,250.50") is split into its rupee and paise digits,
 * and percentages are basis points (1% = 100) applied with integer
 * arithmetic, rounding half up once.
 */
final class Money
{
    /** "1250", "1,250.5", "1250.50" or an integer of paise → paise. */
    public static function parse(string|int $value, string $field = 'amount'): int
    {
        if (is_int($value)) {
            return $value;
        }
        $clean = str_replace([',', ' ', '₹'], '', trim($value));
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $clean, $m) !== 1) {
            throw new ApiException('amount_invalid', 'Enter the amount in rupees, with at most two digits after the point.', 422, ['field' => $field]);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** Paise → "1250.50" (no symbol, no grouping: the screen formats it). */
    public static function format(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** $basisPoints / 10000 of $paise, rounded half up (for amounts ≥ 0). */
    public static function percentOf(int $paise, int $basisPoints): int
    {
        return intdiv($paise * $basisPoints + 5000, 10000);
    }

    /** A percentage string ("12.5") → basis points (1250). */
    public static function basisPoints(string|int $percent): int
    {
        if (is_int($percent)) {
            return $percent * 100;
        }
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($percent), $m) !== 1 || (int) $m[1] > 100) {
            throw new ApiException('percent_invalid', 'Enter a percentage from 0 to 100, with at most two decimals.', 422);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
