<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\Assessment\Domain\Assessments;
use App\Modules\Assessment\Domain\Markbook;
use App\Modules\Assessment\Domain\PaperLifecycle;
use App\Modules\Assessment\Models\Assessment;
use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One paper: the markbook and its lifecycle.
 *
 *   GET  /papers/{id}            the paper, the roll with marks, what I may do; ETag "v<version>"
 *   PUT  /papers/{id}/marks      { marks: { <student_id>: { value?, code?, remark? } } } with If-Match (428 without, 412 paper_conflict when stale)
 *   POST /papers/{id}/open · /submit · /verify · /send-back { note } · /lock · /publish
 *
 * Marks entry is online only (owner's rule).
 */
class PaperController
{
    public function __construct(
        private readonly Markbook $markbook,
        private readonly PaperLifecycle $lifecycle,
        private readonly Assessments $assessments,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->find($request, $id));
    }

    public function saveMarks(Request $request, string $id): JsonResponse
    {
        $paper = $this->find($request, $id);
        $header = trim((string) $request->header('If-Match'));

        if ($header === '') {
            throw new ApiException('precondition_required', 'Send If-Match with the version of the paper you loaded.', 428);
        }
        if (preg_match('/^(?:W\/)?"?v?(\d+)"?$/', $header, $m) !== 1) {
            throw new ApiException('precondition_failed', 'If-Match must be the ETag of GET /papers/{id}, for example "v3".', 412);
        }

        $input = $request->validate([
            'marks' => ['required', 'array', 'max:200'],
            'marks.*' => ['array'],
            'marks.*.value' => ['nullable'],
            'marks.*.code' => ['nullable', 'string', 'max:4'],
            'marks.*.remark' => ['nullable', 'string', 'max:'.(int) config('assessment.remark_max', 200)],
        ]);

        /** @var array<string, array{value?: mixed, code?: mixed, remark?: mixed}> $changes */
        $changes = $input['marks'];

        return $this->answer($request, $this->markbook->save($this->user($request), $paper, (int) $m[1], $changes));
    }

    public function open(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->lifecycle->open($this->user($request), $this->find($request, $id)));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->lifecycle->submit($this->user($request), $this->find($request, $id)));
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->lifecycle->verify($this->user($request), $this->find($request, $id)));
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:300']])['note'];

        return $this->answer($request, $this->lifecycle->sendBack($this->user($request), $this->find($request, $id), $note));
    }

    public function lock(Request $request, string $id): JsonResponse
    {
        $paper = $this->find($request, $id);
        $this->lifecycle->lock($this->user($request), [(string) $paper->getKey()]);

        return $this->answer($request, Paper::query()->findOrFail($id));
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $paper = $this->find($request, $id);
        $this->lifecycle->publish($this->user($request), [(string) $paper->getKey()]);

        return $this->answer($request, Paper::query()->findOrFail($id));
    }

    private function answer(Request $request, Paper $paper): JsonResponse
    {
        $user = $this->user($request);
        $assessment = Assessment::query()->find($paper->assessment_id);
        $pending = CorrectionRequest::query()->where('paper_id', (string) $paper->getKey())->where('status', 'pending')->pluck('student_id')->map(fn ($s) => (string) $s)->all();

        return response()->json(['data' => array_merge($this->assessments->paperSummary($paper, $user), [
            'version' => $paper->version,
            'assessment' => $assessment instanceof Assessment ? AssessmentController::present($assessment) : null,
            'rows' => $this->markbook->rows($paper),
            'returned_note' => $paper->returned_note,
            'locked' => $paper->locked === null ? null : ['by' => $paper->locked['by']['name'] ?? null, 'at' => $paper->locked['at'] ?? null],
            'published_at' => $paper->published_at?->toIso8601String(),
            'pending_corrections' => $pending,
            'history' => array_map(fn (array $h) => ['action' => $h['action'], 'by' => $h['by']['name'] ?? null, 'note' => $h['note'] ?? null, 'at' => $h['at']], $paper->history),
        ])])->setEtag('v'.$paper->version);
    }

    private function find(Request $request, string $id): Paper
    {
        $paper = Paper::query()->findOrFail($id);
        Gate::forUser($this->user($request))->authorize('view', $paper);

        return $paper;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
