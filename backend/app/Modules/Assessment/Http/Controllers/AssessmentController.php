<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Assessment\Domain\PaperAccess;
use App\Modules\Assessment\Domain\PaperLifecycle;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Exams and class tests.
 *
 *   GET  /assessments                       this year's assessments with papers I may see, and counts
 *   POST /assessments { kind, type, name, term?, component?, from, to, entry_closes_at?, papers[] }
 *   GET  /assessments/{id}                  the assessment and my papers
 *   GET  /assessments/{id}/completion       the completion grid (sections x subjects)
 *   POST /assessments/{id}/open · /lock · /publish { section_id? }   every eligible paper at once (lock and publish in one transaction)
 *   GET  /results/students/{id}             a student's published marks only
 *   GET  /results/mine                      a signed-in student's own published marks
 */
class AssessmentController
{
    public function __construct(
        private readonly Assessments $assessments,
        private readonly PaperLifecycle $lifecycle,
        private readonly PaperAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $year = AcademicYear::current();
        if (! $year instanceof AcademicYear) {
            return response()->json(['data' => []]);
        }

        $papers = Paper::query()->where('year_id', (string) $year->getKey())->get()->filter(fn (Paper $p) => $this->access->mayView($user, $p));
        $byAssessment = $papers->groupBy('assessment_id');
        $assessments = Assessment::query()->whereIn('_id', $byAssessment->keys()->all())->orderBy('from', 'desc')->get();

        return response()->json(['data' => $assessments->map(function (Assessment $a) use ($byAssessment) {
            $mine = $byAssessment[(string) $a->getKey()] ?? collect();

            return array_merge(self::present($a), [
                'papers' => $mine->count(),
                // A published paper is counted once, as published.
                'by_status' => $mine->filter(fn (Paper $p) => $p->published_at === null)->countBy('status')->all(),
                'published' => $mine->filter(fn (Paper $p) => $p->published_at !== null)->count(),
            ]);
        })->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'kind' => ['required', Rule::in(['exam', 'class_test'])],
            'type' => ['required', Rule::in((array) config('assessment.types'))],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'term' => ['nullable', 'string', 'max:40'],
            'component' => ['nullable', 'string', 'max:40'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'entry_closes_at' => ['nullable', 'date'],
            'papers' => ['required', 'array', 'min:1', 'max:200'],
            'papers.*.section_id' => ['required', 'string', 'max:30'],
            'papers.*.subject_id' => ['required', 'string', 'max:30'],
            'papers.*.max_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
            'papers.*.date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $assessment = $this->assessments->create($this->user($request), $input);

        return $this->showModel($request, $assessment, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->showModel($request, Assessment::query()->findOrFail($id));
    }

    public function completion(Request $request, string $id): JsonResponse
    {
        $assessment = Assessment::query()->findOrFail($id);
        $this->assertSees($request, $assessment);

        return response()->json(['data' => array_merge(['assessment' => self::present($assessment)], $this->assessments->completion($this->user($request), $assessment))]);
    }

    public function openAll(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $assessment = Assessment::query()->findOrFail($id);
        $ids = $this->lifecycle->eligible($assessment, Paper::DRAFT, fn (Paper $p) => $this->access->mayOpen($user, $p), $request->input('section_id'));
        foreach (Paper::query()->whereIn('_id', $ids)->get() as $paper) {
            $this->lifecycle->open($user, $paper);
        }

        return $this->showModel($request, $assessment);
    }

    public function lockAll(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $assessment = Assessment::query()->findOrFail($id);
        $ids = $this->lifecycle->eligible($assessment, Paper::VERIFIED, fn (Paper $p) => $this->access->mayLock($user, $p), $request->input('section_id'));
        if ($ids === []) {
            throw new ApiException('nothing_to_lock', 'No verified papers here are waiting to be locked.', 409);
        }
        $this->lifecycle->lock($user, $ids);

        return $this->showModel($request, $assessment);
    }

    public function publishAll(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $assessment = Assessment::query()->findOrFail($id);
        $ids = $this->lifecycle->eligible($assessment, Paper::LOCKED, fn (Paper $p) => $p->published_at === null && $this->access->mayPublish($user, $p), $request->input('section_id'));
        if ($ids === []) {
            throw new ApiException('nothing_to_publish', 'No locked papers here are waiting to be published.', 409);
        }
        $this->lifecycle->publish($user, $ids);

        return $this->showModel($request, $assessment);
    }

    public function studentResults(Request $request, string $id, AccessControl $access): JsonResponse
    {
        $student = Student::query()->findOrFail($id);

        if (! $access->allows($this->user($request), 'results.view', $student)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => [
            'student' => ['id' => (string) $student->getKey(), 'name' => $student->name],
            'results' => $this->assessments->publishedFor($student),
        ]]);
    }

    /** A signed-in student's own published marks. */
    public function myResults(Request $request): JsonResponse
    {
        $student = Student::query()->where('user_id', (string) $this->user($request)->getKey())->first();

        if (! $student instanceof Student) {
            throw new ApiException('not_student', 'Only a student account has its own results.', 404);
        }

        return response()->json(['data' => [
            'student' => ['id' => (string) $student->getKey(), 'name' => $student->name],
            'results' => $this->assessments->publishedFor($student),
        ]]);
    }

    private function showModel(Request $request, Assessment $assessment, int $status = 200): JsonResponse
    {
        $user = $this->user($request);
        $papers = $this->assessments->visiblePapers($user, $assessment);

        if ($papers === [] && $status !== 201) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => array_merge(self::present($assessment), [
            'papers' => array_map(fn (Paper $p) => $this->assessments->paperSummary($p, $user), $papers),
        ])], $status);
    }

    private function assertSees(Request $request, Assessment $assessment): void
    {
        if ($this->assessments->visiblePapers($this->user($request), $assessment) === []) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Assessment $a): array
    {
        return [
            'id' => (string) $a->getKey(), 'kind' => $a->kind, 'type' => $a->type, 'term' => $a->term, 'name' => $a->name, 'component' => $a->component,
            'from' => $a->from, 'to' => $a->to, 'entry_closes_at' => $a->entry_closes_at, 'created_by' => $a->created_by['name'] ?? null,
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
