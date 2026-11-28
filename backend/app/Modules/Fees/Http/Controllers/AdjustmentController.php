<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Fees\Domain\Adjustments;
use App\Modules\Fees\Domain\Cancellations;
use App\Modules\Fees\Domain\Dues;
use App\Modules\Fees\Domain\Money;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Fees\Models\ReceiptCancellation;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adjustments and receipt cancellations. Approving either is a step-up
 * action (fees_adjust_approve, receipt_cancel_approve) and never by the
 * person who proposed or requested it.
 *
 *   GET  /fees/adjustments?status=
 *   POST /fees/adjustments { student_id, kind, demand_id?, amount? | percent?, reason }
 *   GET  /fees/adjustments/{id}
 *   POST /fees/adjustments/{id}/approve { reason? }   step-up
 *   POST /fees/adjustments/{id}/reject { reason }
 *   POST /receipts/{id}/cancel { reason }              request (collector) or propose (Clerk)
 *   GET  /fees/cancellations/{id}
 *   POST /fees/cancellations/{id}/forward              the Clerk forwards a request
 *   POST /fees/cancellations/{id}/approve { reason }   step-up
 *   POST /fees/cancellations/{id}/reject { reason }
 */
class AdjustmentController
{
    public function __construct(private readonly Adjustments $adjustments, private readonly Cancellations $cancellations) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('fees.adjust-any');
        $status = $request->validate(['status' => ['nullable', Rule::in(['proposed', 'approved', 'rejected'])]])['status'] ?? null;
        $list = FeeAdjustment::query()->when($status, fn ($q) => $q->where('status', $status))->orderBy('created_at', 'desc')->limit(200)->get();

        return response()->json(['data' => $list->map(fn ($a) => Dues::adjustment($a))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('fees.adjust-propose');
        $input = $request->validate([
            'student_id' => ['required', 'string', 'max:30'], 'kind' => ['required', Rule::in((array) config('fees.adjustment_kinds'))],
            'demand_id' => ['nullable', 'string', 'max:30'], 'amount' => ['nullable', 'required_without:percent', 'string', 'max:15'],
            'percent' => ['nullable', 'string', 'max:6'], 'reason' => ['required', 'string', 'max:500'],
        ]);
        $student = Student::query()->find($input['student_id']) ?? throw new ApiException('not_found', 'The record was not found.', 404);
        $adj = $this->adjustments->propose(StudentFeesController::user($request), $student, [
            'kind' => $input['kind'], 'demand_id' => $input['demand_id'] ?? null, 'reason' => $input['reason'],
            'amount_paise' => isset($input['amount']) && $input['amount'] !== '' ? Money::parse($input['amount']) : null,
            'percent_bp' => isset($input['percent']) && $input['percent'] !== '' ? Money::basisPoints($input['percent']) : null,
        ]);

        return response()->json(['data' => $this->present($request, $adj)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.adjust-any');

        return response()->json(['data' => $this->present($request, FeeAdjustment::query()->findOrFail($id))]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $reason = (string) ($request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? '');
        $adj = $this->adjustments->approve(StudentFeesController::user($request), FeeAdjustment::query()->findOrFail($id), $reason);

        return response()->json(['data' => $this->present($request, $adj)]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $adj = $this->adjustments->reject(StudentFeesController::user($request), FeeAdjustment::query()->findOrFail($id), $reason);

        return response()->json(['data' => $this->present($request, $adj)]);
    }

    public function cancelReceipt(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.cancel-request');
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $c = $this->cancellations->raise(StudentFeesController::user($request), Receipt::query()->findOrFail($id), $reason);

        return response()->json(['data' => $this->cancellation($request, $c)], 201);
    }

    public function showCancellation(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.cancel-request');

        return response()->json(['data' => $this->cancellation($request, ReceiptCancellation::query()->findOrFail($id))]);
    }

    public function forward(Request $request, string $id): JsonResponse
    {
        $c = $this->cancellations->forward(StudentFeesController::user($request), ReceiptCancellation::query()->findOrFail($id));

        return response()->json(['data' => $this->cancellation($request, $c)]);
    }

    public function approveCancellation(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $c = $this->cancellations->approve(StudentFeesController::user($request), ReceiptCancellation::query()->findOrFail($id), $reason);

        return response()->json(['data' => $this->cancellation($request, $c)]);
    }

    public function rejectCancellation(Request $request, string $id): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $c = $this->cancellations->reject(StudentFeesController::user($request), ReceiptCancellation::query()->findOrFail($id), $reason);

        return response()->json(['data' => $this->cancellation($request, $c)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, FeeAdjustment $a): array
    {
        $user = StudentFeesController::user($request);
        $own = ($a->proposed_by['id'] ?? null) === (string) $user->getKey();

        return Dues::adjustment($a) + ['own' => $own, 'can' => ['decide' => $a->status === 'proposed' && ! $own && $this->adjustments->mayApprove($user)]];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellation(Request $request, ReceiptCancellation $c): array
    {
        $user = StudentFeesController::user($request);
        $mine = $c->involves((string) $user->getKey());
        $open = in_array($c->status, ['requested', 'proposed'], true);

        return [
            'id' => (string) $c->getKey(), 'receipt_id' => $c->receipt_id, 'receipt_no' => $c->receipt_no, 'student' => ['id' => $c->student_id, 'name' => $c->student_name],
            'amount_paise' => $c->amount_paise, 'reason' => $c->reason, 'status' => $c->status, 'requested_by' => $c->requested_by, 'proposed_by' => $c->proposed_by,
            'decided_by' => $c->decided_by, 'decision_reason' => $c->decision_reason, 'decided_at' => $c->decided_at, 'at' => $c->created_at?->toIso8601String(), 'own' => $mine,
            'can' => [
                'approve' => $open && ! $mine && $this->cancellations->mayApprove($user),
                'forward' => $c->status === 'requested' && ! $mine && $this->cancellations->mayPropose($user),
                'reject' => $open && ! $mine && ($this->cancellations->mayApprove($user) || ($c->status === 'requested' && $this->cancellations->mayPropose($user))),
            ],
        ];
    }
}
