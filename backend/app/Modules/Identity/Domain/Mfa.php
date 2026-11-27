<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * TOTP two-step sign-in (RFC 6238, 30-second codes), for school users and
 * for our platform admins (the console). Events go to the school's audit
 * log for a school user and to the platform audit log for a platform admin.
 *
 *  - The secret is stored with Laravel's `encrypted` cast.
 *  - Recovery codes are random, shown once, stored as an HMAC under the app
 *    key, and each works once.
 *  - A code is accepted only once: the last accepted time step is kept, so
 *    a code seen over someone's shoulder cannot be replayed.
 */
class Mfa
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditRecorder $audit,
        private readonly PlatformAuditRecorder $platformAudit,
    ) {}

    /**
     * @return array{secret: string, otpauth_url: string}
     */
    public function startEnrolment(User|PlatformAdmin $user, Tenant|string $label): array
    {
        if ($user->mfaEnabled()) {
            throw new ApiException('mfa_already_enabled', 'Two-step sign-in is already set up for this account.', 409);
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['mfa_pending_secret' => $secret])->save();

        $issuer = config('identity.mfa.issuer').' ('.($label instanceof Tenant ? $label->code : $label).')';

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl($issuer, (string) ($user->email ?? $user->phone ?? $user->name), $secret),
        ];
    }

    /**
     * @return list<string> the recovery codes, shown once
     */
    public function confirmEnrolment(User|PlatformAdmin $user, #[SensitiveParameter] string $code): array
    {
        $pending = $user->mfa_pending_secret;

        if (! is_string($pending) || $pending === '') {
            throw new ApiException('mfa_enrolment_not_started', 'Start setting up two-step sign-in first.', 409);
        }

        $step = $this->checkTotp($pending, $code, null);

        if ($step === null) {
            throw new ApiException('mfa_code_invalid', 'That code is not right. Check the time on your phone and try again.', 422);
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'mfa' => array_merge($user->mfa ?? [], ['enabled' => true, 'enrolled_at' => now()->toIso8601String(), 'last_timestep' => $step]),
            'mfa_secret' => $pending,
            'mfa_pending_secret' => null,
            'mfa_recovery_codes' => array_map(self::hashRecoveryCode(...), $codes),
        ])->save();

        $this->event($user, 'mfa.enrolled');

        return $codes;
    }

    /**
     * Checks a TOTP code, or a recovery code when allowed (which is then used
     * up). Returns how it was verified, or null when it was not.
     */
    public function verify(User|PlatformAdmin $user, #[SensitiveParameter] string $code, bool $allowRecovery = true): ?string
    {
        if (! $user->mfaEnabled()) {
            return null;
        }

        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) === 1) {
            $step = $this->checkTotp((string) $user->mfa_secret, $code, $user->mfa['last_timestep'] ?? null);

            if ($step === null) {
                return null;
            }

            $user->forceFill(['mfa' => array_merge($user->mfa ?? [], ['last_timestep' => $step])])->save();

            return 'totp';
        }

        if (! $allowRecovery) {
            return null;
        }

        $hash = self::hashRecoveryCode($code);
        $remaining = $user->mfa_recovery_codes ?? [];
        $left = array_values(array_filter($remaining, fn (string $stored) => ! hash_equals($stored, $hash)));

        if (count($left) === count($remaining)) {
            return null;
        }

        $user->forceFill(['mfa_recovery_codes' => $left])->save();
        $this->event($user, 'mfa.recovery_code_used', ['codes_left' => count($left)]);

        return 'recovery_code';
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User|PlatformAdmin $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['mfa_recovery_codes' => array_map(self::hashRecoveryCode(...), $codes)])->save();
        $this->event($user, 'mfa.recovery_codes_replaced');

        return $codes;
    }

    public function disable(User|PlatformAdmin $user): void
    {
        $user->forceFill([
            'mfa' => ['enabled' => false],
            'mfa_secret' => null,
            'mfa_pending_secret' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        $this->event($user, 'mfa.disabled');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function event(User|PlatformAdmin $account, string $action, array $meta = []): void
    {
        if ($account instanceof PlatformAdmin) {
            $this->platformAudit->record($action, $account, entity: 'platform_admins', entityId: (string) $account->getKey(), meta: $meta);

            return;
        }

        $this->audit->record($action, 'security', 'users', (string) $account->getKey(), meta: $meta, actor: $account);
    }

    public static function hashRecoveryCode(#[SensitiveParameter] string $code): string
    {
        $normalised = strtoupper(str_replace(['-', ' '], '', $code));

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    /** The accepted time step, or null. */
    private function checkTotp(string $secret, #[SensitiveParameter] string $code, ?int $lastStep): ?int
    {
        if (preg_match('/^\d{6}$/', trim($code)) !== 1) {
            return null;
        }

        $step = $this->google2fa->verifyKeyNewer($secret, trim($code), $lastStep ?? 0, (int) config('identity.mfa.window', 1));

        return is_int($step) ? $step : null;
    }

    /**
     * @return list<string> like "K7Q2M-9XWPA"
     */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < (int) config('identity.mfa.recovery_codes', 10); $i++) {
            $raw = strtoupper(Str::random(10));
            $raw = strtr($raw, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'W', 'L' => 'V']);
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5, 5);
        }

        return $codes;
    }
}
