<?php

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Domain\Role;
use App\Modules\Leave\Domain\LeaveBalances;
use App\Modules\Leave\Http\Controllers\LeaveSetupController;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use MongoDB\Client;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 5: staff leave. Every legal move, refusal of illegal ones, the
| owner's routing and privacy rules, the ledger, attendance and cover.
| Dates: Tuesday 1 December 2026, 09:00 school time. 3-4 Dec is Thu-Fri,
| 6 Dec is a Sunday.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 09:00', 'Asia/Kolkata'));
    $this->school = $this->activeSchool('LEAVE-BPL', ['modules' => ['attendance', 'leave']]);
    $this->tenant = $this->school['tenant'];
    $this->currentYear($this->tenant);
    $this->inSchool($this->tenant, fn () => LeaveSetupController::addStarterTypes());

    $this->clerk = $this->staffMember($this->tenant, Role::Clerk);
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->teacher = $this->staffMember($this->tenant, Role::ClassTeacher, ['designation' => 'Teacher, Mathematics']);
    $this->other = $this->staffMember($this->tenant, Role::SubjectTeacher);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function applyLeave(object $test, array $who, array $input = []): TestResponse
{
    return $test->actingWithToken($who['user'])->postJson('/api/v1/leave-requests', array_merge([
        'type_code' => 'CL', 'from' => '2026-12-03', 'to' => '2026-12-04', 'reason' => 'Family wedding in Indore',
    ], $input));
}

function leaveMove(object $test, array $who, string $id, string $move, array $body = []): TestResponse
{
    return $test->actingWithToken($who['user'])->postJson("/api/v1/leave-requests/{$id}/{$move}", $body);
}

function balanceOf(object $test, array $who, string $code = 'CL'): array
{
    return $test->inSchool($test->tenant, function () use ($who, $code) {
        $type = LeaveType::query()->where('code', $code)->firstOrFail();

        return app(LeaveBalances::class)->of((string) $who['staff']->getKey(), $type, (string) AcademicYear::current()->getKey());
    });
}

function leaveRecord(object $test, string $id): LeaveRequest
{
    return $test->inSchool($test->tenant, fn () => LeaveRequest::query()->findOrFail($id));
}

it('runs a teacher\'s leave through Clerk and Principal, reserving then deducting the days', function () {
    $id = applyLeave($this, $this->teacher)->assertCreated()
        ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.stage', 'clerk')
        ->assertJsonPath('data.days', 2)->assertJsonPath('data.chain', ['clerk', 'principal'])
        ->json('data.id');

    expect(balanceOf($this, $this->teacher))->toMatchArray(['reserved' => 2.0, 'taken' => 0.0, 'available' => 10.0]);

    leaveMove($this, $this->clerk, $id, 'verify', ['remark' => 'Balance OK, no exams'])->assertOk()
        ->assertJsonPath('data.status', 'verified')->assertJsonPath('data.stage', 'principal');

    leaveMove($this, $this->principal, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');

    expect(balanceOf($this, $this->teacher))->toMatchArray(['reserved' => 0.0, 'taken' => 2.0, 'available' => 10.0]);

    // The applicant's timeline shows every step with names and remarks.
    $this->actingWithToken($this->teacher['user'])->getJson("/api/v1/leave-requests/{$id}")->assertOk()
        ->assertJsonPath('data.timeline.1.action', 'verified')
        ->assertJsonPath('data.timeline.1.by', $this->clerk['user']->name)
        ->assertJsonPath('data.timeline.1.remark', 'Balance OK, no exams')
        ->assertJsonPath('data.timeline.2.action', 'approved');
});

it('marks approved days On leave as system entries and builds the needs-cover list', function () {
    $sections = $this->makeClass($this->tenant, '7', ['B']);
    $maths = $this->makeSubject($this->tenant, 'MATH', 'Mathematics');
    $this->makeClassTeacher($this->tenant, $sections['B'], $this->teacher['staff']);
    $this->assignTeacher($this->tenant, $sections['B'], $maths, $this->teacher['staff']);

    $id = applyLeave($this, $this->teacher)->json('data.id');
    leaveMove($this, $this->clerk, $id, 'verify')->assertOk();
    $approved = leaveMove($this, $this->principal, $id, 'approve')->assertOk();

    $rows = $this->inSchool($this->tenant, fn () => StaffAttendance::query()->where('staff_id', (string) $this->teacher['staff']->getKey())->orderBy('date')->get());
    expect($rows->pluck('date')->all())->toBe(['2026-12-03', '2026-12-04'])
        ->and($rows->pluck('status')->unique()->all())->toBe(['LV'])
        ->and($rows->pluck('source')->unique()->all())->toBe(['leave'])
        ->and($rows->first()->entered_by)->toBeNull();

    expect($approved->json('data.cover_needed'))->toHaveCount(4)
        ->and(collect($approved->json('data.cover_needed'))->pluck('kind')->unique()->sort()->values()->all())->toBe(['lesson', 'register']);

    $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/leave/cover?from=2026-12-03&to=2026-12-04')->assertOk()
        ->assertJsonCount(4, 'data')->assertJsonPath('data.0.teacher', $this->teacher['user']->name);
});

it('lets the Clerk return a request and the applicant fix and resubmit it', function () {
    $id = applyLeave($this, $this->teacher)->json('data.id');

    leaveMove($this, $this->clerk, $id, 'return', ['remark' => 'Wrong dates: 4 Dec is the exam day'])->assertOk()->assertJsonPath('data.status', 'returned');

    $this->actingWithToken($this->teacher['user'])->patchJson("/api/v1/leave-requests/{$id}", ['to' => '2026-12-03'])->assertOk()->assertJsonPath('data.days', 1);
    leaveMove($this, $this->teacher, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.stage', 'clerk');

    expect(balanceOf($this, $this->teacher))->toMatchArray(['reserved' => 1.0, 'available' => 11.0]);
});

it('requires a reason to reject and releases the reserved days', function () {
    $id = applyLeave($this, $this->teacher)->json('data.id');
    leaveMove($this, $this->clerk, $id, 'verify')->assertOk();

    leaveMove($this, $this->principal, $id, 'reject')->assertUnprocessable();
    leaveMove($this, $this->principal, $id, 'reject', ['remark' => 'Annual day rehearsal'])->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(balanceOf($this, $this->teacher))->toMatchArray(['reserved' => 0.0, 'taken' => 0.0, 'available' => 12.0]);
});

it('lets the applicant withdraw before a decision, releasing the days', function () {
    $id = applyLeave($this, $this->teacher)->json('data.id');
    leaveMove($this, $this->other, $id, 'withdraw')->assertNotFound();
    leaveMove($this, $this->teacher, $id, 'withdraw')->assertOk()->assertJsonPath('data.status', 'withdrawn');

    expect(balanceOf($this, $this->teacher)['reserved'])->toBe(0.0);
});

it('saves a draft that holds no days until it is sent', function () {
    $id = applyLeave($this, $this->teacher, ['submit' => false])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    expect(balanceOf($this, $this->teacher)['reserved'])->toBe(0.0);

    leaveMove($this, $this->teacher, $id, 'submit')->assertOk()->assertJsonPath('data.status', 'submitted');
});

it('approves with changed dates and works the days out again', function () {
    $id = applyLeave($this, $this->teacher)->json('data.id');
    leaveMove($this, $this->clerk, $id, 'verify')->assertOk();
    leaveMove($this, $this->principal, $id, 'approve', ['from' => '2026-12-03', 'to' => '2026-12-03', 'remark' => 'Only Thursday'])->assertOk()
        ->assertJsonPath('data.approved.days', 1)->assertJsonPath('data.timeline.2.action', 'approved_changed');

    expect(balanceOf($this, $this->teacher))->toMatchArray(['reserved' => 0.0, 'taken' => 1.0, 'available' => 11.0]);
});

dataset('illegal moves', [
    'verify a draft' => ['draft', 'clerk', 'verify', 409],
    'approve a draft' => ['draft', 'principal', 'approve', 409],
    'a teacher approves (no decide permission)' => ['submitted', 'other', 'approve', 403],
    'the Chief approves a teacher leave (not in its chain)' => ['submitted', 'chief', 'approve', 404],
    'submit an approved request' => ['approved', 'teacher', 'submit', 409],
    'withdraw an approved request' => ['approved', 'teacher', 'withdraw', 409],
    'verify an approved request' => ['approved', 'clerk', 'verify', 409],
    'approve a rejected request' => ['rejected', 'principal', 'approve', 409],
    'reject a withdrawn request' => ['withdrawn', 'principal', 'reject', 409],
    'resubmit a cancelled request' => ['cancelled', 'teacher', 'submit', 409],
    'verify a returned request' => ['returned', 'clerk', 'verify', 409],
]);

it('refuses moves that are not legal from the current state', function (string $state, string $who, string $move, int $status) {
    $actors = ['clerk' => $this->clerk, 'principal' => $this->principal, 'teacher' => $this->teacher, 'other' => $this->other,
        'chief' => ['user' => $this->school['chief']]];
    $id = applyLeave($this, $this->teacher, ['submit' => $state !== 'draft', 'from' => '2026-12-08', 'to' => '2026-12-09'])->json('data.id');

    match ($state) {
        'approved', 'rejected', 'cancelled' => leaveMove($this, $this->clerk, $id, 'verify')->assertOk(),
        default => null,
    };
    match ($state) {
        'approved', 'cancelled' => leaveMove($this, $this->principal, $id, 'approve')->assertOk(),
        'rejected' => leaveMove($this, $this->principal, $id, 'reject', ['remark' => 'No cover available'])->assertOk(),
        'withdrawn' => leaveMove($this, $this->teacher, $id, 'withdraw')->assertOk(),
        'returned' => leaveMove($this, $this->clerk, $id, 'return', ['remark' => 'Attach the invitation'])->assertOk(),
        default => null,
    };
    if ($state === 'cancelled') {
        leaveMove($this, $this->principal, $id, 'cancel', ['remark' => 'Called off by the school'])->assertOk();
    }

    leaveMove($this, $actors[$who], $id, $move, ['remark' => 'Trying anyway'])->assertStatus($status);
})->with('illegal moves');

it('keeps the Clerk from rejecting unless the Chief switches it on', function () {
    $id = applyLeave($this, $this->teacher)->json('data.id');
    leaveMove($this, $this->clerk, $id, 'reject', ['remark' => 'Not enough cover'])->assertForbidden()->assertJsonPath('error.code', 'clerk_cannot_reject');

    // The Principal may not switch it on: it is the Chief's call.
    $body = $this->configBody($this->tenant);
    $body['policies']['leave']['clerk_can_reject'] = true;
    $version = $this->inSchool($this->tenant, fn () => app(SchoolConfigService::class)->current()->version);
    $this->actingWithToken($this->principal['user'])->withHeader('If-Match', '"v'.$version.'"')
        ->putJson('/api/v1/config', ['body' => $body, 'reason' => 'Let the office reject'])->assertForbidden();
    $this->inSchool($this->tenant, fn () => app(SchoolConfigService::class)->save($this->school['chief'], $body, $version, 'Clerk may reject'));

    leaveMove($this, $this->clerk, $id, 'reject', ['remark' => 'Not enough cover'])->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('lets the Principal fast-track at the Clerk stage and tells the Clerk', function () {
    $id = applyLeave($this, $this->teacher, ['from' => '2026-12-01', 'to' => '2026-12-01', 'reason' => 'Child taken ill this morning'])->json('data.id');

    leaveMove($this, $this->principal, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.fast_tracked', true)
        ->assertJsonPath('data.timeline.1.action', 'fast_tracked');

    $clerkTold = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $this->clerk['user']->getKey())->where('type', 'leave.updated')->count());
    expect($clerkTold)->toBe(1);
});

dataset('own-leave routing', [
    'a Clerk\'s leave goes straight to the Principal' => [Role::Clerk, ['principal'], 'principal'],
    'a Vice Principal\'s leave goes to the Principal' => [Role::VicePrincipal, ['principal'], 'principal'],
    'the Principal\'s leave goes to the Chief' => [Role::Principal, ['chief'], 'chief'],
    'the Chief\'s leave goes to the Principal' => [Role::Chief, ['principal'], 'principal'],
]);

it('routes own leave by level and lets only that decider approve', function (Role $role, array $chain, string $decider) {
    $applicant = $role === Role::Principal ? $this->principal : ($role === Role::Clerk ? $this->clerk : $this->staffMember($this->tenant, $role));
    $chief = $this->staffMember($this->tenant, Role::Chief);
    if ($role === Role::Chief) {
        $applicant = $chief;
    }

    $id = applyLeave($this, $applicant)->assertCreated()->assertJsonPath('data.chain', $chain)->assertJsonPath('data.stage', $decider)->json('data.id');

    // Nobody decides their own leave.
    leaveMove($this, $applicant, $id, 'approve')->assertForbidden();

    $deciderUser = $decider === 'chief' ? $chief : $this->principal;
    leaveMove($this, $deciderUser, $id, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');
})->with('own-leave routing');

it('never lets the Principal approve the Principal\'s own leave, nor the Clerk verify their own', function () {
    $id = applyLeave($this, $this->principal)->json('data.id');
    leaveMove($this, $this->principal, $id, 'approve')->assertForbidden()->assertJsonPath('error.code', 'own_leave');

    $own = applyLeave($this, $this->clerk)->json('data.id');
    // It went straight to the Principal: there is no Clerk check to do.
    leaveMove($this, $this->clerk, $own, 'verify')->assertStatus(409);
    leaveMove($this, $this->clerk, $own, 'approve')->assertForbidden();
});

it('lets a delegated Vice Principal decide only for people below their level, and only on the dates', function () {
    $vp = $this->staffMember($this->tenant, Role::VicePrincipal);
    $otherVp = $this->staffMember($this->tenant, Role::VicePrincipal);

    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/leave/delegations', [
        'delegate_user_id' => (string) $vp['user']->getKey(), 'from' => '2026-12-01', 'to' => '2026-12-05',
    ])->assertCreated()->assertJsonPath('data.active', true);

    $teacherLeave = applyLeave($this, $this->teacher)->json('data.id');
    leaveMove($this, $this->clerk, $teacherLeave, 'verify')->assertOk();
    leaveMove($this, $vp, $teacherLeave, 'approve')->assertOk()->assertJsonPath('data.status', 'approved');

    // Another Vice Principal (same level) and the Clerk's own leave go to the Principal: the delegate cannot decide them.
    $vpLeave = applyLeave($this, $otherVp)->json('data.id');
    leaveMove($this, $vp, $vpLeave, 'approve')->assertNotFound();

    // After the dates the delegation has ended on its own.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-07 09:00', 'Asia/Kolkata'));
    $later = applyLeave($this, $this->other, ['from' => '2026-12-10', 'to' => '2026-12-10'])->json('data.id');
    leaveMove($this, $this->clerk, $later, 'verify')->assertOk();
    leaveMove($this, $vp, $later, 'approve')->assertNotFound();
});

it('counts Sundays and holidays inside the leave only for types with the sandwich rule', function () {
    // Fri 4 to Mon 7 December: Saturday 5 is a working day here, Sunday 6 is off.
    $casual = applyLeave($this, $this->teacher, ['from' => '2026-12-04', 'to' => '2026-12-07'])->json('data.days');
    $earned = applyLeave($this, $this->other, ['type_code' => 'EL', 'from' => '2026-12-04', 'to' => '2026-12-07'])->json('data.days');

    expect($casual)->toEqual(3)->and($earned)->toEqual(4);

    // A Sunday at the edge never counts, sandwich or not.
    $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/leave-requests/preview?type_code=EL&from=2026-12-06&to=2026-12-07')->assertOk()->assertJsonPath('data.days', 1);
});

it('refuses leave beyond the balance', function () {
    $this->inSchool($this->tenant, fn () => LeaveType::query()->where('code', 'CL')->firstOrFail()->fill(['quota' => 2])->save());
    applyLeave($this, $this->teacher, ['from' => '2026-12-03', 'to' => '2026-12-04'])->assertCreated();
    applyLeave($this, $this->teacher, ['from' => '2026-12-08', 'to' => '2026-12-08'])->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_balance');
});

it('keeps balances right when a second transaction commits on the same balance mid-way', function () {
    $this->inSchool($this->tenant, fn () => LeaveType::query()->where('code', 'CL')->firstOrFail()->fill(['quota' => 3])->save());
    $first = applyLeave($this, $this->teacher, ['from' => '2026-12-03', 'to' => '2026-12-03'])->json('data.id');
    $second = applyLeave($this, $this->teacher, ['from' => '2026-12-08', 'to' => '2026-12-09', 'submit' => false])->json('data.id');

    // While the second request's submit runs, another approver's transaction
    // commits on the same balance: it takes two more days.
    $raw = new Client(config('database.connections.mongodb.dsn'));
    $db = $raw->selectDatabase(config('database.connections.mongodb.database'));
    $staffId = (string) $this->teacher['staff']->getKey();
    $tenantId = $this->tenant->id;
    $year = $this->inSchool($this->tenant, fn () => (string) AcademicYear::current()->getKey());
    $fired = false;

    LeaveRequest::retrieved(function (LeaveRequest $r) use (&$fired, $db, $staffId, $tenantId, $year, $second): void {
        if ($fired || (string) $r->getKey() !== $second) {
            return;
        }
        $fired = true;
        $db->selectCollection('leave_ledger')->insertOne(['tenant_id' => $tenantId, 'staff_id' => $staffId, 'year_id' => $year, 'type_code' => 'CL', 'kind' => 'reserve', 'days' => 2.0, 'request_id' => 'elsewhere']);
        $db->selectCollection('leave_balance_locks')->updateOne(['tenant_id' => $tenantId, 'staff_id' => $staffId, 'year_id' => $year, 'type_code' => 'CL'], ['$inc' => ['version' => 1]]);
    });

    // The submit's transaction collides, runs again on the new balance (1 + 2 of 3 used) and refuses.
    leaveMove($this, $this->teacher, $second, 'submit')->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_balance');
    expect($fired)->toBeTrue()
        ->and(balanceOf($this, $this->teacher))->toMatchArray(['reserved' => 3.0, 'available' => 0.0])
        ->and(leaveRecord($this, $second)->status)->toBe('draft');

    // Approving the first request twice at once can never deduct twice.
    leaveMove($this, $this->clerk, $first, 'verify')->assertOk();
    leaveMove($this, $this->principal, $first, 'approve')->assertOk();
    leaveMove($this, $this->principal, $first, 'approve')->assertStatus(409);
    $deducts = $this->inSchool($this->tenant, fn () => LeaveLedgerEntry::query()->where('request_id', $first)->where('kind', 'deduct')->count());
    expect($deducts)->toBe(1);
});

it('cancels an approved leave on request, restoring the untaken days and their attendance together', function () {
    $id = applyLeave($this, $this->teacher, ['from' => '2026-11-30', 'to' => '2026-12-03', 'reason' => 'Travelling home'])->json('data.id');
    leaveMove($this, $this->clerk, $id, 'verify')->assertOk();
    leaveMove($this, $this->principal, $id, 'approve')->assertOk();
    expect(balanceOf($this, $this->teacher)['taken'])->toBe(4.0);

    leaveMove($this, $this->teacher, $id, 'cancel-request', ['reason' => 'Trip called off'])->assertOk()->assertJsonPath('data.cancel_requested', true);
    leaveMove($this, $this->clerk, $id, 'cancel')->assertForbidden();
    leaveMove($this, $this->principal, $id, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.approved.restored_days', 2);

    // 30 Nov and 1 Dec (school already started) were taken; 2 and 3 Dec come back.
    expect(balanceOf($this, $this->teacher))->toMatchArray(['taken' => 2.0, 'available' => 10.0]);
    $dates = $this->inSchool($this->tenant, fn () => StaffAttendance::query()->where('staff_id', (string) $this->teacher['staff']->getKey())->orderBy('date')->pluck('date')->all());
    expect($dates)->toBe(['2026-11-30', '2026-12-01']);
});

it('keeps the reason, remarks and documents to the applicant and the chain', function () {
    $id = applyLeave($this, $this->teacher, ['type_code' => 'SL', 'from' => '2026-12-03', 'to' => '2026-12-03', 'reason' => 'Dental surgery follow-up'])->json('data.id');
    leaveMove($this, $this->clerk, $id, 'verify', ['remark' => 'Certificate seen'])->assertOk();

    // Another teacher: the request does not exist for them.
    $this->actingWithToken($this->other['user'])->getJson("/api/v1/leave-requests/{$id}")->assertNotFound();
    // The Principal (in the chain) sees the reason.
    $this->actingWithToken($this->principal['user'])->getJson("/api/v1/leave-requests/{$id}")->assertOk()->assertJsonPath('data.reason', 'Dental surgery follow-up');

    // Stored encrypted; the audit log never holds it.
    $raw = $this->inSchool($this->tenant, fn () => LeaveRequest::query()->findOrFail($id)->getAttributes()['reason']);
    expect(json_encode($raw))->not->toContain('Dental');
    $logs = $this->inSchool($this->tenant, fn () => AuditLog::query()->where('entity_id', $id)->get());
    expect($logs)->not->toBeEmpty()->and(json_encode($logs->toArray()))->not->toContain('Dental')->not->toContain('Certificate seen');

    leaveMove($this, $this->principal, $id, 'approve')->assertOk();

    // The calendar shows who is away, never the type or reason.
    $calendar = $this->actingWithToken($this->other['user'])->getJson('/api/v1/leave/calendar?from=2026-12-01&to=2026-12-31')->assertOk();
    expect($calendar->json('data.0'))->toMatchArray(['name' => $this->teacher['user']->name, 'request_id' => null])
        ->and(json_encode($calendar->json()))->not->toContain('Sick')->not->toContain('SL')->not->toContain('Dental');

    // Notifications say only that the request changed.
    $texts = $this->inSchool($this->tenant, fn () => Notification::query()->whereIn('type', ['leave.updated', 'leave.waiting'])->get(['title', 'body']));
    expect($texts)->not->toBeEmpty()->and(json_encode($texts->toArray()))->not->toContain('Dental')->not->toContain('Sick')->not->toContain($this->teacher['user']->name);
});

it('stores documents encrypted, requires one above the type\'s limit, and serves them through a signed link', function () {
    $id = applyLeave($this, $this->teacher, ['type_code' => 'SL', 'from' => '2026-12-03', 'to' => '2026-12-08', 'reason' => 'Fever', 'submit' => false])->json('data.id');
    leaveMove($this, $this->teacher, $id, 'submit')->assertUnprocessable()->assertJsonPath('error.code', 'document_required');

    $file = UploadedFile::fake()->createWithContent('certificate.pdf', '%PDF-1.4 medical certificate for the teacher');
    $doc = $this->actingWithToken($this->teacher['user'])->post("/api/v1/leave-requests/{$id}/documents", ['file' => $file])->assertCreated()->json('data.documents.0.id');

    $stored = $this->inSchool($this->tenant, fn () => LeaveRequest::query()->findOrFail($id)->documents[0]['path']);
    expect(Storage::disk('local')->get($stored))->not->toContain('medical certificate');

    leaveMove($this, $this->teacher, $id, 'submit')->assertOk();

    $this->actingWithToken($this->other['user'])->postJson("/api/v1/leave-requests/{$id}/documents/{$doc}/link")->assertNotFound();
    $url = $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/leave-requests/{$id}/documents/{$doc}/link")->assertOk()->json('data.url');
    expect($this->get($url)->assertOk()->getContent())->toContain('medical certificate');
});

it('reminds approvers after 24 hours and escalates to the level above after 48', function () {
    applyLeave($this, $this->teacher)->assertCreated();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-02 10:00', 'Asia/Kolkata'));
    $this->artisan('leave:remind')->assertSuccessful();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 10:00', 'Asia/Kolkata'));
    $this->artisan('leave:remind')->assertSuccessful();
    $this->artisan('leave:remind')->assertSuccessful(); // once per stage

    $count = fn (array $who) => $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $who['user']->getKey())->where('type', 'leave.waiting')->count());
    expect($count($this->clerk))->toBe(2) // on submit, then the reminder
        ->and($count($this->principal))->toBe(1); // the escalation
});

it('answers a repeated request with the same Idempotency-Key once', function () {
    $first = $this->actingWithToken($this->teacher['user'])->withHeader('Idempotency-Key', 'apply-123')->postJson('/api/v1/leave-requests', [
        'type_code' => 'CL', 'from' => '2026-12-03', 'to' => '2026-12-03', 'reason' => 'Bank work',
    ])->assertCreated();
    $again = $this->withHeader('Idempotency-Key', 'apply-123')->postJson('/api/v1/leave-requests', [
        'type_code' => 'CL', 'from' => '2026-12-03', 'to' => '2026-12-03', 'reason' => 'Bank work',
    ])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($this->inSchool($this->tenant, fn () => LeaveRequest::query()->count()))->toBe(1);
});

it('shows waiting leave in the shared approvals inbox of whoever acts next', function () {
    applyLeave($this, $this->teacher)->assertCreated();

    $this->actingWithToken($this->clerk['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->assertJsonPath('counts.leave', 1)->assertJsonPath('data.0.kind', 'leave');
    // The Principal can fast-track it, so it is in their inbox too, flagged.
    $this->actingWithToken($this->principal['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->assertJsonPath('data.0.flags.0', 'fast_track');
    $this->actingWithToken($this->other['user'])->getJson('/api/v1/approvals/inbox')->assertForbidden();
    // A class teacher may open the inbox (student leave) but sees no staff leave there.
    $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->assertJsonPath('total', 0);
});
