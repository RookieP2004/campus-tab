<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\Assessment\Domain\Corrections;
use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Corrections to locked marks.
 *
 *   GET  /corrections ?status=pending|approved|rejected &self_approved=1    what I may see
 *   POST /corrections { paper_id, student_id, value?, code?, reason }       raise (marks.correction_request)
 *   GET  /corrections/{id}
 *   POST /corrections/{id}/approve { reason }   the Principal: step-up marks_correction_approve and a typed reason
 *   POST /corrections/{id}/reject { reason }
 */
class CorrectionController
{
    public function __construct(private readonly Corrections $corrections) {}

    public function index(Request $request, AccessControl $access): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'self_approved' => ['nullable', 'boolean']]);
        $query = CorrectionRequest::query()->orderBy('created_at', 'desc')->limit(200);

        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (($input['self_approved'] ?? false) == true) {
            $query->where('self_approved', true);
        }

        $rows = $query->get()->filter(fn (CorrectionRequest $c) => Gate::forUser($user)->allows('viewCorrection', $c));

        return response()->json([
            'data' => $rows->map(fn (CorrectionRequest $c) => self::present($c, $user, $access))->values(),
            'can' => ['approve' => $access->allows($user, 'marks.correction_approve')],
        ]);
    }

    public function store(Request $request, AccessControl $access): JsonResponse
    {
        $input = $request->validate([
            'paper_id' => ['required', 'string', 'max:30'],
            'student_id' => ['required', 'string', 'max:30'],
            'value' => ['nullable'],
            'code' => ['nullable', 'string', 'max:4'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $user = $this->user($request);
        $paper = Paper::query()->findOrFail($input['paper_id']);
        Gate::forUser($user)->authorize('view', $paper);

        $correction = $this->corrections->raise($user, $paper, $input['student_id'], ['value' => $input['value'] ?? null, 'code' => $input['code'] ?? null], $input['reason']);

        return response()->json(['data' => self::present($correction, $user, $access)], 201);
    }

    public function show(Request $request, string $id, AccessControl $access): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => self::present($this->find($user, $id), $user, $access)]);
    }

    public function approve(Request $request, string $id, AccessControl $access): JsonResponse
    {
        $user = $this->user($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $session = $request->attributes->get('device_session');

        $saved = $this->corrections->approve($user, $this->find($user, $id), $reason, $session instanceof DeviceSession ? $session : null);

        return response()->json(['data' => self::present($saved, $user, $access)]);
    }

    public function reject(Request $request, string $id, AccessControl $access): JsonResponse
    {
        $user = $this->user($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return response()->json(['data' => self::present($this->corrections->reject($user, $this->find($user, $id), $reason), $user, $access)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CorrectionRequest $c, User $viewer, AccessControl $access): array
    {
        $decider = $access->allows($viewer, 'marks.correction_approve');

        return [
            'id' => (string) $c->getKey(),
            'paper_id' => $c->paper_id,
            'assessment_id' => $c->assessment_id,
            'student_id' => $c->student_id,
            'student' => $c->student_name,
            'label' => $c->label,
            'old' => $c->old,
            'new' => $c->new,
            'reason' => $c->reason,
            'requested_by' => $c->requested_by['name'] ?? null,
            'requested_by_role' => $c->requested_by['role'] ?? null,
            'requested_at' => $c->created_at?->toIso8601String(),
            'status' => $c->status,
            'decided_by' => $c->decided_by['name'] ?? null,
            'decision_reason' => $c->decision_reason,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'self_approved' => $c->self_approved,
            'own' => $c->requested_by_id === (string) $viewer->getKey(),
            'can' => ['decide' => $decider && $c->status === 'pending'],
        ];
    }

    private function find(User $user, string $id): CorrectionRequest
    {
        $correction = CorrectionRequest::query()->findOrFail($id);
        Gate::forUser($user)->authorize('viewCorrection', $correction);

        return $correction;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
