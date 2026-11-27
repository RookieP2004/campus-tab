<?php

use App\Modules\Identity\Domain\Role;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 2 item 4 and the "done when": a class teacher sees a class-only
| column that a subject teacher of the same class cannot.
*/

beforeEach(function () {
    $this->school = $this->activeSchool('FIELDS-BPL');
    $this->tenant = $this->school['tenant'];
    $this->sections = $this->makeClass($this->tenant, '7', ['A', 'B'], 'middle');
    $this->classTeacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->subjectTeacher = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->makeClassTeacher($this->tenant, $this->sections['B'], $this->classTeacher['staff']);
    $this->science = $this->makeSubject($this->tenant, 'SCI', 'Science');
    $this->assignTeacher($this->tenant, $this->sections['B'], $this->science, $this->subjectTeacher['staff']);
    $this->student = $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel');
});

function seatRow(object $test): array
{
    return $test->actingWithToken($test->classTeacher['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'enrolment',
        'label' => ['en' => 'Seat row', 'hi' => 'बैठने की पंक्ति'],
        'type' => 'dropdown',
        'options' => ['Row 1', 'Row 2', 'Row 3'],
        'scope' => ['level' => 'section', 'ref' => (string) $test->sections['B']->getKey()],
    ])->assertCreated()->json('data');
}

function columnKeys(object $test, $user): array
{
    return collect($test->actingWithToken($user)->getJson('/api/v1/students?section_id='.$test->sections['B']->getKey())->assertOk()->json('columns'))->pluck('key')->all();
}

it('shows a class-only column to its class teacher and those above, never to a subject teacher', function () {
    $field = seatRow($this);
    expect($field['owner_level'])->toBe(50)->and($field['key'])->toBe('seat_row');

    $this->actingWithToken($this->classTeacher['user'])->patchJson('/api/v1/students/'.$this->student->getKey(), ['custom' => ['seat_row' => 'Row 2']])
        ->assertOk()->assertJsonPath('data.custom.seat_row', 'Row 2');

    expect(columnKeys($this, $this->classTeacher['user']))->toContain('seat_row')
        ->and(columnKeys($this, $this->principal['user']))->toContain('seat_row')
        ->and(columnKeys($this, $this->subjectTeacher['user']))->not->toContain('seat_row');

    $row = $this->actingWithToken($this->subjectTeacher['user'])->getJson('/api/v1/students')->json('data.0');
    expect($row['custom'])->not->toHaveKey('seat_row');
    $this->actingWithToken($this->subjectTeacher['user'])->getJson('/api/v1/custom-fields?entity=enrolment')->assertOk()->assertJsonCount(0, 'data');

    // The subject teacher cannot write it either.
    $this->actingWithToken($this->subjectTeacher['user'])->patchJson('/api/v1/students/'.$this->student->getKey(), ['custom' => ['seat_row' => 'Row 1']])->assertForbidden();
});

it('lets a subject teacher see a section column only when it is shared with subject teachers', function () {
    $field = seatRow($this);
    $this->actingWithToken($this->classTeacher['user'])->patchJson('/api/v1/custom-fields/'.$field['id'], ['visible_to' => ['subject_teacher']])->assertOk();

    expect(columnKeys($this, $this->subjectTeacher['user']))->toContain('seat_row');
});

it('keeps each level to its own scope', function () {
    // A class teacher cannot add a school-wide field, or a column for another section.
    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'student', 'label' => ['en' => 'House'], 'type' => 'text', 'scope' => ['level' => 'school'],
    ])->assertForbidden()->assertJsonPath('error.code', 'custom_field_scope_forbidden');

    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'enrolment', 'label' => ['en' => 'Diary signed'], 'type' => 'yes_no', 'scope' => ['level' => 'section', 'ref' => (string) $this->sections['A']->getKey()],
    ])->assertForbidden();

    // A subject teacher adds a column for their own subject-section.
    $this->actingWithToken($this->subjectTeacher['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'enrolment', 'label' => ['en' => 'Lab file'], 'type' => 'yes_no',
        'scope' => ['level' => 'subject_section', 'ref' => $this->sections['B']->getKey().':'.$this->science->getKey()],
    ])->assertCreated()->assertJsonPath('data.owner_level', 40);

    // The Principal adds a school-wide one.
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'student', 'label' => ['en' => 'House'], 'type' => 'dropdown', 'options' => ['Blue', 'Red'], 'scope' => ['level' => 'school'],
    ])->assertCreated()->assertJsonPath('data.owner_level', 80);
});

it('lets a higher level change a lower field, never the other way round', function () {
    $field = seatRow($this);
    $house = $this->actingWithToken($this->principal['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'student', 'label' => ['en' => 'House'], 'type' => 'text', 'scope' => ['level' => 'school'],
    ])->json('data');

    $this->actingWithToken($this->principal['user'])->patchJson('/api/v1/custom-fields/'.$field['id'], ['label' => ['en' => 'Seat row (term 1)']])->assertOk();
    $this->actingWithToken($this->classTeacher['user'])->patchJson('/api/v1/custom-fields/'.$house['id'], ['label' => ['en' => 'X']])->assertForbidden();
});

it('archives instead of deleting, keeping the values, and the Principal can make a class column school-wide', function () {
    $field = seatRow($this);
    $this->actingWithToken($this->classTeacher['user'])->patchJson('/api/v1/students/'.$this->student->getKey(), ['custom' => ['seat_row' => 'Row 3']])->assertOk();

    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/custom-fields/'.$field['id'].'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
    expect(columnKeys($this, $this->classTeacher['user']))->not->toContain('seat_row');

    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/custom-fields/'.$field['id'].'/restore')->assertOk();
    $this->actingWithToken($this->classTeacher['user'])->getJson('/api/v1/students/'.$this->student->getKey())->assertJsonPath('data.custom.seat_row', 'Row 3');

    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/custom-fields/'.$field['id'].'/promote')->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/custom-fields/'.$field['id'].'/promote')
        ->assertOk()->assertJsonPath('data.scope.level', 'school')->assertJsonPath('data.owner_level', 80);

    expect(columnKeys($this, $this->subjectTeacher['user']))->toContain('seat_row');
});

it('blocks duplicate labels in one scope and checks value types', function () {
    seatRow($this);
    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/custom-fields', [
        'entity' => 'enrolment', 'label' => ['en' => 'seat row'], 'type' => 'text', 'scope' => ['level' => 'section', 'ref' => (string) $this->sections['B']->getKey()],
    ])->assertStatus(422)->assertJsonPath('error.code', 'custom_field_duplicate');

    $this->actingWithToken($this->classTeacher['user'])->patchJson('/api/v1/students/'.$this->student->getKey(), ['custom' => ['seat_row' => 'Row 9']])
        ->assertStatus(422)->assertJsonPath('error.code', 'custom_value_invalid');
});
