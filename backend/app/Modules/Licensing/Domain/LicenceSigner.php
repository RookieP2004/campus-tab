<?php

namespace App\Modules\Licensing\Domain;

use RuntimeException;
use SodiumException;

/**
 * Signs and verifies licence payloads with Ed25519 (PHP's sodium extension).
 *
 * The payload is canonical JSON (keys sorted, no extra spaces), stored next
 * to its signature. Anyone editing the licence in the database without the
 * secret key breaks the signature, and the licence stops working.
 */
class LicenceSigner
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: string, signature: string}
     */
    public function sign(array $payload): array
    {
        $json = self::canonical($payload);
        $signature = sodium_crypto_sign_detached($json, $this->key('secret_key', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES));

        return ['payload' => $json, 'signature' => base64_encode($signature)];
    }

    public function verify(string $payload, string $signature): bool
    {
        $raw = base64_decode($signature, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($raw, $payload, $this->key('public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * @return array{secret_key: string, public_key: string} base64 values for .env
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function canonical(array $payload): string
    {
        return json_encode(self::sortKeys($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        return $value;
    }

    private function key(string $name, int $length): string
    {
        $raw = base64_decode((string) config("licensing.signing.{$name}"), true);

        if ($raw === false || strlen($raw) !== $length) {
            throw new RuntimeException('LICENCE_SIGNING_'.strtoupper($name).' is missing or invalid. Run php artisan licence:keygen and put the values in .env.');
        }

        return $raw;
    }
}
