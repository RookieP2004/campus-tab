<?php

namespace Tests\Concerns;

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Domain\SessionIssuer;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Domain\TenantProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Builds schools, licences and users for tests with made-up data only.
 */
trait CreatesSchools
{
    /**
     * A pending school with an issued (not yet activated) licence.
     *
     * @param  array<string, mixed>  $terms
     * @return array{tenant: Tenant, licence: Licence, key: string}
     */
    public function pendingSchool(string $code = 'SUNRISE-BPL', array $terms = []): array
    {
        $tenant = app(TenantProvisioner::class)->create([
            'code' => $code,
            'name' => Str::title(str_replace('-', ' ', $code)).' School',
            'email' => 'office@'.Str::lower($code).'.test',
            'phone' => '+9198000'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
        ]);

        $result = app(LicenceIssuer::class)->issue($tenant, array_merge([
            'plan' => 'standard',
            'modules' => config('licensing.modules'),
            'students' => 500,
            'staff' => 50,
            'valid_from' => now('Asia/Kolkata')->subMonth()->toDateString(),
            'valid_to' => now('Asia/Kolkata')->addYear()->toDateString(),
        ], $terms));

        return ['tenant' => $tenant, 'licence' => $result['licence'], 'key' => $result['key']];
    }

    /**
     * An active school with a Chief (MFA enrolled) and config version 1,
     * created the way activation does it, without the OTP round trip.
     *
     * @param  array<string, mixed>  $terms
     * @return array{tenant: Tenant, licence: Licence, chief: User}
     */
    public function activeSchool(string $code = 'SUNRISE-BPL', array $terms = []): array
    {
        ['tenant' => $tenant, 'licence' => $licence] = $this->pendingSchool($code, $terms);

        $licence->forceFill(['status' => Licence::STATUS_ACTIVE, 'activated_at' => now()])->save();
        $chief = $this->schoolUser($tenant, Role::Chief);
        app(SchoolConfigService::class)->initialise($tenant, $licence->licensedModules(), $chief);
        $tenant->forceFill(['status' => Tenant::STATUS_ACTIVE])->save();

        return ['tenant' => $tenant->refresh(), 'licence' => $licence->refresh(), 'chief' => $chief];
    }

    /**
     * A school user with a made-up name, email and phone. With MFA enabled a
     * real TOTP secret is stored, so totp() can produce valid codes.
     *
     * @param  list<string>  $extraRoles  more roles (for example fee_collector)
     */
    public function schoolUser(Tenant $tenant, Role $role, bool $mfaEnabled = true, ?string $email = null, array $extraRoles = [], ?string $phone = null): User
    {
        return app(TenantContext::class)->run($tenant->id, fn () => tap(User::create([
            'name' => Str::headline($role->value).' '.Str::random(4),
            'email' => $email ?? Str::lower($role->value.'.'.Str::random(6).'@'.Str::lower($tenant->code).'.test'),
            'phone' => $phone ?? '+9197'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT),
            'password' => TestData::PASSWORD,
            'roles' => array_values(array_unique(array_merge([$role->value], $extraRoles))),
            'mfa' => ['required' => $role->level() >= Role::MFA_REQUIRED_FROM, 'enabled' => $mfaEnabled],
        ]), function (User $user) use ($mfaEnabled): void {
            if ($mfaEnabled) {
                $user->forceFill(['mfa_secret' => app(Google2FA::class)->generateSecretKey(32)])->save();
            }
        }));
    }

    /** A valid TOTP code for this user right now (one per 30-second step). */
    public function totp(User $user): string
    {
        $fresh = app(TenantContext::class)->run($user->tenant_id, fn () => User::query()->findOrFail($user->getKey()));

        return app(Google2FA::class)->getCurrentOtp((string) $fresh->mfa_secret);
    }

    /** A bearer token with full abilities for the given user, with its device session. */
    public function tokenFor(User $user, bool $steppedUp = false): string
    {
        return app(TenantContext::class)->run($user->tenant_id, function () use ($user, $steppedUp): string {
            $issued = app(SessionIssuer::class)->issueToken($user, 'test device', 'test');

            if ($steppedUp) {
                DeviceSession::query()->whereKey($issued['device_session_id'])->update(['step_up_at' => now()]);
            }

            return $issued['token'];
        });
    }

    /**
     * Send the next request as this user, with a fresh bearer token. Guards
     * are reset so one test can act as several users. $steppedUp marks the
     * device as having just passed step-up MFA.
     */
    public function actingWithToken(User $user, bool $steppedUp = false): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->tokenFor($user, $steppedUp));
    }

    /** Run something inside a school (reads and writes are tenant-scoped). */
    public function inSchool(Tenant $tenant, \Closure $callback): mixed
    {
        return app(TenantContext::class)->run($tenant->id, $callback);
    }

    /**
     * The current config body of a school, read inside that school.
     *
     * @return array<string, mixed>
     */
    public function configBody(Tenant $tenant): array
    {
        return app(TenantContext::class)->run($tenant->id, fn () => app(SchoolConfigService::class)->current()->body);
    }
}
