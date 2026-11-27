<?php

namespace App\Modules\Licensing\Domain;

use RuntimeException;

/**
 * The key a school types: CTAB-XXXX-XXXX-XXXX-XXXX.
 *
 * 16 random characters from a 32-letter alphabet without the look-alikes
 * 0, O, 1 and I (80 bits). Typing is forgiving: case, spaces and dashes are
 * ignored. Only an HMAC of the key is ever stored.
 */
final class LicenceKey
{
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PREFIX = 'CTAB';

    public static function generate(): string
    {
        $chars = '';

        for ($i = 0; $i < 16; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::format($chars);
    }

    /**
     * The canonical CTAB-XXXX-XXXX-XXXX-XXXX form of what someone typed,
     * or null when it cannot be a licence key.
     */
    public static function normalise(string $input): ?string
    {
        $chars = strtoupper((string) preg_replace('/[\s\-]+/', '', $input));

        if (str_starts_with($chars, self::PREFIX)) {
            $chars = substr($chars, strlen(self::PREFIX));
        }

        if (strlen($chars) !== 16 || strspn($chars, self::ALPHABET) !== 16) {
            return null;
        }

        return self::format($chars);
    }

    public static function hash(string $normalisedKey): string
    {
        $pepper = config('licensing.key_pepper');

        if (! is_string($pepper) || strlen($pepper) < 32) {
            throw new RuntimeException('LICENCE_KEY_PEPPER is missing or too short. Run php artisan licence:keygen and put the values in .env.');
        }

        return hash_hmac('sha256', $normalisedKey, $pepper);
    }

    public static function lastFour(string $normalisedKey): string
    {
        return substr($normalisedKey, -4);
    }

    private static function format(string $chars): string
    {
        return self::PREFIX.'-'.implode('-', str_split($chars, 4));
    }
}
