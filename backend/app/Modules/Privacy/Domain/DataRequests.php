<?php

namespace App\Modules\Privacy\Domain;

use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Privacy\Models\DataRequest;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The data-requests inbox (DPDP): access, correction and erasure.
 *
 *  - Anyone signed in asks for themselves; a parent may also ask about
 *    their own child. The request is due privacy.request_due_days later.
 *  - The Chief and the Principal (privacy.requests) see the inbox, newest
 *    and late ones first, and move a request on: in progress, completed
 *    (with what was done) or rejected (with why). The requester sees the
 *    outcome in their account; notifications carry only generic text.
 *  - Access: the office downloads a copy of the child's record
 *    (StudentDataExport) and hands it over. Correction: the office edits the
 *    record as usual. Erasure of a child: StudentErasure (Chief only,
 *    step-up). The audit log and the fee ledger stay as they are (decision
 *    0017); the outcome says so.
 */
class DataRequests
{
    public function __construct(private readonly TenantContext $tenant, private readonly Notifier $notifier) {}

    /** @param array{type: string, student_id?: string|null, details: string} $input */
    public function create(User $user, array $input): DataRequest
    {
        $subject = ['subject_type' => 'self', 'student_id' => null, 'subject_name' => $user->name];
        if (! empty($input['student_id'])) {
            $student = $this->ownChild($user, (string) $input['student_id'])
                ?? throw new ApiException('not_your_child', 'You can ask only about yourself or your own children.', 403);
            $subject = ['subject_type' => 'student', 'student_id' => (string) $student->getKey(), 'subject_name' => $student->name];
        }
        $request = DataRequest::create($subject + [
            'type' => $input['type'],
            'requested_by' => ['id' => (string) $user->getKey(), 'name' => $user->name, 'role' => (string) ($user->roles[0] ?? '')],
            'requester_id' => (string) $user->getKey(),
            'details' => trim($input['details']),
            'due_on' => CarbonImmutable::now('Asia/Kolkata')->addDays((int) config('privacy.request_due_days', 30))->toDateString(),
            'history' => [['at' => now()->toIso8601String(), 'by' => $user->name, 'note' => 'Request received']],
        ]);

        $tenant = Tenant::query()->find($this->tenant->require());
        if ($tenant instanceof Tenant) {
            $handlers = User::query()->whereIn('roles', [Role::Chief->value, Role::Principal->value])->where('status', User::STATUS_ACTIVE)->get();
            $this->notifier->notify($tenant, $handlers, 'privacy.request', 'A new data request ('.$request->type.') is waiting', null, '/privacy/requests', []);
        }

        return $request;
    }

    /** @param array{status: string, outcome?: string|null} $input */
    public function move(User $user, DataRequest $request, array $input): DataRequest
    {
        if (! in_array($request->status, DataRequest::OPEN, true)) {
            throw new ApiException('request_closed', 'This request is already closed.', 409);
        }
        $status = $input['status'];
        $outcome = trim((string) ($input['outcome'] ?? ''));
        if (in_array($status, ['completed', 'rejected'], true) && mb_strlen($outcome) < 10) {
            throw new ApiException('outcome_required', 'Write what was done, or why the request is refused (at least 10 characters). The person sees it.', 422);
        }
        $request->fill([
            'status' => $status,
            'handled_by' => ['id' => (string) $user->getKey(), 'name' => $user->name],
            'outcome' => $outcome !== '' ? $outcome : $request->outcome,
            'completed_at' => in_array($status, ['completed', 'rejected'], true) ? now()->toIso8601String() : null,
            'history' => [...$request->history, ['at' => now()->toIso8601String(), 'by' => $user->name, 'note' => ucfirst(str_replace('_', ' ', $status)).($outcome !== '' ? ': '.$outcome : '')]],
        ])->save();

        $tenant = Tenant::query()->find($this->tenant->require());
        $requester = User::query()->find($request->requester_id);
        if ($tenant instanceof Tenant && $requester instanceof User) {
            $this->notifier->notify($tenant, [$requester], 'privacy.request', 'Your data request has an update', null, '/privacy', []);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    public static function present(DataRequest $r, bool $office): array
    {
        $today = CarbonImmutable::now('Asia/Kolkata')->toDateString();

        return [
            'id' => (string) $r->getKey(), 'type' => $r->type, 'status' => $r->status, 'subject_type' => $r->subject_type,
            'student_id' => $r->student_id, 'subject_name' => $r->subject_name, 'details' => $r->details, 'due_on' => $r->due_on,
            'late' => in_array($r->status, DataRequest::OPEN, true) && $r->due_on < $today,
            'outcome' => $r->outcome, 'completed_at' => $r->completed_at, 'created_at' => $r->created_at?->toIso8601String(),
            'requested_by' => $office ? $r->requested_by : null,
            'handled_by' => $office ? ($r->handled_by['name'] ?? null) : null,
            'history' => $office ? $r->history : [],
        ];
    }

    private function ownChild(User $user, string $studentId): ?Student
    {
        $ids = Guardian::query()->where('user_id', (string) $user->getKey())->get()->flatMap(fn (Guardian $g) => $g->student_ids)->all();

        return in_array($studentId, $ids, true) ? Student::query()->find($studentId) : null;
    }
}
