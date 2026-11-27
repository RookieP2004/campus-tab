<?php

namespace App\Modules\People\Domain;

use App\Modules\AcademicStructure\Domain\Structure;
use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Customisation\Domain\FieldRules;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\People\Models\Consent;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use App\Support\Privacy\SensitiveValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Students: admission, edits, custom values, and the list each person may
 * see. Runs inside the school.
 *
 *  - Seats: admission is refused past the licence's student seats.
 *  - Duplicates: a new student with the same name, date of birth and a
 *    guardian phone as an existing one is refused unless confirmed.
 *  - PEN and APAAR are stored only with a consent given in the same request
 *    (a consents record); of an Aadhaar number only the last four digits.
 *  - Siblings share a guardian: a guardian with the same phone is reused.
 *  - A class teacher edits only the roll number and the section's columns.
 */
class StudentRecords
{
    public function __construct(
        private readonly Seats $seats,
        private readonly Structure $structure,
        private readonly FieldRules $fields,
        private readonly AccessControl $access,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function admit(User $actor, array $input, bool $checkSeats = true): Student
    {
        if ($checkSeats) {
            $this->seats->assertRoom('students', 1);
        }

        $year = $this->structure->year();
        $section = Section::query()->where('year_id', (string) $year->getKey())->find($input['section_id']);

        if (! $section instanceof Section) {
            throw new ApiException('section_not_found', 'Choose a section of the current year.', 422);
        }

        if (Student::query()->where('admission_no', $input['admission_no'])->exists()) {
            throw new ApiException('admission_no_taken', 'This admission number is already used.', 422);
        }

        /** @var list<array{name: string, relation?: string|null, phone?: string|null, email?: string|null}> $guardians */
        $guardians = $input['guardians'] ?? [];

        if (! ($input['confirm_not_duplicate'] ?? false)) {
            $duplicate = $this->findDuplicate($input['name'], $input['dob'], array_column($guardians, 'phone'));

            if ($duplicate instanceof Student) {
                throw new ApiException('possible_duplicate', 'A student with the same name, date of birth and guardian phone already exists.', 409, [
                    'student_id' => (string) $duplicate->getKey(),
                    'admission_no' => $duplicate->admission_no,
                ]);
            }
        }

        $ids = $this->checkIds($input['ids'] ?? [], $input['consents'] ?? []);
        [$studentCustom, $enrolmentCustom] = $this->splitCustom($actor, $input['custom'] ?? [], (string) $section->getKey());

        return DB::connection('mongodb')->transaction(function () use ($actor, $input, $year, $section, $guardians, $ids, $studentCustom, $enrolmentCustom): Student {
            $student = Student::create([
                'admission_no' => $input['admission_no'],
                'name' => $input['name'],
                'dob' => $input['dob'],
                'gender' => $input['gender'],
                'ids' => $ids,
                'custom' => $studentCustom,
                'status' => 'active',
                'import_batch_id' => $input['import_batch_id'] ?? null,
            ]);

            Enrolment::create([
                'student_id' => (string) $student->getKey(),
                'year_id' => (string) $year->getKey(),
                'section_id' => (string) $section->getKey(),
                'roll_no' => $input['roll_no'] ?? null,
                'custom' => $enrolmentCustom,
            ]);

            $guardianIds = [];
            foreach ($guardians as $g) {
                $guardianIds[] = (string) $this->linkGuardian($student, $g)->getKey();
            }

            $student->forceFill(['guardian_ids' => $guardianIds])->save();
            $this->recordConsents($actor, $student, $input['consents'] ?? [], $guardianIds[0] ?? null);

            return $student;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(User $actor, Student $student, array $changes): Student
    {
        $this->withEnrolment($student);
        // School or wing scope edits everything; class scope only the roll number and columns.
        $scopes = $this->access->scopesFor($actor, 'students.edit');
        $full = in_array('school', $scopes, true)
            || (in_array('wing', $scopes, true) && in_array($student->currentWing, $actor->accessScope()['wings'], true));

        $basic = array_intersect_key($changes, array_flip(['name', 'dob', 'gender', 'status']));

        if (! $full && $basic !== []) {
            throw new ApiException('field_not_editable', 'A class teacher can change only the roll number and the section\'s own columns.', 403);
        }

        if (array_key_exists('ids', $changes) || array_key_exists('consents', $changes)) {
            if (! $full) {
                throw new ApiException('field_not_editable', 'Only the office can change ID numbers.', 403);
            }
            $this->recordConsents($actor, $student, $changes['consents'] ?? [], $student->guardian_ids[0] ?? null);
            $basic['ids'] = array_merge($student->ids ?? [], $this->checkIds($changes['ids'] ?? [], [], (string) $student->getKey()));
        }

        DB::connection('mongodb')->transaction(function () use ($actor, $student, $changes, $basic): void {
            if ($basic !== []) {
                $student->fill($basic)->save();
            }

            if (array_key_exists('roll_no', $changes) && $student->currentEnrolment instanceof Enrolment) {
                $student->currentEnrolment->fill(['roll_no' => $changes['roll_no']])->save();
            }

            if (isset($changes['custom']) && is_array($changes['custom'])) {
                $this->setCustom($actor, $student, $changes['custom']);
            }
        });

        return $student;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setCustom(User $actor, Student $student, array $values): void
    {
        $this->withEnrolment($student);
        [$studentCustom, $enrolmentCustom] = $this->splitCustom($actor, $values, $student->currentEnrolment?->section_id);

        if ($studentCustom !== []) {
            $student->forceFill(['custom' => array_merge($student->custom ?? [], $studentCustom)])->save();
        }

        if ($enrolmentCustom !== [] && $student->currentEnrolment instanceof Enrolment) {
            $enrolment = $student->currentEnrolment;
            $enrolment->forceFill(['custom' => array_merge($enrolment->custom ?? [], $enrolmentCustom)])->save();
        }
    }

    /**
     * The students this person may see, as rows for the list, with the
     * custom columns they may see.
     *
     * @param  array{section_id?: string|null, q?: string|null, page?: int, per_page?: int}  $filters
     * @return array{rows: list<array<string, mixed>>, columns: list<CustomField>, total: int}
     */
    public function list(User $actor, array $filters): array
    {
        $year = AcademicYear::current();
        $sectionFilter = $filters['section_id'] ?? null;
        $allowedSections = $this->visibleSections($actor);
        $childIds = null;
        $ownIds = null;

        $scopes = $this->access->scopesFor($actor, 'students.view');

        if (! in_array('school', $scopes, true)) {
            if (in_array('child', $scopes, true)) {
                $childIds = $actor->accessScope()['children'];
            }
            if (in_array('own', $scopes, true)) {
                $ownIds = Student::query()->where('user_id', (string) $actor->getKey())->get()->map(fn ($m) => (string) $m->getKey())->all();
            }
        }

        $studentIds = null;

        if ($year instanceof AcademicYear && ($allowedSections !== null || $sectionFilter !== null)) {
            $sections = $allowedSections ?? [$sectionFilter];
            if ($sectionFilter !== null) {
                $sections = in_array($sectionFilter, $sections, true) ? [$sectionFilter] : [];
            }

            $studentIds = Enrolment::query()->where('year_id', (string) $year->getKey())->whereIn('section_id', $sections)
                ->pluck('student_id')->map(fn ($id) => (string) $id)->all();
        }

        if ($childIds !== null || $ownIds !== null) {
            $extra = array_merge($childIds ?? [], $ownIds ?? []);
            $studentIds = $sectionFilter !== null && $studentIds !== null ? array_values(array_intersect($studentIds, $extra)) : array_values(array_unique(array_merge($studentIds ?? [], $extra)));
        }

        $query = Student::query()->where('status', '!=', 'left');

        if ($studentIds !== null) {
            $query->whereIn('_id', $studentIds);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $pattern = '/'.preg_quote($q, '/').'/i';
            $query->where(fn ($w) => $w->where('name', 'regex', $pattern)->orWhere('admission_no', 'regex', $pattern));
        }

        $total = (clone $query)->count();
        $perPage = min(max((int) ($filters['per_page'] ?? 100), 1), 500);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $students = $query->orderBy('name')->skip(($page - 1) * $perPage)->limit($perPage)->get();

        $this->attachEnrolments($students, $year);
        $columns = $this->fields->visibleFields($actor, ['student', 'enrolment'], $sectionFilter);

        return [
            'rows' => $students->map(fn (Student $s) => $this->row($s, $columns))->values()->all(),
            'columns' => $columns->values()->all(),
            'total' => $total,
        ];
    }

    /**
     * Section ids the person may see students of; null means every section.
     *
     * @return list<string>|null
     */
    public function visibleSections(User $actor): ?array
    {
        $scopes = $this->access->scopesFor($actor, 'students.view');

        if (in_array('school', $scopes, true)) {
            return null;
        }

        $mine = $actor->accessScope();
        $sections = [];

        if (in_array('wing', $scopes, true) && $mine['wings'] !== []) {
            $sections = array_merge($sections, Section::query()->whereIn('wing', $mine['wings'])->get()->map(fn ($m) => (string) $m->getKey())->all());
        }

        if (in_array('class', $scopes, true)) {
            $sections = array_merge($sections, $mine['class_sections']);
        }

        if (in_array('subject', $scopes, true)) {
            $sections = array_merge($sections, array_map(fn (string $k) => strtok($k, ':') ?: '', $mine['subject_sections']));
        }

        return array_values(array_unique(array_filter($sections)));
    }

    /** Loads this year's enrolment and wing onto the student (for scope checks). */
    public function withEnrolment(Student $student): Student
    {
        $this->attachEnrolments(collect([$student]), AcademicYear::current());

        return $student;
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    public function attachEnrolments(Collection $students, ?AcademicYear $year): void
    {
        if ($year === null || $students->isEmpty()) {
            return;
        }

        $enrolments = Enrolment::query()->where('year_id', (string) $year->getKey())
            ->whereIn('student_id', $students->map(fn (Student $s) => (string) $s->getKey())->all())->get()->keyBy('student_id');
        $sections = Section::query()->whereIn('_id', $enrolments->pluck('section_id')->unique()->values()->all())->get()->keyBy(fn (Section $s) => (string) $s->getKey());

        foreach ($students as $student) {
            $enrolment = $enrolments[(string) $student->getKey()] ?? null;
            $student->currentEnrolment = $enrolment instanceof Enrolment ? $enrolment : null;
            $student->currentWing = $enrolment instanceof Enrolment ? ($sections[$enrolment->section_id]->wing ?? null) : null;
            $student->setAttribute('section_label', $enrolment instanceof Enrolment ? ($sections[$enrolment->section_id]->label ?? null) : null);
        }
    }

    /**
     * @param  Collection<int, CustomField>  $columns
     * @return array<string, mixed>
     */
    public function row(Student $s, Collection $columns): array
    {
        $values = array_merge($s->custom ?? [], $s->currentEnrolment !== null ? $s->currentEnrolment->custom : []);
        $custom = [];

        foreach ($columns as $field) {
            $value = $values[$field->key] ?? null;
            // Sensitive values stay masked in every list and profile.
            $custom[$field->key] = $field->sensitive && $value !== null ? SensitiveValue::MASK : $value;
        }

        return [
            'id' => (string) $s->getKey(),
            'admission_no' => $s->admission_no,
            'name' => $s->name,
            'dob' => $s->dob,
            'gender' => $s->gender,
            'status' => $s->status,
            'section_id' => $s->currentEnrolment?->section_id,
            'section_label' => $s->getAttribute('section_label'),
            'roll_no' => $s->currentEnrolment?->roll_no,
            'custom' => $custom,
        ];
    }

    /**
     * The sensitive values this person may fill, decrypted, for the profile's
     * "Show" button. Everyone else only ever gets the mask.
     *
     * @param  Collection<int, CustomField>  $columns
     * @return array<string, mixed>
     */
    public function revealed(User $actor, Student $s, Collection $columns): array
    {
        $values = array_merge($s->custom ?? [], $s->currentEnrolment !== null ? $s->currentEnrolment->custom : []);
        $out = [];

        foreach ($columns as $field) {
            if ($field->sensitive && isset($values[$field->key]) && $this->fields->canFill($actor, $field)) {
                $out[$field->key] = SensitiveValue::open($values[$field->key]);
            }
        }

        return $out;
    }

    /**
     * @param  list<string|null>  $phones
     */
    public function findDuplicate(string $name, string $dob, array $phones): ?Student
    {
        $phones = array_values(array_filter(array_map(fn ($p) => is_string($p) ? self::phone($p) : null, $phones)));

        if ($phones === []) {
            return null;
        }

        $candidates = Student::query()->where('dob', $dob)->where('name', 'regex', '/^'.preg_quote(trim($name), '/').'$/i')->get();

        foreach ($candidates as $candidate) {
            $match = Guardian::query()->whereIn('_id', $candidate->guardian_ids)->whereIn('phone', $phones)->exists();

            if ($match) {
                return $candidate;
            }
        }

        return null;
    }

    /** Phone numbers are kept as +<country><number>; 10 digits are Indian. */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (preg_match('/^\d{10}$/', $digits) === 1) {
            return '+91'.$digits;
        }

        if (preg_match('/^91\d{10}$/', $digits) === 1) {
            return '+'.$digits;
        }

        return preg_match('/^\+\d{10,15}$/', $digits) === 1 ? $digits : throw new ApiException('phone_invalid', 'Enter a valid phone number.', 422);
    }

    /**
     * @param  array{name: string, relation?: string|null, phone?: string|null, email?: string|null}  $input
     */
    public function linkGuardian(Student $student, array $input): Guardian
    {
        $phone = self::phone($input['phone'] ?? null);
        $guardian = $phone !== null ? Guardian::query()->where('phone', $phone)->first() : null;

        if ($guardian instanceof Guardian) {
            $guardian->forceFill(['student_ids' => array_values(array_unique([...$guardian->student_ids, (string) $student->getKey()]))])->save();

            return $guardian;
        }

        return Guardian::create([
            'name' => $input['name'],
            'relation' => $input['relation'] ?? null,
            'phone' => $phone,
            'email' => isset($input['email']) && $input['email'] !== '' ? mb_strtolower($input['email']) : null,
            'student_ids' => [(string) $student->getKey()],
        ]);
    }

    /**
     * PEN and APAAR only with consent; Aadhaar only its last four digits.
     *
     * @param  array<string, mixed>  $ids
     * @param  array<string, mixed>  $consents
     * @return array<string, string|null>
     */
    private function checkIds(array $ids, array $consents, ?string $studentId = null): array
    {
        $clean = [];

        foreach (['pen', 'apaar'] as $kind) {
            $value = $ids[$kind] ?? null;

            if ($value === null || $value === '') {
                if (array_key_exists($kind, $ids)) {
                    $clean[$kind] = null;
                }

                continue;
            }

            $given = ($consents[$kind] ?? null) === true || ($studentId !== null && ! array_key_exists($kind, $consents) && Consent::latestGiven($studentId, $kind));

            if (! $given) {
                throw new ApiException('consent_required', strtoupper($kind).' is kept only with the guardian\'s consent. Record the consent first.', 422, ['id' => $kind]);
            }

            if (preg_match('/^[A-Za-z0-9]{6,20}$/', (string) $value) !== 1) {
                throw new ApiException('id_invalid', strtoupper($kind).' has letters and digits only.', 422, ['id' => $kind]);
            }

            $clean[$kind] = (string) $value;
        }

        if (array_key_exists('aadhaar_last4', $ids)) {
            $last4 = $ids['aadhaar_last4'];

            if ($last4 !== null && $last4 !== '' && preg_match('/^\d{4}$/', (string) $last4) !== 1) {
                throw new ApiException('aadhaar_last4_only', 'Keep only the last four digits of the Aadhaar number.', 422);
            }

            $clean['aadhaar_last4'] = $last4 === '' ? null : ($last4 === null ? null : (string) $last4);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $consents
     */
    private function recordConsents(User $actor, Student $student, array $consents, ?string $guardianId): void
    {
        foreach ($consents as $purpose => $given) {
            if (! in_array($purpose, Consent::PURPOSES, true) || ! is_bool($given)) {
                continue;
            }

            Consent::create([
                'student_id' => (string) $student->getKey(),
                'guardian_id' => $guardianId,
                'purpose' => $purpose,
                'notice_version' => (string) config('people.consent_notice_version', '2026-1'),
                'given' => $given,
                'at' => now()->toIso8601String(),
                'recorded_by' => ['id' => (string) $actor->getKey(), 'name' => $actor->name],
            ]);
        }
    }

    /**
     * Splits custom values into student-level and enrolment-level ones,
     * after checking the person may fill each field.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitCustom(User $actor, array $values, ?string $sectionId): array
    {
        if ($values === []) {
            return [[], []];
        }

        $fields = $this->fields->visibleFields($actor, ['student', 'enrolment'], $sectionId)->keyBy('key');
        $student = [];
        $enrolment = [];

        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? null;

            if (! $field instanceof CustomField || ! $this->fields->canFill($actor, $field)) {
                throw new ApiException('custom_field_forbidden', "You cannot change the field \"{$key}\".", 403, ['field' => $key]);
            }

            $normalised = $this->fields->normalise($field, $value);

            // Sensitive fields are stored encrypted (SensitiveValue).
            if ($field->sensitive) {
                $normalised = SensitiveValue::seal($normalised);
            }

            if ($field->entity === 'enrolment') {
                $enrolment[$field->key] = $normalised;
            } else {
                $student[$field->key] = $normalised;
            }
        }

        return [$student, $enrolment];
    }
}
