<?php

namespace Tests\Concerns;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Privacy\Aadhaar;
use Illuminate\Support\Str;

/**
 * Builds a school's records for tests (made-up data only): a current year,
 * classes with sections, staff linked to accounts, teaching assignments,
 * students with guardians. Use with CreatesSchools.
 */
trait BuildsSchoolRecords
{
    public function currentYear(Tenant $tenant): AcademicYear
    {
        return $this->inSchool($tenant, function (): AcademicYear {
            $year = AcademicYear::current() ?? AcademicYear::create(['name' => '2026-27', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31']);
            app(Structure::class)->makeCurrent($year);

            return $year;
        });
    }

    /** A class with sections; returns the sections by name. */
    public function makeClass(Tenant $tenant, string $grade, array $sections = ['A'], ?string $wing = null): array
    {
        $year = $this->currentYear($tenant);

        return $this->inSchool($tenant, function () use ($year, $grade, $sections, $wing): array {
            $class = app(Structure::class)->createClass($year, ['grade' => $grade, 'name' => 'Class '.$grade, 'wing' => $wing, 'sections' => $sections]);

            return Section::query()->where('class_id', (string) $class->getKey())->get()->keyBy('name')->all();
        });
    }

    /** A staff record linked to a new account with this role. */
    public function staffMember(Tenant $tenant, Role $role, array $attributes = []): array
    {
        $user = $this->schoolUser($tenant, $role, mfaEnabled: $role->level() >= 60);
        $staff = $this->inSchool($tenant, fn () => Staff::create(array_merge([
            'employee_code' => 'EMP-'.Str::upper(Str::random(6)),
            'name' => $user->name,
            'type' => 'teaching',
            'email' => $user->email,
            'user_id' => (string) $user->getKey(),
            'status' => Staff::STATUS_ACTIVE,
        ], $attributes)));

        return ['user' => $user, 'staff' => $staff];
    }

    public function makeSubject(Tenant $tenant, string $code, string $name): Subject
    {
        return $this->inSchool($tenant, fn () => Subject::query()->where('code', $code)->first() ?? Subject::create(['code' => $code, 'name' => $name]));
    }

    public function assignTeacher(Tenant $tenant, Section $section, Subject $subject, Staff $teacher): TeachingAssignment
    {
        return $this->inSchool($tenant, fn () => app(Structure::class)->assign((string) $section->getKey(), (string) $subject->getKey(), (string) $teacher->getKey()));
    }

    public function makeClassTeacher(Tenant $tenant, Section $section, Staff $teacher): void
    {
        $this->inSchool($tenant, fn () => Section::query()->findOrFail($section->getKey())->fill(['class_teacher_id' => (string) $teacher->getKey()])->save());
    }

    /** A student enrolled in the section, with one guardian (shared when the phone matches). */
    public function enrol(Tenant $tenant, Section $section, string $name, ?string $guardianPhone = null, array $attributes = []): Student
    {
        $year = $this->currentYear($tenant);

        return $this->inSchool($tenant, function () use ($year, $section, $name, $guardianPhone, $attributes): Student {
            $student = Student::create(array_merge([
                'admission_no' => 'ADM-'.Str::upper(Str::random(8)),
                'name' => $name,
                'dob' => '2014-06-01',
                'gender' => 'female',
                'status' => 'active',
            ], $attributes));

            Enrolment::create(['student_id' => (string) $student->getKey(), 'year_id' => (string) $year->getKey(), 'section_id' => (string) $section->getKey()]);

            $phone = $guardianPhone ?? '+9196'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $guardian = Guardian::query()->where('phone', $phone)->first() ?? Guardian::create(['name' => 'Guardian of '.$name, 'phone' => $phone, 'student_ids' => []]);
            $guardian->forceFill(['student_ids' => array_values(array_unique([...$guardian->student_ids, (string) $student->getKey()]))])->save();
            $student->forceFill(['guardian_ids' => [(string) $guardian->getKey()]])->save();

            return $student;
        });
    }

    public function guardianOf(Tenant $tenant, Student $student): Guardian
    {
        return $this->inSchool($tenant, fn () => Guardian::query()->findOrFail(Student::query()->findOrFail($student->getKey())->guardian_ids[0]));
    }

    public function freshUser(Tenant $tenant, User $user): User
    {
        return $this->inSchool($tenant, fn () => User::query()->findOrFail($user->getKey()));
    }

    /** A made-up 12-digit number that passes the Verhoeff check, like a real Aadhaar number. */
    public static function fakeAadhaar(string $first11 = '23456789012'): string
    {
        for ($d = 0; $d <= 9; $d++) {
            if (Aadhaar::isFullNumber($first11.$d)) {
                return $first11.$d;
            }
        }

        throw new \RuntimeException('no check digit');
    }

    public function sectionModel(Tenant $tenant, string $id): Section
    {
        return $this->inSchool($tenant, fn () => Section::query()->findOrFail($id));
    }

    public function classOf(Tenant $tenant, Section $section): SchoolClass
    {
        return $this->inSchool($tenant, fn () => SchoolClass::query()->findOrFail($section->class_id));
    }
}
