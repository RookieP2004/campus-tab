<?php

namespace Tests\Concerns;

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use App\Modules\Tenancy\Domain\TenantProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

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

    public function schoolUser(Tenant $tenant, Role $role, bool $mfaEnabled = true, ?string $email = null): User
    {
        return app(TenantContext::class)->run($tenant->id, fn () => User::create([
            'name' => Str::headline($role->value).' '.Str::random(4),
            'email' => $email ?? Str::lower($role->value.'.'.Str::random(6).'@'.Str::lower($tenant->code).'.test'),
            'phone' => '+9197'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT),
            'password' => 'correct-horse-42-battery',
            'roles' => [$role->value],
            'mfa' => ['required' => $role->level() >= Role::MFA_REQUIRED_FROM, 'enabled' => $mfaEnabled],
        ]));
    }

    /** A bearer token with full abilities for the given user. */
    public function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Send the next request as this user, with a fresh bearer token. Guards
     * are reset so one test can act as several users.
     */
    public function actingWithToken(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->tokenFor($user));
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
