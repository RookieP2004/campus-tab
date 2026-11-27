<?php

use App\Modules\Identity\Domain\Role;
use App\Providers\AppServiceProvider;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Parent sign-in by SMS code: Parent role only, never with MFA on or a
| staff role; hash only; 5 minutes; limits per phone and per IP; the same
| reply whether or not the number is known.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('OTP-BPL');
    $this->tenant = $this->school['tenant'];
    $this->sms = app(SmsSender::class);
});

function askCode(object $test, string $phone): TestResponse
{
    return $test->postJson('/api/v1/auth/otp/request', ['school_code' => 'OTP-BPL', 'phone' => $phone]);
}

function codeFrom(?string $message): string
{
    preg_match('/\b(\d{6})\b/', (string) $message, $m);

    return $m[1] ?? '';
}

it('signs a parent in with the code sent by SMS', function () {
    $parent = $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, phone: '+919811100001');

    askCode($this, '+91 98111 00001')->assertStatus(202);
    $code = codeFrom($this->sms->lastTo('+919811100001'));

    $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'OTP-BPL', 'phone' => '+919811100001', 'code' => $code])
        ->assertOk()
        ->assertJsonPath('data.mode', 'token')
        ->assertJsonPath('data.user.id', (string) $parent->getKey());
});

it('gives the same reply for an unknown number and sends nothing', function () {
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, phone: '+919811100002');

    $known = askCode($this, '+919811100002')->assertStatus(202)->json();
    $unknown = askCode($this, '+919811199999')->assertStatus(202)->json();

    expect($unknown)->toBe($known)
        ->and($this->sms->lastTo('+919811199999'))->toBeNull();
});

it('never sends a code to an account that also holds a staff role', function () {
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, extraRoles: ['subject_teacher'], phone: '+919811100003');

    askCode($this, '+919811100003')->assertStatus(202);

    expect($this->sms->lastTo('+919811100003'))->toBeNull();
});

it('never sends a code to a parent who has MFA switched on', function () {
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: true, phone: '+919811100004');

    askCode($this, '+919811100004')->assertStatus(202);

    expect($this->sms->lastTo('+919811100004'))->toBeNull();
});

it('stores only a hash of the code, which expires after 5 minutes', function () {
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, phone: '+919811100005');

    askCode($this, '+919811100005');
    $code = codeFrom($this->sms->lastTo('+919811100005'));

    $stored = Cache::get('otp:parent-sign-in:'.hash('sha256', $this->tenant->id.'|+919811100005'));
    expect($stored)->toBeArray()
        ->and($stored['hash'])->not->toBe($code)
        ->and(json_encode($stored))->not->toContain($code)
        ->and(config('identity.parent_otp.ttl_minutes'))->toBe(5);

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'OTP-BPL', 'phone' => '+919811100005', 'code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'otp_invalid');
});

it('gives every failed check the same answer', function () {
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, phone: '+919811100006');
    askCode($this, '+919811100006');

    $wrong = $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'OTP-BPL', 'phone' => '+919811100006', 'code' => '999999'])->json();
    $unknown = $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'OTP-BPL', 'phone' => '+919811177777', 'code' => '123456'])->json();

    expect($wrong)->toBe($unknown)->and($wrong['error']['code'])->toBe('otp_invalid');
});

it('limits sends per phone, for known and unknown numbers alike', function () {
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, phone: '+919811100007');

    foreach (range(1, 3) as $i) {
        askCode($this, '+919811100007')->assertStatus(202);
        askCode($this, '+919811188888')->assertStatus(202);
    }

    askCode($this, '+919811100007')->assertStatus(429)->assertJsonPath('error.code', 'otp_rate_limited');
    askCode($this, '+919811188888')->assertStatus(429)->assertJsonPath('error.code', 'otp_rate_limited');
});

it('limits sends per IP only for bulk abuse, with a roomy configurable default', function () {
    expect(config('identity.parent_otp.sends_per_ip_per_10_minutes'))->toBe(60);
    config(['identity.parent_otp.sends_per_ip_per_10_minutes' => 2]);

    askCode($this, '+919811100011')->assertStatus(202);
    askCode($this, '+919811100012')->assertStatus(202);
    askCode($this, '+919811100013')->assertStatus(429);
});

it('stops checking codes for a phone after too many failures', function () {
    config(['identity.parent_otp.failures_per_phone_per_hour' => 2]);
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false, phone: '+919811100008');

    askCode($this, '+919811100008');
    $code = codeFrom($this->sms->lastTo('+919811100008'));

    foreach (['111111', '222222'] as $wrong) {
        $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'OTP-BPL', 'phone' => '+919811100008', 'code' => $wrong])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/otp/verify', ['school_code' => 'OTP-BPL', 'phone' => '+919811100008', 'code' => $code])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'sign_in_failed');
});

describe('SMS driver', function () {
    it('allows log and array only in local and testing', function () {
        config(['services.sms.driver' => 'log']);
        expect(AppServiceProvider::smsDriver())->toBe('log');

        app()->detectEnvironment(fn () => 'production');

        expect(fn () => AppServiceProvider::smsDriver())->toThrow(RuntimeException::class, 'allowed only in local and testing');

        config(['services.sms.driver' => 'array']);
        expect(fn () => AppServiceProvider::smsDriver())->toThrow(RuntimeException::class);
    });

    it('treats a missing driver as an error in production, never a silent fallback', function () {
        config(['services.sms.driver' => null]);
        expect(AppServiceProvider::smsDriver())->toBe('log'); // testing

        app()->detectEnvironment(fn () => 'production');

        expect(fn () => AppServiceProvider::smsDriver())->toThrow(RuntimeException::class, 'SMS_DRIVER is not set');
    });
});
