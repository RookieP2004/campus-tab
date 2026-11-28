<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Models\Consent;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Support\Privacy\Aadhaar;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

beforeEach(function () {
    $this->school = $this->activeSchool('PEOPLE-BPL', ['students' => 3]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->sections = $this->makeClass($this->tenant, '7', ['A', 'B']);
});

function admission(object $test, array $overrides = []): array
{
    return array_merge([
        'admission_no' => 'SPS/2026/'.random_int(1000, 9999),
        'name' => 'Aanya Sharma',
        'dob' => '2014-05-21',
        'gender' => 'female',
        'section_id' => (string) $test->sections['B']->getKey(),
        'roll_no' => 1,
        'guardians' => [['name' => 'Kavita Sharma', 'relation' => 'mother', 'phone' => '9800000101']],
    ], $overrides);
}

describe('admission', function () {
    it('admits a student with an enrolment and a guardian, and audits it', function () {
        $response = $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this))->assertCreated();

        expect($response->json('data.section_label'))->toBe('7-B')
            ->and($response->json('data.guardians.0.phone'))->toBe('+919800000101');

        $this->inSchool($this->tenant, fn () => expect(AuditLog::query()->where('entity', 'students')->where('action', 'created')->count())->toBe(1));
    });

    it('shares one guardian between siblings with the same phone', function () {
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this))->assertCreated();
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['name' => 'Kabir Sharma', 'dob' => '2017-01-02']))->assertCreated();

        $this->inSchool($this->tenant, function () {
            expect(Guardian::query()->count())->toBe(1)
                ->and(Guardian::query()->first()->student_ids)->toHaveCount(2);
        });
    });

    it('refuses a duplicate on name, date of birth and guardian phone unless confirmed', function () {
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this))->assertCreated();

        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['name' => 'aanya sharma']))
            ->assertStatus(409)->assertJsonPath('error.code', 'possible_duplicate');
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['confirm_not_duplicate' => true]))->assertCreated();
    });

    it('refuses students beyond the licence seats with a clear message', function () {
        foreach (range(1, 3) as $i) {
            $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['name' => "Student {$i}", 'guardians' => []]))->assertCreated();
        }

        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['name' => 'One Too Many', 'guardians' => []]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'seat_limit_reached')
            ->assertJsonPath('error.details.limit', 3)
            ->assertJsonPath('error.message', 'The licence allows 3 students and 3 are enrolled, so 0 more can be added, not 1. Ask the Chief Admin to contact Campus TAB to add seats.');

        $this->actingWithToken($this->chief)->getJson('/api/v1/students/seats')->assertJsonPath('data.warning', true);
    });

    it('keeps PEN and APAAR only after the parent consents from their own account (Phase 9)', function () {
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['ids' => ['pen' => 'PEN123456']]))
            ->assertStatus(422)->assertJsonPath('error.code', 'consent_required');
        // The office can no longer record a consent for the parent.
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['ids' => ['pen' => 'PEN123456'], 'consents' => ['pen' => true]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'consent_parent_only');

        $id = $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this))->assertCreated()->json('data.id');
        $this->inSchool($this->tenant, fn () => expect(Consent::latestGiven($id, 'pen'))->toBeFalse());
        // The parent's own consent: tests/Feature/Privacy/PrivacyTest.php.
    });

    it('keeps only the last four Aadhaar digits', function () {
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['ids' => ['aadhaar_last4' => '12345']]))
            ->assertStatus(422);
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['ids' => ['aadhaar_last4' => '4321']]))
            ->assertCreated()->assertJsonPath('data.ids.aadhaar_last4', '4321');
    });
});

describe('Aadhaar numbers', function () {
    it('knows the Verhoeff check', function () {
        $valid = self::fakeAadhaar();

        expect(Aadhaar::isFullNumber($valid))->toBeTrue()
            ->and(Aadhaar::isFullNumber(substr($valid, 0, 11).((int) $valid[11] + 1) % 10))->toBeFalse()
            ->and(Aadhaar::isFullNumber('1'.substr($valid, 1)))->toBeFalse()
            ->and(Aadhaar::containsFullNumber('Aadhaar: '.substr($valid, 0, 4).' '.substr($valid, 4, 4).' '.substr($valid, 8)))->toBeTrue()
            ->and(Aadhaar::containsFullNumber('+91'.substr($valid, 2)))->toBeFalse();
    });

    it('refuses a full Aadhaar number in any field of any request', function () {
        $aadhaar = self::fakeAadhaar();

        foreach ([
            ['name' => 'Aanya '.$aadhaar],
            ['guardians' => [['name' => 'Kavita', 'phone' => '9800000101', 'email' => null, 'relation' => $aadhaar]]],
            ['custom' => ['anything' => substr($aadhaar, 0, 4).'-'.substr($aadhaar, 4, 4).'-'.substr($aadhaar, 8)]],
            ['ids' => ['aadhaar_last4' => $aadhaar]],
        ] as $overrides) {
            $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, $overrides))
                ->assertStatus(422)->assertJsonPath('error.code', 'aadhaar_not_allowed')->assertJsonMissing([$aadhaar]);
        }

        $this->actingWithToken($this->chief)->postJson('/api/v1/staff', ['name' => 'Made Up', 'designation' => $aadhaar])
            ->assertStatus(422)->assertJsonPath('error.code', 'aadhaar_not_allowed');

        $this->inSchool($this->tenant, fn () => expect(Student::query()->count())->toBe(0)
            ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($aadhaar));
    });

    it('does not mistake a phone number with +91 for an Aadhaar number', function () {
        $this->actingWithToken($this->chief)->postJson('/api/v1/students', admission($this, ['guardians' => [['name' => 'K', 'phone' => '+919876543210']]]))->assertCreated();
    });

    it('refuses custom fields meant for Aadhaar or bank numbers', function () {
        foreach ([
            ['label' => ['en' => 'Aadhaar number'], 'type' => 'text'],
            ['label' => ['en' => 'Aadhar No.'], 'type' => 'text'],
            ['label' => ['en' => 'आधार संख्या'], 'type' => 'text'],
            ['label' => ['en' => 'Bank account number'], 'type' => 'number'],
            ['label' => ['en' => 'IFSC code'], 'type' => 'text'],
            ['label' => ['en' => 'Student ID'], 'type' => 'aadhaar'],
        ] as $field) {
            $this->actingWithToken($this->chief)->postJson('/api/v1/custom-fields', array_merge($field, ['entity' => 'student', 'scope' => ['level' => 'school']]))
                ->assertStatus(422)->assertJsonPath('error.code', 'custom_field_forbidden');
        }
    });
});

describe('editing', function () {
    it('lets a class teacher change only the roll number and the section\'s own columns', function () {
        $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);
        $this->makeClassTeacher($this->tenant, $this->sections['B'], $teacher['staff']);
        $student = $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel');

        $this->actingWithToken($teacher['user'])->patchJson('/api/v1/students/'.$student->getKey(), ['name' => 'Changed'])
            ->assertForbidden()->assertJsonPath('error.code', 'field_not_editable');
        $this->actingWithToken($teacher['user'])->patchJson('/api/v1/students/'.$student->getKey(), ['roll_no' => 7])
            ->assertOk()->assertJsonPath('data.roll_no', 7);

        $other = $this->enrol($this->tenant, $this->sections['A'], 'Diya Gupta');
        $this->actingWithToken($teacher['user'])->patchJson('/api/v1/students/'.$other->getKey(), ['roll_no' => 7])->assertForbidden();
    });

    it('lets the office edit everything and sends deleted students to the recycle bin', function () {
        $clerk = $this->staffMember($this->tenant, Role::Clerk);
        $student = $this->enrol($this->tenant, $this->sections['B'], 'Aarav Patel');

        $this->actingWithToken($clerk['user'])->patchJson('/api/v1/students/'.$student->getKey(), ['name' => 'Aarav K Patel'])->assertOk()->assertJsonPath('data.name', 'Aarav K Patel');
        $this->actingWithToken($clerk['user'])->deleteJson('/api/v1/students/'.$student->getKey())->assertOk();
        $this->actingWithToken($this->chief)->getJson('/api/v1/recycle-bin')->assertOk()->assertJsonPath('data.0.type', 'student');
    });
});
