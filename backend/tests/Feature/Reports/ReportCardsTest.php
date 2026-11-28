<?php

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Communication\Models\Notification;
use App\Modules\Identity\Domain\Role;
use App\Modules\People\Domain\ParentLogins;
use App\Modules\People\Models\Enrolment;
use App\Modules\Reports\Models\ReportCard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchoolRecords;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class, BuildsSchoolRecords::class);

/*
| Phase 7 "done when": 45 report cards generate in the background, every QR
| verifies, and a re-issued card keeps its old version. Plus the owner's
| rules: locked papers only (2), a verification page with identity facts
| only and an unguessable code (3), PDFs in the school's folder behind
| short-lived signed links and a permission check, parents see only their
| own children (4), re-issue needs the Principal's reason and tells the
| family only that a new version exists (5), and the promotion wizard
| previews, applies in a transaction, is audited and leaves the old year
| read-only (6).
*/

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-10 10:00', 'Asia/Kolkata'));
    Storage::fake('local');
    $this->school = $this->activeSchool('CARDS-BPL', ['modules' => ['attendance', 'assessment', 'reports']]);
    $this->tenant = $this->school['tenant'];
    $this->chief = $this->school['chief'];
    $sections = $this->makeClass($this->tenant, '9', ['A', 'B']);
    $this->section = $sections['A'];
    $this->other = $sections['B'];
    $this->maths = $this->makeSubject($this->tenant, 'MAT', 'Mathematics');
    $this->principal = $this->staffMember($this->tenant, Role::Principal);
    $this->clerk = $this->staffMember($this->tenant, Role::Clerk);
    $this->classTeacher = $this->staffMember($this->tenant, Role::ClassTeacher);
    $this->teacher = $this->staffMember($this->tenant, Role::SubjectTeacher);
    $this->makeClassTeacher($this->tenant, $this->section, $this->classTeacher['staff']);
    $this->assignTeacher($this->tenant, $this->section, $this->maths, $this->teacher['staff']);

    // Five students here; the "45 cards" test adds forty more.
    $this->students = [];
    addStudents($this, 5);
    $this->elsewhere = $this->enrol($this->tenant, $this->other, 'Other Child');

    // The scheme (CBSE-style IX-X: 20 internal + 80 board pattern) and a template.
    $scheme = $this->actingWithToken($this->principal['user'])->postJson('/api/v1/assessment-schemes', ['name' => 'Class 9', 'grades' => ['9'], 'preset' => 'cbse_9_10'])->assertCreated()->json('data.id');
    $this->actingWithToken($this->principal['user'])->putJson("/api/v1/assessment-schemes/{$scheme}", ['status' => 'active'])->assertOk();
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-templates', ['name' => 'Secondary', 'grades' => ['9'], 'preset' => 'cbse_9_10'])->assertCreated();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function addStudents(object $test, int $count): void
{
    $from = count($test->students) + 1;
    for ($i = $from; $i < $from + $count; $i++) {
        $test->students[] = $test->enrol($test->tenant, $test->section, sprintf('Student %02d', $i), $i === 1 ? '+919811100071' : null);
    }
}

/** An exam for one component, every student's mark entered, then locked (or left at $stopAt). */
function examFor(object $test, string $component, float $max, callable $mark, string $stopAt = 'locked'): string
{
    $paper = $test->actingWithToken($test->clerk['user'])->postJson('/api/v1/assessments', [
        'kind' => 'exam', 'type' => $component === 'internal' ? 'internal' : 'annual', 'name' => ucfirst($component), 'term' => 'Year', 'component' => $component,
        'from' => '2027-03-01', 'to' => '2027-03-05',
        'papers' => [['section_id' => (string) $test->section->getKey(), 'subject_id' => (string) $test->maths->getKey(), 'max_marks' => $max]],
    ])->assertCreated()->json('data.papers.0.id');
    $test->actingWithToken($test->clerk['user'])->postJson("/api/v1/papers/{$paper}/open")->assertOk();
    $marks = [];
    foreach ($test->students as $i => $s) {
        $marks[(string) $s->getKey()] = ['value' => $mark($i)];
    }
    $version = $test->inSchool($test->tenant, fn () => Paper::query()->findOrFail($paper)->version);
    $test->actingWithToken($test->teacher['user'])->withHeader('If-Match', '"v'.$version.'"')->putJson("/api/v1/papers/{$paper}/marks", ['marks' => $marks])->assertOk();
    if ($stopAt === 'open') {
        return $paper;
    }
    $test->actingWithToken($test->teacher['user'])->postJson("/api/v1/papers/{$paper}/submit")->assertOk();
    $test->actingWithToken($test->classTeacher['user'])->postJson("/api/v1/papers/{$paper}/verify")->assertOk();
    $test->actingWithToken($test->clerk['user'])->postJson("/api/v1/papers/{$paper}/lock")->assertOk();

    return $paper;
}

/** Generates, reviews and publishes the section's 45 cards. */
function publishAll(object $test): void
{
    $id = (string) $test->section->getKey();
    $test->actingWithToken($test->principal['user'])->postJson("/api/v1/report-cards/sections/{$id}/generate")->assertStatus(202);
    $cards = $test->actingWithToken($test->principal['user'])->getJson("/api/v1/report-cards/sections/{$id}")->assertOk()->json('data.cards');
    foreach ($cards as $card) {
        $test->actingWithToken($test->principal['user'])->postJson("/api/v1/report-cards/{$card['id']}/review")->assertOk();
    }
    $run = $test->actingWithToken($test->principal['user'])->postJson("/api/v1/report-cards/sections/{$id}/publish")->assertStatus(202)->json('data.id');
    $test->actingWithToken($test->principal['user'])->getJson("/api/v1/report-cards/runs/{$run}")->assertOk()->assertJsonPath('data.status', 'done');
}

function verifyCode(ReportCard $card): string
{
    preg_match('#/verify/[A-Z0-9-]+/([A-Za-z0-9_-]{43})#', (string) Storage::disk('local')->get($card->pdf['path']), $m);

    return $m[1] ?? '';
}

it('generates 45 cards in the background from locked papers only, and every QR verifies', function () {
    addStudents($this, 40);
    // 45 lookups from one address in a minute: above the public page's limit, which a later test checks.
    config(['reports.verification.per_minute' => 100]);
    examFor($this, 'internal', 20, fn ($i) => 10 + ($i % 10));
    examFor($this, 'board', 80, fn ($i) => 40 + $i % 40 + 0.5);
    // A paper that is entered but never locked does not reach a card (owner's rule 2).
    examFor($this, 'board', 80, fn () => 80, 'open');

    publishAll($this);

    $cards = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('section_id', (string) $this->section->getKey())->get());
    expect($cards)->toHaveCount(45)->and($cards->every(fn (ReportCard $c) => $c->status === ReportCard::PUBLISHED && $c->pdf !== null))->toBeTrue();

    // Student 01: internal 10, board 40.5 -> 50.5 -> 51 (half up, once): C1. The open paper's 80 is ignored.
    $first = $cards->firstWhere('student_name', 'Student 01');
    expect($first->snapshot['subjects'][0]['percent'])->toBe('51')->and($first->snapshot['subjects'][0]['grade'])->toBe('C1');

    // Every PDF sits in the school's own folder and carries a QR link that verifies.
    foreach ($cards as $card) {
        expect($card->pdf['path'])->toStartWith('tenants/'.$this->tenant->id.'/report-cards/');
        $code = verifyCode($card);
        expect(strlen($code))->toBe(43)->and($card->verify_hash)->toBe(hash('sha256', $code));
        $this->getJson("/api/v1/public/verify/CARDS-BPL/{$code}")->assertOk()->assertJsonPath('data.student', $card->student_name)->assertJsonPath('data.status', 'current');
    }
});

it('shows only identity facts on the verification page, and nothing for a guessed code', function () {
    examFor($this, 'internal', 20, fn () => 15);
    examFor($this, 'board', 80, fn () => 60);
    publishAll($this);
    $card = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('student_name', 'Student 01')->firstOrFail());

    $page = $this->getJson('/api/v1/public/verify/CARDS-BPL/'.verifyCode($card))->assertOk()->json('data');
    expect(array_keys($page))->toBe(['school', 'student', 'class', 'year', 'version', 'issued_on', 'status'])
        ->and($page)->toMatchArray(['student' => 'Student 01', 'class' => '9-A', 'year' => '2026-27', 'version' => 1, 'issued_on' => '2027-03-10', 'status' => 'current'])
        ->and(json_encode($page))->not->toContain('75')->not->toContain('B1');

    $this->getJson('/api/v1/public/verify/CARDS-BPL/'.str_repeat('A', 43))->assertNotFound();
    $this->getJson('/api/v1/public/verify/OTHER-SCH/'.verifyCode($card))->assertNotFound();

    // Rate-limited per address, so codes cannot be tried in bulk.
    config(['reports.verification.per_minute' => 3]);
    for ($i = 0; $i < 3; $i++) {
        $this->getJson('/api/v1/public/verify/CARDS-BPL/'.str_repeat('B', 43));
    }
    $this->getJson('/api/v1/public/verify/CARDS-BPL/'.verifyCode($card))->assertStatus(429);
});

it('gives parents only their own child\'s published card, through a short-lived signed link', function () {
    examFor($this, 'internal', 20, fn () => 15);
    examFor($this, 'board', 80, fn () => 60);
    $guardian = $this->guardianOf($this->tenant, $this->students[0]);
    $parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $guardian));
    $child = (string) $this->students[0]->getKey();

    // Drafts are not shown to families.
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-cards/sections/'.$this->section->getKey().'/generate')->assertStatus(202);
    $this->actingWithToken($parent)->getJson("/api/v1/report-cards/students/{$child}")->assertOk()->assertJsonCount(0, 'data.cards');

    publishAll($this);
    $cards = $this->actingWithToken($parent)->getJson("/api/v1/report-cards/students/{$child}")->assertOk()->assertJsonCount(1, 'data.cards');
    expect($cards->json('data.cards.0.snapshot'))->toHaveKey('overall')->not->toHaveKey('subjects');
    $other = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('student_name', 'Student 02')->firstOrFail());
    $this->actingWithToken($parent)->getJson('/api/v1/report-cards/'.$other->getKey())->assertNotFound();
    $this->actingWithToken($parent)->postJson('/api/v1/report-cards/'.$other->getKey().'/link')->assertNotFound();
    $this->actingWithToken($parent)->getJson('/api/v1/report-cards/students/'.$this->elsewhere->getKey())->assertNotFound();

    $url = $this->actingWithToken($parent)->postJson('/api/v1/report-cards/'.$cards->json('data.cards.0.id').'/link')->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-10 10:06', 'Asia/Kolkata'));
    $this->get($url)->assertForbidden();

    // The family is told only that a card is ready.
    $note = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $parent->getKey())->where('type', 'report_card.published')->firstOrFail());
    expect($note->title)->toBe('A report card is ready')->and($note->body)->toBeNull();
});

it('lets teachers add remarks within their scope, and a change after review sends the card back to draft', function () {
    examFor($this, 'internal', 20, fn () => 15);
    examFor($this, 'board', 80, fn () => 60);
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-cards/sections/'.$this->section->getKey().'/generate')->assertStatus(202);
    $card = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('student_name', 'Student 01')->firstOrFail());
    $id = (string) $card->getKey();
    $maths = (string) $this->maths->getKey();

    // The class teacher gets the co-scholastic areas and grades to choose from; a subject teacher does not.
    $this->actingWithToken($this->classTeacher['user'])->getJson("/api/v1/report-cards/{$id}")->assertOk()
        ->assertJsonPath('data.co_scholastic_options.grades', ['A', 'B', 'C', 'D', 'E'])
        ->assertJsonPath('data.co_scholastic_options.areas.discipline', 'Discipline');
    $this->actingWithToken($this->teacher['user'])->getJson("/api/v1/report-cards/{$id}")->assertOk()->assertJsonPath('data.co_scholastic_options', null);

    $this->actingWithToken($this->classTeacher['user'])->putJson("/api/v1/report-cards/{$id}/remarks", ['class_teacher' => 'Works steadily and helps others.', 'co_scholastic' => ['discipline' => 'A']])->assertOk();
    $this->actingWithToken($this->teacher['user'])->putJson("/api/v1/report-cards/{$id}/remarks", ['subjects' => [$maths => 'Strong in algebra.']])->assertOk()
        ->assertJsonPath('data.remarks.subjects.'.$maths, 'Strong in algebra.');
    // A subject teacher cannot write the class remark.
    $this->actingWithToken($this->teacher['user'])->putJson("/api/v1/report-cards/{$id}/remarks", ['class_teacher' => 'X'])->assertForbidden();

    $this->actingWithToken($this->principal['user'])->postJson("/api/v1/report-cards/{$id}/review")->assertOk()->assertJsonPath('data.status', 'reviewed');
    $this->actingWithToken($this->classTeacher['user'])->putJson("/api/v1/report-cards/{$id}/remarks", ['class_teacher' => 'Works steadily.'])->assertOk()->assertJsonPath('data.status', 'draft');
    // Only the Principal (results.publish) reviews.
    $this->actingWithToken($this->classTeacher['user'])->postJson("/api/v1/report-cards/{$id}/review")->assertForbidden();
});

it('re-issues a published card only with the Principal\'s reason, keeps the old version and tells the family only that a new one exists', function () {
    examFor($this, 'internal', 20, fn () => 15);
    $board = examFor($this, 'board', 80, fn () => 60);
    $guardian = $this->guardianOf($this->tenant, $this->students[0]);
    $parent = $this->inSchool($this->tenant, fn () => app(ParentLogins::class)->enable($this->chief, $guardian));
    publishAll($this);
    $old = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('student_name', 'Student 01')->firstOrFail());
    $oldCode = verifyCode($old);

    // A correction to the locked mark, approved by the Principal, then the re-issue.
    $student = (string) $this->students[0]->getKey();
    $correction = $this->actingWithToken($this->teacher['user'])->postJson('/api/v1/corrections', ['paper_id' => $board, 'student_id' => $student, 'value' => 70, 'reason' => 'Page 4 was not added up'])->assertCreated()->json('data.id');
    $this->actingWithToken($this->principal['user'], steppedUp: true)->postJson("/api/v1/corrections/{$correction}/approve", ['reason' => 'Checked the answer sheet'])->assertOk();

    $this->actingWithToken($this->chief, steppedUp: true)->postJson('/api/v1/report-cards/'.$old->getKey().'/reissue', ['reason' => 'Corrected Mathematics total'])->assertForbidden();
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-cards/'.$old->getKey().'/reissue', ['reason' => 'short'])->assertStatus(422);
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-cards/'.$old->getKey().'/reissue', ['reason' => 'Corrected Mathematics total'])->assertStatus(202);

    [$v1, $v2] = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('student_id', $student)->orderBy('version')->get()->all());
    expect($v1->status)->toBe('replaced')->and($v1->replaced_by_id)->toBe((string) $v2->getKey())
        ->and($v1->snapshot['subjects'][0]['percent'])->toBe('75')
        ->and(Storage::disk('local')->exists($v1->pdf['path']))->toBeTrue()
        ->and($v2->status)->toBe('published')->and($v2->version)->toBe(2)->and($v2->reissue_reason)->toBe('Corrected Mathematics total')
        ->and($v2->snapshot['subjects'][0]['percent'])->toBe('85')
        ->and($v2->pdf['path'])->not->toBe($v1->pdf['path']);

    $this->getJson("/api/v1/public/verify/CARDS-BPL/{$oldCode}")->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'replaced');
    $this->getJson('/api/v1/public/verify/CARDS-BPL/'.verifyCode($v2))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'current');
    $this->actingWithToken($parent)->getJson("/api/v1/report-cards/students/{$student}")->assertOk()->assertJsonCount(2, 'data.cards');
    $this->actingWithToken($this->principal['user'])->getJson('/api/v1/report-cards/'.$v1->getKey())->assertOk()->assertJsonPath('data.replaced_by_id', (string) $v2->getKey());

    $note = $this->inSchool($this->tenant, fn () => Notification::query()->where('user_id', (string) $parent->getKey())->where('type', 'report_card.new_version')->firstOrFail());
    expect($note->title)->toBe('A new version of a report card is available')->and(json_encode($note->toArray()))->not->toContain('Mathematics')->not->toContain('Corrected');

    // Only the current version can be re-issued.
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-cards/'.$v1->getKey().'/reissue', ['reason' => 'Corrected Mathematics total'])->assertStatus(409);
});

it('previews the promotion, applies it in one go, audits it, and leaves the old year read-only', function () {
    examFor($this, 'internal', 20, fn ($i) => $i === 1 ? 0 : 15);
    examFor($this, 'board', 80, fn ($i) => $i === 1 ? 10 : 60);
    publishAll($this);
    $this->makeClass($this->tenant, '10', ['A']);
    $failed = (string) $this->students[1]->getKey();
    $leaving = (string) $this->students[2]->getKey();
    $to = ['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'];

    $preview = $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/preview', ['to' => $to, 'exceptions' => [$leaving => 'leave']])->assertOk()->json('data');
    $rows = collect($preview['sections'])->firstWhere('section', '9-A')['students'];
    expect(collect($rows)->firstWhere('id', $failed))->toMatchArray(['action' => 'detain', 'suggested' => 'detain', 'to_section' => '9-A'])
        ->and(collect($rows)->firstWhere('id', $leaving)['action'])->toBe('leave')
        ->and(collect($rows)->firstWhere('name', 'Student 01'))->toMatchArray(['action' => 'promote', 'to_section' => '10-A'])
        ->and($preview['counts'])->toMatchArray(['promote' => 4, 'detain' => 1, 'leave' => 1])
        // 9-B's child moves up, but Class 10 has only A: 10-B is created as a draft.
        ->and($preview['roll_forward']['new_sections'])->toBe(['10-B']);

    // Nothing changes on preview; a stale digest is refused.
    expect($this->inSchool($this->tenant, fn () => AcademicYear::query()->count()))->toBe(1);
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $to, 'exceptions' => [], 'digest' => $preview['digest']])->assertStatus(409)->assertJsonPath('error.code', 'preview_stale');
    $this->actingWithToken($this->classTeacher['user'])->postJson('/api/v1/promotions/apply', ['to' => $to, 'exceptions' => [$leaving => 'leave'], 'digest' => $preview['digest']])->assertForbidden();

    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $to, 'exceptions' => [$leaving => 'leave'], 'digest' => $preview['digest']])->assertOk()
        ->assertJsonPath('data.to.name', '2027-28');

    $this->inSchool($this->tenant, function () use ($failed, $leaving) {
        $old = AcademicYear::query()->where('name', '2026-27')->firstOrFail();
        $new = AcademicYear::query()->where('name', '2027-28')->firstOrFail();
        expect($old->status)->toBe('closed')->and($new->status)->toBe('current');
        $sections = Section::query()->where('year_id', (string) $new->getKey())->get()->keyBy('label');
        expect($sections->keys()->sort()->values()->all())->toBe(['10-A', '10-B', '9-A', '9-B'])->and($sections['10-A']->draft)->toBeTrue()->and($sections['10-B']->draft)->toBeTrue();
        expect(Enrolment::query()->where('year_id', (string) $new->getKey())->where('student_id', $failed)->firstOrFail()->section_id)->toBe((string) $sections['9-A']->getKey());
        expect(Enrolment::query()->where('year_id', (string) $new->getKey())->where('student_id', $leaving)->exists())->toBeFalse();
        expect(Enrolment::query()->where('year_id', (string) $old->getKey())->where('student_id', $leaving)->firstOrFail()->result)->toBe('left');
        expect(AuditLog::query()->where('action', 'year.promoted')->exists())->toBeTrue();
    });

    // The old year is read-only: its sections and papers refuse changes.
    $this->actingWithToken($this->principal['user'])->patchJson('/api/v1/sections/'.$this->section->getKey(), ['room' => 'R-12'])->assertStatus(409)->assertJsonPath('error.code', 'year_closed');
    $card = $this->inSchool($this->tenant, fn () => ReportCard::query()->where('student_name', 'Student 01')->firstOrFail());
    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/report-cards/'.$card->getKey().'/reissue', ['reason' => 'Trying after the year closed'])->assertStatus(409);
});

it('moves a numbered class to the next number, making a class the school skipped as a draft', function () {
    // The school has Class 9 and Class 11 but no Class 10 yet.
    $this->makeClass($this->tenant, '11', ['A']);
    $to = ['name' => '2027-28', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'];

    $preview = $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/preview', ['to' => $to])->assertOk()->json('data');
    $row = collect($preview['sections'])->firstWhere('section', '9-A');
    expect($row['next_class'])->toBe('10')
        ->and(collect($row['students'])->pluck('to_section')->unique()->all())->toBe(['10-A'])
        ->and($preview['roll_forward']['new_classes'])->toBe(['10'])
        ->and($preview['roll_forward']['new_sections'])->toBe(['10-A', '10-B']);

    $this->actingWithToken($this->principal['user'])->postJson('/api/v1/promotions/apply', ['to' => $to, 'exceptions' => [], 'digest' => $preview['digest']])->assertOk();

    $this->inSchool($this->tenant, function () {
        $new = AcademicYear::query()->where('name', '2027-28')->firstOrFail();
        $sections = Section::query()->where('year_id', (string) $new->getKey())->get()->keyBy('label');
        expect($sections->keys()->sort(SORT_NATURAL)->values()->all())->toBe(['9-A', '9-B', '10-A', '10-B', '11-A'])
            ->and($sections['10-A']->draft)->toBeTrue();
        $moved = Enrolment::query()->where('year_id', (string) $new->getKey())->where('student_id', (string) $this->students[0]->getKey())->firstOrFail();
        expect($moved->section_id)->toBe((string) $sections['10-A']->getKey());
    });
});
