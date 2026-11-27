<?php

use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Mail\ActivationOtpMail;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Tenancy\Models\SchoolConfig;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 2: POST /licences/activate/otp and POST /licences/activate.
*/

beforeEach(function () {
    Mail::fake();
    $this->school = $this->pendingSchool('SUNRISE-BPL');
});

function lastActivationCode(string $phone): string
{
    $message = app(SmsSender::class)->lastTo($phone);
    preg_match('/\b(\d{6})\b/', (string) $message, $m);

    return $m[1] ?? '';
}

function chiefInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Asha Verma',
        'email' => 'asha.verma@sunrise.test',
        'phone' => '+919812345678',
        'password' => 'sunrise-chief-2026',
        'password_confirmation' => 'sunrise-chief-2026',
    ], $overrides);
}

it('sends an activation code to the registered email and phone', function () {
    $tenant = $this->school['tenant'];

    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']])
        ->assertOk()
        ->assertJsonPath('data.school.code', 'SUNRISE-BPL')
        ->assertJsonPath('data.sent_to.email', 'o*****@sunrise-bpl.test')
        ->assertJsonPath('data.sent_to.phone', str_repeat('*', strlen($tenant->contact['phone']) - 4).substr($tenant->contact['phone'], -4))
        ->assertJsonPath('data.expires_in', 600);

    Mail::assertSent(ActivationOtpMail::class, fn (ActivationOtpMail $mail) => $mail->hasTo('office@sunrise-bpl.test') && strlen($mail->code) === 6);
    expect(lastActivationCode($tenant->contact['phone']))->toHaveLength(6);
});

it('activates the school, creates the Chief with MFA required, and returns an enrolment-only token', function () {
    $tenant = $this->school['tenant'];
    $this->postJson('/api/v1/licences/activate/otp', ['key' => strtolower($this->school['key'])])->assertOk();

    $response = $this->postJson('/api/v1/licences/activate', [
        'key' => strtolower($this->school['key']),
        'otp' => lastActivationCode($tenant->contact['phone']),
        'chief' => chiefInput(),
    ])->assertCreated()
        ->assertJsonPath('data.school.code', 'SUNRISE-BPL')
        ->assertJsonPath('data.user.roles', ['chief'])
        ->assertJsonPath('data.mfa_enrolment_required', true)
        ->assertJsonPath('data.token_abilities', ['mfa:enrol']);

    expect(Tenant::query()->find($tenant->id)->status)->toBe('active')
        ->and(Licence::query()->find($this->school['licence']->getKey())->status)->toBe('active');

    $chief = app(TenantContext::class)->run($tenant->id, fn () => User::query()->where('email', 'asha.verma@sunrise.test')->first());
    expect($chief)->not->toBeNull()
        ->and($chief->mfaRequired())->toBeTrue()
        ->and($chief->mfaEnabled())->toBeFalse()
        ->and($chief->password)->not->toBe('sunrise-chief-2026');

    $config = app(TenantContext::class)->run($tenant->id, fn () => SchoolConfig::query()->where('version', 1)->first());
    expect($config->body['school']['name'])->toBe($tenant->name);

    // The token works only for MFA enrolment: everything else is refused.
    app('auth')->forgetGuards();
    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/config')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'mfa_enrolment_required');
});

it('cannot be used a second time', function () {
    $phone = $this->school['tenant']->contact['phone'];
    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']]);
    $this->postJson('/api/v1/licences/activate', ['key' => $this->school['key'], 'otp' => lastActivationCode($phone), 'chief' => chiefInput()])->assertCreated();

    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'licence_already_activated');
});

it('refuses a wrong code and says how many tries are left', function () {
    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']]);
    $wrong = lastActivationCode($this->school['tenant']->contact['phone']) === '000000' ? '111111' : '000000';

    $this->postJson('/api/v1/licences/activate', ['key' => $this->school['key'], 'otp' => $wrong, 'chief' => chiefInput()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'otp_invalid')
        ->assertJsonPath('error.details.attempts_left', 4);

    expect(Tenant::query()->find($this->school['tenant']->id)->status)->toBe('pending');
});

it('locks the code after too many wrong tries', function () {
    $phone = $this->school['tenant']->contact['phone'];
    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']]);
    $right = lastActivationCode($phone);
    $wrong = $right === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $_) {
        $this->postJson('/api/v1/licences/activate', ['key' => $this->school['key'], 'otp' => $wrong, 'chief' => chiefInput()])->assertJsonPath('error.code', 'otp_invalid');
    }

    $this->postJson('/api/v1/licences/activate', ['key' => $this->school['key'], 'otp' => $wrong, 'chief' => chiefInput()])->assertJsonPath('error.code', 'otp_locked');
    $this->postJson('/api/v1/licences/activate', ['key' => $this->school['key'], 'otp' => $right, 'chief' => chiefInput()])->assertJsonPath('error.code', 'otp_expired');
});

it('limits how many codes can be sent', function () {
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']])->assertOk();
    }

    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'otp_rate_limited');
});

it('checks the Chief\'s details before using the code', function () {
    $this->postJson('/api/v1/licences/activate', [
        'key' => $this->school['key'],
        'otp' => '123456',
        'chief' => chiefInput(['password' => 'short', 'password_confirmation' => 'short', 'email' => 'not-an-email']),
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['chief.password', 'chief.email']]]);
});

it('explains why a key cannot be activated', function (string $key, int $status, string $code) {
    $this->postJson('/api/v1/licences/activate/otp', ['key' => $key])
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code);
})->with([
    'malformed' => ['hello', 422, 'licence_key_invalid'],
    'unknown' => ['CTAB-2222-3333-4444-5555', 404, 'licence_not_found'],
]);

it('refuses a revoked key', function () {
    $this->school['licence']->forceFill(['status' => Licence::STATUS_REVOKED])->save();

    $this->postJson('/api/v1/licences/activate/otp', ['key' => $this->school['key']])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'licence_revoked');
});

it('refuses a key whose term has already ended', function () {
    $expired = $this->pendingSchool('OLD-SCHOOL', ['valid_from' => '2024-04-01', 'valid_to' => '2025-03-31']);

    $this->postJson('/api/v1/licences/activate/otp', ['key' => $expired['key']])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'licence_expired');
});
