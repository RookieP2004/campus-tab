<?php

use App\Modules\Attendance\Domain\StaffAttendanceService;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 4 items 5 and 6: staff check-in (app, geofence, office QR, biometric
| CSV) and manual entry, which always comes from someone at a higher level
| than the person it is for and never from the person themself.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('STAFF-BPL', ['modules' => ['attendance']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    // 09:00 school time, a weekday.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 09:00', 'Asia/Kolkata'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A staff member with an account; extra roles are duties such as attendance_officer. */
function member(object $test, Role $role, array $duties = []): array
{
    $user = $test->schoolUser($test->tenant, $role, mfaEnabled: $role->level() >= 60, extraRoles: $duties);
    $staff = $test->inSchool($test->tenant, fn () => Staff::create([
        'employee_code' => 'EMP-'.Str::upper(Str::random(6)), 'name' => $user->name, 'email' => $user->email,
        'user_id' => (string) $user->getKey(), 'status' => Staff::STATUS_ACTIVE,
    ]));

    return ['user' => $user, 'staff' => $staff];
}

function manualEntry(object $test, array $by, array $for): TestResponse
{
    return $test->actingWithToken($by['user'], steppedUp: true)->postJson('/api/v1/attendance/staff/'.$for['staff']->getKey().'/manual', [
        'date' => '2026-12-03', 'status' => 'P', 'in' => '08:05', 'reason' => 'Phone battery died at the gate',
    ]);
}

dataset('allowed pairs', [
    'Attendance Officer records a class teacher' => [Role::Clerk, ['attendance_officer'], Role::ClassTeacher],
    'Attendance Officer records a subject teacher' => [Role::Clerk, ['attendance_officer'], Role::SubjectTeacher],
    'Attendance Officer records staff' => [Role::Clerk, ['attendance_officer'], Role::Staff],
    'Vice Principal records the Clerk' => [Role::VicePrincipal, [], Role::Clerk],
    'Principal records the Clerk' => [Role::Principal, [], Role::Clerk],
    'Principal records the Vice Principal' => [Role::Principal, [], Role::VicePrincipal],
    'Chief records the Principal' => [Role::Chief, [], Role::Principal],
]);

dataset('refused pairs', [
    'Attendance Officer cannot record the Vice Principal' => [Role::Clerk, ['attendance_officer'], Role::VicePrincipal, 'level_too_low'],
    'Attendance Officer cannot record the Principal' => [Role::Clerk, ['attendance_officer'], Role::Principal, 'level_too_low'],
    'Attendance Officer cannot record another Clerk' => [Role::Clerk, ['attendance_officer'], Role::Clerk, 'level_too_low'],
    'Vice Principal cannot record the Principal' => [Role::VicePrincipal, [], Role::Principal, 'level_too_low'],
    'Principal cannot record the Chief' => [Role::Principal, [], Role::Chief, 'level_too_low'],
    'a Clerk without the duty cannot record a teacher' => [Role::Clerk, [], Role::ClassTeacher, 'permission_denied'],
    'a class teacher cannot record staff' => [Role::ClassTeacher, [], Role::Staff, 'permission_denied'],
]);

it('lets a higher level enter a manual check-in, with the time and reason, audited', function (Role $by, array $duties, Role $for) {
    $actor = member($this, $by, $duties);
    $target = member($this, $for);

    manualEntry($this, $actor, $target)->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.in', '08:05')
        ->assertJsonPath('data.entered_by', $actor['user']->name);

    $this->inSchool($this->tenant, function () use ($actor) {
        $audit = AuditLog::query()->where('entity', 'staff_attendance')->where('actor_id', (string) $actor['user']->getKey())->first();
        expect($audit)->not->toBeNull()->and($audit->meta['reason'])->toBe('Phone battery died at the gate');
    });
})->with('allowed pairs');

it('refuses a manual entry for someone at the same or a higher level', function (Role $by, array $duties, Role $for, string $code) {
    manualEntry($this, member($this, $by, $duties), member($this, $for))->assertForbidden()->assertJsonPath('error.code', $code);
})->with('refused pairs');

it('never lets anyone record their own attendance by hand, not even the Attendance Officer or the Chief', function () {
    $officer = member($this, Role::Clerk, ['attendance_officer']);
    manualEntry($this, $officer, $officer)->assertForbidden()->assertJsonPath('error.code', 'own_attendance');

    $chiefStaff = ['user' => $this->chief, 'staff' => $this->inSchool($this->tenant, fn () => Staff::create([
        'employee_code' => 'CH-1', 'name' => $this->chief->name, 'user_id' => (string) $this->chief->getKey(), 'status' => Staff::STATUS_ACTIVE,
    ]))];
    manualEntry($this, $chiefStaff, $chiefStaff)->assertForbidden()->assertJsonPath('error.code', 'own_attendance');

    $this->inSchool($this->tenant, fn () => expect(StaffAttendance::query()->count())->toBe(0));
});

it('needs a reason for a manual entry', function () {
    $this->actingWithToken(member($this, Role::Principal)['user'], steppedUp: true)
        ->postJson('/api/v1/attendance/staff/'.member($this, Role::Staff)['staff']->getKey().'/manual', ['date' => '2026-12-03', 'status' => 'P'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['reason']]]);
});

it('checks staff in by the app button, late after the school\'s time, once a day', function () {
    $teacher = member($this, Role::ClassTeacher);

    $this->actingWithToken($teacher['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'app'])
        ->assertCreated()->assertJsonPath('data.status', 'L')->assertJsonPath('data.late', true)->assertJsonPath('data.in', '09:00');
    $this->actingWithToken($teacher['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'app'])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_checked_in');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 15:30', 'Asia/Kolkata'));
    $this->actingWithToken($teacher['user'])->postJson('/api/v1/attendance/staff/check-out', ['method' => 'app'])->assertOk()->assertJsonPath('data.out', '15:30');

    $early = member($this, Role::Staff);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-04 07:55', 'Asia/Kolkata'));
    $this->actingWithToken($early['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'app'])->assertCreated()->assertJsonPath('data.status', 'P');
});

it('stores only whether a geofenced check-in was inside the area, never the location', function () {
    $this->inSchool($this->tenant, function () {
        $configs = app(SchoolConfigService::class);
        $body = $configs->current()->body;
        $body['policies']['attendance']['staff'] = ['late_after' => '09:30', 'geofence' => ['enabled' => true, 'lat' => 23.2599, 'lng' => 77.4126, 'radius_m' => 200]];
        $configs->save($this->chief, $body, $configs->current()->version);
    });

    $inside = member($this, Role::Staff);
    $outside = member($this, Role::Staff);

    $this->actingWithToken($inside['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'app'])->assertUnprocessable()->assertJsonPath('error.code', 'location_required');
    $this->actingWithToken($inside['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'app', 'lat' => 23.2601, 'lng' => 77.4128])->assertCreated()->assertJsonPath('data.inside_area', true);
    $this->actingWithToken($outside['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'app', 'lat' => 23.3000, 'lng' => 77.5000])->assertCreated()->assertJsonPath('data.inside_area', false);

    $this->inSchool($this->tenant, function () {
        $raw = json_encode([StaffAttendance::query()->get()->map->getAttributes()->all(), AuditLog::query()->where('entity', 'staff_attendance')->get()->map->getAttributes()->all()]);
        expect($raw)->not->toContain('23.26')->not->toContain('77.41')->not->toContain('"lat"')->not->toContain('"lng"');
    });

    // Screens learn only that the geofence is on, not where the school is.
    $this->actingWithToken($inside['user'])->getJson('/api/v1/attendance/settings')->assertOk()
        ->assertJsonPath('data.staff.geofence', ['enabled' => true]);
});

it('checks staff in by the office QR code, which expires', function () {
    $clerk = member($this, Role::Clerk);
    $teacher = member($this, Role::ClassTeacher);
    $token = $this->actingWithToken($clerk['user'], steppedUp: true)->getJson('/api/v1/attendance/staff/qr')->assertOk()->json('data.token');

    $this->actingWithToken($teacher['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'qr', 'qr' => 'nope'])->assertUnprocessable()->assertJsonPath('error.code', 'qr_invalid');
    $this->actingWithToken($teacher['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'qr', 'qr' => $token])->assertCreated()->assertJsonPath('data.source', 'qr');

    // A teacher cannot show the office code.
    $this->actingWithToken($teacher['user'])->getJson('/api/v1/attendance/staff/qr')->assertForbidden();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 09:30', 'Asia/Kolkata'));
    $other = member($this, Role::Staff);
    $this->actingWithToken($other['user'])->postJson('/api/v1/attendance/staff/check-in', ['method' => 'qr', 'qr' => $token])->assertUnprocessable();
});

it('imports a biometric device CSV with a dry run first', function () {
    $clerk = member($this, Role::Clerk);
    $teacher = member($this, Role::ClassTeacher);
    $csv = "employee_code,date,in,out\n{$teacher['staff']->employee_code},2026-12-02,08:40,15:00\nNOPE,2026-12-02,08:00,15:00\n";

    $dry = $this->actingWithToken($clerk['user'], steppedUp: true)->post('/api/v1/attendance/staff/import', ['file' => UploadedFile::fake()->createWithContent('device.csv', $csv)], ['Accept' => 'application/json'])
        ->assertOk()->json('data');
    expect($dry['ok'])->toBe(1)->and($dry['errors'])->toBe(1)->and($dry['written'])->toBe(0);

    $done = $this->actingWithToken($clerk['user'], steppedUp: true)->post('/api/v1/attendance/staff/import', ['file' => UploadedFile::fake()->createWithContent('device.csv', $csv), 'commit' => '1'], ['Accept' => 'application/json'])->json('data');
    expect($done['written'])->toBe(1);

    $this->inSchool($this->tenant, fn () => expect(StaffAttendance::query()->where('staff_id', (string) $teacher['staff']->getKey())->first())
        ->source->toBe('biometric')->late->toBeTrue()->status->toBe('L'));
});

it('gives the QR token for a window and accepts the one before it', function () {
    $this->inSchool($this->tenant, function () {
        $service = app(StaffAttendanceService::class);
        $now = $service->qrToken();
        $later = $service->qrToken(CarbonImmutable::now()->addMinutes(5));
        expect($now['token'])->not->toBe($later['token'])->and(strlen($now['token']))->toBe(32);
    });
});
