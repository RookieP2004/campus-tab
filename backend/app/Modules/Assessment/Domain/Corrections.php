<?php

namespace App\Modules\Assessment\Domain;

use App\Modules\Assessment\Models\CorrectionRequest;
use App\Modules\Assessment\Models\Paper;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Models\DeviceSession;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Changing a locked mark. A fixed rule in code, not a setting:
 *
 *  1. Someone with marks.correction_request over the paper raises a request:
 *     the student, the new mark (or AB / ML) and a reason. The old mark is
 *     taken from the paper.
 *  2. Only someone holding marks.correction_approve (the Principal) decides,
 *     from their own account: the route needs step-up MFA
 *     (marks_correction_approve) and the Principal types a reason.
 *  3. If the Principal raised the request, approving it is still a separate
 *     step: the MFA code must have been entered after the request was raised.
 *     It is flagged self_approved and shown to the Chief (owner's rule).
 *  4. The mark changes in a MongoDB transaction together with the request,
 *     and only if the paper still holds the old mark. The request and the
 *     audit log keep old and new mark, both reasons, who asked, who approved
 *     and when.
 */
class Corrections
{
    public function __construct(
        private readonly PaperAccess $access,
        private readonly AccessControl $control,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenant,
        private readonly RoleRegistry $roles,
    ) {}

    /**
     * @param  array{value?: mixed, code?: mixed}  $new
     */
    public function raise(User $user, Paper $paper, string $studentId, array $new, string $reason): CorrectionRequest
    {
        if (! $paper->isLocked()) {
            throw new ApiException('paper_not_locked', 'This paper is not locked yet: change the mark in the markbook.', 409);
        }
        if (! $this->access->mayRequestCorrection($user, $paper)) {
            throw new ApiException('permission_denied', 'You may not ask for corrections on this paper.', 403);
        }
        $this->assertReason($reason, 'Say why the mark should change (at least '.$this->min().' characters).');

        $current = $paper->marksByStudent()[$studentId] ?? null;
        if ($current === null) {
            throw new ApiException('student_not_in_paper', 'This student has no mark on this paper.', 422);
        }

        $cell = Markbook::cell($new, $paper->max_marks, $studentId);
        $old = ['value' => isset($current['value']) ? (float) $current['value'] : null, 'code' => $current['code'] ?? null];
        $newMark = ['value' => $cell['value'], 'code' => $cell['code']];

        if ($old === $newMark) {
            throw new ApiException('correction_same', 'The new mark is the same as the locked one.', 422);
        }
        if ($newMark['value'] === null && $newMark['code'] === null) {
            throw new ApiException('mark_invalid', 'Give the new mark, AB or ML.', 422);
        }
        if (CorrectionRequest::query()->where('paper_id', (string) $paper->getKey())->where('student_id', $studentId)->where('status', 'pending')->exists()) {
            throw new ApiException('correction_pending', 'A correction for this mark is already waiting for the Principal.', 409);
        }

        $student = Student::query()->find($studentId);
        $correction = CorrectionRequest::create([
            'paper_id' => (string) $paper->getKey(),
            'assessment_id' => $paper->assessment_id,
            'student_id' => $studentId,
            'student_name' => $student instanceof Student ? $student->name : '',
            'section_id' => $paper->section_id,
            'label' => $paper->subject_name.' '.$paper->section_label,
            'old' => $old,
            'new' => $newMark,
            'reason' => trim($reason),
            'requested_by' => ['id' => (string) $user->getKey(), 'name' => $user->name, 'role' => $this->roleLabel($user)],
            'requested_by_id' => (string) $user->getKey(),
            'status' => 'pending',
        ]);

        $this->notify($this->approvers(), 'marks.correction', 'A mark correction is waiting for you', '/approvals?item=marks:'.$correction->getKey());

        return $correction;
    }

    public function approve(User $user, CorrectionRequest $correction, string $reason, ?DeviceSession $session): CorrectionRequest
    {
        $this->assertDecider($user);
        $this->assertReason($reason, 'Type why you approve this change (at least '.$this->min().' characters).');

        $self = $correction->requested_by_id === (string) $user->getKey();

        // Raising and approving are separate steps: the code must be newer than the request.
        if ($self && (! $session instanceof DeviceSession || $session->step_up_at === null || $session->step_up_at->lessThanOrEqualTo($correction->created_at))) {
            throw new ApiException('step_up_required', 'You raised this correction yourself. Enter a new code from your authenticator app to approve it as a separate step.', 403, [
                'action' => 'marks_correction_approve', 'valid_minutes' => (int) config('identity.step_up.valid_minutes', 10), 'self' => true,
            ]);
        }

        $id = (string) $correction->getKey();

        $saved = DB::connection('mongodb')->transaction(function () use ($id, $user, $reason, $self): CorrectionRequest {
            $fresh = CorrectionRequest::query()->findOrFail($id);
            if ($fresh->status !== 'pending') {
                throw new ApiException('correction_decided', 'This correction has already been decided.', 409);
            }

            $paper = Paper::query()->findOrFail($fresh->paper_id);
            if (! $paper->isLocked()) {
                throw new ApiException('paper_not_locked', 'The paper is not locked.', 409);
            }

            $marks = $paper->marksByStudent();
            $current = $marks[$fresh->student_id] ?? null;
            $now = ['value' => isset($current['value']) ? (float) $current['value'] : null, 'code' => $current['code'] ?? null];
            if ($current === null || $now !== $fresh->old) {
                throw new ApiException('correction_stale', 'The mark has changed since this correction was raised. Ask for a new correction.', 409);
            }

            $marks[$fresh->student_id] = array_merge($current, ['value' => $fresh->new['value'], 'code' => $fresh->new['code']]);
            $paper->fill([
                'marks' => array_values($marks),
                'version' => $paper->version + 1,
                'history' => [...$paper->history, [
                    'action' => 'corrected', 'by' => ['id' => (string) $user->getKey(), 'name' => $user->name], 'note' => null,
                    'at' => now()->toIso8601String(), 'correction_id' => (string) $fresh->getKey(), 'student_id' => $fresh->student_id,
                ]],
            ])->save();

            $fresh->fill([
                'status' => 'approved',
                'decided_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
                'decision_reason' => trim($reason),
                'decided_at' => now(),
                'self_approved' => $self,
            ])->save();

            $this->audit->record('marks.correction_applied', 'academic', 'paper', (string) $paper->getKey(), $fresh->old, $fresh->new, [
                'correction_id' => (string) $fresh->getKey(), 'student_id' => $fresh->student_id, 'reason' => $fresh->reason,
                'requested_by' => $fresh->requested_by['name'], 'approved_by' => $user->name, 'approval_reason' => trim($reason), 'self_approved' => $self,
            ]);

            return $fresh;
        }, 5);

        $this->notify(User::query()->whereKey($saved->requested_by_id)->get(), 'marks.correction', 'Your correction request has an update', '/tests/corrections');

        if ($self) {
            $chiefs = User::query()->where('roles', 'chief')->where('status', User::STATUS_ACTIVE)->get();
            $this->notify($chiefs, 'marks.correction', 'A mark correction was self-approved', '/tests/corrections?self_approved=1');
        }

        return $saved;
    }

    public function reject(User $user, CorrectionRequest $correction, string $reason): CorrectionRequest
    {
        $this->assertDecider($user);
        $this->assertReason($reason, 'Say why the mark stays (at least '.$this->min().' characters).');

        if ($correction->status !== 'pending') {
            throw new ApiException('correction_decided', 'This correction has already been decided.', 409);
        }

        $correction->fill([
            'status' => 'rejected',
            'decided_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            'decision_reason' => trim($reason),
            'decided_at' => now(),
        ])->save();

        $this->notify(User::query()->whereKey($correction->requested_by_id)->get(), 'marks.correction', 'Your correction request has an update', '/tests/corrections');

        return $correction;
    }

    /**
     * @return Collection<int, User>
     */
    public function approvers(): Collection
    {
        $names = [];
        foreach ($this->roles->all() as $name => $role) {
            if (in_array('marks.correction_approve:school', $role->permissions ?? [], true)) {
                $names[] = $name;
            }
        }

        return $names === [] ? collect() : User::query()->whereIn('roles', $names)->where('status', User::STATUS_ACTIVE)->get();
    }

    private function assertDecider(User $user): void
    {
        if (! $this->control->allows($user, 'marks.correction_approve')) {
            throw new ApiException('permission_denied', 'Only the Principal approves changes to locked marks.', 403);
        }
    }

    private function assertReason(string $reason, string $message): void
    {
        if (mb_strlen(trim($reason)) < $this->min()) {
            throw new ApiException('reason_required', $message, 422);
        }
    }

    private function min(): int
    {
        return (int) config('assessment.reason_min', 10);
    }

    private function roleLabel(User $user): ?string
    {
        $best = null;
        foreach ($user->roles ?? [] as $role) {
            $level = $this->roles->level($role);
            if ($level !== null && ($best === null || $level > $best[1])) {
                $best = [$role, $level];
            }
        }

        return $best[0] ?? null;
    }

    /**
     * @param  iterable<User>  $users
     */
    private function notify(iterable $users, string $type, string $title, string $link): void
    {
        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant) {
            $this->notifier->notify($tenant, $users, $type, $title, null, $link, []);
        }
    }
}
