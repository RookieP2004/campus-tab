<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Password sign-in, the MFA step and step-up.
 *
 *  - Unknown school, unknown user, wrong password and a locked account all
 *    get the same answer (sign_in_failed). While an account is locked its
 *    password is not checked at all, so the right password cannot be found
 *    by watching the answers. A dummy hash check keeps the timing the same.
 *  - Per account: account_max_failures in a row lock it for lockout_minutes.
 *    Wrong MFA codes count too. The Principal or Chief can unlock early.
 *  - Per IP: a roomy limit for bulk abuse only (many people share one IP).
 */
class SignInService
{
    private const CHALLENGE_PREFIX = 'mfa-challenge:';

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly SchoolUserLocator $locator,
        private readonly TenantContext $context,
        private readonly SessionIssuer $sessions,
        private readonly Mfa $mfa,
        private readonly AuditRecorder $audit,
        private readonly Repository $cache,
        private readonly SchoolConfigService $configs,
    ) {}

    public static function failed(): ApiException
    {
        return new ApiException(
            'sign_in_failed',
            "Those details didn't work, or there were too many tries. Try again in 15 minutes or ask your Principal.",
            401,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function attempt(Request $request, string $schoolCode, string $login, #[SensitiveParameter] string $password): array
    {
        $this->guardIp($request);

        $found = $this->locator->find($schoolCode, $login);

        if ($found === null) {
            Hash::check($password, self::dummyHash());
            $this->failIp($request);

            throw self::failed();
        }

        [$tenant, $user] = $found;

        return $this->context->run($tenant->id, function () use ($request, $tenant, $user, $password): array {
            if ($user->isLocked()) {
                // Not checked while locked: same answer, same timing, no hint.
                Hash::check($password, self::dummyHash());
                $this->failIp($request);
                $this->audit->record('sign_in.blocked', 'security', 'users', (string) $user->getKey(), meta: ['reason' => 'locked'], actor: $user);

                throw self::failed();
            }

            // Student sign-in is a school setting, off by default: same answer as a wrong password.
            if ($user->roles === [Role::Student->value] && ! in_array('student_login', $this->configs->current()->body['sign_in']['methods'] ?? [], true)) {
                Hash::check($password, self::dummyHash());
                $this->failIp($request);

                throw self::failed();
            }

            if (! is_string($user->password) || $user->password === '' || ! Hash::check($password, $user->password)) {
                if (! is_string($user->password) || $user->password === '') {
                    Hash::check($password, self::dummyHash());
                }

                $this->registerFailure($user, 'password');
                $this->failIp($request);

                throw self::failed();
            }

            return $this->afterFirstFactor($request, $tenant, $user, 'password');
        });
    }

    /**
     * The password (or OTP) was right. Either ask for the MFA code, or start a
     * session; a role that needs MFA but has none gets an enrol-only session.
     *
     * @return array<string, mixed>
     */
    public function afterFirstFactor(Request $request, Tenant $tenant, User $user, string $method): array
    {
        // Failures are cleared only when sign-in completes: a right password
        // must not reset the count of wrong MFA codes.
        if ($user->mfaEnabled()) {
            $challenge = Str::random(48);
            $minutes = (int) config('identity.mfa.challenge_minutes', 5);

            $this->cache->put(self::CHALLENGE_PREFIX.hash('sha256', $challenge), [
                'tenant_id' => $tenant->id,
                'user_id' => (string) $user->getKey(),
                'method' => $method,
                'attempts' => 0,
            ], now()->addMinutes($minutes));

            return ['mfa_required' => true, 'challenge' => $challenge, 'expires_in' => $minutes * 60];
        }

        return $this->complete($request, $tenant, $user, $method, limited: $user->mfaRequired());
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyChallenge(Request $request, string $challenge, #[SensitiveParameter] string $code): array
    {
        $key = self::CHALLENGE_PREFIX.hash('sha256', $challenge);
        /** @var array{tenant_id: string, user_id: string, method: string, attempts: int}|null $data */
        $data = $this->cache->get($key);

        if ($data === null) {
            throw new ApiException('mfa_challenge_expired', 'Sign in again: the time for entering the code has run out.', 401);
        }

        $tenant = Tenant::query()->find($data['tenant_id']);

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            $this->cache->forget($key);

            throw self::failed();
        }

        return $this->context->run($tenant->id, function () use ($request, $tenant, $data, $key, $code): array {
            $user = User::query()->find($data['user_id']);

            if (! $user instanceof User || ! $user->isActive() || $user->isLocked()) {
                $this->cache->forget($key);

                throw self::failed();
            }

            $how = $this->mfa->verify($user, $code);

            if ($how === null) {
                $attempts = $data['attempts'] + 1;
                $locked = $this->registerFailure($user, 'mfa');
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

            return $this->complete($request, $tenant, $user, $data['method'].'+'.$how, limited: false);
        });
    }

    /** Step-up: a fresh MFA code marks the current device for a few minutes. */
    public function stepUp(Request $request, User $user, #[SensitiveParameter] string $code): DeviceSession
    {
        $session = $request->attributes->get('device_session');

        if (! $session instanceof DeviceSession) {
            throw new ApiException('session_required', 'Sign in on this device first.', 401);
        }

        if (! $user->mfaEnabled()) {
            throw new ApiException('mfa_enrolment_required', 'Set up two-step sign-in to do this.', 403);
        }

        $limiter = 'step-up:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            throw new ApiException('too_many_attempts', 'Too many wrong codes. Wait a few minutes and try again.', 429, [
                'retry_after' => RateLimiter::availableIn($limiter),
            ]);
        }

        if ($this->mfa->verify($user, $code) === null) {
            RateLimiter::hit($limiter, 900);

            throw new ApiException('mfa_code_invalid', 'That code is not right. Check the time on your phone and try again.', 422);
        }

        RateLimiter::clear($limiter);
        $session->forceFill(['step_up_at' => now()])->save();
        $this->audit->record('step_up', 'security', 'device_sessions', (string) $session->getKey());

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function complete(Request $request, Tenant $tenant, User $user, string $method, bool $limited): array
    {
        $user->forceFill(['last_sign_in_at' => now(), 'failed_sign_ins' => 0, 'locked_until' => null])->save();
        $session = $this->sessions->issue($request, $user, $method, $limited);

        $this->audit->record('sign_in', 'security', 'users', (string) $user->getKey(), meta: [
            'method' => $method,
            'mode' => $session['mode'],
            'device_session_id' => $session['device_session_id'],
            'mfa_enrolment_only' => $limited,
        ], actor: $user);

        return array_merge([
            'school' => ['code' => $tenant->code, 'name' => $tenant->name],
            'user' => ['id' => (string) $user->getKey(), 'name' => $user->name, 'roles' => $user->roles],
        ], $session);
    }

    /** Counts a failed password or MFA code; returns true when the account is now locked. */
    private function registerFailure(User $user, string $factor): bool
    {
        $max = (int) config('identity.sign_in.account_max_failures', 5);
        $previous = $user->locked_until !== null && $user->locked_until->isPast() ? 0 : (int) $user->failed_sign_ins;
        $failures = $previous + 1;
        $locked = $failures >= $max;

        $user->forceFill([
            'failed_sign_ins' => $failures,
            'locked_until' => $locked ? now()->addMinutes((int) config('identity.sign_in.lockout_minutes', 15)) : null,
        ])->save();

        $this->audit->record($locked ? 'sign_in.locked' : 'sign_in.failed', 'security', 'users', (string) $user->getKey(), meta: [
            'factor' => $factor,
            'failures' => $failures,
        ], actor: $user);

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
        return 'sign-in-ip:'.hash('sha256', (string) $request->ip());
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('campus-tab-timing-equaliser');
    }
}
