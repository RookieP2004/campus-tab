<?php

namespace App\Modules\Licensing\Domain;

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Mail\ActivationOtpMail;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Otp\OtpService;
use App\Support\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * One-time activation of a school with its licence key.
 *
 *  1. requestOtp(): the key is checked and a code goes to the school's
 *     registered email and phone.
 *  2. activate(): with the right code, in one transaction, the Chief Admin is
 *     created (MFA required), config version 1 is written, and the licence
 *     and school become active. The key cannot be used again.
 *  3. The controller starts a session that only allows MFA enrolment.
 */
class ActivationService
{
    private const OTP_PURPOSE = 'licence-activation';

    public function __construct(
        private readonly OtpService $otp,
        private readonly SmsSender $sms,
        private readonly SchoolConfigService $configs,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{school: array{code: string, name: string}, sent_to: array{email: string|null, phone: string|null}, expires_in: int}
     */
    public function requestOtp(string $keyInput): array
    {
        [$licence, $tenant] = $this->findActivatable($keyInput);

        $email = $tenant->contact['email'] ?? null;
        $phone = $tenant->contact['phone'] ?? null;
        $minutes = (int) config('licensing.activation.otp_ttl_minutes');

        $code = $this->otp->issue(
            self::OTP_PURPOSE,
            (string) $licence->getKey(),
            $minutes,
            (int) config('licensing.activation.otp_sends_per_10_minutes'),
        );

        if (is_string($email) && $email !== '') {
            Mail::to($email)->send(new ActivationOtpMail($code, $tenant->name, $minutes));
        }

        if (is_string($phone) && $phone !== '') {
            $this->sms->send($phone, "Campus TAB activation code for {$tenant->name}: {$code}. Valid for {$minutes} minutes.");
        }

        return [
            'school' => ['code' => $tenant->code, 'name' => $tenant->name],
            'sent_to' => ['email' => self::maskEmail($email), 'phone' => self::maskPhone($phone)],
            'expires_in' => $minutes * 60,
        ];
    }

    /**
     * @param  array{name: string, email: string, phone: string, password: string}  $chief
     * @return array{user: User, tenant: Tenant}
     */
    public function activate(string $keyInput, string $otp, array $chief): array
    {
        [$licence, $tenant] = $this->findActivatable($keyInput);

        $this->otp->consume(self::OTP_PURPOSE, (string) $licence->getKey(), $otp, (int) config('licensing.activation.otp_max_attempts'));

        $user = DB::connection('mongodb')->transaction(function () use ($licence, $tenant, $chief): User {
            $claimed = Licence::query()
                ->where('_id', $licence->getKey())
                ->where('status', Licence::STATUS_ISSUED)
                ->update(['status' => Licence::STATUS_ACTIVE, 'activated_at' => now()]);

            if ($claimed !== 1) {
                throw new ApiException('licence_already_activated', 'This licence key has already been used.', 409);
            }

            $user = $this->context->run($tenant->id, fn () => User::create([
                'name' => $chief['name'],
                'email' => mb_strtolower($chief['email']),
                'phone' => $chief['phone'],
                'password' => $chief['password'],
                'roles' => [Role::Chief->value],
                'mfa' => ['required' => true, 'enabled' => false],
                'status' => 'active',
            ]));

            $this->configs->initialise($tenant, $licence->licensedModules(), $user);

            Tenant::query()->where('_id', $tenant->id)->update(['status' => Tenant::STATUS_ACTIVE]);

            return $user;
        });

        $this->context->run($tenant->id, function () use ($user, $licence): void {
            $this->audit->record('licence.activated', 'security', 'licences', (string) $licence->getKey(), actor: $user);
            $this->audit->record('chief.added', 'security', 'users', (string) $user->getKey(), meta: ['how' => 'activation'], actor: $user);
        });

        return ['user' => $user, 'tenant' => $tenant->refresh()];
    }

    /**
     * @return array{0: Licence, 1: Tenant}
     */
    private function findActivatable(string $keyInput): array
    {
        $key = LicenceKey::normalise($keyInput)
            ?? throw new ApiException('licence_key_invalid', 'A licence key looks like CTAB-XXXX-XXXX-XXXX-XXXX.', 422);

        /** @var Licence|null $licence */
        $licence = Licence::query()->where('key_hash', LicenceKey::hash($key))->first();

        if ($licence === null) {
            throw new ApiException('licence_not_found', 'This licence key is not recognised. Check it and try again.', 404);
        }

        if ($licence->status === Licence::STATUS_ACTIVE) {
            throw new ApiException('licence_already_activated', 'This licence key has already been used. The Chief Admin signs in with their own password.', 409);
        }

        if ($licence->status === Licence::STATUS_REVOKED) {
            throw new ApiException('licence_revoked', 'This licence key has been withdrawn. Contact Campus TAB support.', 403);
        }

        $payload = $licence->verifiedPayload()
            ?? throw new ApiException('licence_invalid', 'This licence could not be verified. Contact Campus TAB support.', 403);

        if (CarbonImmutable::parse($payload['valid_to'], 'Asia/Kolkata')->endOfDay()->isPast()) {
            throw new ApiException('licence_expired', 'This licence has expired. Contact Campus TAB support to renew it.', 403);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($licence->tenant_id);

        if ($tenant === null || $tenant->status !== Tenant::STATUS_PENDING) {
            throw new ApiException('tenant_not_activatable', 'This school cannot be activated. Contact Campus TAB support.', 409);
        }

        return [$licence, $tenant];
    }

    public static function maskEmail(?string $email): ?string
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', max(mb_strlen($name) - 1, 3)).'@'.$domain;
    }

    public static function maskPhone(?string $phone): ?string
    {
        if (! is_string($phone) || strlen($phone) < 4) {
            return null;
        }

        return str_repeat('*', strlen($phone) - 4).substr($phone, -4);
    }
}
