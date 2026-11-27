<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\PlatformAuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Platform\Mail\ChiefRecoveryCodeMail;
use App\Modules\Platform\Mail\ConsolePasswordLinkMail;
use App\Modules\Platform\Models\PlatformSession;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Phase 1 part C: the platform console. Platform admins come only from the
| command line, sign in with password and MFA every time, follow the same
| idle rules as school users, and every console action is audited (in the
| school's own log too when it touches a school).
*/

const CONSOLE_ORIGIN = ['Origin' => 'http://127.0.0.1:5173', 'Referer' => 'http://127.0.0.1:5173/'];

/** An active platform admin with password and a real TOTP secret. */
function consoleAdmin(string $email = 'ravi@campustab.test'): PlatformAdmin
{
    $admin = PlatformAdmin::create(['name' => 'Ravi Platform', 'email' => $email, 'password' => TestData::PASSWORD]);
    $admin->forceFill(['mfa' => ['enabled' => true], 'mfa_secret' => app(Google2FA::class)->generateSecretKey(32)])->save();

    return $admin;
}

function adminTotp(PlatformAdmin $admin): string
{
    $fresh = PlatformAdmin::query()->findOrFail($admin->getKey());

    return app(Google2FA::class)->getCurrentOtp((string) $fresh->mfa_secret);
}

/** The next request from the web app's browser, carrying the session cookie. */
function browser(object $test): object
{
    app('auth')->forgetGuards();

    return $test->withHeaders(CONSOLE_ORIGIN)->withCredentials()->withCookie((string) config('session.cookie'), session()->getId());
}

/** Signs the admin in to the console (password, then TOTP). */
function consoleSignIn(object $test, PlatformAdmin $admin): void
{
    $challenge = browser($test)->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => TestData::PASSWORD])
        ->assertOk()->assertJsonPath('data.mfa_required', true)->json('data.challenge');

    browser($test)->postJson('/api/v1/platform/auth/mfa/verify', ['challenge' => $challenge, 'code' => adminTotp($admin)])
        ->assertOk()->assertJsonPath('data.mfa_enrolment_required', false);
}

function consoleStepUp(object $test, PlatformAdmin $admin): void
{
    // A TOTP code is accepted once: forget the step used at sign-in, as if
    // the next 30-second step had come.
    $fresh = PlatformAdmin::query()->findOrFail($admin->getKey());
    $fresh->forceFill(['mfa' => array_merge($fresh->mfa, ['last_timestep' => 0])])->save();
    browser($test)->postJson('/api/v1/platform/auth/step-up', ['code' => adminTotp($admin)])->assertOk();
}

function createSchoolInConsole(object $test, string $code = 'NEW-BPL'): TestResponse
{
    return browser($test)->postJson('/api/v1/platform/schools', [
        'code' => $code,
        'name' => 'New Public School',
        'email' => 'office@new-bpl.test',
        'phone' => '+919800000123',
    ]);
}

describe('accounts', function () {
    it('creates platform admins only from the command line, with no password and a one-time link', function () {
        Mail::fake();

        expect(Artisan::call('platform:create-admin', ['name' => 'Meera Platform', 'email' => 'Meera@CampusTab.test']))->toBe(0);

        $admin = PlatformAdmin::query()->where('email', 'meera@campustab.test')->firstOrFail();
        expect($admin->status)->toBe(PlatformAdmin::STATUS_INVITED)
            ->and($admin->hasPassword())->toBeFalse()
            ->and(Artisan::output())->toContain('/console/set-password?token=');

        Mail::assertSent(ConsolePasswordLinkMail::class);
        expect(PlatformAuditLog::query()->where('action', 'platform_admin.created')->count())->toBe(1);

        // There is no sign-up route.
        $this->postJson('/api/v1/platform/admins', ['name' => 'X', 'email' => 'x@campustab.test'])->assertNotFound();
        $this->postJson('/api/v1/platform/auth/register', [])->assertNotFound();
    });

    it('sets the password once with the link, and the link cannot be used again', function () {
        Mail::fake();
        Artisan::call('platform:create-admin', ['name' => 'Meera Platform', 'email' => 'meera@campustab.test']);
        preg_match('/token=([A-Za-z0-9]+)/', Artisan::output(), $m);

        $body = ['token' => $m[1], 'password' => 'new-console-pass-77', 'password_confirmation' => 'new-console-pass-77'];
        $this->postJson('/api/v1/platform/auth/password/set', $body)->assertOk();
        $this->postJson('/api/v1/platform/auth/password/set', $body)->assertStatus(422)->assertJsonPath('error.code', 'password_link_invalid');

        $admin = PlatformAdmin::query()->where('email', 'meera@campustab.test')->firstOrFail();
        expect($admin->status)->toBe(PlatformAdmin::STATUS_ACTIVE)
            ->and(Hash::check('new-console-pass-77', (string) $admin->password))->toBeTrue()
            ->and(json_encode(PlatformAuditLog::query()->get()->toArray()))->not->toContain('new-console-pass-77');
    });

    it('never signs in an account with no password, whatever is typed', function () {
        PlatformAdmin::create(['name' => 'Invited One', 'email' => 'invited@campustab.test', 'status' => PlatformAdmin::STATUS_INVITED]);

        foreach (['x', 'null', 'anything-at-all-1', TestData::PASSWORD] as $typed) {
            browser($this)->postJson('/api/v1/platform/auth/login', ['email' => 'invited@campustab.test', 'password' => $typed])
                ->assertUnauthorized()->assertJsonPath('error.code', 'console_sign_in_failed');
        }
    });
});

describe('signing in', function () {
    it('asks for the password and an MFA code every time', function () {
        $admin = consoleAdmin();

        browser($this)->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => 'wrong-password-1'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'console_sign_in_failed');

        $challenge = browser($this)->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => TestData::PASSWORD])
            ->assertOk()->json('data.challenge');

        // Password alone is not a session.
        browser($this)->getJson('/api/v1/platform/schools')->assertUnauthorized();

        browser($this)->postJson('/api/v1/platform/auth/mfa/verify', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'mfa_code_invalid');
        browser($this)->postJson('/api/v1/platform/auth/mfa/verify', ['challenge' => $challenge, 'code' => adminTotp($admin)])->assertOk();

        browser($this)->getJson('/api/v1/platform/schools')->assertOk();
        browser($this)->getJson('/api/v1/platform/me')->assertOk()->assertJsonPath('data.session.idle_minutes', 15);

        expect(PlatformAuditLog::query()->where('action', 'console.sign_in')->count())->toBe(1)
            ->and(PlatformAuditLog::query()->where('action', 'console.sign_in.failed')->count())->toBe(2);
    });

    it('locks the account after 5 failures, wrong MFA codes included', function () {
        $admin = consoleAdmin();

        foreach (range(1, 5) as $i) {
            browser($this)->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => 'wrong-password-'.$i]);
        }

        browser($this)->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => TestData::PASSWORD])
            ->assertUnauthorized()->assertJsonPath('error.code', 'console_sign_in_failed');
        expect(PlatformAdmin::query()->find($admin->getKey())->isLocked())->toBeTrue();
    });

    it('makes the first sign-in enrol MFA before anything else', function () {
        $admin = PlatformAdmin::create(['name' => 'New Admin', 'email' => 'new@campustab.test', 'password' => TestData::PASSWORD]);

        browser($this)->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => TestData::PASSWORD])
            ->assertOk()->assertJsonPath('data.mfa_enrolment_required', true);

        browser($this)->getJson('/api/v1/platform/schools')->assertForbidden()->assertJsonPath('error.code', 'mfa_enrolment_required');
        browser($this)->getJson('/api/v1/platform/me')->assertOk()->assertJsonPath('data.mfa.enabled', false);

        $secret = browser($this)->postJson('/api/v1/platform/me/mfa/enrol')->assertOk()->json('data.secret');
        $codes = browser($this)->postJson('/api/v1/platform/me/mfa/enrol/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertOk()->json('data.recovery_codes');

        expect($codes)->toHaveCount(10);
        browser($this)->getJson('/api/v1/platform/schools')->assertOk();
    });

    it('works only in the web app with the session cookie, never with a token', function () {
        $admin = consoleAdmin();

        app('auth')->forgetGuards();
        $this->postJson('/api/v1/platform/auth/login', ['email' => $admin->email, 'password' => TestData::PASSWORD])
            ->assertForbidden()->assertJsonPath('error.code', 'console_browser_only');

        app('auth')->forgetGuards();
        $this->withToken($admin->createToken('x', ['*'])->plainTextToken)->getJson('/api/v1/platform/schools')
            ->assertForbidden()->assertJsonPath('error.code', 'console_browser_only');
    });

    it('keeps the console and school accounts apart', function () {
        $school = $this->activeSchool('APART-BPL');
        $admin = consoleAdmin();
        consoleSignIn($this, $admin);

        // The console session opens no school route.
        browser($this)->getJson('/api/v1/users')->assertUnauthorized();

        // Signing in to a school in the same browser ends the console session.
        browser($this)->postJson('/api/v1/auth/login', ['school_code' => 'APART-BPL', 'login' => $school['chief']->email, 'password' => TestData::PASSWORD])
            ->assertOk()->assertJsonPath('data.mfa_required', true);
        $challenge = browser($this)->postJson('/api/v1/auth/login', ['school_code' => 'APART-BPL', 'login' => $school['chief']->email, 'password' => TestData::PASSWORD])->json('data.challenge');
        browser($this)->postJson('/api/v1/auth/mfa/verify', ['challenge' => $challenge, 'code' => $this->totp($school['chief'])])->assertOk();

        browser($this)->getJson('/api/v1/platform/schools')->assertUnauthorized();
    });
});

describe('idle sign-out', function () {
    it('signs out after 15 minutes without use', function () {
        consoleSignIn($this, consoleAdmin());

        $this->travel(16)->minutes();

        browser($this)->getJson('/api/v1/platform/schools')->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');
        expect(PlatformSession::query()->first()->revoked_reason)->toBe('idle');
    });

    it('does not count background requests as use', function () {
        consoleSignIn($this, consoleAdmin());

        $this->travel(10)->minutes();
        browser($this)->withHeader('X-Background-Request', '1')->getJson('/api/v1/platform/schools')->assertOk();

        $this->travel(6)->minutes();
        browser($this)->withHeader('X-Background-Request', '1')->getJson('/api/v1/platform/schools')
            ->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');
    });

    it('counts real use', function () {
        consoleSignIn($this, consoleAdmin());

        $this->travel(10)->minutes();
        browser($this)->getJson('/api/v1/platform/schools')->assertOk();

        $this->travel(6)->minutes();
        browser($this)->getJson('/api/v1/platform/schools')->assertOk();
    });

    it('lists the admin\'s browsers and signs one out remotely', function () {
        consoleSignIn($this, consoleAdmin());

        $sessions = browser($this)->getJson('/api/v1/platform/me/sessions')->assertOk()->json('data');
        expect($sessions)->toHaveCount(1)->and($sessions[0]['current'])->toBeTrue();

        browser($this)->deleteJson('/api/v1/platform/me/sessions/'.$sessions[0]['id'])->assertOk();
        browser($this)->getJson('/api/v1/platform/schools')->assertUnauthorized()->assertJsonPath('error.code', 'session_revoked');
    });
});

describe('schools and licences', function () {
    beforeEach(function () {
        $this->admin = consoleAdmin();
        consoleSignIn($this, $this->admin);
    });

    it('creates a school and issues its key, shown once, in both audit logs', function () {
        createSchoolInConsole($this)->assertCreated()->assertJsonPath('data.status', 'pending');

        $issued = browser($this)->postJson('/api/v1/platform/schools/NEW-BPL/licences', [
            'plan' => 'standard', 'modules' => ['attendance', 'leave'], 'students' => 600, 'staff' => 40,
        ])->assertCreated()->assertJsonPath('data.activation_needed', true);

        expect($issued->json('data.key'))->toMatch('/^CTAB-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/');

        browser($this)->getJson('/api/v1/platform/schools/NEW-BPL')->assertOk()
            ->assertJsonPath('data.licence.seats.students', 600)
            ->assertJsonMissingPath('data.licence.key');

        $tenant = Tenant::findByCode('NEW-BPL');
        $platform = PlatformAuditLog::query()->where('school_id', $tenant->id)->pluck('action')->all();
        expect($platform)->toContain('school.created', 'licence.issued');

        $this->inSchool($tenant, function () use ($issued) {
            $entry = AuditLog::query()->where('action', 'platform.licence.issued')->firstOrFail();
            expect($entry->actor['type'])->toBe('platform_admin')
                ->and($entry->category)->toBe('security')
                ->and(json_encode($entry->toArray()))->not->toContain($issued->json('data.key'));
        });
    });

    it('changes plan, seats and modules with a newly signed licence', function () {
        $school = $this->activeSchool('CHANGE-BPL');

        browser($this)->patchJson('/api/v1/platform/schools/CHANGE-BPL/licence', ['plan' => 'premium', 'students' => 900, 'modules' => ['attendance']])
            ->assertOk()->assertJsonPath('data.plan', 'premium')->assertJsonPath('data.seats.students', 900);

        $licence = Licence::currentFor($school['tenant']->id);
        expect($licence->verifiedPayload())->not->toBeNull()
            ->and($licence->verifiedPayload()['seats']['students'])->toBe(900)
            ->and($licence->licensedModules())->toBe(['attendance']);

        $this->inSchool($school['tenant'], function () {
            $entry = AuditLog::query()->where('action', 'platform.licence.changed')->firstOrFail();
            expect($entry->before['seats']['students'])->toBe(500)
                ->and($entry->after['seats']['students'])->toBe(900)
                ->and($entry->meta['changed'])->toContain('plan', 'modules', 'seats');
        });
    });

    it('revokes a licence only with step-up and the school code typed again', function () {
        $school = $this->activeSchool('STOP-BPL');

        browser($this)->postJson('/api/v1/platform/schools/STOP-BPL/licence/revoke', ['confirm_code' => 'STOP-BPL', 'reason' => 'Contract ended'])
            ->assertForbidden()->assertJsonPath('error.code', 'step_up_required');

        consoleStepUp($this, $this->admin);

        browser($this)->postJson('/api/v1/platform/schools/STOP-BPL/licence/revoke', ['confirm_code' => 'STOP-BP', 'reason' => 'Contract ended'])
            ->assertStatus(422)->assertJsonPath('error.code', 'confirmation_mismatch');

        browser($this)->postJson('/api/v1/platform/schools/STOP-BPL/licence/revoke', ['confirm_code' => 'stop-bpl', 'reason' => 'Contract ended'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        // The school is shut out at once.
        app('auth')->forgetGuards();
        $this->withToken($this->tokenFor($school['chief']))->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('error.code', 'licence_inactive');

        $this->inSchool($school['tenant'], fn () => expect(AuditLog::query()->where('action', 'platform.licence.revoked')->first()->meta['reason'])->toBe('Contract ended'));
    });

    it('records every console action in the platform audit log', function () {
        createSchoolInConsole($this);
        browser($this)->getJson('/api/v1/platform/audit-logs')->assertOk()
            ->assertJsonPath('data.0.action', 'school.created')
            ->assertJsonPath('data.0.actor.type', 'platform_admin');
    });

    it('revokes from the command line too, with the code typed again', function () {
        $this->activeSchool('CLI-BPL');

        expect(Artisan::call('platform:revoke-licence', ['code' => 'CLI-BPL', '--reason' => 'Contract ended', '--confirm' => 'WRONG']))->toBe(1);
        expect(Artisan::call('platform:revoke-licence', ['code' => 'CLI-BPL', '--reason' => 'Contract ended', '--confirm' => 'CLI-BPL']))->toBe(0);
        expect(PlatformAuditLog::query()->where('action', 'licence.revoked')->first()->actor['name'])->toBe('command line');
    });
});

describe('sole-Chief recovery', function () {
    beforeEach(function () {
        Mail::fake();
        $this->admin = consoleAdmin();
        consoleSignIn($this, $this->admin);
        $this->school = $this->activeSchool('LOST-BPL');
    });

    it('needs step-up', function () {
        browser($this)->postJson('/api/v1/platform/schools/LOST-BPL/chief-recovery')
            ->assertForbidden()->assertJsonPath('error.code', 'step_up_required');
    });

    it('is refused when the school has more than one Chief', function () {
        $this->schoolUser($this->school['tenant'], Role::Chief);
        consoleStepUp($this, $this->admin);

        browser($this)->postJson('/api/v1/platform/schools/LOST-BPL/chief-recovery')
            ->assertStatus(409)->assertJsonPath('error.code', 'not_sole_chief');
    });

    it('sends a code to the registered contact, then resets only the Chief\'s MFA with the code and a reason', function () {
        $chief = $this->school['chief'];
        $passwordBefore = $this->inSchool($this->school['tenant'], fn () => User::query()->findOrFail($chief->getKey())->password);
        $this->tokenFor($chief);

        consoleStepUp($this, $this->admin);
        browser($this)->postJson('/api/v1/platform/schools/LOST-BPL/chief-recovery')->assertStatus(202)
            ->assertJsonPath('data.chief.name', $chief->name);

        Mail::assertSent(ChiefRecoveryCodeMail::class, fn ($mail) => $mail->hasTo('office@lost-bpl.test'));
        $sms = app(SmsSender::class)->lastTo($this->school['tenant']->contact['phone']);
        preg_match('/(\d{6})/', (string) $sms, $m);

        browser($this)->postJson('/api/v1/platform/schools/LOST-BPL/chief-recovery/complete', ['code' => $m[1], 'reason' => 'too short'])
            ->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
        browser($this)->postJson('/api/v1/platform/schools/LOST-BPL/chief-recovery/complete', ['code' => '000000', 'reason' => 'Signed letter from the trust received; identity checked by video call'])
            ->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');
        browser($this)->postJson('/api/v1/platform/schools/LOST-BPL/chief-recovery/complete', ['code' => $m[1], 'reason' => 'Signed letter from the trust received; identity checked by video call'])
            ->assertOk()->assertJsonPath('data.mfa_reset', true)->assertJsonMissingPath('data.password');

        $this->inSchool($this->school['tenant'], function () use ($chief, $passwordBefore) {
            $fresh = User::query()->findOrFail($chief->getKey());
            expect($fresh->mfaEnabled())->toBeFalse()
                ->and($fresh->mfaRequired())->toBeTrue()
                ->and($fresh->password)->toBe($passwordBefore)
                ->and(DeviceSession::query()->where('user_id', (string) $chief->getKey())->whereNull('revoked_at')->count())->toBe(0);

            $entry = AuditLog::query()->where('action', 'platform.chief_recovery.mfa_reset')->firstOrFail();
            expect($entry->category)->toBe('security')
                ->and($entry->meta['reason'])->toContain('Signed letter')
                ->and(json_encode($entry->toArray()))->not->toMatch('/\b\d{6}\b(?!\d)/');
        });

        // The Chief signs in with their own password and must enrol again.
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'LOST-BPL', 'login' => $chief->email, 'password' => TestData::PASSWORD])
            ->assertOk()->assertJsonPath('data.mfa_enrolment_required', true);
    });
});
