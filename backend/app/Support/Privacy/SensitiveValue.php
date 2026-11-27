<?php

namespace App\Support\Privacy;

use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

/**
 * Values of custom fields marked "sensitive" (for example medical notes) are
 * encrypted with the app key before they are stored: the database, backups
 * and the audit log only ever hold {"enc": "<ciphertext>"}. They are
 * decrypted only for someone allowed to fill the field, and screens still
 * show them masked until that person asks to see them.
 */
final class SensitiveValue
{
    public const MASK = '••••';

    /**
     * @return array{enc: string}|null
     */
    public static function seal(#[SensitiveParameter] mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return ['enc' => Crypt::encryptString((string) json_encode($value))];
    }

    public static function isSealed(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === ['enc'] && is_string($value['enc']);
    }

    public static function open(mixed $value): mixed
    {
        if (! self::isSealed($value)) {
            return $value;
        }

        return json_decode(Crypt::decryptString($value['enc']), true);
    }
}
