<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Identity\Domain\Mfa;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Platform\Models\PlatformSession;
use App\Support\Http\ApiException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Console sign-in: email and password, then a TOTP (or recovery) code, every
 * time. The same rules as school sign-in:
 *
 *  - one answer for every failure (console_sign_in_failed); a locked or
 *    passwordless account is never checked against the typed password;
 *  - 5 failures in a row, wrong codes included, lock the account for 15
 *    minutes; a per-IP limit for bulk abuse;
 *  - an account without MFA yet gets a session that may only enrol MFA.
 */
class ConsoleSignIn
{
    private const CHALLENGE_PREFIX = 'console-mfa-challenge:';

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly ConsoleSessions $sessions,
        private readonly Mfa $mfa,
        private readonly PlatformAuditRecorder $audit,
        private readonly Repository $cache,
    ) {}

    public static function failed(): ApiException
    {
        return new ApiException(
            'console_sign_in_failed',
            "Those details didn't work, or there were too many tries. Try again in 15 minutes.",
            401,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function attempt(Request $request, string $email, #[SensitiveParameter] string $password): array
    {
        ConsoleSessions::requireBrowser($request);
        $this->guardIp($request);

        $admin = PlatformAdmin::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $admin instanceof PlatformAdmin || ! $admin->isActive() || ! $admin->hasPassword()) {
            Hash::check($password, self::dummyHash());
            $this->failIp($request);

            throw self::failed();
        }

        if ($admin->isLocked()) {
            Hash::check($password, self::dummyHash());
            $this->failIp($request);
            $this->audit->record('console.sign_in.blocked', $admin, entity: 'platform_admins', entityId: (string) $admin->getKey(), meta: ['reason' => 'locked']);

            throw self::failed();
        }

        if (! Hash::check($password, (string) $admin->password)) {
            $this->registerFailure($admin, 'password');
            $this->failIp($request);

            throw self::failed();
        }

        if ($admin->mfaEnabled()) {
            $challenge = Str::random(48);
            $minutes = (int) config('identity.mfa.challenge_minutes', 5);

            $this->cache->put(self::CHALLENGE_PREFIX.hash('sha256', $challenge), [
                'admin_id' => (string) $admin->getKey(),
                'attempts' => 0,
            ], now()->addMinutes($minutes));

            return ['mfa_required' => true, 'challenge' => $challenge, 'expires_in' => $minutes * 60];
        }

        // First sign-in: the session may only enrol MFA.
        return $this->complete($request, $admin, 'password', limited: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyChallenge(Request $request, string $challenge, #[SensitiveParameter] string $code): array
    {
        ConsoleSessions::requireBrowser($request);

        $key = self::CHALLENGE_PREFIX.hash('sha256', $challenge);
        /** @var array{admin_id: string, attempts: int}|null $data */
        $data = $this->cache->get($key);

        if ($data === null) {
            throw new ApiException('mfa_challenge_expired', 'Sign in again: the time for entering the code has run out.', 401);
        }

        $admin = PlatformAdmin::query()->find($data['admin_id']);

        if (! $admin instanceof PlatformAdmin || ! $admin->isActive() || $admin->isLocked()) {
            $this->cache->forget($key);

            throw self::failed();
        }

        $how = $this->mfa->verify($admin, $code);

        if ($how === null) {
            $attempts = $data['attempts'] + 1;
            $locked = $this->registerFailure($admin, 'mfa');
            $max = (int) config('identity.mfa.challenge_max_attempts', 5);

            if ($locked || $attempts >= $max) {
                $this->cache->forget($key);

                throw self::failed();
            }

            $this->cache->put($key, array_merge($data, ['attempts' => $attempts]), now()->addMinutes((int) config('identity.mfa.challenge_minutes', 5)));

            throw new ApiException('mfa_code_invalid', 'That code is not right. Check the time on your phone and try again.', 422, [
                'attempts_left' => $max - $attempts,
            ]);
        }

        $this->cache->forget($key);

        return $this->complete($request, $admin, 'password+'.$how, limited: false);
    }

    /** Step-up: a fresh TOTP code marks this console session for a few minutes. */
    public function stepUp(Request $request, PlatformAdmin $admin, #[SensitiveParameter] string $code): PlatformSession
    {
        $session = $request->attributes->get('platform_session');

        if (! $session instanceof PlatformSession) {
            throw new ApiException('session_required', 'Sign in on this device first.', 401);
        }

        $limiter = 'console-step-up:'.$admin->getKey();

        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            throw new ApiException('too_many_attempts', 'Too many wrong codes. Wait a few minutes and try again.', 429, [
                'retry_after' => RateLimiter::availableIn($limiter),
            ]);
        }

        if ($this->mfa->verify($admin, $code, allowRecovery: false) === null) {
            RateLimiter::hit($limiter, 900);

            throw new ApiException('mfa_code_invalid', 'That code is not right. Check the time on your phone and try again.', 422);
        }

        RateLimiter::clear($limiter);
        $session->forceFill(['step_up_at' => now()])->save();
        $this->audit->record('console.step_up', $admin, entity: 'platform_sessions', entityId: (string) $session->getKey());

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function complete(Request $request, PlatformAdmin $admin, string $method, bool $limited): array
    {
        $admin->forceFill(['last_sign_in_at' => now(), 'failed_sign_ins' => 0, 'locked_until' => null])->save();
        $session = $this->sessions->start($request, $admin, $method, $limited);

        $this->audit->record('console.sign_in', $admin, entity: 'platform_admins', entityId: (string) $admin->getKey(), meta: [
            'method' => $method,
            'session_id' => (string) $session->getKey(),
            'mfa_enrolment_only' => $limited,
        ]);

        return [
            'admin' => ['id' => (string) $admin->getKey(), 'name' => $admin->name, 'email' => $admin->email],
            'mfa_enrolment_required' => $limited,
        ];
    }

    private function registerFailure(PlatformAdmin $admin, string $factor): bool
    {
        $max = (int) config('identity.sign_in.account_max_failures', 5);
        $previous = $admin->locked_until !== null && $admin->locked_until->isPast() ? 0 : (int) $admin->failed_sign_ins;
        $failures = $previous + 1;
        $locked = $failures >= $max;

        $admin->forceFill([
            'failed_sign_ins' => $failures,
            'locked_until' => $locked ? now()->addMinutes((int) config('identity.sign_in.lockout_minutes', 15)) : null,
        ])->save();

        $this->audit->record($locked ? 'console.sign_in.locked' : 'console.sign_in.failed', $admin, entity: 'platform_admins', entityId: (string) $admin->getKey(), meta: [
            'factor' => $factor,
            'failures' => $failures,
        ]);

        return $locked;
    }

    private function guardIp(Request $request): void
    {
        $key = $this->ipKey($request);

        if (RateLimiter::tooManyAttempts($key, (int) config('identity.sign_in.ip_max_failures', 100))) {
            throw new ApiException('too_many_attempts', 'Too many failed sign-ins from this network. Wait a few minutes and try again.', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }
    }

    private function failIp(Request $request): void
    {
        RateLimiter::hit($this->ipKey($request), (int) config('identity.sign_in.ip_decay_minutes', 15) * 60);
    }

    private function ipKey(Request $request): string
    {
        return 'console-sign-in-ip:'.hash('sha256', (string) $request->ip());
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('campus-tab-timing-equaliser');
    }
}
