<?php

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Domain\StudentRecords;
use App\Modules\People\Models\Student;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 2 rule 1: the child, class, subject and wing scope resolvers work
| from the school's records: guardians' children, sections' class teacher
| and wing, and teaching assignments. One test per resolver, each checking
| a record inside and one outside the scope, through the API as well.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('SCOPE-BPL');
    $this->tenant = $this->school['tenant'];
    $this->s7 = $this->makeClass($this->tenant, '7', ['A', 'B'], 'middle');
    $this->s3 = $this->makeClass($this->tenant, '3', ['A'], 'primary');
    $this->in7b = $this->enrol($this->tenant, $this->s7['B'], 'Aarav Patel', '+919811110001');
    $this->in7a = $this->enrol($this->tenant, $this->s7['A'], 'Diya Gupta');
    $this->in3a = $this->enrol($this->tenant, $this->s3['A'], 'Riya Patel', '+919811110001');
});

function scopeCovers(object $test, $user, Student $student, string $ability = 'students.view'): bool
{
    return $test->inSchool($test->tenant, function () use ($user, $student, $ability) {
        $user = User::query()->findOrFail($user->getKey());
        $student = app(StudentRecords::class)->withEnrolment(Student::query()->findOrFail($student->getKey()));

        return app(AccessControl::class)->allows($user, $ability, $student);
    });
}

function visibleStudentNames(object $test, $user): array
{
    return collect($test->actingWithToken($user)->getJson('/api/v1/students')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
}

it('class: a class teacher covers the section whose class teacher they are', function () {
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $teacher['staff']);

    expect(scopeCovers($this, $teacher['user'], $this->in7b))->toBeTrue()
        ->and(scopeCovers($this, $teacher['user'], $this->in7a))->toBeFalse()
        ->and(visibleStudentNames($this, $teacher['user']))->toBe(['Aarav Patel']);

    $this->actingWithToken($teacher['user'])->getJson('/api/v1/students/'.$this->in7a->getKey())->assertForbidden();
});

it('subject: a subject teacher covers the sections they teach, from teaching assignments', function () {
    $teacher = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $science = $this->makeSubject($this->tenant, 'SCI', 'Science');
    $this->assignTeacher($this->tenant, $this->s7['A'], $science, $teacher['staff']);

    expect(scopeCovers($this, $teacher['user'], $this->in7a))->toBeTrue()
        ->and(scopeCovers($this, $teacher['user'], $this->in7b))->toBeFalse()
        ->and(visibleStudentNames($this, $teacher['user']))->toBe(['Diya Gupta']);
});

it('wing: a Vice Principal covers the sections of their wings', function () {
    $vp = $this->staffMember($this->tenant, Role::VicePrincipal, ['wings' => ['primary']]);

    expect(scopeCovers($this, $vp['user'], $this->in3a))->toBeTrue()
        ->and(scopeCovers($this, $vp['user'], $this->in7b))->toBeFalse()
        ->and(visibleStudentNames($this, $vp['user']))->toBe(['Riya Patel']);
});

it('child: a parent covers the children of their guardian record, siblings included', function () {
    $guardian = $this->guardianOf($this->tenant, $this->in7b);
    $parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->school['chief'], $guardian));

    expect(scopeCovers($this, $parent, $this->in7b))->toBeTrue()
        ->and(scopeCovers($this, $parent, $this->in3a))->toBeTrue()
        ->and(scopeCovers($this, $parent, $this->in7a))->toBeFalse();

    $parent->forceFill(['mfa' => ['enabled' => false]]);
    expect(visibleStudentNames($this, $parent))->toBe(['Aarav Patel', 'Riya Patel']);
});

it('own: a student covers only their own record', function () {
    $user = $this->schoolUser($this->tenant, Role::Student, mfaEnabled: false);
    $this->inSchool($this->tenant, fn () => Student::query()->findOrFail($this->in7a->getKey())->forceFill(['user_id' => (string) $user->getKey()])->save());

    expect(scopeCovers($this, $user, $this->in7a))->toBeTrue()
        ->and(scopeCovers($this, $user, $this->in7b))->toBeFalse();
});

it('follows the records when they change: a new assignment widens the scope at once', function () {
    $teacher = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $maths = $this->makeSubject($this->tenant, 'MATH', 'Mathematics');

    expect(scopeCovers($this, $teacher['user'], $this->in7b))->toBeFalse();
    $this->assignTeacher($this->tenant, $this->s7['B'], $maths, $teacher['staff']);
    expect(scopeCovers($this, $teacher['user'], $this->in7b))->toBeTrue();
});

it('ignores sections of a year that is not current', function () {
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->makeClassTeacher($this->tenant, $this->s7['B'], $teacher['staff']);

    $this->inSchool($this->tenant, function () {
        $next = AcademicYear::create(['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']);
        app(Structure::class)->makeCurrent($next);
    });

    expect($this->inSchool($this->tenant, fn () => User::query()->findOrFail($teacher['user']->getKey())->accessScope()['class_sections']))->toBe([]);
});
