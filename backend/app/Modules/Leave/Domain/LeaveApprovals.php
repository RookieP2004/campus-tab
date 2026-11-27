<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Approvals\Domain\ApprovalSource;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Leave requests in the shared approvals inbox: those this person may act
 * on now (their stage, a fast-track, or a request to cancel an approved
 * leave they decide). The applicant never sees their own here.
 */
class LeaveApprovals implements ApprovalSource
{
    public function __construct(
        private readonly LeaveRouting $routing,
        private readonly LeaveWorkflow $workflow,
        private readonly SchoolConfigService $configs,
    ) {}

    public function kind(): string
    {
        return 'leave';
    }

    public function itemsFor(User $user): array
    {
        if (! (bool) ($this->configs->current()->body['modules']['leave'] ?? false)) {
            return [];
        }

        $id = (string) $user->getKey();
        $rows = LeaveRequest::query()
            ->where(fn ($q) => $q->whereIn('status', LeaveRequest::PENDING)->orWhere(fn ($q) => $q->where('status', LeaveRequest::APPROVED)->whereNotNull('cancel_request')))
            ->orderBy('stage_since')->limit(300)->get();

        $items = [];
        foreach ($rows as $r) {
            $flags = [];
            if ($r->isPending()) {
                $fast = $this->routing->mayFastTrack($user, $r);
                if (! $this->routing->mayAct($user, $r) && ! $fast) {
                    continue;
                }
                if ($fast) {
                    $flags[] = 'fast_track';
                }
            } elseif (! $this->workflow->deciders($r)->contains(fn (User $u) => (string) $u->getKey() === $id)) {
                continue;
            } else {
                $flags[] = 'cancel_requested';
            }

            if ($r->principal_sees) {
                $flags[] = 'long_student_leave';
            }

            $last = $r->steps[count($r->steps) - 1] ?? null;
            $items[] = [
                'kind' => 'leave',
                'id' => (string) $r->getKey(),
                'title' => $r->applicant['name'].($r->kind === 'staff' ? ' · '.$r->type_name : ''),
                'detail' => null,
                'subtitle' => [
                    'from' => $r->from, 'to' => $r->to, 'days' => $r->days, 'student' => $r->kind === 'student', 'section' => $r->applicant['section'] ?? null,
                    'last_action' => $last['action'] ?? null, 'last_stage' => $last['stage'] ?? null, 'last_by' => $last['by']['name'] ?? null,
                ],
                'status' => $r->status,
                'at' => $r->stage_since?->toIso8601String() ?? ($last['at'] ?? null),
                'link' => '/approvals?item=leave:'.$r->getKey(),
                'flags' => $flags,
            ];
        }

        return $items;
    }
}
