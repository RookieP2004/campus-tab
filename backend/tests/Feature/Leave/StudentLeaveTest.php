<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\People\Domain\ParentLogins;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 5 item 7: a parent applies for their child, the class teacher
| decides, attendance shows Leave and the parent is told. Long leave also
| appears to the Principal.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 09:00', 'Asia/Kolkata'));
    $this->school = $this->activeSchool('KIDS-BPL', ['modules' => ['attendance', 'leave']]);
    $this->tenant = $this->school['tenant'];
    $sections = $this->makeClass($this->tenant, '7', ['B', 'C']);
    $this->section = $sections['B'];
    $this->teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->otherTeacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->makeClassTeacher($this->tenant, $this->section, $this->teacher['staff']);
    $this->makeClassTeacher($this->tenant, $sections['C'], $this->otherTeacher['staff']);

    $this->child = $this->enrol($this->tenant, $this->section, 'Aarav Patel', '+919811100001');
    $this->classmate = $this->enrol($this->tenant, $this->section, 'Diya Shah');
    $guardian = $this->guardianOf($this->tenant, $this->child);
    $this->parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->school['chief'], $guardian));
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lets a parent apply, the class teacher approve, and the register show LV', function () {
    $id = $this->actingWithToken($this->parent)->postJson('/api/v1/leave-requests', [
        'student_id' => (string) $this->child->getKey(), 'from' => '2026-12-03', 'to' => '2026-12-04', 'reason' => 'Grandmother\'s wedding anniversary',
    ])->assertCreated()->assertJsonPath('data.kind', 'student')->assertJsonPath('data.stage', 'class_teacher')->json('data.id');

    // Another class's teacher cannot see it.
    $this->actingWithToken($this->otherTeacher['user'])->getJson("/api/v1/leave-requests/{$id}")->assertNotFound();

    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $day = $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/attendance/sections/'.$this->section->getKey().'/days/2026-12-03')->assertOk();
    $statuses = collect($day->json('data.students'))->pluck('status', 'id');
    expect($statuses[(string) $this->child->getKey()])->toBe('LV')
        ->and($statuses[(string) $this->classmate->getKey()])->toBe('P');

    // The parent is told that the request changed, nothing more.
    $this->actingWithToken($this->parent)->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('data.0.title', 'A leave request has an update');
});

it('changes a register already saved for those days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 09:00', 'Asia/Kolkata'));
    $this->actingWithToken($this->teacher['user'])->putJson('/api/v1/attendance/sections/'.$this->section->getKey().'/days/2026-12-03', ['statuses' => []])->assertOk();

    $id = $this->actingWithToken($this->parent)->postJson('/api/v1/leave-requests', [
        'student_id' => (string) $this->child->getKey(), 'from' => '2026-12-03', 'to' => '2026-12-03', 'reason' => 'Fever since last night',
    ])->json('data.id');
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/leave-requests/{$id}/approve")->assertOk();

    $day = $this->actingWithToken($this->teacher['user'])->getJson('/api/v1/attendance/sections/'.$this->section->getKey().'/days/2026-12-03')->assertOk();
    expect(collect($day->json('data.students'))->firstWhere('id', (string) $this->child->getKey())['status'])->toBe('LV');
});

it('refuses a parent applying for someone else\'s child', function () {
    $this->actingWithToken($this->parent)->postJson('/api/v1/leave-requests', [
        'student_id' => (string) $this->classmate->getKey(), 'from' => '2026-12-03', 'to' => '2026-12-03', 'reason' => 'Trying another child',
    ])->assertNotFound();
});

it('shows long student leave to the Principal as well', function () {
    $this->actingWithToken($this->parent)->postJson('/api/v1/leave-requests', [
        'student_id' => (string) $this->child->getKey(), 'from' => '2026-12-03', 'to' => '2026-12-10', 'reason' => 'Family travel abroad',
    ])->assertCreated()->assertJsonPath('data.principal_sees', true);

    $this->actingWithToken($this->principal['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->assertJsonPath('data.0.flags', ['long_student_leave']);
});

it('lets the class teacher reject with a reason, and not their own child\'s request', function () {
    $id = $this->actingWithToken($this->parent)->postJson('/api/v1/leave-requests', [
        'student_id' => (string) $this->child->getKey(), 'from' => '2026-12-03', 'to' => '2026-12-03', 'reason' => 'Cousin visiting',
    ])->json('data.id');

    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/leave-requests/{$id}/reject")->assertUnprocessable();
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/leave-requests/{$id}/reject", ['remark' => 'Unit test that day'])->assertOk()->assertJsonPath('data.status', 'rejected');

    expect($this->inSchool($this->tenant, fn () => LeaveRequest::query()->findOrFail($id)->status))->toBe('rejected');
});
