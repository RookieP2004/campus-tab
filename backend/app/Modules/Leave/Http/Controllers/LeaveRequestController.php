<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Domain\LeaveBalances;
use App\Modules\Leave\Domain\LeaveDocuments;
use App\Modules\Leave\Domain\LeaveRouting;
use App\Modules\Leave\Domain\LeaveViews;
use App\Modules\Leave\Domain\LeaveWorkflow;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\People\Models\Staff;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave requests: staff leave and student leave.
 *
 *   GET  /leave-requests ?box=mine|queue|history     my requests, waiting on me, or decided ones I was in the chain of
 *   GET  /leave-requests/preview ?type_code&from&to&half_day   days, balance after, whether a document is needed
 *   POST /leave-requests { type_code, from, to, half_day?, reason, submit? }   own leave (staff)
 *   POST /leave-requests { student_id, from, to, reason }                     a parent, for their child
 *   GET  /leave-requests/{id}                         the request, its timeline, balance and what I may do
 *   PATCH /leave-requests/{id}                        a draft or returned request (the applicant)
 *   POST /leave-requests/{id}/submit · /verify · /return · /approve · /reject · /withdraw
 *        /cancel-request · /cancel · /keep
 *   POST /leave-requests/{id}/documents (file)  ·  POST …/documents/{doc}/link → { url } (5 minutes)
 *
 * A request someone is not allowed to see answers 404, so its existence is
 * private too.
 */
class LeaveRequestController
{
    public function __construct(
        private readonly LeaveWorkflow $workflow,
        private readonly LeaveViews $views,
        private readonly LeaveRouting $routing,
    ) {}

    public function index(Request $request, AccessControl $access): JsonResponse
    {
        $user = $this->user($request);
        $box = (string) $request->query('box', 'mine');
        $id = (string) $user->getKey();

        $rows = match ($box) {
            'queue' => LeaveRequest::query()->where(fn ($q) => $q->whereIn('status', LeaveRequest::PENDING)->orWhere(fn ($q) => $q->where('status', LeaveRequest::APPROVED)->whereNotNull('cancel_request')))
                ->orderBy('stage_since')->limit(300)->get()
                ->filter(fn (LeaveRequest $r) => $r->isPending()
                    ? ($this->routing->mayAct($user, $r) || $this->routing->mayFastTrack($user, $r))
                    : $this->workflow->deciders($r)->contains(fn (User $u) => (string) $u->getKey() === $id)),
            'history' => LeaveRequest::query()->whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::REJECTED, LeaveRequest::CANCELLED, LeaveRequest::WITHDRAWN, LeaveRequest::RETURNED])
                ->orderBy('updated_at', 'desc')->limit(300)->get()
                ->filter(fn (LeaveRequest $r) => $r->applicantUserId() !== $id && $r->applied_by_id !== $id && $this->routing->canSeeDetails($user, $r)),
            default => LeaveRequest::query()->where(fn ($q) => $q->where('applicant_user_id', $id)->orWhere('applied_by_id', $id))
                ->orderBy('created_at', 'desc')->limit(200)->get(),
        };

        return response()->json(['data' => $rows->map(fn (LeaveRequest $r) => $this->views->summary($r))->values()]);
    }

    public function preview(Request $request, LeaveBalances $balances): JsonResponse
    {
        $user = $this->user($request);
        $input = $request->validate([
            'type_code' => ['required', 'string', 'max:20'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'half_day' => ['nullable', Rule::in(['first', 'second'])],
        ]);
        $type = $this->workflow->type($input['type_code']);
        $count = $this->workflow->count($type, $input['from'], $input['to'], $input['half_day'] ?? null);
        $staff = Staff::query()->where('user_id', (string) $user->getKey())->where('status', Staff::STATUS_ACTIVE)->first();
        $balance = $staff instanceof Staff ? $balances->of((string) $staff->getKey(), $type, $this->workflow->yearId()) : null;

        return response()->json(['data' => [
            'days' => $count['days'],
            'counted' => $count['counted'],
            'off' => $count['off'],
            'sandwich' => $type->sandwich,
            'document_required' => $type->needsDocument($count['days']),
            'balance' => $balance,
            'available_after' => $balance === null || $balance['available'] === null ? null : round($balance['available'] - $count['days'], 1),
            'chain' => $this->routing->chainFor($user, $staff instanceof Staff ? $this->routing->wingOf($staff) : null),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($request->has('student_id')) {
            $input = $request->validate([
                'student_id' => ['required', 'string', 'max:30'],
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'reason' => ['required', 'string', 'min:5', 'max:1000'],
            ]);
            $leave = $this->workflow->applyForChild($user, $input);
        } else {
            if (! app(AccessControl::class)->holds($user, 'leave.apply')) {
                throw new ApiException('permission_denied', 'You do not have permission to do this.', 403);
            }
            $input = $request->validate([
                'type_code' => ['required', 'string', 'max:20'],
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'half_day' => ['nullable', Rule::in(['first', 'second'])],
                'reason' => ['required', 'string', 'min:5', 'max:1000'],
                'submit' => ['sometimes', 'boolean'],
            ]);
            $leave = $this->workflow->apply($user, $input);
        }

        return response()->json(['data' => $this->views->detail($leave, $user)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $leave = $this->find($request, $id);

        return response()->json(['data' => $this->views->detail($leave, $this->user($request))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $leave = $this->find($request, $id);
        $input = $request->validate([
            'type_code' => ['sometimes', 'string', 'max:20'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'half_day' => ['sometimes', 'nullable', Rule::in(['first', 'second'])],
            'reason' => ['sometimes', 'string', 'min:5', 'max:1000'],
        ]);

        return $this->answer($request, $this->workflow->edit($this->user($request), $leave, $input));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->workflow->submit($this->user($request), $this->find($request, $id)));
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $remark = $request->validate(['remark' => ['nullable', 'string', 'max:500']])['remark'] ?? null;

        return $this->answer($request, $this->workflow->verify($this->user($request), $this->find($request, $id), $remark));
    }

    public function sendBack(Request $request, string $id): JsonResponse
    {
        $remark = $request->validate(['remark' => ['required', 'string', 'max:500']])['remark'];

        return $this->answer($request, $this->workflow->sendBack($this->user($request), $this->find($request, $id), $remark));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'remark' => ['nullable', 'string', 'max:500'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        return $this->answer($request, $this->workflow->approve($this->user($request), $this->find($request, $id), $input['remark'] ?? null, $input['from'] ?? null, $input['to'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $remark = $request->validate(['remark' => ['required', 'string', 'max:500']])['remark'];

        return $this->answer($request, $this->workflow->reject($this->user($request), $this->find($request, $id), $remark));
    }

    public function withdraw(Request $request, string $id): JsonResponse
    {
        return $this->answer($request, $this->workflow->withdraw($this->user($request), $this->find($request, $id)));
    }

    public function requestCancel(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->answer($request, $this->workflow->requestCancel($this->user($request), $this->find($request, $id), $reason));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $remark = $request->validate(['remark' => ['nullable', 'string', 'max:500']])['remark'] ?? null;

        return $this->answer($request, $this->workflow->cancel($this->user($request), $this->find($request, $id), $remark));
    }

    public function keep(Request $request, string $id): JsonResponse
    {
        $remark = $request->validate(['remark' => ['required', 'string', 'max:500']])['remark'];

        return $this->answer($request, $this->workflow->keep($this->user($request), $this->find($request, $id), $remark));
    }

    public function addDocument(Request $request, string $id, LeaveDocuments $documents): JsonResponse
    {
        $leave = $this->find($request, $id);
        $user = $this->user($request);

        if (! $this->views->can($leave, $user)['attach']) {
            throw new ApiException('permission_denied', 'Only the applicant can attach documents, before the decision.', 403);
        }

        $request->validate(['file' => ['required', 'file', 'mimes:'.implode(',', (array) config('leave.documents.types')), 'max:'.(int) config('leave.documents.max_kb', 5120)]]);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw new ApiException('file_required', 'Choose a file.', 422);
        }

        return $this->answer($request, $documents->add($leave, $file), 201);
    }

    public function documentLink(Request $request, string $id, string $doc, TenantContext $context): JsonResponse
    {
        $leave = $this->find($request, $id);

        if (! collect($leave->documents)->contains(fn (array $d) => $d['id'] === $doc)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('files.leave-document', now()->addMinutes((int) config('leave.documents.link_minutes', 5)), [
                'tenant' => $context->require(), 'request' => (string) $leave->getKey(), 'doc' => $doc,
            ], absolute: false),
        ]]);
    }

    /** Only through the signed, short-lived link above. */
    public function document(string $tenant, string $request, string $doc, TenantContext $context, LeaveDocuments $documents): Response
    {
        return $context->run($tenant, function () use ($request, $doc, $documents): Response {
            $leave = LeaveRequest::query()->find($request);
            if (! $leave instanceof LeaveRequest) {
                throw new ApiException('not_found', 'The record was not found.', 404);
            }
            $file = $documents->read($leave, $doc);
            $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file['name']) ?: 'document';

            return response($file['contents'], 200, [
                'Content-Type' => $file['mime'],
                'Content-Disposition' => 'inline; filename="'.$name.'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    private function answer(Request $request, LeaveRequest $leave, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->views->detail($leave, $this->user($request))], $status);
    }

    private function find(Request $request, string $id): LeaveRequest
    {
        $leave = LeaveRequest::query()->findOrFail($id);
        Gate::forUser($this->user($request))->authorize('view', $leave);

        return $leave;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
    }
}
