<?php

use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Models\PlatformAuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\PlatformAdmin;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\Platform\Domain\SchoolStatsCollector;
use App\Modules\Platform\Mail\SecurityAlertMail;
use App\Modules\Platform\Models\PlatformAlert;
use App\Modules\Platform\Models\SchoolPayment;
use App\Modules\Platform\Models\SchoolStats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\Concerns\TestData;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Part C additions: client records and payments, usage and security numbers
| from a scheduled job inside each school (numbers only), alerts on a jump,
| renewals that take effect at once, and still no school data without a grant.
*/

function statsBrowser(object $test): object
{
    app('auth')->forgetGuards();

    $test->flushHeaders();

    return $test->withHeaders(['Origin' => 'http://127.0.0.1:5173', 'Referer' => 'http://127.0.0.1:5173/'])
        ->withCredentials()->withCookie((string) config('session.cookie'), session()->getId());
}

/**
 * A school request as an app with a bearer token: no console cookie or
 * browser headers carried over from the console requests in the same test.
 */
function asSchool(object $test, $user, bool $steppedUp = false): object
{
    $test->flushHeaders();
    (fn () => $this->withCredentials = false)->call($test);

    return $test->actingWithToken($user, $steppedUp);
}

beforeEach(function () {
    Mail::fake();
    $this->admin = PlatformAdmin::create(['name' => 'Ravi Platform', 'email' => 'ravi@campustab.test', 'password' => TestData::PASSWORD]);
    $this->admin->forceFill(['mfa' => ['enabled' => true], 'mfa_secret' => app(Google2FA::class)->generateSecretKey(32)])->save();

    $challenge = statsBrowser($this)->postJson('/api/v1/platform/auth/login', ['email' => 'ravi@campustab.test', 'password' => TestData::PASSWORD])->json('data.challenge');
    $secret = PlatformAdmin::query()->find($this->admin->getKey())->mfa_secret;
    statsBrowser($this)->postJson('/api/v1/platform/auth/mfa/verify', ['challenge' => $challenge, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

    $this->school = $this->activeSchool('STATS-BPL', ['students' => 40, 'staff' => 10]);
    $this->tenant = $this->school['tenant'];
});

it('keeps a client record and a hand-entered payment history, every change in the platform audit log', function () {
    statsBrowser($this)->putJson('/api/v1/platform/schools/STATS-BPL/client', [
        'contact_person' => 'Made Up Trustee', 'phone' => '+919800000555', 'email' => 'trustee@stats-bpl.test', 'address' => '12 Made Up Road, Bhopal',
        'package_name' => 'Standard 500', 'price' => 60000, 'billing_period' => 'yearly', 'paid_until' => '2027-03-31',
    ])->assertOk()->assertJsonPath('data.package_name', 'Standard 500');

    statsBrowser($this)->putJson('/api/v1/platform/schools/STATS-BPL/client', ['price' => 65000])->assertOk()->assertJsonPath('data.price', 65000);

    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/payments', ['amount' => 65000, 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'NEFT-123'])->assertCreated();
    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/payments', ['amount' => -5000, 'paid_on' => now()->toDateString(), 'method' => 'other'])
        ->assertStatus(422)->assertJsonPath('error.code', 'note_required');
    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/payments', ['amount' => -5000, 'paid_on' => now()->toDateString(), 'method' => 'other', 'note' => 'Discount agreed'])->assertCreated();

    $page = statsBrowser($this)->getJson('/api/v1/platform/schools/STATS-BPL')->assertOk()->json('data');
    expect($page['client_record']['contact_person'])->toBe('Made Up Trustee')->and($page['payments'])->toHaveCount(2);

    $updates = PlatformAuditLog::query()->where('action', 'client.updated')->orderBy('at')->get();
    expect($updates)->toHaveCount(2)
        ->and($updates[1]->before['price'])->toBe(60000)->and($updates[1]->after['price'])->toBe(65000)
        ->and(PlatformAuditLog::query()->where('action', 'payment.recorded')->count())->toBe(2);

    expect(fn () => SchoolPayment::query()->first()->forceFill(['amount' => 1])->save())->toThrow(LogicException::class);
});

it('shows usage from the job\'s numbers only, and stores no names', function () {
    $sections = $this->makeClass($this->tenant, '7', ['A']);
    $this->enrol($this->tenant, $sections['A'], 'Aarav Patel');
    $this->staffMember($this->tenant, Role::ClassTeacher);
    $parent = $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false);
    $this->tokenFor($this->school['chief']);

    // Before the job has run there are no numbers: the console never reads the school live.
    statsBrowser($this)->getJson('/api/v1/platform/schools')->assertOk()->assertJsonPath('data.0.usage.students', null);

    expect(Artisan::call('platform:collect-stats', ['code' => 'STATS-BPL']))->toBe(0);

    $row = statsBrowser($this)->getJson('/api/v1/platform/schools')->json('data.0');
    expect($row['usage'])->toMatchArray(['students' => 1, 'student_seats' => 40, 'staff' => 2, 'staff_seats' => 10, 'active_7d' => 1, 'active_30d' => 1])
        ->and($row['usage']['last_activity_at'])->not->toBeNull()
        ->and($row['licence']['modules'])->not->toBeEmpty();

    $stored = json_encode(SchoolStats::query()->first()->toArray());
    expect($stored)->not->toContain('Aarav')->not->toContain($this->school['chief']->email)->not->toContain($parent->name);
});

it('counts security events and alerts platform admins when one jumps, with counts only', function () {
    $this->inSchool($this->tenant, function () {
        $recorder = app(AuditRecorder::class);
        foreach (range(1, 25) as $i) {
            $recorder->record('sign_in.failed', 'security', 'users', 'u'.$i, meta: ['factor' => 'password']);
        }
        $recorder->record('sign_in.locked', 'security', 'users', 'u1');
        $recorder->record('mfa.reset', 'security', 'users', 'u2');
        $recorder->record('chief.added', 'security', 'users', 'u3');
    });

    app(SchoolStatsCollector::class)->collect($this->tenant);

    $page = statsBrowser($this)->getJson('/api/v1/platform/schools/STATS-BPL')->assertOk()->json('data');
    expect($page['security']['24h'])->toMatchArray(['failed_sign_ins' => 26, 'lockouts' => 1, 'mfa_resets' => 1, 'chief_changes' => 1, 'password_resets' => 0])
        ->and($page['alerts'][0]['metric'])->toBe('failed_sign_ins')
        ->and(json_encode($page['security']))->not->toContain('u1');

    Mail::assertSent(SecurityAlertMail::class, fn ($mail) => $mail->hasTo('ravi@campustab.test') && str_contains($mail->alert->message, '26 failed sign ins'));

    // No second alert for the same jump.
    app(SchoolStatsCollector::class)->collect($this->tenant);
    expect(PlatformAlert::query()->count())->toBe(1);

    $id = statsBrowser($this)->getJson('/api/v1/platform/alerts')->assertOk()->json('data.0.id');
    statsBrowser($this)->postJson("/api/v1/platform/alerts/{$id}/acknowledge")->assertOk()->assertJsonPath('data.acknowledged_by', 'Ravi Platform');
    statsBrowser($this)->getJson('/api/v1/platform/alerts')->assertJsonCount(0, 'data');
});

it('shows licence history and support grants on the school page', function () {
    asSchool($this, $this->school['chief'], true)->postJson('/api/v1/support-grants', [
        'agent_email' => 'ravi@campustab.test', 'scope' => ['settings'], 'reason' => 'Help with branding settings', 'ends_at' => now()->addHours(2)->toIso8601String(),
    ])->assertCreated();

    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/licence/renew', ['valid_to' => now()->addYears(2)->toDateString()])->assertOk();
    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/stats/refresh')->assertOk();

    $page = statsBrowser($this)->getJson('/api/v1/platform/schools/STATS-BPL')->json('data');
    expect($page['licence_history'][0]['action'])->toBe('renewed')
        ->and($page['support_grants'][0])->toMatchArray(['agent_email' => 'ravi@campustab.test', 'status' => 'active'])
        ->and($page['support_grants'][0])->not->toHaveKey('reason');
});

it('renews at once: an expired, read-only school can write again, recorded in both audit logs', function () {
    $licence = Licence::currentFor($this->tenant->id);
    app(LicenceIssuer::class)->amend($licence, ['valid_from' => '2024-04-01', 'valid_to' => '2025-03-31']);

    asSchool($this, $this->school['chief'])->postJson('/api/v1/subjects', ['code' => 'ART', 'name' => 'Art'])->assertForbidden()->assertJsonPath('error.code', 'licence_read_only');

    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/licence/renew', ['valid_to' => now()->addYear()->toDateString()])->assertOk()->assertJsonPath('data.state', 'active');
    statsBrowser($this)->postJson('/api/v1/platform/schools/STATS-BPL/licence/renew', ['valid_to' => '2025-01-01'])->assertStatus(422)->assertJsonPath('error.code', 'renewal_not_later');

    asSchool($this, $this->school['chief'])->postJson('/api/v1/subjects', ['code' => 'ART', 'name' => 'Art'])->assertCreated();

    expect(PlatformAuditLog::query()->where('action', 'licence.renewed')->count())->toBe(1);
    $this->inSchool($this->tenant, fn () => expect(AuditLog::query()->where('action', 'platform.licence.renewed')->first()->actor['type'])->toBe('platform_admin'));
});

it('changes seats at once', function () {
    statsBrowser($this)->patchJson('/api/v1/platform/schools/STATS-BPL/licence', ['staff' => 1])->assertOk();

    asSchool($this, $this->school['chief'])->postJson('/api/v1/users', ['name' => 'Made Up Teacher', 'email' => 't@stats-bpl.test', 'roles' => ['class_teacher']])
        ->assertStatus(422)->assertJsonPath('error.code', 'seat_limit_reached')->assertJsonPath('error.details.kind', 'staff');
});

it('still opens no school data without a support grant', function () {
    foreach (['/api/v1/students', '/api/v1/users', '/api/v1/audit-logs', '/api/v1/staff', '/api/v1/config'] as $path) {
        statsBrowser($this)->getJson($path)->assertUnauthorized();
    }
});
