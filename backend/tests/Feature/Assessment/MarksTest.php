<?php

use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\SchoolRole;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\Tenancy\Domain\SchoolConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 6: tests and marks. The "done when" items: two people editing one
| paper get a conflict, a locked paper refuses edits, a post-lock change
| without the Principal's approval is refused, and every changed mark shows
| old and new values in the audit log. Plus the owner's six rules.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 10:00', 'Asia/Kolkata'));
    $this->school = $this->activeSchool('MARKS-BPL', ['modules' => ['attendance', 'assessment']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $sections = $this->makeClass($this->tenant, '8', ['A', 'B']);
    $this->section = $sections['A'];
    $this->other = $sections['B'];
    $this->science = $this->makeSubject($this->tenant, 'SCI', 'Science');

    $this->teacher = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $this->otherTeacher = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $this->classTeacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->clerk = $this->staffMember($this->tenant, Role::Clerk);
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->assignTeacher($this->tenant, $this->section, $this->science, $this->teacher['staff']);
    $this->assignTeacher($this->tenant, $this->other, $this->science, $this->otherTeacher['staff']);
    $this->makeClassTeacher($this->tenant, $this->section, $this->classTeacher['staff']);

    $this->a = $this->enrol($this->tenant, $this->section, 'Aditya Kulkarni', '+919811100011');
    $this->b = $this->enrol($this->tenant, $this->section, 'Ananya Bose');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function exam(object $test, array $overrides = []): array
{
    $assessment = $test->actingWithToken($test->clerk['user'])->postJson('/api/v1/assessments', array_merge([
        'kind' => 'exam', 'type' => 'periodic', 'name' => 'Unit Test 1', 'term' => 'Term 1', 'from' => '2026-12-01', 'to' => '2026-12-05',
        'papers' => [['section_id' => (string) $test->section->getKey(), 'subject_id' => (string) $test->science->getKey(), 'max_marks' => 40]],
    ], $overrides))->assertCreated();

    $paper = $assessment->json('data.papers.0.id');
    $test->actingWithToken($test->clerk['user'])->postJson("/api/v1/papers/{$paper}/open")->assertOk()->assertJsonPath('data.status', 'open');

    return [$assessment->json('data.id'), $paper];
}

function saveMarks(object $test, mixed $who, string $paper, int $version, array $marks): TestResponse
{
    return $test->actingWithToken(is_array($who) ? $who['user'] : $who)->withHeader('If-Match', '"v'.$version.'"')->putJson("/api/v1/papers/{$paper}/marks", ['marks' => $marks]);
}

function paperRecord(object $test, string $id): Paper
{
    return $test->inSchool($test->tenant, fn () => Paper::query()->findOrFail($id));
}

/** Enters both marks, submits, verifies and locks. */
function lockedPaper(object $test): string
{
    [, $paper] = exam($test);
    $v = paperRecord($test, $paper)->version;
    saveMarks($test, $test->teacher, $paper, $v, [(string) $test->a->getKey() => ['value' => 32], (string) $test->b->getKey() => ['code' => 'AB']])->assertOk();
    $test->actingWithToken($test->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertOk();
    $test->actingWithToken($test->classTeacher['user'])->postJson("/api/v1/papers/{$paper}/verify")->assertOk();
    $test->actingWithToken($test->clerk['user'])->postJson("/api/v1/papers/{$paper}/lock")->assertOk()->assertJsonPath('data.status', 'locked');

    return $paper;
}

it('gives the second of two people editing the same paper a conflict, not an overwrite', function () {
    [, $paper] = exam($this);
    $loaded = $this->actingWithToken($this->teacher['user'])->getJson("/api/v1/papers/{$paper}")->assertOk()->assertHeader('ETag', '"v2"');
    $version = $loaded->json('data.version');

    // Both loaded version 2. The teacher saves first.
    saveMarks($this, $this->teacher, $paper, $version, [(string) $this->a->getKey() => ['value' => 30]])->assertOk()->assertJsonPath('data.version', $version + 1);
    // The Clerk, still on version 2, is refused and the teacher's mark stands.
    saveMarks($this, $this->clerk, $paper, $version, [(string) $this->a->getKey() => ['value' => 12]])
        ->assertStatus(412)->assertJsonPath('error.code', 'paper_conflict')->assertJsonPath('error.details.version', $version + 1);

    expect(paperRecord($this, $paper)->marksByStudent()[(string) $this->a->getKey()]['value'])->toEqual(30.0);

    // Without If-Match nothing is saved.
    $this->flushHeaders()->actingWithToken($this->clerk['user'])->putJson("/api/v1/papers/{$paper}/marks", ['marks' => [(string) $this->a->getKey() => ['value' => 12]]])->assertStatus(428);
});

it('checks every mark: 0 to max in half steps, AB or ML, never above the maximum', function () {
    [, $paper] = exam($this);
    $id = (string) $this->a->getKey();

    saveMarks($this, $this->teacher, $paper, 2, [$id => ['value' => 43]])->assertUnprocessable()->assertJsonPath('error.code', 'mark_above_max');
    saveMarks($this, $this->teacher, $paper, 2, [$id => ['value' => 'X']])->assertUnprocessable();
    saveMarks($this, $this->teacher, $paper, 2, [$id => ['value' => 12.3]])->assertUnprocessable();
    saveMarks($this, $this->teacher, $paper, 2, [$id => ['value' => 'ml']])->assertOk()->assertJsonPath('data.rows.0.code', 'ML');
});

it('records every changed mark in the audit log with its old and new value', function () {
    [, $paper] = exam($this);
    $id = (string) $this->a->getKey();
    saveMarks($this, $this->teacher, $paper, 2, [$id => ['value' => 30]])->assertOk();
    saveMarks($this, $this->teacher, $paper, 3, [$id => ['value' => 34.5]])->assertOk();

    $entries = $this->inSchool($this->tenant, fn () => AuditLog::query()->where('action', 'marks.changed')->where('entity_id', $paper)->orderBy('at')->get());
    expect($entries)->toHaveCount(2)
        ->and($entries[1]->before['value'])->toEqual(30.0)
        ->and($entries[1]->after['value'])->toEqual(34.5)
        ->and($entries[1]->meta['student_id'])->toBe($id);
});

it('runs the lifecycle in order, each move checked, and refuses it out of order', function () {
    [, $paper] = exam($this);

    // Not every student has a mark yet.
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertUnprocessable()->assertJsonPath('error.code', 'marks_incomplete');
    saveMarks($this, $this->teacher, $paper, 2, [(string) $this->a->getKey() => ['value' => 30], (string) $this->b->getKey() => ['value' => 22]])->assertOk();

    // Another section's teacher cannot even see it; the Clerk cannot verify; nobody locks before verification.
    $this->actingWithToken($this->otherTeacher['user'])->getJson("/api/v1/papers/{$paper}")->assertNotFound();
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/papers/{$paper}/lock")->assertStatus(409);

    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    saveMarks($this, $this->teacher, $paper, 3, [(string) $this->a->getKey() => ['value' => 31]])->assertStatus(409)->assertJsonPath('error.code', 'paper_not_open');

    // The class teacher sends it back with a note, the teacher fixes and resubmits.
    $this->actingWithToken($this->classTeacher['user'])->postJson("/api/v1/papers/{$paper}/send-back", ['note' => 'Recheck roll 2'])->assertOk()->assertJsonPath('data.status', 'open');
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertOk();
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/papers/{$paper}/verify")->assertForbidden();
    $this->actingWithToken($this->classTeacher['user'])->postJson("/api/v1/papers/{$paper}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$paper}/lock")->assertForbidden();
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/papers/{$paper}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/papers/{$paper}/publish")->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/papers/{$paper}/publish")->assertOk()->assertJsonPath('data.published', true);
});

it('refuses every edit to a locked paper', function () {
    $paper = lockedPaper($this);
    $v = paperRecord($this, $paper)->version;

    foreach ([$this->teacher, $this->clerk, $this->principal] as $who) {
        saveMarks($this, $who, $paper, $v, [(string) $this->a->getKey() => ['value' => 40]])->assertStatus(409)->assertJsonPath('error.code', 'marks_locked');
    }
    $this->actingWithToken($this->classTeacher['user'])->postJson("/api/v1/papers/{$paper}/send-back", ['note' => 'Reopen please'])->assertStatus(409);
    saveMarks($this, $this->chief, $paper, $v, [(string) $this->a->getKey() => ['value' => 40]])->assertStatus(409);

    expect(paperRecord($this, $paper)->marksByStudent()[(string) $this->a->getKey()]['value'])->toEqual(32.0);
});

it('changes a locked mark only when the Principal approves, with MFA and a typed reason, and logs old and new', function () {
    $paper = lockedPaper($this);
    $student = (string) $this->a->getKey();

    $id = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/corrections', [
        'paper_id' => $paper, 'student_id' => $student, 'value' => 36, 'reason' => 'Page 3 was not totalled',
    ])->assertCreated()->assertJsonPath('data.old.value', 32)->assertJsonPath('data.new.value', 36)->json('data.id');

    // Still 32 while it waits.
    expect(paperRecord($this, $paper)->marksByStudent()[$student]['value'])->toEqual(32.0);

    // The Clerk, the teacher and the Chief can only request.
    foreach ([$this->clerk, $this->teacher] as $who) {
        $this->actingWithToken($who['user'], steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Looks right to me'])->assertForbidden();
    }
    $this->actingWithToken($this->chief, steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Looks right to me'])->assertForbidden();

    // The Principal: step-up first, then a typed reason.
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Checked the script'])->assertForbidden()->assertJsonPath('error.code', 'step_up_required');
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'ok'])->assertUnprocessable()->assertJsonPath('error.code', 'reason_required');
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Checked the answer script myself'])
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by', $this->principal['user']->name)->assertJsonPath('data.self_approved', false);

    expect(paperRecord($this, $paper)->marksByStudent()[$student]['value'])->toEqual(36.0);

    $log = $this->inSchool($this->tenant, fn () => AuditLog::query()->where('action', 'marks.correction_applied')->firstOrFail());
    expect($log->before['value'])->toEqual(32.0)->and($log->after['value'])->toEqual(36.0)
        ->and($log->meta)->toMatchArray(['reason' => 'Page 3 was not totalled', 'requested_by' => $this->teacher['user']->name, 'approved_by' => $this->principal['user']->name, 'approval_reason' => 'Checked the answer script myself']);

    // Decided once only.
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Checked the answer script myself'])->assertStatus(409);
});

it('makes the Principal\'s own correction a separate MFA step, flagged self-approved and shown to the Chief', function () {
    $paper = lockedPaper($this);

    $id = $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson('/api/v1/corrections', [
        'paper_id' => $paper, 'student_id' => (string) $this->a->getKey(), 'value' => 35, 'reason' => 'Re-totalled at the office',
    ])->assertCreated()->json('data.id');

    // The code entered before raising it does not count.
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Confirmed against the script'])
        ->assertForbidden()->assertJsonPath('error.code', 'step_up_required')->assertJsonPath('error.details.self', true);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 10:02', 'Asia/Kolkata'));
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/corrections/{$id}/approve", ['reason' => 'Confirmed against the script'])
        ->assertOk()->assertJsonPath('data.self_approved', true);

    $this->actingWithToken($this->chief)->getJson('/api/v1/corrections?self_approved=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.self_approved', true);
    $cards = collect($this->actingWithToken($this->chief)->getJson('/api/v1/me/dashboard')->json('data.cards'));
    expect($cards->pluck('id'))->toContain('self_approved_marks');
    $told = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $this->chief->getKey())->where('type', 'marks.correction')->count());
    expect($told)->toBe(1);
});

it('shows parents and students only published marks, and tells them only that results are out', function () {
    $guardian = $this->guardianOf($this->tenant, $this->a);
    $parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $guardian));
    $paper = lockedPaper($this);
    $student = (string) $this->a->getKey();

    $this->actingWithToken($parent)->getJson("/api/v1/results/students/{$student}")->assertOk()->assertJsonCount(0, 'data.results');
    $this->actingWithToken($parent)->getJson("/api/v1/papers/{$paper}")->assertForbidden();
    $this->actingWithToken($parent)->getJson('/api/v1/results/students/'.$this->b->getKey())->assertNotFound();

    [$assessment] = [paperRecord($this, $paper)->assessment_id];
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/assessments/{$assessment}/publish")->assertOk();

    $this->actingWithToken($parent)->getJson("/api/v1/results/students/{$student}")->assertOk()
        ->assertJsonPath('data.results.0.subject', 'Science')->assertJsonPath('data.results.0.value', 32);

    $note = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $parent->getKey())->where('type', 'results.published')->firstOrFail());
    // Only the words a family reads: the record's ids are random hex and may contain "32" by chance.
    $text = json_encode([$note->title, $note->body, $note->data]);
    expect($note->title)->toBe('Results are out')->and($text)->not->toContain('32')->not->toContain('Science');
});

it('locks and publishes every eligible paper of an assessment together', function () {
    $assessment = $this->actingWithToken($this->clerk['user'])->postJson('/api/v1/assessments', [
        'kind' => 'exam', 'type' => 'half_yearly', 'name' => 'Half-yearly', 'from' => '2026-12-01', 'to' => '2026-12-10',
        'papers' => [
            ['section_id' => (string) $this->section->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 80],
            ['section_id' => (string) $this->other->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 80],
        ],
    ])->assertCreated()->json('data.id');
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/assessments/{$assessment}/open")->assertOk();

    $papers = $this->inSchool($this->tenant, fn () => Paper::query()->where('assessment_id', $assessment)->get()->keyBy('section_id'));
    $p1 = (string) $papers[(string) $this->section->getKey()]->getKey();
    saveMarks($this, $this->teacher, $p1, 2, [(string) $this->a->getKey() => ['value' => 60], (string) $this->b->getKey() => ['value' => 55]])->assertOk();
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$p1}/submit")->assertOk();
    $this->actingWithToken($this->classTeacher['user'])->postJson("/api/v1/papers/{$p1}/verify")->assertOk();

    // Only the verified paper locks; the other (empty section, not submitted) stays open.
    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/assessments/{$assessment}/lock")->assertOk();
    expect(paperRecord($this, $p1)->status)->toBe('locked')
        ->and(paperRecord($this, (string) $papers[(string) $this->other->getKey()]->getKey())->status)->toBe('open');

    // The completion grid shows both sections for the Principal.
    $grid = $this->actingWithToken($this->principal['user'])->getJson("/api/v1/assessments/{$assessment}/completion")->assertOk();
    expect($grid->json('data.subjects'))->toBe(['Science'])->and($grid->json('data.sections'))->toHaveCount(2);
    // The class teacher sees only their section.
    $this->actingWithToken($this->classTeacher['user'])->getJson("/api/v1/assessments/{$assessment}/completion")->assertOk()->assertJsonCount(1, 'data.sections');
});

it('lets a subject teacher set class tests only for their own subject-sections', function () {
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/assessments', [
        'kind' => 'class_test', 'type' => 'weekly', 'name' => 'Quiz 3', 'from' => '2026-12-02', 'to' => '2026-12-02',
        'papers' => [['section_id' => (string) $this->other->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 10]],
    ])->assertForbidden();

    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/assessments', [
        'kind' => 'class_test', 'type' => 'weekly', 'name' => 'Quiz 3', 'from' => '2026-12-02', 'to' => '2026-12-02',
        'papers' => [['section_id' => (string) $this->section->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 10]],
    ])->assertCreated()->assertJsonPath('data.papers.0.status', 'open');

    // A teacher cannot schedule a school exam.
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/assessments', [
        'kind' => 'exam', 'type' => 'annual', 'name' => 'Annual', 'from' => '2027-03-01', 'to' => '2027-03-10',
        'papers' => [['section_id' => (string) $this->section->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 80]],
    ])->assertForbidden();
});

it('publishes an auto-publish class test at submit and keeps it editable until it is locked', function () {
    $paper = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/assessments', [
        'kind' => 'class_test', 'type' => 'weekly', 'name' => 'Quiz 4', 'from' => '2026-12-02', 'to' => '2026-12-02',
        'papers' => [['section_id' => (string) $this->section->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 10]],
    ])->assertCreated()->json('data.papers.0.id');

    saveMarks($this, $this->teacher, $paper, 1, [(string) $this->a->getKey() => ['value' => 8], (string) $this->b->getKey() => ['value' => 9]])->assertOk();
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertOk()->assertJsonPath('data.published', true);

    // Still editable (owner's decision), and audited.
    saveMarks($this, $this->teacher, $paper, paperRecord($this, $paper)->version, [(string) $this->a->getKey() => ['value' => 7.5]])->assertOk();
    $this->actingWithToken($this->classTeacher['user'])->postJson("/api/v1/papers/{$paper}/verify")->assertOk();
    $this->actingWithToken($this->clerk['user'])->postJson("/api/v1/papers/{$paper}/lock")->assertOk();
    saveMarks($this, $this->teacher, $paper, paperRecord($this, $paper)->version, [(string) $this->a->getKey() => ['value' => 9]])->assertStatus(409);
});

it('tags a mark that changed after an auto-published class test was published, so families see it was updated', function () {
    $guardian = $this->guardianOf($this->tenant, $this->a);
    $parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $guardian));
    $paper = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/assessments', [
        'kind' => 'class_test', 'type' => 'weekly', 'name' => 'Quiz 5', 'from' => '2026-12-02', 'to' => '2026-12-02',
        'papers' => [['section_id' => (string) $this->section->getKey(), 'subject_id' => (string) $this->science->getKey(), 'max_marks' => 10]],
    ])->assertCreated()->json('data.papers.0.id');
    $a = (string) $this->a->getKey();
    $b = (string) $this->b->getKey();

    // Changes before publishing carry no tag.
    saveMarks($this, $this->teacher, $paper, 1, [$a => ['value' => 6], $b => ['value' => 9]])->assertOk();
    saveMarks($this, $this->teacher, $paper, 2, [$a => ['value' => 8]])->assertOk();
    $this->actingWithToken($this->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertOk()->assertJsonPath('data.published', true);
    $this->actingWithToken($parent)->getJson("/api/v1/results/students/{$a}")->assertOk()
        ->assertJsonPath('data.results.0.value', 8)->assertJsonPath('data.results.0.updated_at', null);

    // After publishing, a changed mark is tagged; a remark-only change is not a new mark.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-03 11:00', 'Asia/Kolkata'));
    saveMarks($this, $this->teacher, $paper, paperRecord($this, $paper)->version, [$a => ['value' => 7.5], $b => ['value' => 9, 'remark' => 'Neat work']])->assertOk();
    $this->actingWithToken($parent)->getJson("/api/v1/results/students/{$a}")->assertOk()
        ->assertJsonPath('data.results.0.value', 7.5)->assertJsonPath('data.results.0.updated_at', fn ($at) => is_string($at) && str_starts_with($at, '2026-12-03'));
    $marks = paperRecord($this, $paper)->marksByStudent();
    expect($marks[$b])->not->toHaveKey('updated_at');
});

it('lets a class teacher type marks only when the school switches it on', function () {
    [, $paper] = exam($this);
    saveMarks($this, $this->classTeacher, $paper, 2, [(string) $this->a->getKey() => ['value' => 20]])->assertForbidden();

    $body = $this->configBody($this->tenant);
    $body['policies']['marks']['class_teacher_can_enter'] = true;
    $this->inSchool($this->tenant, fn () => app(SchoolConfigService::class)->save($this->chief, $body, app(SchoolConfigService::class)->current()->version, 'Class teachers enter marks'));

    saveMarks($this, $this->classTeacher, $paper, 2, [(string) $this->a->getKey() => ['value' => 20]])->assertOk();
});

it('offers board presets only as starting points the Principal can change', function () {
    $presets = $this->actingWithToken($this->principal['user'])->getJson('/api/v1/assessment-schemes/presets')->assertOk()->json('data');
    expect(collect($presets)->pluck('note')->unique()->all())->toBe(["A starting point only. Check against your board's latest circular and change anything that differs."]);

    $id = $this->actingWithToken($this->principal['user'])->postJson('/api/v1/assessment-schemes', ['name' => 'Middle school', 'grades' => ['6', '7', '8'], 'preset' => 'cbse_6_8'])
        ->assertCreated()->assertJsonPath('data.preset_note', "A starting point only. Check against your board's latest circular and change anything that differs.")->json('data.id');

    $this->actingWithToken($this->principal['user'])->putJson("/api/v1/assessment-schemes/{$id}", [
        'components' => [['key' => 'tests', 'name' => 'Tests', 'max' => 30, 'weight' => 30], ['key' => 'exam', 'name' => 'Exam', 'max' => 70, 'weight' => 70]],
        'grade_scale' => [['grade' => 'A', 'min' => 75], ['grade' => 'B', 'min' => 50], ['grade' => 'C', 'min' => 0]],
        'rules' => ['pass_percent' => 40],
        'terms' => ['Semester 1', 'Semester 2'],
    ])->assertOk()->assertJsonPath('data.rules.pass_percent', 40)->assertJsonPath('data.terms.0', 'Semester 1');

    $this->actingWithToken($this->principal['user'])->putJson("/api/v1/assessment-schemes/{$id}", [
        'components' => [['key' => 'a', 'name' => 'A', 'max' => 10, 'weight' => 60]],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'weights_total');
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/assessment-schemes', ['name' => 'Mine', 'grades' => ['8']])->assertForbidden();
});

it('retires marks.unlock: no ability, no step-up action, and sync-roles takes it out of stored roles', function () {
    expect(config('permissions.abilities'))->not->toHaveKey('marks.unlock')
        ->and(config('identity.step_up.actions'))->not->toContain('marks_unlock')->toContain('marks_correction_approve');

    $this->inSchool($this->tenant, fn () => SchoolRole::query()->where('name', 'principal')->firstOrFail()
        ->forceFill(['permissions' => [...SchoolRole::query()->where('name', 'principal')->firstOrFail()->permissions, 'marks.unlock:school']])->save());

    $this->artisan('identity:sync-roles', ['code' => 'MARKS-BPL', '--with-new-permissions' => true])->assertSuccessful();

    $perms = $this->inSchool($this->tenant, fn () => SchoolRole::query()->where('name', 'principal')->firstOrFail()->permissions);
    expect($perms)->not->toContain('marks.unlock:school')->toContain('marks.correction_approve:school');
});

it('lists pending corrections in the Principal\'s approvals inbox', function () {
    $paper = lockedPaper($this);
    $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/corrections', [
        'paper_id' => $paper, 'student_id' => (string) $this->a->getKey(), 'value' => 33, 'reason' => 'One mark missed on Q4',
    ])->assertCreated();

    $this->actingWithToken($this->principal['user'])->getJson('/api/v1/approvals/inbox')->assertOk()->assertJsonPath('counts.marks', 1)->assertJsonPath('data.0.kind', 'marks');
    expect(CorrectionRequest::class)->toBeString();
});
