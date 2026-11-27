<?php

namespace App\Modules\People\Domain\Import;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\SchoolClass;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Customisation\Domain\FieldRules;
use App\Modules\Customisation\Models\CustomField;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\People\Domain\StaffRecords;
use App\Modules\People\Domain\StudentRecords;
use App\Modules\People\Models\ImportBatch;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Privacy\Aadhaar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Exception\IOException;
use Throwable;

/**
 * Excel import for students and staff: template, dry run, commit.
 *
 *  1. The dry run (a queued job) reads every row and reports, per row: ok,
 *     error (with plain messages) or duplicate. Duplicates are checked on
 *     name + date of birth + guardian phone, inside the file and against the
 *     school. Full Aadhaar numbers are refused in any cell. The seat check
 *     (student seats, or staff seats for staff with accounts) comes first:
 *     rows past the licence's seats block the commit.
 *  2. The report can be downloaded as a spreadsheet.
 *  3. The commit (a queued job) checks again and creates the ok rows. Every
 *     record is written to the audit log under the batch id, plus one
 *     import.committed entry.
 *
 * Runs inside the school (the jobs set the tenant).
 */
class Importer
{
    public const ENTITIES = ['students', 'staff'];

    private const STUDENT_COLUMNS = [
        'admission_no' => true, 'name' => true, 'dob' => true, 'gender' => true, 'class' => true, 'section' => true, 'roll_no' => false,
        'guardian_name' => true, 'guardian_relation' => false, 'guardian_phone' => true, 'guardian_email' => false,
        'guardian2_name' => false, 'guardian2_relation' => false, 'guardian2_phone' => false,
        'pen' => false, 'consent_pen' => false, 'apaar' => false, 'consent_apaar' => false, 'aadhaar_last4' => false,
    ];

    private const STAFF_COLUMNS = [
        'employee_code' => false, 'name' => true, 'designation' => false, 'type' => false, 'email' => false, 'phone' => false,
        'joined_on' => false, 'roles' => false,
    ];

    private const PROGRESS_EVERY = 25;

    public function __construct(
        private readonly StudentRecords $students,
        private readonly StaffRecords $staff,
        private readonly Seats $seats,
        private readonly FieldRules $fields,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Column headers for the template: required ones marked, plus the
     * school-wide custom fields this person may fill.
     *
     * @return array{headers: list<string>, example: list<string>}
     */
    public function template(User $actor, string $entity): array
    {
        $columns = $entity === 'students' ? self::STUDENT_COLUMNS : self::STAFF_COLUMNS;
        $headers = array_map(fn (string $c, bool $required) => $required ? $c.' (required)' : $c, array_keys($columns), $columns);
        $example = $entity === 'students'
            ? ['SPS/2026/0001', 'Made Up Student', '2014-05-21', 'female', '7', 'B', '1', 'Made Up Parent', 'mother', '9800000000', '', '', '', '', '', 'no', '', 'no', '']
            : ['', 'Made Up Teacher', 'TGT Science', 'teaching', 'teacher@example.test', '', '2026-04-01', 'subject_teacher'];

        foreach ($this->customColumns($actor, $entity) as $field) {
            $headers[] = 'custom:'.$field->key.' ('.($field->label['en'] ?? $field->key).')';
            $example[] = '';
        }

        return ['headers' => $headers, 'example' => $example];
    }

    public function validate(ImportBatch $batch, User $actor): void
    {
        $this->run($batch, $actor, commit: false);
    }

    public function commit(ImportBatch $batch, User $actor, Tenant $tenant): void
    {
        $this->run($batch, $actor, commit: true, tenant: $tenant);
    }

    private function run(ImportBatch $batch, User $actor, bool $commit, ?Tenant $tenant = null): void
    {
        $batch->forceFill(['status' => $commit ? 'committing' : 'validating', 'error' => null])->save();

        try {
            $rows = Spreadsheet::read(Storage::disk('local')->path($batch->file_path), $batch->file_name);
            $checked = $batch->entity === 'students' ? $this->checkStudents($rows, $actor) : $this->checkStaff($rows, $actor);
            $summary = $this->summarise($batch->entity, $checked);

            if (! $commit) {
                $batch->forceFill([
                    'status' => 'validated',
                    'rows' => array_map(fn (array $r) => array_diff_key($r, ['payload' => true]), $checked),
                    'summary' => $summary,
                    'progress' => ['done' => count($rows), 'total' => count($rows)],
                ])->save();

                return;
            }

            if (! $summary['can_commit']) {
                throw new ApiException('import_not_ready', $summary['messages'][0] ?? 'Fix the errors in the file first.', 422);
            }

            $created = $this->create($batch, $actor, $tenant ?? throw new ApiException('tenant_required', 'No school.', 500), $checked);
            $batch->forceFill(['status' => 'committed', 'result' => ['created' => $created], 'progress' => ['done' => count($rows), 'total' => count($rows)]])->save();
        } catch (Throwable $e) {
            $batch->forceFill([
                'status' => 'failed',
                'error' => $e instanceof ApiException || $e instanceof \RuntimeException ? $e->getMessage() : 'The file could not be read. Use the template and save it as .xlsx or .csv.',
            ])->save();

            if (! $e instanceof ApiException && ! $e instanceof \RuntimeException && ! $e instanceof IOException) {
                report($e);
            }
        }
    }

    /**
     * @param  list<array{row: int, status: string, label: string, messages: list<string>, payload?: array<string, mixed>}>  $checked
     */
    private function create(ImportBatch $batch, User $actor, Tenant $tenant, array $checked): int
    {
        $batchId = (string) $batch->getKey();
        $total = count($checked);
        $created = 0;

        AuditRecorder::withMeta(['import_batch_id' => $batchId], function () use ($batch, $actor, $tenant, $checked, $batchId, $total, &$created): void {
            foreach ($checked as $i => $row) {
                if ($row['status'] === 'ok' && isset($row['payload'])) {
                    $payload = array_merge($row['payload'], ['import_batch_id' => $batchId]);

                    if ($batch->entity === 'students') {
                        $this->students->admit($actor, $payload, checkSeats: false);
                    } else {
                        $this->staff->create($actor, $tenant, $payload);
                    }

                    $created++;
                }

                if (($i + 1) % self::PROGRESS_EVERY === 0) {
                    $batch->forceFill(['progress' => ['done' => $i + 1, 'total' => $total]])->save();
                }
            }

            $this->audit->record('import.committed', $batch->entity === 'students' ? 'academic' : 'admin', 'import_batches', $batchId, meta: [
                'entity' => $batch->entity, 'created' => $created, 'rows' => $total, 'file_name' => $batch->file_name,
            ]);
        });

        return $created;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{row: int, status: string, label: string, messages: list<string>, payload?: array<string, mixed>}>
     */
    private function checkStudents(array $rows, User $actor): array
    {
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'Set up the academic year first (for example 2026-27).', 409);
        $classes = SchoolClass::query()->where('year_id', (string) $year->getKey())->get()->keyBy(fn (SchoolClass $c) => mb_strtolower($c->grade));
        $sections = Section::query()->where('year_id', (string) $year->getKey())->get();
        $custom = $this->customColumns($actor, 'students')->keyBy('key');
        $existingAdmissions = array_flip(Student::query()->withTrashed()->pluck('admission_no')->all());
        $seenAdmissions = [];
        $seenPeople = [];
        $checked = [];

        foreach ($rows as $i => $raw) {
            $line = $i + 2;
            $messages = $this->missing($raw, self::STUDENT_COLUMNS);
            $label = trim(($raw['name'] ?? '').' '.($raw['admission_no'] ?? ''));

            if (Aadhaar::findIn($raw) !== []) {
                $messages[] = 'A full Aadhaar number is not allowed in any column. Keep only the last four digits in aadhaar_last4.';
            }

            $dob = $this->date($raw['dob'] ?? '');
            if (($raw['dob'] ?? '') !== '' && $dob === null) {
                $messages[] = 'dob must be a date, YYYY-MM-DD.';
            }

            $gender = mb_strtolower($raw['gender'] ?? '');
            $gender = ['f' => 'female', 'm' => 'male', 'girl' => 'female', 'boy' => 'male'][$gender] ?? $gender;
            if (($raw['gender'] ?? '') !== '' && ! in_array($gender, ['female', 'male', 'other'], true)) {
                $messages[] = 'gender must be female, male or other.';
            }

            $class = $classes[mb_strtolower($raw['class'] ?? '')] ?? null;
            $section = $class instanceof SchoolClass
                ? $sections->first(fn (Section $s) => $s->class_id === (string) $class->getKey() && $s->name === strtoupper($raw['section'] ?? ''))
                : null;
            if (($raw['class'] ?? '') !== '' && $section === null) {
                $messages[] = 'No section '.($raw['class'] ?? '').'-'.strtoupper($raw['section'] ?? '').' in '.$year->name.'.';
            }

            $admission = $raw['admission_no'] ?? '';
            if ($admission !== '' && (isset($existingAdmissions[$admission]) || isset($seenAdmissions[$admission]))) {
                $messages[] = 'admission_no '.$admission.' is already used.';
            }

            $phone = null;
            try {
                $phone = StudentRecords::phone($raw['guardian_phone'] ?? null);
            } catch (ApiException) {
                $messages[] = 'guardian_phone is not a valid phone number.';
            }

            $phone2 = null;
            try {
                $phone2 = StudentRecords::phone($raw['guardian2_phone'] ?? null);
            } catch (ApiException) {
                $messages[] = 'guardian2_phone is not a valid phone number.';
            }

            if (($raw['roll_no'] ?? '') !== '' && ! ctype_digit($raw['roll_no'])) {
                $messages[] = 'roll_no must be a whole number.';
            }

            if (($raw['aadhaar_last4'] ?? '') !== '' && preg_match('/^\d{4}$/', $raw['aadhaar_last4']) !== 1) {
                $messages[] = 'aadhaar_last4 holds only the last four digits.';
            }

            foreach (['pen', 'apaar'] as $kind) {
                if (($raw[$kind] ?? '') !== '' && ! in_array(mb_strtolower($raw['consent_'.$kind] ?? ''), ['yes', 'y', '1', 'true'], true)) {
                    $messages[] = strtoupper($kind).' is kept only with consent: put yes in consent_'.$kind.'.';
                }
            }

            $customValues = [];
            foreach ($raw as $column => $value) {
                if (str_starts_with($column, 'custom:') && $value !== '') {
                    $field = $custom[substr($column, 7)] ?? null;
                    if (! $field instanceof CustomField) {
                        $messages[] = 'Unknown column '.$column.'.';

                        continue;
                    }
                    try {
                        $customValues[$field->key] = $this->fields->normalise($field, $field->type === 'multi_select' ? array_map('trim', explode(',', $value)) : $value);
                    } catch (ApiException $e) {
                        $messages[] = $e->getMessage();
                    }
                }
            }

            $status = $messages === [] ? 'ok' : 'error';

            if ($status === 'ok') {
                $personKey = mb_strtolower(trim($raw['name'])).'|'.$dob.'|'.$phone;
                $duplicate = isset($seenPeople[$personKey]) ? 'Same name, date of birth and guardian phone as row '.$seenPeople[$personKey].'.' : null;
                $existing = $duplicate === null ? $this->students->findDuplicate($raw['name'], (string) $dob, [$phone]) : null;

                if ($existing instanceof Student) {
                    $duplicate = 'Same name, date of birth and guardian phone as '.$existing->name.' ('.$existing->admission_no.') already in the school.';
                }

                if ($duplicate !== null) {
                    $status = 'duplicate';
                    $messages[] = $duplicate;
                }

                $seenPeople[$personKey] ??= $line;
            }

            $seenAdmissions[$admission] = true;

            $entry = ['row' => $line, 'status' => $status, 'label' => $label, 'messages' => $messages];

            if ($status === 'ok' && $section instanceof Section) {
                $guardians = [['name' => $raw['guardian_name'], 'relation' => ($raw['guardian_relation'] ?? '') ?: null, 'phone' => $phone, 'email' => ($raw['guardian_email'] ?? '') ?: null]];
                if (($raw['guardian2_name'] ?? '') !== '') {
                    $guardians[] = ['name' => $raw['guardian2_name'], 'relation' => ($raw['guardian2_relation'] ?? '') ?: null, 'phone' => $phone2];
                }

                $entry['payload'] = [
                    'admission_no' => $admission,
                    'name' => $raw['name'],
                    'dob' => $dob,
                    'gender' => $gender,
                    'section_id' => (string) $section->getKey(),
                    'roll_no' => ($raw['roll_no'] ?? '') !== '' ? (int) $raw['roll_no'] : null,
                    'guardians' => $guardians,
                    'ids' => array_filter(['pen' => $raw['pen'] ?? '', 'apaar' => $raw['apaar'] ?? '', 'aadhaar_last4' => $raw['aadhaar_last4'] ?? ''], fn ($v) => $v !== ''),
                    'consents' => array_filter(['pen' => ($raw['pen'] ?? '') !== '' ? true : null, 'apaar' => ($raw['apaar'] ?? '') !== '' ? true : null], fn ($v) => $v !== null),
                    'custom' => $customValues,
                    'confirm_not_duplicate' => true,
                ];
            }

            $checked[] = $entry;
        }

        return $checked;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{row: int, status: string, label: string, messages: list<string>, payload?: array<string, mixed>}>
     */
    private function checkStaff(array $rows, User $actor): array
    {
        $existingCodes = array_flip(Staff::query()->withTrashed()->pluck('employee_code')->all());
        $existingEmails = array_flip(array_filter(User::query()->pluck('email')->all(), 'is_string'));
        $assignable = [];
        foreach ((array) config('permissions.roles') as $name => $role) {
            $level = $role['level'] ?? null;
            if (is_int($level) && $level >= 30 && $level < $actor->level()) {
                $assignable[] = (string) $name;
            }
        }
        $seen = [];
        $checked = [];

        foreach ($rows as $i => $raw) {
            $line = $i + 2;
            $messages = $this->missing($raw, self::STAFF_COLUMNS);

            if (Aadhaar::findIn($raw) !== []) {
                $messages[] = 'A full Aadhaar number is not allowed in any column.';
            }

            $code = $raw['employee_code'] ?? '';
            if ($code !== '' && (isset($existingCodes[$code]) || isset($seen['code:'.$code]))) {
                $messages[] = 'employee_code '.$code.' is already used.';
            }

            $email = mb_strtolower($raw['email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = 'email is not valid.';
            } elseif ($email !== '' && (isset($existingEmails[$email]) || isset($seen['email:'.$email]))) {
                $messages[] = 'email '.$email.' already has an account.';
            }

            $phone = null;
            try {
                $phone = StudentRecords::phone($raw['phone'] ?? null);
            } catch (ApiException) {
                $messages[] = 'phone is not a valid phone number.';
            }

            $type = mb_strtolower($raw['type'] ?? '') ?: 'teaching';
            if (! in_array($type, ['teaching', 'non_teaching'], true)) {
                $messages[] = 'type must be teaching or non_teaching.';
            }

            $joined = ($raw['joined_on'] ?? '') === '' ? null : $this->date($raw['joined_on']);
            if (($raw['joined_on'] ?? '') !== '' && $joined === null) {
                $messages[] = 'joined_on must be a date, YYYY-MM-DD.';
            }

            $roles = array_values(array_filter(array_map('trim', explode(',', mb_strtolower($raw['roles'] ?? '')))));
            foreach ($roles as $role) {
                if (! in_array($role, $assignable, true)) {
                    $messages[] = 'You cannot give the role '.$role.'.';
                }
            }

            $seen['code:'.$code] = true;
            $seen['email:'.$email] = true;
            $entry = ['row' => $line, 'status' => $messages === [] ? 'ok' : 'error', 'label' => trim(($raw['name'] ?? '').' '.$code), 'messages' => $messages];

            if ($messages === []) {
                $entry['payload'] = [
                    'employee_code' => $code, 'name' => $raw['name'], 'designation' => ($raw['designation'] ?? '') ?: null,
                    'type' => $type, 'email' => $email ?: null, 'phone' => $phone, 'joined_on' => $joined, 'roles' => $roles,
                ];
            }

            $checked[] = $entry;
        }

        return $checked;
    }

    /**
     * @param  list<array{row: int, status: string}>  $checked
     * @return array<string, mixed>
     */
    private function summarise(string $entity, array $checked): array
    {
        $count = fn (string $status) => count(array_filter($checked, fn ($r) => $r['status'] === $status));
        $ok = $count('ok');
        $messages = [];

        // The seat check comes first in the report. Students use a student
        // seat each; staff rows use a staff seat when they get an account
        // (they have roles other than Parent or Student).
        $kind = $entity === 'students' ? 'students' : 'staff';
        $adding = $entity === 'students' ? $ok : count(array_filter($checked, fn ($r) => $r['status'] === 'ok' && Seats::usesStaffSeat($r['payload']['roles'] ?? [])));
        $seats = $this->seats->status($kind);
        $seats['kind'] = $kind;
        $seats['adding'] = $adding;
        $seats['over'] = max($adding - $seats['available'], 0);

        if ($seats['over'] > 0) {
            $messages[] = Seats::message($kind, $seats, $adding);
        }

        if ($count('error') > 0) {
            $messages[] = $count('error').' rows have errors and will be skipped. Fix them and upload again to include them.';
        }

        if ($count('duplicate') > 0) {
            $messages[] = $count('duplicate').' rows look like students already in the school and will be skipped.';
        }

        return [
            'rows' => count($checked),
            'ok' => $ok,
            'errors' => $count('error'),
            'duplicates' => $count('duplicate'),
            'seats' => $seats,
            'can_commit' => $ok > 0 && $seats['over'] === 0,
            'messages' => $messages,
        ];
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function customColumns(User $actor, string $entity): Collection
    {
        return $this->fields->visibleFields($actor, [$entity === 'students' ? 'student' : 'staff'])
            ->filter(fn (CustomField $f) => in_array($f->scope['level'], ['school', 'wing'], true) && $this->fields->canFill($actor, $f))
            ->values();
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, bool>  $columns
     * @return list<string>
     */
    private function missing(array $raw, array $columns): array
    {
        $messages = [];

        foreach ($columns as $column => $required) {
            if ($required && trim($raw[$column] ?? '') === '') {
                $messages[] = $column.' is required.';
            }
        }

        return $messages;
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }
}
