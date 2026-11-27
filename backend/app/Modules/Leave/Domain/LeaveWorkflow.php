<?php

namespace App\Modules\Leave\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Privacy\SensitiveValue;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The leave state machine. Every move checks who is acting and from which
 * state, and runs its writes (the request, the ledger, attendance) in one
 * MongoDB transaction that is retried on a write conflict, so concurrent
 * approvals can never take the same days twice. Notifications go out only
 * after the transaction commits and say only that the request changed.
 *
 *   draft ──submit──▶ submitted ──verify──▶ verified ──approve──▶ approved ──cancel──▶ cancelled
 *     ▲                  │  │                  │  │                  │
 *     └──resubmit── returned ◀──return────────┘  └──reject──▶ rejected
 *   draft, submitted, verified, returned ──withdraw──▶ withdrawn
 *
 * Own-leave routing can make the first stage the decider (submitted ──approve).
 * The Principal may fast-track at the Clerk's stage; the Clerk is told.
 */
class LeaveWorkflow
{
    /** Which moves each state allows (anything else is refused with 409). */
    public const MOVES = [
        LeaveRequest::DRAFT => ['edit', 'submit', 'withdraw'],
        LeaveRequest::RETURNED => ['edit', 'submit', 'withdraw'],
        LeaveRequest::SUBMITTED => ['verify', 'return', 'approve', 'reject', 'withdraw'],
        LeaveRequest::VERIFIED => ['verify', 'return', 'approve', 'reject', 'withdraw'],
        LeaveRequest::APPROVED => ['request_cancel', 'cancel', 'keep'],
        LeaveRequest::REJECTED => [],
        LeaveRequest::WITHDRAWN => [],
        LeaveRequest::CANCELLED => [],
    ];

    public function __construct(
        private readonly LeaveRouting $routing,
        private readonly LeaveDays $days,
        private readonly LeaveBalances $balances,
        private readonly LeaveEffects $effects,
        private readonly LeaveSettings $settings,
        private readonly AccessControl $access,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * A staff member's own leave.
     *
     * @param  array{type_code: string, from: string, to: string, half_day?: string|null, reason: string, submit?: bool}  $input
     */
    public function apply(User $actor, array $input): LeaveRequest
    {
        $staff = Staff::query()->where('user_id', (string) $actor->getKey())->where('status', Staff::STATUS_ACTIVE)->first()
            ?? throw new ApiException('not_staff', 'Only staff apply for their own leave here.', 403);
        $type = $this->type($input['type_code']);
        $count = $this->count($type, $input['from'], $input['to'], $input['half_day'] ?? null);
        $wing = $this->routing->wingOf($staff);

        $request = LeaveRequest::create([
            'kind' => 'staff',
            'applicant' => ['user_id' => (string) $actor->getKey(), 'name' => $actor->name, 'staff_id' => (string) $staff->getKey(), 'student_id' => null, 'level' => $actor->level(), 'wing' => $wing, 'designation' => $staff->designation],
            'applicant_user_id' => (string) $actor->getKey(),
            'applicant_staff_id' => (string) $staff->getKey(),
            'applied_by' => ['id' => (string) $actor->getKey(), 'name' => $actor->name],
            'applied_by_id' => (string) $actor->getKey(),
            'wing' => $wing,
            'type_code' => $type->code,
            'type_name' => $type->name,
            'year_id' => $this->yearId(),
            'from' => $input['from'], 'to' => $input['to'], 'half_day' => $input['half_day'] ?? null, 'days' => $count['days'],
            'reason' => SensitiveValue::seal(trim($input['reason'])),
            'status' => LeaveRequest::DRAFT,
            'chain' => $this->routing->chainFor($actor, $wing),
            'steps' => [],
        ]);

        return ($input['submit'] ?? true) ? $this->submit($actor, $request) : $request;
    }

    /**
     * A parent's request for their child. The class teacher decides.
     *
     * @param  array{student_id: string, from: string, to: string, reason: string}  $input
     */
    public function applyForChild(User $actor, array $input): LeaveRequest
    {
        $student = Student::query()->find($input['student_id']);

        if (! $student instanceof Student || ! in_array((string) $student->getKey(), $actor->accessScope()['children'], true) || ! $this->access->holdsAt($actor, 'student_leave.apply', 'child')) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }

        $enrolment = Enrolment::query()->where('student_id', (string) $student->getKey())->where('year_id', $this->yearId())->first()
            ?? throw new ApiException('not_enrolled', 'This child is not in a section this year.', 422);
        $section = Section::query()->find($enrolment->section_id);
        $count = $this->days->count($input['from'], $input['to'], false);

        $request = LeaveRequest::create([
            'kind' => 'student',
            'applicant' => ['user_id' => null, 'name' => $student->name, 'staff_id' => null, 'student_id' => (string) $student->getKey(), 'level' => 10, 'wing' => $section?->wing, 'section' => $section?->label],
            'applicant_student_id' => (string) $student->getKey(),
            'applied_by' => ['id' => (string) $actor->getKey(), 'name' => $actor->name],
            'applied_by_id' => (string) $actor->getKey(),
            'section_id' => $enrolment->section_id,
            'wing' => $section?->wing,
            'year_id' => $this->yearId(),
            'from' => $input['from'], 'to' => $input['to'], 'days' => $count['days'],
            'reason' => SensitiveValue::seal(trim($input['reason'])),
            'status' => LeaveRequest::DRAFT,
            'chain' => ['class_teacher'],
            'principal_sees' => $count['days'] > $this->settings->policy()['student_principal_after_days'],
        ]);

        return $this->submit($actor, $request);
    }

    /**
     * Change a draft or a returned request (the applicant only).
     *
     * @param  array{type_code?: string, from?: string, to?: string, half_day?: string|null, reason?: string}  $input
     */
    public function edit(User $actor, LeaveRequest $request, array $input): LeaveRequest
    {
        $this->assertMove($request, 'edit');
        $this->assertApplicant($actor, $request);

        $type = $request->kind === 'staff' ? $this->type($input['type_code'] ?? (string) $request->type_code) : null;
        $from = $input['from'] ?? $request->from;
        $to = $input['to'] ?? $request->to;
        $half = array_key_exists('half_day', $input) ? $input['half_day'] : $request->half_day;
        $count = $type instanceof LeaveType ? $this->count($type, $from, $to, $half) : $this->days->count($from, $to, false);

        $request->fill(array_filter([
            'type_code' => $type?->code, 'type_name' => $type?->name, 'from' => $from, 'to' => $to, 'half_day' => $half, 'days' => $count['days'],
            'reason' => isset($input['reason']) ? SensitiveValue::seal(trim($input['reason'])) : null,
        ], fn ($v) => $v !== null) + ['half_day' => $half])->save();

        return $request;
    }

    public function submit(User $actor, LeaveRequest $request): LeaveRequest
    {
        $this->assertMove($request, 'submit');
        $this->assertApplicant($actor, $request);

        if ($request->kind === 'staff') {
            $type = $this->type((string) $request->type_code);
            $count = $this->count($type, $request->from, $request->to, $request->half_day);

            if ($type->needsDocument($count['days']) && $request->documents === []) {
                throw new ApiException('document_required', "{$type->name} for more than {$type->document_after_days} days needs a supporting document, such as a medical certificate. Attach it, then send the request.", 422, ['request_id' => (string) $request->getKey()]);
            }

            $this->balances->prepare((string) $request->applicant['staff_id'], $type->code, (string) $request->year_id);
        }

        $resubmit = $request->status === LeaveRequest::RETURNED;

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor, $resubmit): LeaveRequest {
            $this->assertMove($fresh, 'submit');
            $days = $fresh->days;

            if ($fresh->kind === 'staff') {
                $type = $this->type((string) $fresh->type_code);
                $days = $this->count($type, $fresh->from, $fresh->to, $fresh->half_day)['days'];
                $staffId = (string) $fresh->applicant['staff_id'];
                $this->balances->lock($staffId, $type->code, (string) $fresh->year_id);
                $this->balances->write('release', $staffId, $type->code, (string) $fresh->year_id, $fresh->reserved_days, (string) $fresh->getKey(), $actor);
                // The days being released are back in the balance before the new hold is checked.
                $this->balances->assertAvailable($staffId, $type, (string) $fresh->year_id, $days);
                $this->balances->write('reserve', $staffId, $type->code, (string) $fresh->year_id, $days, (string) $fresh->getKey(), $actor);
            }

            $fresh->fill([
                'days' => $days,
                'reserved_days' => $fresh->kind === 'staff' ? $days : 0.0,
                'status' => LeaveRequest::SUBMITTED,
                'stage' => $fresh->chain[0],
                'stage_since' => now(),
                'reminded_for' => null,
                'escalated_for' => null,
                'steps' => [...$fresh->steps, $this->step('applicant', $resubmit ? 'resubmitted' : 'submitted', $actor)],
            ])->save();

            return $fresh;
        });

        $this->tellActors($saved);

        return $saved;
    }

    /** Stage 1 (Clerk) or the Vice Principal stage: check and pass on. */
    public function verify(User $actor, LeaveRequest $request, ?string $remark): LeaveRequest
    {
        $this->assertMove($request, 'verify');

        if (! in_array($request->stage, ['clerk', 'vice_principal'], true)) {
            throw new ApiException('leave_wrong_stage', 'This request is waiting for a decision, not a check.', 409);
        }
        $this->assertMayAct($actor, $request);

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor, $remark): LeaveRequest {
            $this->assertMove($fresh, 'verify');
            $next = $fresh->chain[array_search($fresh->stage, $fresh->chain, true) + 1] ?? 'principal';
            $fresh->fill([
                'status' => LeaveRequest::VERIFIED,
                'steps' => [...$fresh->steps, $this->step((string) $fresh->stage, $fresh->stage === 'clerk' ? 'verified' : 'forwarded', $actor, $remark)],
                'stage' => $next,
                'stage_since' => now(),
            ])->save();

            return $fresh;
        });

        $this->tellApplicant($saved);
        $this->tellActors($saved);

        return $saved;
    }

    /** Back to the applicant to fix and resubmit. A remark is required. */
    public function sendBack(User $actor, LeaveRequest $request, string $remark): LeaveRequest
    {
        $this->assertMove($request, 'return');
        $fastTrack = $this->routing->mayFastTrack($actor, $request);
        if (! $fastTrack) {
            $this->assertMayAct($actor, $request);
        }
        $this->assertRemark($remark, 'Say what needs fixing.');

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor, $remark): LeaveRequest {
            $this->assertMove($fresh, 'return');
            $fresh->fill([
                'status' => LeaveRequest::RETURNED,
                'steps' => [...$fresh->steps, $this->step((string) $fresh->stage, 'returned', $actor, $remark)],
                'stage' => null,
                'stage_since' => null,
            ])->save();

            return $fresh;
        });

        $this->tellApplicant($saved);

        return $saved;
    }

    public function reject(User $actor, LeaveRequest $request, string $reason): LeaveRequest
    {
        $this->assertMove($request, 'reject');
        $fastTrack = $this->routing->mayFastTrack($actor, $request);

        if (! $fastTrack) {
            $this->assertMayAct($actor, $request);

            if ($request->stage === 'clerk' && ! $this->settings->policy()['clerk_can_reject']) {
                throw new ApiException('clerk_cannot_reject', 'Rejecting is the Principal\'s decision. Verify and forward it with your remark, or return it for correction.', 403);
            }
            if ($request->stage === 'vice_principal') {
                throw new ApiException('leave_wrong_stage', 'Forward it to the Principal with your remark, or return it for correction.', 403);
            }
        }
        $this->assertRemark($reason, 'A reason is required to reject a request.');

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor, $reason, $fastTrack): LeaveRequest {
            $this->assertMove($fresh, 'reject');
            $this->releaseHeld($fresh, $actor);
            $fresh->fill([
                'status' => LeaveRequest::REJECTED,
                'fast_tracked' => $fastTrack,
                'steps' => [...$fresh->steps, $this->step((string) $fresh->stage, $fastTrack ? 'rejected_fast_track' : 'rejected', $actor, $reason)],
                'stage' => null, 'stage_since' => null, 'reserved_days' => 0.0,
            ])->save();

            return $fresh;
        });

        $this->tellApplicant($saved);
        if ($fastTrack) {
            $this->tellClerks($saved);
        }

        return $saved;
    }

    /**
     * The final decision. With other dates, the days are worked out again and
     * the balance checked for the new number.
     */
    public function approve(User $actor, LeaveRequest $request, ?string $remark, ?string $from = null, ?string $to = null): LeaveRequest
    {
        $this->assertMove($request, 'approve');
        $fastTrack = $this->routing->mayFastTrack($actor, $request);

        if (! $fastTrack) {
            if (! in_array($request->stage, LeaveRouting::DECIDING, true)) {
                throw new ApiException('leave_wrong_stage', 'This request still needs its check before a decision.', 409);
            }
            $this->assertMayAct($actor, $request);
        }

        $from ??= $request->from;
        $to ??= $request->to;
        $changed = $from !== $request->from || $to !== $request->to;
        $type = $request->kind === 'staff' ? $this->type((string) $request->type_code) : null;
        $half = $changed ? null : $request->half_day;
        $count = $type instanceof LeaveType ? $this->days->count($from, $to, $type->sandwich, $half) : $this->days->count($from, $to, false);

        if ($type instanceof LeaveType) {
            $this->balances->prepare((string) $request->applicant['staff_id'], $type->code, (string) $request->year_id);
        }

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor, $remark, $from, $to, $changed, $type, $count, $fastTrack): LeaveRequest {
            $this->assertMove($fresh, 'approve');

            if ($type instanceof LeaveType) {
                $staffId = (string) $fresh->applicant['staff_id'];
                $this->balances->lock($staffId, $type->code, (string) $fresh->year_id);
                $this->balances->write('release', $staffId, $type->code, (string) $fresh->year_id, $fresh->reserved_days, (string) $fresh->getKey(), $actor);
                $this->balances->assertAvailable($staffId, $type, (string) $fresh->year_id, $count['days']);
                $this->balances->write('deduct', $staffId, $type->code, (string) $fresh->year_id, $count['days'], (string) $fresh->getKey(), $actor);
                $this->effects->applyStaff($fresh, $count['working']);
            } else {
                $this->effects->applyStudent($fresh, $count['working']);
            }

            $steps = $fresh->steps;
            if ($fastTrack) {
                $steps[] = $this->step('clerk', 'fast_tracked', $actor);
            }
            $steps[] = $this->step($fastTrack ? 'principal' : (string) $fresh->stage, $changed ? 'approved_changed' : 'approved', $actor, $remark, $changed ? ['from' => $from, 'to' => $to] : []);

            $fresh->fill([
                'status' => LeaveRequest::APPROVED,
                'approved' => ['from' => $from, 'to' => $to, 'days' => $count['days'], 'dates' => $count['counted'], 'working' => $count['working']],
                'fast_tracked' => $fastTrack,
                'cover_needed' => $type instanceof LeaveType ? $this->effects->coverFor((string) $fresh->applicant['staff_id'], $count['working']) : [],
                'steps' => $steps,
                'stage' => null, 'stage_since' => null, 'reserved_days' => 0.0,
            ])->save();

            return $fresh;
        });

        $this->tellApplicant($saved);
        if ($fastTrack) {
            $this->tellClerks($saved);
        }

        return $saved;
    }

    /** Taken back by the applicant before a decision. */
    public function withdraw(User $actor, LeaveRequest $request): LeaveRequest
    {
        $this->assertMove($request, 'withdraw');
        $this->assertApplicant($actor, $request);
        $wasPending = $request->isPending();

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor): LeaveRequest {
            $this->assertMove($fresh, 'withdraw');
            $this->releaseHeld($fresh, $actor);
            $fresh->fill([
                'status' => LeaveRequest::WITHDRAWN,
                'steps' => [...$fresh->steps, $this->step('applicant', 'withdrawn', $actor)],
                'stage' => null, 'stage_since' => null, 'reserved_days' => 0.0,
            ])->save();

            return $fresh;
        });

        if ($wasPending) {
            $this->notify($this->routing->actorsAt($saved, (string) ($request->stage ?? 'principal')), $saved, 'leave.updated', 'leave.notifications.changed');
        }

        return $saved;
    }

    /** The applicant asks to call off an approved leave. */
    public function requestCancel(User $actor, LeaveRequest $request, string $reason): LeaveRequest
    {
        $this->assertMove($request, 'request_cancel');
        $this->assertApplicant($actor, $request);
        $this->assertRemark($reason, 'Say why the leave should be cancelled.');

        if ($request->cancel_request !== null) {
            throw new ApiException('cancel_already_requested', 'Cancelling this leave has already been asked for.', 409);
        }
        if ($this->effects->untaken($request->approved['dates'] ?? []) === []) {
            throw new ApiException('leave_already_taken', 'Every day of this leave has already been taken, so there is nothing left to cancel.', 409);
        }

        $request->fill([
            'cancel_request' => ['by' => ['id' => (string) $actor->getKey(), 'name' => $actor->name], 'reason' => SensitiveValue::seal($reason), 'at' => now()->toIso8601String()],
            'steps' => [...$request->steps, $this->step('applicant', 'cancel_requested', $actor, $reason)],
        ])->save();

        $this->notify($this->deciders($request), $request, 'leave.waiting', 'leave.notifications.waiting');

        return $request;
    }

    /**
     * The decider calls off an approved leave, on the applicant's request or
     * with their own reason. The days not yet taken go back to the balance
     * and their attendance is put back, in the same transaction.
     */
    public function cancel(User $actor, LeaveRequest $request, ?string $remark): LeaveRequest
    {
        $this->assertMove($request, 'cancel');

        if (! $this->deciders($request)->contains(fn (User $u) => (string) $u->getKey() === (string) $actor->getKey())) {
            throw new ApiException('permission_denied', 'Only the person who decides this kind of leave can cancel it.', 403);
        }
        if ($request->cancel_request === null) {
            $this->assertRemark((string) $remark, 'Say why the leave is being cancelled.');
        }

        $type = $request->kind === 'staff' ? $this->type((string) $request->type_code) : null;
        if ($type instanceof LeaveType) {
            $this->balances->prepare((string) $request->applicant['staff_id'], $type->code, (string) $request->year_id);
        }

        $saved = $this->transaction($request, function (LeaveRequest $fresh) use ($actor, $remark, $type): LeaveRequest {
            $this->assertMove($fresh, 'cancel');
            $approved = (array) $fresh->approved;
            $untakenCounted = $this->effects->untaken((array) ($approved['dates'] ?? []));
            $untakenWorking = $this->effects->untaken((array) ($approved['working'] ?? []));
            $restore = $fresh->half_day !== null ? ($untakenCounted === [] ? 0.0 : 0.5) : (float) count($untakenCounted);

            if ($type instanceof LeaveType) {
                $staffId = (string) $fresh->applicant['staff_id'];
                $this->balances->lock($staffId, $type->code, (string) $fresh->year_id);
                $this->balances->write('restore', $staffId, $type->code, (string) $fresh->year_id, $restore, (string) $fresh->getKey(), $actor);
                $this->effects->revertStaff($fresh, $untakenWorking);
            } else {
                $this->effects->revertStudent($fresh, $untakenWorking);
            }

            $fresh->fill([
                'status' => LeaveRequest::CANCELLED,
                'approved' => array_merge($approved, ['restored_days' => $restore, 'cancelled_dates' => $untakenWorking]),
                'cover_needed' => array_values(array_filter($fresh->cover_needed, fn (array $row) => ! in_array($row['date'], $untakenWorking, true))),
                'steps' => [...$fresh->steps, $this->step('decider', 'cancelled', $actor, $remark, ['restored_days' => $restore])],
            ])->save();

            return $fresh;
        });

        $this->tellApplicant($saved);

        return $saved;
    }

    /** The decider turns down a request to cancel: the leave stays. */
    public function keep(User $actor, LeaveRequest $request, string $remark): LeaveRequest
    {
        $this->assertMove($request, 'keep');

        if ($request->cancel_request === null) {
            throw new ApiException('no_cancel_request', 'Nobody has asked to cancel this leave.', 409);
        }
        if (! $this->deciders($request)->contains(fn (User $u) => (string) $u->getKey() === (string) $actor->getKey())) {
            throw new ApiException('permission_denied', 'Only the person who decides this kind of leave can answer this.', 403);
        }
        $this->assertRemark($remark, 'Say why the leave stays.');

        $request->fill([
            'cancel_request' => null,
            'steps' => [...$request->steps, $this->step('decider', 'cancel_declined', $actor, $remark)],
        ])->save();

        $this->tellApplicant($request);

        return $request;
    }

    /**
     * Who decides at the request's final stage (for cancelling an approved leave).
     *
     * @return Collection<int, User>
     */
    public function deciders(LeaveRequest $request): Collection
    {
        $final = $request->chain[count($request->chain) - 1] ?? 'principal';

        return $this->routing->actorsAt($request, $final);
    }

    /**
     * @return array{days: float, working: list<string>, counted: list<string>, off: list<array{date: string, why: string}>}
     */
    public function count(LeaveType $type, string $from, string $to, ?string $halfDay): array
    {
        if ($halfDay !== null && ! $type->half_day) {
            throw new ApiException('half_day_not_allowed', "{$type->name} cannot be taken as a half day.", 422);
        }

        return $this->days->count($from, $to, $type->sandwich, $halfDay);
    }

    public function type(string $code): LeaveType
    {
        $type = LeaveType::query()->where('code', $code)->first();

        if (! $type instanceof LeaveType || $type->status !== 'active') {
            throw new ApiException('leave_type_unknown', 'Choose one of the school\'s leave types.', 422);
        }

        return $type;
    }

    public function yearId(): string
    {
        $year = AcademicYear::current();

        return $year instanceof AcademicYear ? (string) $year->getKey() : throw new ApiException('no_current_year', 'The school has no current academic year yet.', 422);
    }

    public function assertMove(LeaveRequest $request, string $move): void
    {
        if (! in_array($move, self::MOVES[$request->status] ?? [], true)) {
            throw new ApiException('leave_transition_invalid', "A {$request->status} request cannot be ".str_replace('_', ' ', $move).'ed.', 409, [
                'status' => $request->status, 'move' => $move,
            ]);
        }
    }

    private function assertApplicant(User $actor, LeaveRequest $request): void
    {
        $id = (string) $actor->getKey();

        if ($id !== $request->applicantUserId() && $id !== ($request->applied_by['id'] ?? null)) {
            throw new ApiException('permission_denied', 'Only the person who applied can do this.', 403);
        }
    }

    private function assertMayAct(User $actor, LeaveRequest $request): void
    {
        $id = (string) $actor->getKey();

        if ($id === $request->applicantUserId() || $id === ($request->applied_by['id'] ?? null)) {
            throw new ApiException('own_leave', 'Nobody decides their own leave. It goes to the person above you.', 403);
        }

        if (! $this->routing->mayAct($actor, $request)) {
            throw new ApiException('not_your_stage', 'This request is waiting for someone else.', 403);
        }
    }

    private function assertRemark(string $text, string $message): void
    {
        if (mb_strlen(trim($text)) < 3) {
            throw new ApiException('remark_required', $message, 422);
        }
    }

    /** Give back days still held for a pending request (inside a transaction). */
    private function releaseHeld(LeaveRequest $fresh, User $actor): void
    {
        if ($fresh->kind !== 'staff' || $fresh->reserved_days <= 0) {
            return;
        }

        $staffId = (string) $fresh->applicant['staff_id'];
        $this->balances->prepare($staffId, (string) $fresh->type_code, (string) $fresh->year_id);
        $this->balances->lock($staffId, (string) $fresh->type_code, (string) $fresh->year_id);
        $this->balances->write('release', $staffId, (string) $fresh->type_code, (string) $fresh->year_id, $fresh->reserved_days, (string) $fresh->getKey(), $actor);
    }

    /**
     * Run the writes in one transaction, reading the request again inside it
     * each attempt, so a conflicting change made meanwhile is seen.
     *
     * @param  Closure(LeaveRequest): LeaveRequest  $callback
     */
    private function transaction(LeaveRequest $request, Closure $callback): LeaveRequest
    {
        $id = (string) $request->getKey();

        return DB::connection('mongodb')->transaction(function () use ($id, $callback): LeaveRequest {
            $fresh = LeaveRequest::query()->findOrFail($id);

            return $callback($fresh);
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function step(string $stage, string $action, User $by, ?string $remark = null, array $extra = []): array
    {
        $remark = $remark !== null && trim($remark) !== '' ? trim($remark) : null;

        return array_merge([
            'stage' => $stage,
            'action' => $action,
            'by' => ['id' => (string) $by->getKey(), 'name' => $by->name],
            'remark' => $remark === null ? null : SensitiveValue::seal($remark),
            'at' => now()->toIso8601String(),
        ], $extra);
    }

    private function tellApplicant(LeaveRequest $request): void
    {
        $users = User::query()->whereIn('_id', array_values(array_filter([$request->applicantUserId(), $request->applied_by['id'] ?? null])))->get();
        $this->notify($users, $request, 'leave.updated', 'leave.notifications.changed');
    }

    private function tellActors(LeaveRequest $request): void
    {
        $this->notify($this->routing->actorsNow($request), $request, 'leave.waiting', 'leave.notifications.waiting');
    }

    /** A fast-tracked request: the Clerk is told. */
    private function tellClerks(LeaveRequest $request): void
    {
        $this->notify($this->routing->actorsAt($request, 'clerk'), $request, 'leave.updated', 'leave.notifications.changed');
    }

    /**
     * Owner's rule: notifications say only that a request changed; never the
     * person, the type, the dates or the reason.
     *
     * @param  iterable<User>  $users
     */
    public function notify(iterable $users, LeaveRequest $request, string $type, string $titleKey): void
    {
        $tenant = Tenant::query()->find($this->tenant->require());
        if (! $tenant instanceof Tenant) {
            return;
        }

        $title = $type === 'leave.waiting' ? 'A leave request is waiting for you' : 'A leave request has an update';
        $this->notifier->notify($tenant, $users, $type, $title, null, '/leave/requests/'.$request->getKey(), ['request_id' => (string) $request->getKey(), 'title_key' => $titleKey]);
    }
}
