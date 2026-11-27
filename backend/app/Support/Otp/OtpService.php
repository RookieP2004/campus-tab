<?php

namespace App\Support\Otp;

use App\Support\Http\ApiException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time codes for licence activation and parent sign-in.
 *
 * A code is 6 digits, stored only as an HMAC in the cache, valid for a few
 * minutes (a wrong try never extends that), with a limited number of tries.
 * Sending is rate-limited per subject unless the caller applies its own
 * limits, so nobody can flood a phone or inbox.
 */
class OtpService
{
    public function __construct(private readonly Repository $cache) {}

    /**
     * @param  int|null  $maxSendsPer10Minutes  null when the caller applies its own send limits
     */
    public function issue(string $purpose, string $subject, int $ttlMinutes, ?int $maxSendsPer10Minutes): string
    {
        if ($maxSendsPer10Minutes !== null) {
            $limiterKey = "otp-send:{$purpose}:".hash('sha256', $subject);

            if (RateLimiter::tooManyAttempts($limiterKey, $maxSendsPer10Minutes)) {
                throw new ApiException('otp_rate_limited', 'Too many codes sent. Try again in a few minutes.', 429, [
                    'retry_after' => RateLimiter::availableIn($limiterKey),
                ]);
            }

            RateLimiter::hit($limiterKey, 600);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $expiresAt = now()->addMinutes($ttlMinutes);

        $this->cache->put($this->key($purpose, $subject), [
            'hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return $code;
    }

    /**
     * Checks a code and uses it up when it matches.
     *
     * @throws ApiException otp_expired | otp_invalid | otp_locked
     */
    public function consume(string $purpose, string $subject, string $code, int $maxAttempts): void
    {
        $key = $this->key($purpose, $subject);
        /** @var array{hash: string, attempts: int, expires_at: int}|null $stored */
        $stored = $this->cache->get($key);

        if ($stored === null || $stored['expires_at'] <= now()->getTimestamp()) {
            $this->cache->forget($key);

            throw new ApiException('otp_expired', 'The code has expired. Ask for a new one.', 422);
        }

        if (hash_equals($stored['hash'], $this->hash(trim($code)))) {
            $this->cache->forget($key);

            return;
        }

        $attempts = $stored['attempts'] + 1;

        if ($attempts >= $maxAttempts) {
            $this->cache->forget($key);

            throw new ApiException('otp_locked', 'Too many wrong codes. Ask for a new one.', 422);
        }

        // Keep the original expiry: a wrong try never gives the code more time.
        $this->cache->put($key, array_merge($stored, ['attempts' => $attempts]), Carbon::createFromTimestamp($stored['expires_at']));

        throw new ApiException('otp_invalid', 'That code is not right.', 422, ['attempts_left' => $maxAttempts - $attempts]);
    }

    private function key(string $purpose, string $subject): string
    {
        return "otp:{$purpose}:".hash('sha256', $subject);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
