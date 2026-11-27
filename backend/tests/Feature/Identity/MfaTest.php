<?php

use App\Modules\Identity\Domain\Mfa;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| TOTP MFA: mandatory from level 60 (Clerk, VP, Principal, Chief), optional
| below; secrets encrypted, recovery codes hashed; the enrol-only session.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('MFA-BPL');
    $this->tenant = $this->school['tenant'];
});

function rawUser(User $user): array
{
    return (array) DB::connection('mongodb')->getCollection('users')->findOne(['tenant_id' => $user->tenant_id, '_id' => new ObjectId((string) $user->getKey())], ['typeMap' => ['root' => 'array', 'document' => 'array']]);
}

it('requires MFA from level 60 and makes it optional below', function (Role $role, bool $required) {
    $user = $this->schoolUser($this->tenant, $role, mfaEnabled: false);

    $this->inSchool($this->tenant, fn () => expect($user->mfaRequired())->toBe($required));
})->with([
    'Chief' => [Role::Chief, true],
    'Principal' => [Role::Principal, true],
    'Vice Principal' => [Role::VicePrincipal, true],
    'Clerk' => [Role::Clerk, true],
    'Class Teacher' => [Role::ClassTeacher, false],
    'Subject Teacher' => [Role::SubjectTeacher, false],
    'Staff' => [Role::Staff, false],
    'Parent' => [Role::Parent, false],
]);

it('enrols a Clerk from the enrol-only token, then replaces that token with a full one', function () {
    $clerk = $this->schoolUser($this->tenant, Role::Clerk, mfaEnabled: false);

    $limited = $this->postJson('/api/v1/auth/login', ['school_code' => 'MFA-BPL', 'login' => $clerk->email, 'password' => TestData::PASSWORD])
        ->json('data.token');

    app('auth')->forgetGuards();
    $start = $this->withToken($limited)->postJson('/api/v1/me/mfa/enrol')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'otpauth_url']]);
    expect($start->json('data.otpauth_url'))->toStartWith('otpauth://totp/');

    $code = app(Google2FA::class)->getCurrentOtp($start->json('data.secret'));
    app('auth')->forgetGuards();
    $done = $this->withToken($limited)->postJson('/api/v1/me/mfa/enrol/confirm', ['code' => $code])
        ->assertOk()
        ->assertJsonCount(10, 'data.recovery_codes')
        ->assertJsonPath('data.session.token_abilities', ['*']);

    // The enrol-only token is gone; the new one works.
    app('auth')->forgetGuards();
    $this->withToken($limited)->getJson('/api/v1/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($done->json('data.session.token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.mfa.enabled', true);
});

it('never lets an enrol-only token through, even after MFA is set up elsewhere', function () {
    $clerk = $this->schoolUser($this->tenant, Role::Clerk, mfaEnabled: false);
    $limited = $this->postJson('/api/v1/auth/login', ['school_code' => 'MFA-BPL', 'login' => $clerk->email, 'password' => TestData::PASSWORD])->json('data.token');

    // MFA gets switched on by another path.
    $this->inSchool($this->tenant, fn () => User::query()->find($clerk->getKey())
        ->forceFill(['mfa' => ['enabled' => true], 'mfa_secret' => app(Google2FA::class)->generateSecretKey(32)])->save());

    app('auth')->forgetGuards();
    $this->withToken($limited)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'mfa_enrolment_required');
});

it('stores the TOTP secret encrypted and the recovery codes only as hashes', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
    $mfa = app(Mfa::class);

    [$secret, $codes] = $this->inSchool($this->tenant, function () use ($teacher, $mfa) {
        $user = User::query()->find($teacher->getKey());
        $secret = $mfa->startEnrolment($user, $this->tenant)['secret'];

        return [$secret, $mfa->confirmEnrolment($user, app(Google2FA::class)->getCurrentOtp($secret))];
    });

    $raw = rawUser($teacher);
    $dump = json_encode($raw);

    expect($raw['mfa_secret'])->not->toBe($secret)
        ->and($dump)->not->toContain($secret)
        ->and(decrypt($raw['mfa_secret'], false))->toBe($secret)
        ->and($raw['mfa_recovery_codes'])->toHaveCount(10);

    foreach ($codes as $code) {
        expect($dump)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
    }
});

it('accepts each recovery code once', function () {
    $chief = $this->school['chief'];
    $codes = $this->inSchool($this->tenant, fn () => app(Mfa::class)->regenerateRecoveryCodes(User::query()->find($chief->getKey())));

    $this->inSchool($this->tenant, function () use ($chief, $codes) {
        $mfa = app(Mfa::class);
        expect($mfa->verify(User::query()->find($chief->getKey()), $codes[0]))->toBe('recovery_code')
            ->and($mfa->verify(User::query()->find($chief->getKey()), $codes[0]))->toBeNull()
            ->and($mfa->verify(User::query()->find($chief->getKey()), strtolower($codes[1])))->toBe('recovery_code');
    });
});

it('lets a teacher switch MFA off, but not a Clerk', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: true);
    $clerk = $this->schoolUser($this->tenant, Role::Clerk, mfaEnabled: true);

    $this->actingWithToken($clerk)->deleteJson('/api/v1/me/mfa', ['code' => $this->totp($clerk)])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'mfa_required_for_role');

    $this->actingWithToken($teacher)->deleteJson('/api/v1/me/mfa', ['code' => $this->totp($teacher)])
        ->assertOk()
        ->assertJsonPath('data.mfa.enabled', false);
});
