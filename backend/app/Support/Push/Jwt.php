<?php

namespace App\Support\Push;

use RuntimeException;

/**
 * The two short JWTs push needs: RS256 for Google's service-account sign-in
 * and ES256 for APNs. Signed with PHP's OpenSSL; nothing else is verified
 * here.
 */
final class Jwt
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function sign(array $header, array $claims, string $privateKeyPem): string
    {
        $input = self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode($claims));
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('The push signing key could not be read.');
        }
        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The push token could not be signed.');
        }
        if (($header['alg'] ?? null) === 'ES256') {
            $signature = self::derToRaw($signature, 32);
        }

        return $input.'.'.self::b64($signature);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** OpenSSL gives ECDSA signatures as DER; JWS wants R and S side by side. */
    public static function derToRaw(string $der, int $size): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new RuntimeException('Unexpected ECDSA signature.');
            }
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $value = ltrim($value, "\x00");
            $parts[] = str_pad($value, $size, "\x00", STR_PAD_LEFT);
        }

        return $parts[0].$parts[1];
    }
}
