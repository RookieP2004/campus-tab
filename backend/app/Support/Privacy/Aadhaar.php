<?php

namespace App\Support\Privacy;

use App\Support\Http\ApiException;

/**
 * Full Aadhaar numbers are never stored ("APAAR, PEN and Aadhaar").
 *
 * A full Aadhaar number is 12 digits, not starting with 0 or 1, whose last
 * digit is a valid Verhoeff check digit. It is found:
 *  - as a whole value, with spaces or dashes allowed (2345 6789 0124), and
 *  - inside longer text, as a 12-digit run or a 4-4-4 group not touching
 *    other digits.
 *
 * A phone number written with its + (for example +919812345678) is not a
 * match: it is a phone number, not a bare 12-digit identifier. Only the last
 * four digits of an Aadhaar number may be kept (students.ids.aadhaar_last4).
 */
final class Aadhaar
{
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 2, 3, 4, 0, 6, 7, 8, 9, 5], [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7], [4, 0, 1, 2, 3, 9, 5, 6, 7, 8], [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2], [7, 6, 5, 9, 8, 2, 1, 0, 4, 3], [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 5, 7, 6, 2, 8, 3, 0, 9, 4], [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7], [9, 4, 5, 3, 1, 2, 6, 8, 7, 0], [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5], [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /** Valid Verhoeff checksum over the whole digit string. */
    public static function verhoeffValid(string $digits): bool
    {
        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $c = 0;
        $reversed = strrev($digits);

        for ($i = 0, $n = strlen($reversed); $i < $n; $i++) {
            $c = self::D[$c][self::P[$i % 8][(int) $reversed[$i]]];
        }

        return $c === 0;
    }

    public static function isFullNumber(string $digits): bool
    {
        return preg_match('/^[2-9]\d{11}$/', $digits) === 1 && self::verhoeffValid($digits);
    }

    /** Does this text hold a full Aadhaar number, alone or inside other text? */
    public static function containsFullNumber(string $text): bool
    {
        if (strlen($text) < 12) {
            return false;
        }

        // Record ids are not Aadhaar numbers. A MongoDB id (24 hex characters)
        // or a ULID (26 characters) is random, so now and then it holds a run
        // of 12 digits that passes the checksum; such a request used to be
        // refused at random (a test that sent ids failed now and then).
        $text = self::withoutIds($text);
        if (strlen(trim($text)) < 12) {
            return false;
        }

        $whole = preg_replace('/[\s-]/', '', trim($text)) ?? '';

        if (self::isFullNumber($whole)) {
            return true;
        }

        if (preg_match_all('/(?<![\d+])(\d{4}[ -]?\d{4}[ -]?\d{4})(?!\d)/', $text, $matches) > 0) {
            foreach ($matches[1] as $candidate) {
                if (self::isFullNumber(preg_replace('/[ -]/', '', $candidate) ?? '')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Blanks out record ids: 24-hex MongoDB ids and 26-character ULIDs (with
     * at least one letter, so a long run of plain digits is still checked).
     */
    public static function withoutIds(string $text): string
    {
        $text = preg_replace('/(?<![0-9A-Za-z])(?=[0-9a-fA-F]*[a-fA-F])[0-9a-fA-F]{24}(?![0-9A-Za-z])/', ' ', $text) ?? $text;

        return preg_replace('/(?<![0-9A-Za-z])(?=[0-9A-HJKMNP-TV-Z]*[A-HJKMNP-TV-Z])[0-9A-HJKMNP-TV-Z]{26}(?![0-9A-Za-z])/', ' ', $text) ?? $text;
    }

    /**
     * Every string in a nested value, with its dotted path, that holds a full
     * Aadhaar number.
     *
     * @return list<string>
     */
    public static function findIn(mixed $value, string $path = ''): array
    {
        if (is_string($value) || is_int($value)) {
            return self::containsFullNumber((string) $value) ? [$path === '' ? 'value' : $path] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $found = [];

        foreach ($value as $key => $item) {
            $found = [...$found, ...self::findIn($item, $path === '' ? (string) $key : $path.'.'.$key)];
        }

        return $found;
    }

    /** Throws 422 aadhaar_not_allowed naming the fields, never their values. */
    public static function refuseIn(mixed $value): void
    {
        $fields = self::findIn($value);

        if ($fields !== []) {
            throw self::refused($fields);
        }
    }

    /**
     * @param  list<string>  $fields
     */
    public static function refused(array $fields): ApiException
    {
        return new ApiException(
            'aadhaar_not_allowed',
            'A full Aadhaar number cannot be stored. Keep only its last four digits, in the Aadhaar (last 4) field.',
            422,
            ['fields' => array_values(array_unique($fields))],
        );
    }
}
