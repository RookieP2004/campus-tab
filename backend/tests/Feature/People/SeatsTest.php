<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\People\Domain\ParentLogins;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| The two licence limits: staff means every account except parents and
| students; students means active students; parents never count.
*/

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    // The Chief uses one of the three staff seats.
    $this->school = $this->activeSchool('SEATS-BPL', ['students' => 2, 'staff' => 3]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->sections = $this->makeClass($this->tenant, '7', ['A']);
});

function staffCount(object $test): array
{
    return $test->inSchool($test->tenant, fn () => app(Seats::class)->status('staff'));
}

it('counts every account except parents and students as staff', function () {
    $this->schoolUser($this->tenant, Role::ClassTeacher);
    $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false);
    $this->schoolUser($this->tenant, Role::Student, mfaEnabled: false);
    $this->schoolUser($this->tenant, Role::SubjectTeacher, extraRoles: ['parent']);
    $inactive = $this->schoolUser($this->tenant, Role::Staff);
    $this->inSchool($this->tenant, fn () => User::query()->findOrFail($inactive->getKey())->forceFill(['status' => 'inactive'])->save());

    expect(staffCount($this))->toMatchArray(['limit' => 3, 'used' => 3, 'available' => 0, 'warning' => true]);
});

it('refuses a new staff account past the staff seats, with a clear message', function () {
    $this->actingWithToken($this->chief)->postJson('/api/v1/users', ['name' => 'Made Up One', 'email' => 'one@seats-bpl.test', 'roles' => ['class_teacher']])->assertCreated();
    $this->actingWithToken($this->chief)->postJson('/api/v1/users', ['name' => 'Made Up Two', 'email' => 'two@seats-bpl.test', 'roles' => ['staff']])->assertCreated();

    $this->actingWithToken($this->chief)->postJson('/api/v1/users', ['name' => 'Made Up Three', 'email' => 'three@seats-bpl.test', 'roles' => ['staff']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'seat_limit_reached')
        ->assertJsonPath('error.message', 'The licence allows 3 staff accounts and 3 are in use, so 0 more can be added, not 1. Ask the Chief Admin to contact Campus TAB to add seats.');

    // Staff created from the staff screen are checked the same way.
    $this->actingWithToken($this->chief)->postJson('/api/v1/staff', ['name' => 'Made Up Four', 'email' => 'four@seats-bpl.test', 'roles' => ['staff']])
        ->assertStatus(422)->assertJsonPath('error.details.kind', 'staff');
});

it('never counts parents: a parent login works with every staff seat taken', function () {
    $this->schoolUser($this->tenant, Role::ClassTeacher);
    $this->schoolUser($this->tenant, Role::Staff);
    $student = $this->enrol($this->tenant, $this->sections['A'], 'Aarav Patel');
    $guardian = $this->guardianOf($this->tenant, $student);

    $this->actingWithToken($this->chief)->postJson('/api/v1/guardians/'.$guardian->getKey().'/parent-login')->assertCreated();
    expect(staffCount($this)['used'])->toBe(3);
});

it('refuses turning a parent into staff when the seats are full', function () {
    $this->schoolUser($this->tenant, Role::ClassTeacher);
    $this->schoolUser($this->tenant, Role::Staff);
    $parent = $this->schoolUser($this->tenant, Role::Parent, mfaEnabled: false);

    $this->actingWithToken($this->chief, steppedUp: true)->putJson('/api/v1/users/'.$parent->getKey().'/roles', ['roles' => ['parent', 'staff']])
        ->assertStatus(422)->assertJsonPath('error.code', 'seat_limit_reached');
});

it('shows the staff seat check first in a staff import and blocks the commit', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    Spreadsheet::write($path, ['employee_code', 'name (required)', 'designation', 'type', 'email', 'phone', 'joined_on', 'roles'], [
        ['E1', 'Made Up A', '', 'teaching', 'a@seats-bpl.test', '', '', 'staff'],
        ['E2', 'Made Up B', '', 'teaching', 'b@seats-bpl.test', '', '', 'staff'],
        ['E3', 'Made Up C', '', 'teaching', 'c@seats-bpl.test', '', '', 'staff'],
        ['E4', 'No Account', '', 'non_teaching', '', '', '', ''],
    ]);

    $id = $this->actingWithToken($this->chief)->post('/api/v1/imports/staff', ['file' => new UploadedFile($path, 'staff.xlsx', null, null, true)], ['Accept' => 'application/json'])->json('data.id');
    $summary = $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->json('data.summary');

    expect($summary['seats'])->toMatchArray(['kind' => 'staff', 'limit' => 3, 'used' => 1, 'adding' => 3, 'over' => 1])
        ->and($summary['can_commit'])->toBeFalse()
        ->and($summary['messages'][0])->toStartWith('The licence allows 3 staff accounts and 1 are in use, so 2 more can be added, not 3.');
});

it('counts only active students: left students and the recycle bin free a seat', function () {
    $a = $this->enrol($this->tenant, $this->sections['A'], 'Student A');
    $b = $this->enrol($this->tenant, $this->sections['A'], 'Student B');

    $this->actingWithToken($this->chief)->getJson('/api/v1/students/seats')->assertJsonPath('data.available', 0)->assertJsonPath('data.staff.limit', 3);

    $this->actingWithToken($this->chief)->patchJson('/api/v1/students/'.$a->getKey(), ['status' => 'left'])->assertOk();
    $this->actingWithToken($this->chief)->deleteJson('/api/v1/students/'.$b->getKey())->assertOk();
    $this->actingWithToken($this->chief)->getJson('/api/v1/students/seats')->assertJsonPath('data.used', 0);

    // Restoring from the recycle bin takes the seat back, within the limit.
    $this->enrol($this->tenant, $this->sections['A'], 'Student C');
    $this->enrol($this->tenant, $this->sections['A'], 'Student D');
    $this->actingWithToken($this->chief)->postJson('/api/v1/recycle-bin/student/'.$b->getKey().'/restore')->assertStatus(422)->assertJsonPath('error.code', 'seat_limit_reached');

    expect($this->inSchool($this->tenant, fn () => app(ParentLogins::class)))->toBeInstanceOf(ParentLogins::class);
});
