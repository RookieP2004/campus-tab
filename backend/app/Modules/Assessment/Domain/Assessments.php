<?php

namespace App\Modules\Assessment\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Exams and class tests, and what each person sees of them.
 *
 *  - A school exam (kind exam) is scheduled by whoever holds exams.schedule
 *    (Principal, Clerk); its papers start as drafts and open together.
 *  - A class test (kind class_test, weekly or monthly) is made by a teacher
 *    with tests.create, only for subject-sections in their own scope; its
 *    papers open at once.
 *  - The completion grid shows, per section, each subject's paper state and
 *    how many marks are in, for the class teacher and the Principal.
 *  - Students and parents see a paper's marks only once it is published.
 */
class Assessments
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly PaperAccess $papers,
        private readonly Markbook $markbook,
    ) {}

    /**
     * @param  array{kind: string, type: string, name: string, term?: string|null, component?: string|null, from: string, to: string, entry_closes_at?: string|null, papers: list<array{section_id: string, subject_id: string, max_marks: float|int, date?: string|null}>}  $input
     */
    public function create(User $user, array $input): Assessment
    {
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'The school has no current academic year yet.', 422);
        $classTest = $input['kind'] === 'class_test';

        if ($classTest && ! in_array($input['type'], (array) config('assessment.class_test_types'), true)) {
            throw new ApiException('class_test_type', 'A class test is weekly or monthly.', 422);
        }
        if (! $classTest && ! $this->access->allows($user, 'exams.schedule')) {
            throw new ApiException('permission_denied', 'Only the Principal or the Clerk schedules school exams.', 403);
        }

        $sections = Section::query()->whereIn('_id', array_column($input['papers'], 'section_id'))->where('year_id', (string) $year->getKey())->get()->keyBy(fn ($s) => (string) $s->getKey());
        $subjects = Subject::query()->whereIn('_id', array_column($input['papers'], 'subject_id'))->get()->keyBy(fn ($s) => (string) $s->getKey());
        $rows = [];

        foreach ($input['papers'] as $p) {
            $section = $sections[$p['section_id']] ?? throw new ApiException('section_unknown', 'One of the sections is not in this year.', 422);
            $subject = $subjects[$p['subject_id']] ?? throw new ApiException('subject_unknown', 'One of the subjects was not found.', 422);
            $key = $p['section_id'].':'.$p['subject_id'];

            if (isset($rows[$key])) {
                throw new ApiException('paper_duplicate', "{$subject->name} {$section->label} is listed twice.", 422);
            }

            $probe = self::probe($p['section_id'], $section->wing, $key);
            if ($classTest && ! $this->access->allows($user, 'tests.create', $probe)) {
                throw new ApiException('permission_denied', "You may set class tests only for your own classes and subjects ({$subject->name} {$section->label} is not yours).", 403);
            }

            $rows[$key] = [
                'section_id' => $p['section_id'], 'section_label' => $section->label, 'wing' => $section->wing,
                'subject_id' => $p['subject_id'], 'subject_name' => $subject->name, 'max_marks' => (float) $p['max_marks'], 'date' => $p['date'] ?? null,
            ];
        }

        if ($rows === []) {
            throw new ApiException('papers_required', 'Choose at least one class and subject.', 422);
        }

        return DB::connection('mongodb')->transaction(function () use ($user, $input, $year, $rows, $classTest): Assessment {
            $assessment = Assessment::create([
                'year_id' => (string) $year->getKey(),
                'kind' => $classTest ? 'class_test' : 'exam',
                'type' => $input['type'],
                'term' => $input['term'] ?? null,
                'name' => trim($input['name']),
                'component' => $input['component'] ?? null,
                'from' => $input['from'],
                'to' => $input['to'],
                'entry_closes_at' => $input['entry_closes_at'] ?? null,
                'created_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            ]);

            foreach ($rows as $row) {
                $paper = new Paper(array_merge($row, [
                    'assessment_id' => (string) $assessment->getKey(),
                    'year_id' => (string) $year->getKey(),
                    'class_test' => $classTest,
                    'status' => $classTest ? Paper::OPEN : Paper::DRAFT,
                ]));
                $paper->marks = array_map(fn (array $s) => ['student_id' => $s['id'], 'value' => null, 'code' => null, 'remark' => null], $this->markbook->roll($paper));
                $paper->history = [['action' => 'created', 'by' => ['id' => (string) $user->getKey(), 'name' => $user->name], 'note' => null, 'at' => now()->toIso8601String()]];
                $paper->save();
            }

            return $assessment;
        }, 5);
    }

    /**
     * The papers of an assessment this person may see.
     *
     * @return list<Paper>
     */
    public function visiblePapers(User $user, Assessment $assessment): array
    {
        return Paper::query()->where('assessment_id', (string) $assessment->getKey())->orderBy('section_label')->orderBy('subject_name')->get()
            ->filter(fn (Paper $p) => $this->papers->mayView($user, $p))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function paperSummary(Paper $paper, User $user): array
    {
        $roll = count($this->markbook->roll($paper));
        $filled = count(array_filter($paper->marks, fn (array $m) => Markbook::filled($m)));

        return [
            'id' => (string) $paper->getKey(),
            'assessment_id' => $paper->assessment_id,
            'section_id' => $paper->section_id,
            'section' => $paper->section_label,
            'subject_id' => $paper->subject_id,
            'subject' => $paper->subject_name,
            'max_marks' => $paper->max_marks,
            'date' => $paper->date,
            'status' => $paper->status,
            'published' => $paper->published_at !== null,
            'class_test' => $paper->class_test,
            'entered' => min($filled, $roll),
            'students' => $roll,
            'can' => $this->can($paper, $user),
        ];
    }

    /**
     * What this person may do with the paper now (the server checks again).
     *
     * @return array<string, bool>
     */
    public function can(Paper $paper, User $user): array
    {
        $status = $paper->status;

        return [
            'enter' => Markbook::editable($paper) && $this->papers->mayEnter($user, $paper),
            'open' => $status === Paper::DRAFT && $this->papers->mayOpen($user, $paper),
            'submit' => $status === Paper::OPEN && $this->papers->mayEnter($user, $paper),
            'verify' => $status === Paper::SUBMITTED && $this->papers->mayVerify($user, $paper),
            'send_back' => in_array($status, [Paper::SUBMITTED, Paper::VERIFIED], true) && ($this->papers->mayVerify($user, $paper) || $this->papers->mayLock($user, $paper)),
            'lock' => $status === Paper::VERIFIED && $this->papers->mayLock($user, $paper),
            'publish' => $status === Paper::LOCKED && $paper->published_at === null && $this->papers->mayPublish($user, $paper),
            'correct' => $status === Paper::LOCKED && $this->papers->mayRequestCorrection($user, $paper),
        ];
    }

    /**
     * The completion grid: sections down, subjects across, each cell the
     * paper's state and how many marks are in.
     *
     * @return array{subjects: list<string>, sections: list<array{section_id: string, section: string, cells: array<string, array<string, mixed>>}>}
     */
    public function completion(User $user, Assessment $assessment): array
    {
        $papers = $this->visiblePapers($user, $assessment);
        $subjects = [];
        $sections = [];

        foreach ($papers as $paper) {
            $subjects[$paper->subject_name] = true;
            $sections[$paper->section_id] ??= ['section_id' => $paper->section_id, 'section' => $paper->section_label, 'cells' => []];
            $summary = $this->paperSummary($paper, $user);
            $sections[$paper->section_id]['cells'][$paper->subject_name] = [
                'paper_id' => $summary['id'], 'status' => $summary['status'], 'published' => $summary['published'], 'entered' => $summary['entered'], 'students' => $summary['students'],
            ];
        }

        $names = array_keys($subjects);
        sort($names);
        $rows = array_values($sections);
        usort($rows, fn ($a, $b) => strnatcmp($a['section'], $b['section']));

        return ['subjects' => $names, 'sections' => $rows];
    }

    /**
     * A student's published marks (for the student, their parents and staff
     * who may see results). Unpublished papers never appear.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedFor(Student $student): array
    {
        $papers = Paper::query()->whereNotNull('published_at')->where('marks.student_id', (string) $student->getKey())->orderBy('published_at', 'desc')->limit(200)->get();
        $assessments = Assessment::query()->whereIn('_id', $papers->pluck('assessment_id')->unique()->values()->all())->get()->keyBy(fn ($a) => (string) $a->getKey());

        return $papers->map(function (Paper $p) use ($student, $assessments) {
            $mark = $p->marksByStudent()[(string) $student->getKey()] ?? [];
            $assessment = $assessments[$p->assessment_id] ?? null;

            return [
                'paper_id' => (string) $p->getKey(),
                'assessment' => $assessment?->name,
                'type' => $assessment?->type,
                'term' => $assessment?->term,
                'subject' => $p->subject_name,
                'max_marks' => $p->max_marks,
                'value' => isset($mark['value']) ? (float) $mark['value'] : null,
                'code' => $mark['code'] ?? null,
                // Set when the mark changed after families could see it.
                'updated_at' => $mark['updated_at'] ?? null,
                'date' => $p->date ?? $assessment?->from,
                'published_at' => $p->published_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** An access probe for a section and subject before the paper exists. */
    public static function probe(string $sectionId, ?string $wing, string $subjectKey): AccessSubject
    {
        return new class($sectionId, $wing, $subjectKey) implements AccessSubject
        {
            public function __construct(private readonly string $section, private readonly ?string $wing, private readonly string $key) {}

            public function accessFacts(): array
            {
                return ['section_id' => $this->section, 'wing' => $this->wing, 'subject_key' => $this->key];
            }
        };
    }
}
