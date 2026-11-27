<?php

namespace App\Modules\Platform\Domain;

use App\Modules\Audit\Domain\PlatformAuditRecorder;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\ActivationService;
use App\Modules\Platform\Mail\ChiefRecoveryCodeMail;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Otp\OtpService;
use App\Support\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use SensitiveParameter;

/**
 * Sole-Chief recovery: the last way back in when a school has exactly one
 * Chief Admin and that Chief has lost both the authenticator and the
 * recovery codes (with two Chiefs, the other Chief resets MFA in the school).
 *
 *  1. start(): a platform admin, with step-up, sends a one-time code to the
 *     school's registered contact (the email and phone set when the school
 *     was created, where the activation code went).
 *  2. complete(): with that code (the school reads it to us) and a written
 *     reason, the Chief's MFA is reset and every session ends, so the Chief
 *     enrols again at the next sign-in.
 *
 * It never sets, shows or changes a password. It is a security event in the
 * school's own audit log, visible to the school, and in the platform log.
 */
class ChiefRecovery
{
    private const OTP_PURPOSE = 'chief-recovery';

    public function __construct(
        private readonly OtpService $otp,
        private readonly SmsSender $sms,
        private readonly TenantContext $context,
        private readonly SessionIssuer $sessions,
        private readonly PlatformAuditRecorder $audit,
    ) {}

    /**
     * @return array{chief: array{id: string, name: string}, sent_to: array{email: string|null, phone: string|null}, expires_in: int}
     */
    public function start(PlatformAdmin $actor, Tenant $tenant): array
    {
        $chief = $this->soleChief($tenant);
        $email = $tenant->contact['email'] ?? null;
        $phone = $tenant->contact['phone'] ?? null;

        if ((! is_string($email) || $email === '') && (! is_string($phone) || $phone === '')) {
            throw new ApiException('no_registered_contact', 'This school has no registered contact to send the code to.', 409);
        }

        $minutes = (int) config('identity.platform.chief_recovery.code_ttl_minutes', 30);
        $code = $this->otp->issue(self::OTP_PURPOSE, $tenant->id, $minutes, (int) config('identity.platform.chief_recovery.sends_per_10_minutes', 3));

        if (is_string($email) && $email !== '') {
            Mail::to($email)->send(new ChiefRecoveryCodeMail($code, $tenant->name, $minutes));
        }

        if (is_string($phone) && $phone !== '') {
            $this->sms->send($phone, "Campus TAB: code to restore the Chief Admin sign-in for {$tenant->name}: {$code}. Read it only to Campus TAB support. Valid {$minutes} minutes.");
        }

        $sentTo = ['email' => ActivationService::maskEmail($email), 'phone' => ActivationService::maskPhone($phone)];

        $this->audit->record('chief_recovery.code_sent', $actor, $tenant, 'users', (string) $chief->getKey(),
            meta: ['sent_to' => $sentTo], schoolCategory: 'security');

        return [
            'chief' => ['id' => (string) $chief->getKey(), 'name' => $chief->name],
            'sent_to' => $sentTo,
            'expires_in' => $minutes * 60,
        ];
    }

    public function complete(PlatformAdmin $actor, Tenant $tenant, #[SensitiveParameter] string $code, string $reason): void
    {
        $reason = trim($reason);
        $min = (int) config('identity.platform.chief_recovery.reason_min_length', 20);

        if (mb_strlen($reason) < $min) {
            throw new ApiException('reason_required', "Write down why, in at least {$min} characters: how the school proved who they are.", 422);
        }

        $chief = $this->soleChief($tenant);

        $this->otp->consume(self::OTP_PURPOSE, $tenant->id, $code, (int) config('identity.platform.chief_recovery.max_attempts', 5));

        $ended = $this->context->run($tenant->id, function () use ($chief): int {
            $chief->forceFill([
                'mfa' => ['required' => true, 'enabled' => false],
                'mfa_secret' => null,
                'mfa_pending_secret' => null,
                'mfa_recovery_codes' => null,
                'failed_sign_ins' => 0,
                'locked_until' => null,
            ])->save();

            return $this->sessions->revokeAllFor($chief, 'mfa_reset_by_platform');
        });

        $this->audit->record('chief_recovery.mfa_reset', $actor, $tenant, 'users', (string) $chief->getKey(),
            meta: ['reason' => $reason, 'sessions_ended' => $ended, 'chief_name' => $chief->name], schoolCategory: 'security');
    }

    /** The school's only active Chief Admin. Anything else is refused. */
    private function soleChief(Tenant $tenant): User
    {
        if (! $tenant->isActive()) {
            throw new ApiException('tenant_not_active', 'This school has not been activated yet.', 409);
        }

        $chiefs = $this->context->run($tenant->id, fn () => User::query()
            ->where('roles', Role::Chief->value)
            ->where('status', User::STATUS_ACTIVE)
            ->get());

        if ($chiefs->count() !== 1) {
            throw new ApiException('not_sole_chief', $chiefs->count() === 0
                ? 'This school has no active Chief Admin.'
                : 'This school has more than one Chief Admin: another Chief can reset two-step sign-in inside the school.', 409, [
                    'chiefs' => $chiefs->count(),
                ]);
        }

        /** @var User $chief */
        $chief = $chiefs->first();

        return $chief;
    }
}
