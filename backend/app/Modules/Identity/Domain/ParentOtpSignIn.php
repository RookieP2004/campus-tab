<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Otp\OtpService;
use App\Support\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;

/**
 * Parent sign-in with a code sent by SMS.
 *
 *  - Only for accounts whose every role is Parent, and never for one with
 *    MFA switched on: a teacher or staff member must not be able to skip
 *    MFA with an SMS code. Everyone else uses password sign-in.
 *  - The code is stored only as a hash, lasts 5 minutes and allows 5 tries.
 *  - Sends and failures are limited per phone number (strict) and per IP
 *    (roomy, for bulk abuse only).
 *  - The reply to a request is the same whether or not the number belongs
 *    to anyone, and every failed check gets the same answer.
 */
class ParentOtpSignIn
{
    private const PURPOSE = 'parent-sign-in';

    public function __construct(
        private readonly OtpService $otp,
        private readonly SmsSender $sms,
        private readonly TenantContext $context,
        private readonly SignInService $signIn,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{message: string, expires_in: int}
     */
    public function request(Request $request, string $schoolCode, string $phoneInput): array
    {
        $phone = self::normalisePhone($phoneInput);
        $minutes = (int) config('identity.parent_otp.ttl_minutes', 5);

        $this->limit('otp-send-ip:'.hash('sha256', (string) $request->ip()), (int) config('identity.parent_otp.sends_per_ip_per_10_minutes', 60), 600);
        // Counted for every number, known or not, so the limit itself says nothing.
        $this->limit('otp-send-phone:'.hash('sha256', strtoupper($schoolCode).'|'.$phone), (int) config('identity.parent_otp.sends_per_phone_per_10_minutes', 3), 600);

        [$tenant, $user] = $this->eligible($schoolCode, $phone);

        if ($tenant !== null && $user !== null) {
            $code = $this->otp->issue(self::PURPOSE, $tenant->id.'|'.$phone, $minutes, null);
            $this->sms->send($phone, "Your Campus TAB sign-in code for {$tenant->name} is {$code}. It works for {$minutes} minutes. Do not share it.");
            $this->context->run($tenant->id, fn () => $this->audit->record('sign_in.otp_sent', 'security', 'users', (string) $user->getKey(), actor: $user));
        }

        return [
            'message' => 'If this number is registered for a parent account, a code has been sent.',
            'expires_in' => $minutes * 60,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(Request $request, string $schoolCode, string $phoneInput, #[SensitiveParameter] string $code): array
    {
        $phone = self::normalisePhone($phoneInput);
        $ipKey = 'otp-fail-ip:'.hash('sha256', (string) $request->ip());
        $phoneKey = 'otp-fail-phone:'.hash('sha256', strtoupper($schoolCode).'|'.$phone);
        $ipMax = (int) config('identity.parent_otp.failures_per_ip_per_15_minutes', 100);
        $phoneMax = (int) config('identity.parent_otp.failures_per_phone_per_hour', 10);

        if (RateLimiter::tooManyAttempts($ipKey, $ipMax)) {
            throw new ApiException('too_many_attempts', 'Too many failed codes from this network. Wait a few minutes and try again.', 429, [
                'retry_after' => RateLimiter::availableIn($ipKey),
            ]);
        }

        if (RateLimiter::tooManyAttempts($phoneKey, $phoneMax)) {
            throw SignInService::failed();
        }

        $fail = function () use ($ipKey, $phoneKey): ApiException {
            RateLimiter::hit($ipKey, 900);
            RateLimiter::hit($phoneKey, 3600);

            return new ApiException('otp_invalid', "That code didn't work or has expired. Ask for a new one.", 422);
        };

        [$tenant, $user] = $this->eligible($schoolCode, $phone);

        if ($tenant === null || $user === null) {
            throw $fail();
        }

        try {
            $this->otp->consume(self::PURPOSE, $tenant->id.'|'.$phone, $code, (int) config('identity.parent_otp.max_attempts', 5));
        } catch (ApiException) {
            throw $fail();
        }

        return $this->context->run($tenant->id, fn () => $this->signIn->afterFirstFactor($request, $tenant, $user, 'otp'));
    }

    /**
     * @return array{0: Tenant|null, 1: User|null}
     */
    private function eligible(string $schoolCode, string $phone): array
    {
        $tenant = Tenant::findActiveByCode($schoolCode);

        if ($tenant === null || $phone === '') {
            return [null, null];
        }

        $user = $this->context->run($tenant->id, fn () => User::query()
            ->where('phone', $phone)
            ->where('status', User::STATUS_ACTIVE)
            ->first());

        if (! $user instanceof User) {
            return [$tenant, null];
        }

        $allowed = $this->context->run($tenant->id, fn () => $user->isParentOnly() && ! $user->mfaEnabled() && ! $user->isLocked());

        return [$tenant, $allowed ? $user : null];
    }

    private function limit(string $key, int $max, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException('otp_rate_limited', 'Too many codes asked for. Try again in a few minutes.', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    public static function normalisePhone(string $phone): string
    {
        return preg_replace('/[\s\-()]/', '', trim($phone)) ?? '';
    }
}
