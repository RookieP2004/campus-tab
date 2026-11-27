<?php

namespace App\Modules\Audit\Domain;

/**
 * Strips secrets out of audit before/after values. The value is dropped and
 * only the field's path is kept in `redacted`, so the log shows that a
 * password changed without ever holding it.
 */
final class Redactor
{
    /** Field names that are never written to the audit log, at any depth. */
    public const SECRET_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'remember_token', 'token', 'plain_text_token', 'token_hash', 'session_hash', 'session_id',
        'secret', 'mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes', 'recovery_codes',
        'otp', 'otp_code', 'totp', 'key_hash', 'api_key', 'signature',
    ];

    /**
     * @param  array<string, mixed>|null  $values
     * @param  list<string>  $redacted  collects the paths that were removed
     * @return array<string, mixed>|null
     */
    public static function clean(?array $values, array &$redacted, string $prefix = ''): ?array
    {
        if ($values === null) {
            return null;
        }

        $clean = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $redacted[] = $path;

                continue;
            }

            $clean[$key] = is_array($value) ? self::clean($value, $redacted, $path) : $value;
        }

        return $clean;
    }
}
