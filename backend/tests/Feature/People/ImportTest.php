<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\LicenceIssuer;
use App\Modules\Licensing\Models\Licence;
use App\Modules\People\Domain\Import\Importer;
use App\Modules\People\Domain\Import\Spreadsheet;
use App\Modules\People\Models\ImportBatch;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 2 item 3 and rules 6 and 7: template, queued dry run with progress,
| downloadable report, commit audited under one batch id, seat check first.
| The queue runs synchronously in tests.
*/

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    $this->school = $this->activeSchool('IMPORT-BPL', ['students' => 600]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $this->s7 = $this->makeClass($this->tenant, '7', ['A', 'B']);
    $this->makeClass($this->tenant, '8', ['A']);
});

const STUDENT_HEADERS = ['admission_no (required)', 'name (required)', 'dob (required)', 'gender (required)', 'class (required)', 'section (required)', 'roll_no', 'guardian_name (required)', 'guardian_relation', 'guardian_phone (required)'];

function studentFile(array $rows, array $headers = STUDENT_HEADERS): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    Spreadsheet::write($path, $headers, $rows);

    return new UploadedFile($path, 'students.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function studentRows(int $count, int $start = 1): array
{
    $rows = [];
    for ($i = $start; $i < $start + $count; $i++) {
        $rows[] = ['SPS/2026/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'Made Up Student '.$i, '2014-'.str_pad((string) (($i % 12) + 1), 2, '0', STR_PAD_LEFT).'-15',
            $i % 2 ? 'female' : 'male', $i % 3 ? '7' : '8', $i % 3 ? ($i % 2 ? 'A' : 'B') : 'A', (string) $i, 'Made Up Parent '.$i, 'mother', '98'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT)];
    }

    return $rows;
}

function upload(object $test, UploadedFile $file, string $entity = 'students'): array
{
    return $test->actingWithToken($test->chief)->post('/api/v1/imports/'.$entity, ['file' => $file], ['Accept' => 'application/json'])->assertStatus(202)->json('data');
}

it('downloads a template with the columns and the school-wide custom fields', function () {
    $this->actingWithToken($this->chief)->postJson('/api/v1/custom-fields', ['entity' => 'student', 'label' => ['en' => 'House'], 'type' => 'text', 'scope' => ['level' => 'school']])->assertCreated();

    $headers = $this->inSchool($this->tenant, fn () => app(Importer::class)->template(User::query()->findOrFail($this->chief->getKey()), 'students')['headers']);
    expect($headers)->toContain('admission_no (required)', 'guardian_phone (required)', 'aadhaar_last4', 'custom:house (House)');

    $this->actingWithToken($this->chief)->get('/api/v1/imports/students/template')->assertOk()->assertDownload('campus-tab-students-template.xlsx');
});

it('imports 500 students: a queued dry run with progress and a report, then a commit under one batch id', function () {
    $batch = upload($this, studentFile(studentRows(500)));
    $id = $batch['id'];

    $checked = $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'validated')
        ->assertJsonPath('data.summary.ok', 500)
        ->assertJsonPath('data.summary.seats.over', 0)
        ->assertJsonPath('data.summary.can_commit', true)
        ->assertJsonPath('data.progress.done', 500)
        ->json('data');
    expect($checked['rows'])->toHaveCount(500);

    $this->actingWithToken($this->chief)->get("/api/v1/imports/{$id}/report")->assertOk()->assertDownload('campus-tab-students-import-report.xlsx');

    $this->actingWithToken($this->chief)->postJson("/api/v1/imports/{$id}/commit")->assertStatus(202);
    $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->assertJsonPath('data.status', 'committed')->assertJsonPath('data.result.created', 500);

    $this->inSchool($this->tenant, function () use ($id) {
        expect(Student::query()->count())->toBe(500)
            ->and(Student::query()->where('import_batch_id', $id)->count())->toBe(500)
            ->and(AuditLog::query()->where('entity', 'students')->where('action', 'created')->where('meta.import_batch_id', $id)->count())->toBe(500)
            ->and(AuditLog::query()->where('entity', 'enrolments')->where('meta.import_batch_id', $id)->count())->toBe(500)
            ->and(AuditLog::query()->where('action', 'import.committed')->where('entity_id', $id)->count())->toBe(1);
    });

    // A batch is committed once.
    $this->actingWithToken($this->chief)->postJson("/api/v1/imports/{$id}/commit")->assertStatus(409);
});

it('reports errors and duplicates row by row, and skips them on commit', function () {
    $this->enrol($this->tenant, $this->s7['A'], 'Existing Child', '+919800000777', ['dob' => '2013-02-02']);
    $rows = studentRows(3);
    $rows[] = ['SPS/2026/0001', 'Repeated Number', '2014-01-01', 'female', '7', 'A', '', 'P', '', '9800000001'];
    $rows[] = ['SPS/2026/9999', 'Bad Date', '31-31-2014', 'x', '9', 'Z', 'abc', 'P', '', '12'];
    $rows[] = ['SPS/2026/9998', 'Existing Child', '2013-02-02', 'female', '7', 'A', '', 'P', '', '9800000777'];
    $rows[] = ['SPS/2026/9997', 'Made Up Student 1', '2014-02-15', 'female', '7', 'A', '', 'P', '', '9810000001'];

    $id = upload($this, studentFile($rows))['id'];
    $data = $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->json('data');

    expect($data['summary'])->toMatchArray(['rows' => 7, 'ok' => 3, 'errors' => 2, 'duplicates' => 2, 'can_commit' => true]);
    $bad = collect($data['rows'])->firstWhere('label', 'Bad Date SPS/2026/9999');
    expect(implode(' ', $bad['messages']))->toContain('dob must be a date')->toContain('gender must be')->toContain('No section 9-Z')->toContain('roll_no must be')->toContain('guardian_phone');

    $this->actingWithToken($this->chief)->postJson("/api/v1/imports/{$id}/commit")->assertStatus(202);
    $this->inSchool($this->tenant, fn () => expect(Student::query()->count())->toBe(4));
});

it('shows the seat check first and refuses to commit past the licence seats', function () {
    $this->inSchool($this->tenant, fn () => null);
    $licence = Licence::currentFor($this->tenant->id);
    app(LicenceIssuer::class)->amend($licence, ['students' => 10]);

    $id = upload($this, studentFile(studentRows(12)))['id'];
    $data = $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->json('data');

    expect($data['summary']['can_commit'])->toBeFalse()
        ->and($data['summary']['seats'])->toMatchArray(['limit' => 10, 'used' => 0, 'adding' => 12, 'over' => 2])
        ->and($data['summary']['messages'][0])->toBe('The licence allows 10 students and 0 are enrolled, so 10 more can be added, not 12. Ask the Chief Admin to contact Campus TAB to add seats.');

    $this->actingWithToken($this->chief)->postJson("/api/v1/imports/{$id}/commit")->assertStatus(422)->assertJsonPath('error.code', 'import_not_ready');
    $this->inSchool($this->tenant, fn () => expect(Student::query()->count())->toBe(0));
});

it('refuses a full Aadhaar number in any cell', function () {
    $rows = studentRows(1);
    $rows[0][7] = 'Parent '.self::fakeAadhaar();

    $id = upload($this, studentFile($rows))['id'];
    $row = $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->json('data.rows.0');

    expect($row['status'])->toBe('error')->and(implode(' ', $row['messages']))->toContain('full Aadhaar number');
});

it('imports staff with accounts, checking roles against the importer', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    Spreadsheet::write($path, ['employee_code', 'name (required)', 'designation', 'type', 'email', 'phone', 'joined_on', 'roles'], [
        ['EMP-0101', 'Rakesh Sharma', 'TGT Science', 'teaching', 'rakesh@import-bpl.test', '', '2026-04-01', 'subject_teacher'],
        ['EMP-0102', 'Wants Chief', '', 'teaching', 'wants@import-bpl.test', '', '', 'chief'],
    ]);

    $id = upload($this, new UploadedFile($path, 'staff.xlsx', null, null, true), 'staff')['id'];
    $data = $this->actingWithToken($this->chief)->getJson("/api/v1/imports/{$id}")->json('data');
    expect($data['summary']['ok'])->toBe(1)->and(implode(' ', $data['rows'][1]['messages']))->toContain('cannot give the role chief');

    $this->actingWithToken($this->chief)->postJson("/api/v1/imports/{$id}/commit")->assertStatus(202);
    $this->inSchool($this->tenant, fn () => expect(Staff::query()->where('employee_code', 'EMP-0101')->firstOrFail()->user_id)->not->toBeNull());
});

it('lets only the right roles import', function () {
    $clerk = $this->staffMember($this->tenant, Role::Clerk);
    $teacher = $this->staffMember($this->tenant, Role::ClassTeacher);

    $this->actingWithToken($clerk['user'])->get('/api/v1/imports/students/template')->assertOk();
    $this->actingWithToken($clerk['user'])->getJson('/api/v1/imports/staff/template')->assertForbidden();
    $this->actingWithToken($teacher['user'])->getJson('/api/v1/imports/students/template')->assertForbidden();
    expect(ImportBatch::class)->toBeString();
});
