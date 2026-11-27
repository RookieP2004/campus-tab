<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 6: password sign-in, the MFA step, the enrol-only session, lockout
| and the same answer for every failure.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('SIGNIN-BPL');
    $this->tenant = $this->school['tenant'];
});

function signIn(object $test, string $login, string $password, string $code = 'SIGNIN-BPL'): TestResponse
{
    app('auth')->forgetGuards();

    return $test->postJson('/api/v1/auth/login', [
        'school_code' => $code,
        'login' => $login,
        'password' => $password,
        'device_name' => 'Test phone',
    ]);
}

it('signs a teacher in with a password and returns a token for an app', function () {
    $teacher = $this->schoolUser($this->tenant, Role::SubjectTeacher, mfaEnabled: false);

    $response = signIn($this, $teacher->email, TestData::PASSWORD)
        ->assertOk()
        ->assertJsonPath('data.mode', 'token')
        ->assertJsonPath('data.token_abilities', ['*'])
        ->assertJsonPath('data.mfa_enrolment_required', false);

    app('auth')->forgetGuards();
    $this->withToken($response->json('data.token'))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.roles', ['subject_teacher'])
        ->assertJsonPath('data.language', 'en')
        ->assertJsonPath('data.theme', 'system');
});

it('asks a Chief with MFA for the code, then signs in with it', function () {
    $chief = $this->school['chief'];

    $step = signIn($this, $chief->email, TestData::PASSWORD)
        ->assertOk()
        ->assertJsonPath('data.mfa_required', true)
        ->assertJsonMissingPath('data.token');

    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $step->json('data.challenge'), 'code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'mfa_code_invalid');

    $code = $this->totp($chief);
    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $step->json('data.challenge'), 'code' => $code])
        ->assertOk()
        ->assertJsonPath('data.mode', 'token');

    // The same code cannot be used twice.
    $again = signIn($this, $chief->email, TestData::PASSWORD);
    $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $again->json('data.challenge'), 'code' => $code])
        ->assertStatus(422);
});

it('gives the same answer for an unknown school, an unknown user and a wrong password', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

    $unknownSchool = signIn($this, $teacher->email, TestData::PASSWORD, 'NOPE-XYZ')->assertUnauthorized()->json();
    $unknownUser = signIn($this, 'nobody@signin-bpl.test', TestData::PASSWORD)->assertUnauthorized()->json();
    $wrongPassword = signIn($this, $teacher->email, 'not-the-password-99')->assertUnauthorized()->json();

    expect($unknownSchool)->toBe($wrongPassword)
        ->and($unknownUser)->toBe($wrongPassword)
        ->and($wrongPassword['error']['code'])->toBe('sign_in_failed')
        ->and($wrongPassword['error']['message'])->toContain('Try again in 15 minutes or ask your Principal');
});

it('locks an account after 5 failures and does not check the password while locked', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

    foreach (range(1, 5) as $i) {
        signIn($this, $teacher->email, "wrong-password-{$i}x")->assertUnauthorized();
    }

    // Right password during the lock: exactly the same answer as a wrong one.
    $right = signIn($this, $teacher->email, TestData::PASSWORD)->assertUnauthorized()->json();
    $wrong = signIn($this, $teacher->email, 'still-wrong-123x')->assertUnauthorized()->json();
    expect($right)->toBe($wrong);

    $this->inSchool($this->tenant, function () use ($teacher) {
        expect(User::query()->find($teacher->getKey())->isLocked())->toBeTrue()
            ->and(AuditLog::query()->where('action', 'sign_in.locked')->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'sign_in.blocked')->count())->toBe(2);
    });

    // The lock ends by itself after 15 minutes.
    $this->travel(16)->minutes();
    signIn($this, $teacher->email, TestData::PASSWORD)->assertOk();
});

it('shows the lock in GET /users and lets the Principal unlock early', function () {
    $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
    $principal = $this->schoolUser($this->tenant, Role::Principal);

    foreach (range(1, 5) as $i) {
        signIn($this, $teacher->email, "wrong-password-{$i}x");
    }

    $listed = collect($this->actingWithToken($principal)->getJson('/api/v1/users')->assertOk()->json('data'))
        ->firstWhere('id', (string) $teacher->getKey());
    expect($listed['locked'])->toBeTrue()->and($listed['locked_until'])->not->toBeNull();

    $this->actingWithToken($principal)->postJson("/api/v1/users/{$teacher->getKey()}/unlock")
        ->assertOk()
        ->assertJsonPath('data.locked', false);

    signIn($this, $teacher->email, TestData::PASSWORD)->assertOk();
});

it('does not let a Principal unlock a Chief (level rule)', function () {
    $principal = $this->schoolUser($this->tenant, Role::Principal);

    $this->actingWithToken($principal)->postJson("/api/v1/users/{$this->school['chief']->getKey()}/unlock")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'level_too_low');
});

it('counts wrong MFA codes towards the lockout', function () {
    $chief = $this->school['chief'];

    foreach (range(1, 5) as $i) {
        $step = signIn($this, $chief->email, TestData::PASSWORD);

        if ($step->json('data.challenge') === null) {
            break;
        }

        $this->postJson('/api/v1/auth/mfa/verify', ['challenge' => $step->json('data.challenge'), 'code' => '000001']);
    }

    $this->inSchool($this->tenant, fn () => expect(User::query()->find($chief->getKey())->isLocked())->toBeTrue());
    signIn($this, $chief->email, TestData::PASSWORD)->assertJsonPath('error.code', 'sign_in_failed');
});

it('limits failures per IP only for bulk abuse, with a configurable roomy default', function () {
    expect(config('identity.sign_in.ip_max_failures'))->toBe(100);

    config(['identity.sign_in.ip_max_failures' => 3]);

    foreach (range(1, 3) as $i) {
        signIn($this, "someone{$i}@signin-bpl.test", 'whatever-password-1')->assertUnauthorized();
    }

    signIn($this, 'another@signin-bpl.test', 'whatever-password-1')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_attempts');
});

it('gives a Clerk without MFA an enrol-only session', function () {
    $clerk = $this->schoolUser($this->tenant, Role::Clerk, mfaEnabled: false);

    $token = signIn($this, $clerk->email, TestData::PASSWORD)
        ->assertOk()
        ->assertJsonPath('data.mfa_enrolment_required', true)
        ->assertJsonPath('data.token_abilities', ['mfa:enrol'])
        ->json('data.token');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'mfa_enrolment_required');
});

it('signs the web app in with a session cookie and stores only a hash of the session id', function () {
    $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);

    $this->withHeaders(['Origin' => 'http://127.0.0.1:5173', 'Referer' => 'http://127.0.0.1:5173/'])
        ->postJson('/api/v1/auth/login', ['school_code' => 'SIGNIN-BPL', 'login' => $teacher->email, 'password' => TestData::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.mode', 'browser')
        ->assertJsonMissingPath('data.token');

    $sessionId = session()->getId();

    $this->inSchool($this->tenant, function () use ($sessionId) {
        $session = DeviceSession::query()->where('kind', 'browser')->firstOrFail();
        expect($session->session_hash)->toBe(hash('sha256', $sessionId))
            ->and(json_encode($session->getAttributes()))->not->toContain($sessionId);
    });
});

describe('sign in every time', function () {
    it('keeps the three session limits in config, and the browser cookie ends with the browser', function () {
        expect(config('identity.sessions'))->toBe(['idle_minutes_mfa_roles' => 15, 'idle_minutes' => 30, 'max_hours' => 8])
            ->and(config('session.expire_on_close'))->toBeTrue()
            ->and(config('session.lifetime'))->toBe(30);
    });

    it('signs out after 15 idle minutes at level 60 and up, and 30 below', function () {
        $clerk = $this->schoolUser($this->tenant, Role::Clerk);
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
        $clerkToken = $this->tokenFor($clerk);
        $teacherToken = $this->tokenFor($teacher);

        $this->travel(16)->minutes();

        $this->withToken($clerkToken)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');
        app('auth')->forgetGuards();
        $this->withToken($teacherToken)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.session.idle_minutes', 30);

        $this->travel(31)->minutes();
        app('auth')->forgetGuards();
        $this->withToken($teacherToken)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('signs out after 8 hours in total, even when in use all the time', function () {
        $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);
        $token = $this->tokenFor($teacher);

        foreach (range(1, 17) as $i) {
            $this->travel(28)->minutes();
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        }

        $this->travel(28)->minutes(); // 8 h 24 min since sign-in
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('never offers "remember me": no remember token is ever set', function () {
        $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);

        $this->withHeaders(['Origin' => 'http://127.0.0.1:5173', 'Referer' => 'http://127.0.0.1:5173/'])
            ->postJson('/api/v1/auth/login', ['school_code' => 'SIGNIN-BPL', 'login' => $teacher->email, 'password' => TestData::PASSWORD, 'remember' => true])
            ->assertOk();

        $this->inSchool($this->tenant, fn () => expect(User::query()->find($teacher->getKey())->getAttribute('remember_token'))->toBeNull());
    });
});

it('lets a person sign in with a login name', function () {
    $user = $this->inSchool($this->tenant, fn () => User::create([
        'name' => 'Gopal Driver', 'username' => 'gopal.driver', 'password' => TestData::PASSWORD, 'roles' => ['staff'],
    ]));

    signIn($this, 'Gopal.Driver', TestData::PASSWORD)->assertOk()->assertJsonPath('data.user.id', (string) $user->getKey());
});

it('lets every user pick any language the app ships, and a theme', function () {
    $parent = $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false);

    $this->actingWithToken($parent)->patchJson('/api/v1/me/preferences', ['language' => 'ta', 'theme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('data.language', 'ta')
        ->assertJsonPath('data.theme', 'dark');

    // Not one of the school's featured languages: still allowed.
    expect($this->configBody($this->tenant)['locale']['featured_languages'])->not->toContain('ur');
    $this->actingWithToken($parent)->patchJson('/api/v1/me/preferences', ['language' => 'ur'])->assertOk();

    $this->actingWithToken($parent)->patchJson('/api/v1/me/preferences', ['language' => 'xx'])->assertStatus(422);
    $this->actingWithToken($parent)->patchJson('/api/v1/me/preferences', ['theme' => 'neon'])->assertStatus(422);

    expect($this->configBody($this->tenant)['locale'])->not->toHaveKey('user_can_choose_language');
});

it('records every sign-in in the audit log', function () {
    $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);
    signIn($this, $teacher->email, TestData::PASSWORD)->assertOk();

    $this->inSchool($this->tenant, function () use ($teacher) {
        $entry = AuditLog::query()->where('action', 'sign_in')->firstOrFail();
        expect($entry->actor['id'])->toBe((string) $teacher->getKey())
            ->and($entry->category)->toBe('security')
            ->and($entry->meta['method'])->toBe('password')
            ->and($entry->ip)->not->toBeNull();
    });
});
