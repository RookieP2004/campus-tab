<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Support\Privacy\SensitiveValue;

/**
 * What screens get. A request's reason, remarks, documents, type and
 * balance go only to the applicant and the people in its chain
 * (LeaveRouting::canSeeDetails); the leave calendar shows everyone else
 * only who is away.
 */
class LeaveViews
{
    public function __construct(
        private readonly LeaveRouting $routing,
        private readonly LeaveWorkflow $workflow,
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * The full view of one request for someone allowed to see it.
     *
     * @return array<string, mixed>
     */
    public function detail(LeaveRequest $request, User $viewer): array
    {
        $balance = null;
        if ($request->kind === 'staff' && $request->type_code !== null && $request->year_id !== null) {
            $type = LeaveType::query()->where('code', $request->type_code)->first();
            if ($type instanceof LeaveType) {
                $balance = $this->balances->of((string) $request->applicant['staff_id'], $type, (string) $request->year_id);
            }
        }

        return array_merge($this->summary($request), [
            'reason' => $request->plainReason(),
            'documents' => array_map(fn (array $d) => ['id' => $d['id'], 'name' => SensitiveValue::open($d['name']), 'mime' => $d['mime'], 'size' => $d['size']], $request->documents),
            'timeline' => $this->timeline($request),
            'cover_needed' => $request->cover_needed,
            'approved' => $request->approved === null ? null : array_intersect_key($request->approved, array_flip(['from', 'to', 'days', 'restored_days'])),
            'cancel_request' => $request->cancel_request === null ? null : [
                'by' => $request->cancel_request['by']['name'] ?? null, 'at' => $request->cancel_request['at'] ?? null, 'reason' => SensitiveValue::open($request->cancel_request['reason'] ?? null),
            ],
            'balance' => $balance,
            'can' => $this->can($request, $viewer),
        ]);
    }

    /**
     * The list line (still only for people who may see the request).
     *
     * @return array<string, mixed>
     */
    public function summary(LeaveRequest $request): array
    {
        return [
            'id' => (string) $request->getKey(),
            'kind' => $request->kind,
            'applicant' => [
                'name' => $request->applicant['name'],
                'designation' => $request->applicant['designation'] ?? null,
                'section' => $request->applicant['section'] ?? null,
                'student_id' => $request->applicant['student_id'] ?? null,
            ],
            'applied_by' => $request->applied_by['name'] ?? null,
            'type_code' => $request->type_code,
            'type_name' => $request->type_name,
            'from' => $request->from,
            'to' => $request->to,
            'half_day' => $request->half_day,
            'days' => $request->days,
            'status' => $request->status,
            'stage' => $request->stage,
            'chain' => $request->chain,
            'fast_tracked' => $request->fast_tracked,
            'principal_sees' => $request->principal_sees,
            'cancel_requested' => $request->cancel_request !== null,
            'has_documents' => $request->documents !== [],
            'last_step' => $this->lastStep($request),
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * What this viewer may do now; the server checks again on every move.
     *
     * @return array<string, bool>
     */
    public function can(LeaveRequest $request, User $viewer): array
    {
        $id = (string) $viewer->getKey();
        $own = $id === $request->applicantUserId() || $id === ($request->applied_by['id'] ?? null);
        $moves = LeaveWorkflow::MOVES[$request->status] ?? [];
        $actor = ! $own && $this->routing->mayAct($viewer, $request);
        $fastTrack = ! $own && $this->routing->mayFastTrack($viewer, $request);
        $deciding = in_array($request->stage, LeaveRouting::DECIDING, true);
        $decider = ! $own && $request->status === LeaveRequest::APPROVED && $this->workflow->deciders($request)->contains(fn (User $u) => (string) $u->getKey() === $id);
        $clerkCanReject = app(LeaveSettings::class)->policy()['clerk_can_reject'];

        return [
            'edit' => $own && in_array('edit', $moves, true),
            'submit' => $own && in_array('submit', $moves, true),
            'withdraw' => $own && in_array('withdraw', $moves, true),
            'attach' => $own && in_array($request->status, [LeaveRequest::DRAFT, LeaveRequest::RETURNED, LeaveRequest::SUBMITTED, LeaveRequest::VERIFIED], true),
            'request_cancel' => $own && $request->status === LeaveRequest::APPROVED && $request->cancel_request === null,
            'verify' => $actor && in_array($request->stage, ['clerk', 'vice_principal'], true) && in_array('verify', $moves, true),
            'return' => ($actor || $fastTrack) && in_array('return', $moves, true),
            'approve' => (($actor && $deciding) || $fastTrack) && in_array('approve', $moves, true),
            'reject' => (($actor && ($deciding || ($request->stage === 'clerk' && $clerkCanReject))) || $fastTrack) && in_array('reject', $moves, true),
            'fast_track' => $fastTrack,
            'cancel' => $decider,
            'keep' => $decider && $request->cancel_request !== null,
        ];
    }

    /**
     * Every step taken, then the stages still to come.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(LeaveRequest $request): array
    {
        $out = array_map(fn (array $s) => [
            'stage' => $s['stage'],
            'action' => $s['action'],
            'by' => $s['by']['name'] ?? null,
            'remark' => SensitiveValue::open($s['remark'] ?? null),
            'at' => $s['at'],
            'from' => $s['from'] ?? null,
            'to' => $s['to'] ?? null,
            'restored_days' => $s['restored_days'] ?? null,
            'state' => 'done',
        ], $request->steps);

        if ($request->isPending() && $request->stage !== null) {
            $started = false;
            foreach ($request->chain as $stage) {
                $started = $started || $stage === $request->stage;
                if ($started) {
                    $out[] = ['stage' => $stage, 'action' => null, 'by' => null, 'remark' => null, 'at' => null, 'from' => null, 'to' => null, 'restored_days' => null, 'state' => $stage === $request->stage ? 'current' : 'upcoming'];
                }
            }
        }

        return $out;
    }

    /**
     * @return array{action: string, by: string|null, at: string, stage: string}|null
     */
    private function lastStep(LeaveRequest $request): ?array
    {
        $last = $request->steps[count($request->steps) - 1] ?? null;

        return $last === null ? null : ['action' => $last['action'], 'by' => $last['by']['name'] ?? null, 'at' => $last['at'], 'stage' => $last['stage']];
    }

    /**
     * Who is away between two dates: names and dates only. The viewer's own
     * leave, and requests they are in the chain of, carry an id to open.
     *
     * @return list<array{name: string, from: string, to: string, half_day: string|null, own: bool, request_id: string|null}>
     */
    public function calendar(User $viewer, string $from, string $to): array
    {
        $requests = LeaveRequest::query()->where('kind', 'staff')->where('status', LeaveRequest::APPROVED)
            ->where('from', '<=', $to)->where('to', '>=', $from)->orderBy('from')->get();

        return $requests->map(function (LeaveRequest $r) use ($viewer) {
            $own = $r->applicantUserId() === (string) $viewer->getKey();

            return [
                'name' => $r->applicant['name'],
                'from' => $r->approved['from'] ?? $r->from,
                'to' => $r->approved['to'] ?? $r->to,
                'half_day' => $r->half_day,
                'own' => $own,
                'request_id' => $own || $this->routing->canSeeDetails($viewer, $r) ? (string) $r->getKey() : null,
            ];
        })->values()->all();
    }
}
