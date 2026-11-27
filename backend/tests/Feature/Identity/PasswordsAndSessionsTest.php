<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\PasswordPolicy;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Mail\PasswordChangedMail;
use App\Modules\Identity\Mail\PasswordLinkMail;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PasswordToken;
use App\Modules\Identity\Models\User;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Nobody types or sees another person's password. New accounts start
| "invited" with a one-time set link (48 h) or setup code; resets are
| one-time links (30 min). Any set, change or reset ends every session and
| token, keeps MFA, and tells the user. Lost authenticator: reset by someone
| above. Device sessions hold no raw token or cookie.
*/

beforeEach(function () {
    Mail::fake();
    $this->school = $this->activeSchool('PASS-BPL');
    $this->tenant = $this->school['tenant'];
    $this->principal = $this->schoolUser($this->tenant, Role::Principal);
});

function linkSecret(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

function lastMailedLink(): string
{
    return Mail::sent(PasswordLinkMail::class)->last()->url;
}

function setPassword(object $test, string $secret, string $password): TestResponse
{
    return $test->postJson('/api/v1/auth/password/set', [
        'school_code' => 'PASS-BPL', 'token' => $secret, 'password' => $password, 'password_confirmation' => $password,
    ]);
}

function newStaff(object $test, array $fields): TestResponse
{
    return $test->actingWithToken($test->principal)->postJson('/api/v1/users', array_merge(['name' => 'Kavya Rao', 'roles' => ['subject_teacher']], $fields));
}

describe('a new account', function () {
    it('starts invited with no password, and gets a one-time set link valid for 48 hours', function () {
        $id = newStaff($this, ['email' => 'kavya@pass-bpl.test'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.password_setup.channel', 'email')
            ->assertJsonPath('data.password_setup.code', null)
            ->json('data.id');

        $url = lastMailedLink();
        expect($url)->toStartWith('http://127.0.0.1:5173/password/set?')->toContain('school=PASS-BPL');

        $this->inSchool($this->tenant, function () use ($id, $url) {
            $token = PasswordToken::query()->where('user_id', $id)->firstOrFail();
            expect($token->token_hash)->toBe(hash('sha256', linkSecret($url)))
                ->and(json_encode($token->getAttributes()))->not->toContain(linkSecret($url))
                ->and((int) round(now()->diffInHours($token->expires_at)))->toBe(48);
        });

        // An invited account cannot sign in yet.
        $this->postJson('/api/v1/auth/login', ['school_code' => 'PASS-BPL', 'login' => 'kavya@pass-bpl.test', 'password' => 'anything at all 1'])
            ->assertJsonPath('error.code', 'sign_in_failed');

        setPassword($this, linkSecret($url), 'my own teaching phrase 7')->assertOk();
        $this->inSchool($this->tenant, fn () => expect(User::query()->find($id)->status)->toBe('active'));
        $this->postJson('/api/v1/auth/login', ['school_code' => 'PASS-BPL', 'login' => 'kavya@pass-bpl.test', 'password' => 'my own teaching phrase 7'])->assertOk();
        Mail::assertSent(PasswordChangedMail::class);

        // The link works once.
        setPassword($this, linkSecret($url), 'another long phrase 88')->assertStatus(422)->assertJsonPath('error.code', 'password_link_invalid');
    });

    it('sends the link by SMS when there is no email', function () {
        newStaff($this, ['phone' => '+919822200001', 'roles' => ['staff']])->assertCreated()->assertJsonPath('data.password_setup.channel', 'sms');

        expect(app(SmsSender::class)->lastTo('+919822200001'))->toContain('/password/set?');
    });

    it('gives the Principal a one-time setup code, shown once, when there is no email or phone', function () {
        $response = newStaff($this, ['name' => 'Gopal Driver', 'username' => 'gopal.driver', 'roles' => ['staff']])
            ->assertCreated()
            ->assertJsonPath('data.password_setup.channel', 'code');
        $code = $response->json('data.password_setup.code');

        expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/');
        Mail::assertNothingSent();

        $this->inSchool($this->tenant, function () use ($code) {
            $all = json_encode(PasswordToken::query()->get()->map->getAttributes());
            expect($all)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
        });

        // Typed in lower case without dashes still works, once.
        setPassword($this, strtolower(str_replace('-', '', $code)), 'drives the school bus 9')->assertOk();
        setPassword($this, $code, 'drives the school bus 10')->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['school_code' => 'PASS-BPL', 'login' => 'gopal.driver', 'password' => 'drives the school bus 9'])->assertOk();
    });

    it('lets the Principal resend the link, which cancels the old one', function () {
        $id = newStaff($this, ['email' => 'resend@pass-bpl.test'])->json('data.id');
        $first = linkSecret(lastMailedLink());

        $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$id}/password-link")
            ->assertStatus(202)
            ->assertJsonPath('data.password_setup.channel', 'email');
        $second = linkSecret(lastMailedLink());

        setPassword($this, $first, 'first link long phrase 1')->assertStatus(422);
        setPassword($this, $second, 'second link long phrase 2')->assertOk();
    });

    it('expires the set link after 48 hours', function () {
        newStaff($this, ['email' => 'late@pass-bpl.test']);
        $secret = linkSecret(lastMailedLink());

        $this->travel(49)->hours();

        setPassword($this, $secret, 'too late long phrase 9')->assertStatus(422)->assertJsonPath('error.code', 'password_link_invalid');
    });

    it('makes level 60 and up enrol MFA before anything else once the password is set', function () {
        newStaff($this, ['name' => 'Neha Clerk', 'email' => 'neha@pass-bpl.test', 'roles' => ['clerk']]);
        setPassword($this, linkSecret(lastMailedLink()), 'office desk phrase 42')->assertOk();

        $this->postJson('/api/v1/auth/login', ['school_code' => 'PASS-BPL', 'login' => 'neha@pass-bpl.test', 'password' => 'office desk phrase 42'])
            ->assertOk()
            ->assertJsonPath('data.mfa_enrolment_required', true)
            ->assertJsonPath('data.token_abilities', ['mfa:enrol']);
    });
});

describe('password rules', function () {
    it('needs 10 characters and refuses common passwords from the bundled list', function (string $password) {
        newStaff($this, ['email' => 'weak@pass-bpl.test']);

        setPassword($this, linkSecret(lastMailedLink()), $password)->assertStatus(422)->assertJsonValidationErrors('password', 'error.details');
    })->with(['short1234', '1234567890', 'password123', 'Password@123', 'qwertyuiop', 'iloveindia123', 'aaaaaaaaaaaa', 'cricket@2024']);

    it('refuses the person\'s own login or name', function (string $password) {
        newStaff($this, ['name' => 'Kavya Ramesh', 'email' => 'kavyarao@pass-bpl.test', 'phone' => '+919822200077']);

        setPassword($this, linkSecret(lastMailedLink()), $password)->assertStatus(422)->assertJsonValidationErrors('password', 'error.details');
    })->with(['kavyarao@pass-bpl.test', 'kavyarao2026!!', '+919822200077', 'Kavya Ramesh 12', 'ramesh@1234567']);

    it('checks the common list without calling any outside service', function () {
        expect(PasswordPolicy::isCommon('welcome@123'))->toBeTrue()
            ->and(PasswordPolicy::isCommon('a quiet river at dawn'))->toBeFalse()
            ->and(is_file(config('identity.passwords.common_list')))->toBeTrue();
    });
});

describe('reset', function () {
    it('gives the same forgot-password reply whether or not an account matches', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'PASS-BPL', 'login' => $teacher->email])->assertStatus(202)->json();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'PASS-BPL', 'login' => 'ghost@pass-bpl.test'])->assertStatus(202)->json();
        $badSchool = $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'NOPE-XYZ', 'login' => $teacher->email])->assertStatus(202)->json();

        expect($unknown)->toBe($known)->and($badSchool)->toBe($known);
        Mail::assertSent(PasswordLinkMail::class, 1);
    });

    it('makes the reset link last 30 minutes', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
        $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'PASS-BPL', 'login' => $teacher->email]);
        $secret = linkSecret(lastMailedLink());

        $this->travel(31)->minutes();

        setPassword($this, $secret, 'fresh start phrase 2026')->assertStatus(422);
    });

    it('limits reset requests per account, silently', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'PASS-BPL', 'login' => $teacher->email])->assertStatus(202);
        }

        Mail::assertSent(PasswordLinkMail::class, 3);
    });

    it('ends every session and token, keeps MFA on, and tells the user', function () {
        $vp = $this->schoolUser($this->tenant, Role::VicePrincipal, mfaEnabled: true);
        $phone = $this->tokenFor($vp);
        $laptop = $this->tokenFor($vp);

        $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'PASS-BPL', 'login' => $vp->email]);
        setPassword($this, linkSecret(lastMailedLink()), 'fresh start phrase 2026')->assertOk();

        foreach ([$phone, $laptop] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        }

        $this->inSchool($this->tenant, fn () => expect(User::query()->find($vp->getKey())->mfaEnabled())->toBeTrue());
        Mail::assertSent(PasswordChangedMail::class, fn ($m) => $m->hasTo($vp->email));
    });

    it('lets the Principal send a reset link to someone below them, but never to the Chief', function () {
        $clerk = $this->schoolUser($this->tenant, Role::Clerk);

        $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$clerk->getKey()}/password-link")
            ->assertStatus(202)
            ->assertJsonPath('data.password_setup.channel', 'email');

        $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$this->school['chief']->getKey()}/password-link")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'level_too_low');
    });

    it('needs the current password to change it in settings, and then ends every session', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
        $other = $this->tokenFor($teacher);
        $current = $this->tokenFor($teacher);

        app('auth')->forgetGuards();
        $this->withToken($current)->putJson('/api/v1/me/password', [
            'current_password' => 'not my password 1', 'password' => 'a new phrase for me 11', 'password_confirmation' => 'a new phrase for me 11',
        ])->assertStatus(422)->assertJsonPath('error.code', 'current_password_wrong');

        app('auth')->forgetGuards();
        $this->withToken($current)->putJson('/api/v1/me/password', [
            'current_password' => TestData::PASSWORD, 'password' => 'a new phrase for me 11', 'password_confirmation' => 'a new phrase for me 11',
        ])->assertOk()->assertJsonPath('data.sessions_ended', 2)->assertJsonPath('data.sign_in_again', true);

        foreach ([$current, $other] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        }

        Mail::assertSent(PasswordChangedMail::class);
    });

    it('audits every password event without any password, link or code', function () {
        newStaff($this, ['email' => 'audit@pass-bpl.test']);
        $secret = linkSecret(lastMailedLink());
        setPassword($this, $secret, 'audited long phrase 55')->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['school_code' => 'PASS-BPL', 'login' => 'audit@pass-bpl.test']);
        $resetSecret = linkSecret(lastMailedLink());

        $this->inSchool($this->tenant, function () {
            $actions = AuditLog::query()->where('action', 'like', 'password.%')->pluck('action')->all();
            expect($actions)->toContain('password.set_link_sent', 'password.set', 'password.reset_link_sent');
        });

        $dump = json_encode(DB::connection('mongodb')->getCollection('audit_logs')->find(['tenant_id' => $this->tenant->id])->toArray());
        expect($dump)->not->toContain($secret)->not->toContain($resetSecret)->not->toContain('audited long phrase 55');
    });
});

describe('lost authenticator', function () {
    it('lets the Principal reset MFA for someone below them, with step-up', function () {
        $clerk = $this->schoolUser($this->tenant, Role::Clerk, mfaEnabled: true);
        $token = $this->tokenFor($clerk);

        $this->actingWithToken($this->principal)->postJson("/api/v1/users/{$clerk->getKey()}/mfa-reset")
            ->assertJsonPath('error.code', 'step_up_required');

        $this->actingWithToken($this->principal, steppedUp: true)->postJson("/api/v1/users/{$clerk->getKey()}/mfa-reset")
            ->assertOk()
            ->assertJsonPath('data.mfa_enabled', false);

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        // Next sign-in: enrol again before anything else.
        $this->postJson('/api/v1/auth/login', ['school_code' => 'PASS-BPL', 'login' => $clerk->email, 'password' => TestData::PASSWORD])
            ->assertJsonPath('data.mfa_enrolment_required', true);

        $this->inSchool($this->tenant, fn () => expect(AuditLog::query()->where('action', 'mfa.reset')->exists())->toBeTrue());
    });

    it('needs another Chief for a Chief', function () {
        $chief = $this->school['chief'];

        $this->actingWithToken($this->principal, steppedUp: true)->postJson("/api/v1/users/{$chief->getKey()}/mfa-reset")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'level_too_low');

        $this->actingWithToken($chief, steppedUp: true)->postJson("/api/v1/users/{$chief->getKey()}/mfa-reset")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mfa_reset_self');

        $second = $this->schoolUser($this->tenant, Role::Chief);
        $this->actingWithToken($second, steppedUp: true)->postJson("/api/v1/users/{$chief->getKey()}/mfa-reset")->assertOk();
    });
});

describe('device sessions', function () {
    it('lists devices and signs one out remotely', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
        $phone = $this->tokenFor($teacher);
        $laptop = $this->tokenFor($teacher);

        app('auth')->forgetGuards();
        $sessions = $this->withToken($laptop)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $phoneSession = collect($sessions)->firstWhere('current', false);

        app('auth')->forgetGuards();
        $this->withToken($laptop)->deleteJson("/api/v1/me/sessions/{$phoneSession['id']}")->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($laptop)->getJson('/api/v1/me')->assertOk();
    });

    it('signs out every other device at once', function () {
        $teacher = $this->schoolUser($this->tenant, Role::ClassTeacher, mfaEnabled: false);
        $a = $this->tokenFor($teacher);
        $b = $this->tokenFor($teacher);
        $current = $this->tokenFor($teacher);

        app('auth')->forgetGuards();
        $this->withToken($current)->postJson('/api/v1/me/sessions/revoke-others')->assertOk()->assertJsonPath('data.sessions_ended', 2);

        foreach ([$a, $b] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        }
    });

    it('stores the token record id, never the token itself', function () {
        $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);
        [$id, $secret] = explode('|', $this->tokenFor($teacher), 2);

        $this->inSchool($this->tenant, function () use ($id, $secret) {
            $session = DeviceSession::query()->where('token_id', $id)->firstOrFail();
            expect(json_encode($session->getAttributes()))->not->toContain($secret);
        });
    });

    it('cannot sign out another person\'s device', function () {
        $teacher = $this->schoolUser($this->tenant, Role::Staff, mfaEnabled: false);
        $this->tokenFor($teacher);
        $theirs = $this->inSchool($this->tenant, fn () => DeviceSession::query()->where('user_id', (string) $teacher->getKey())->firstOrFail());

        $this->actingWithToken($this->principal)->deleteJson("/api/v1/me/sessions/{$theirs->getKey()}")->assertNotFound();
    });
});
