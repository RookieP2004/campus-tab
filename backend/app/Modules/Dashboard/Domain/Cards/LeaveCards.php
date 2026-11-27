<?php

namespace App\Modules\Dashboard\Domain\Cards;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Domain\LeaveApprovals;
use App\Modules\Leave\Domain\LeaveBalances;
use App\Modules\Leave\Domain\LeaveSettings;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Leave cards (Phase 5):
 *
 *   leave_waiting   approvers: requests waiting on me (links to the approvals inbox)
 *   staff_away      Clerk, Principal, Chief: who is away today (names only)
 *   my_leave        staff: my balance per leave type
 */
class LeaveCards implements CardProvider
{
    public function __construct(
        private readonly LeaveApprovals $approvals,
        private readonly LeaveBalances $balances,
        private readonly LeaveSettings $settings,
        private readonly SchoolConfigService $configs,
    ) {}

    public function cards(User $user, DashboardContext $context): array
    {
        if (! (bool) ($this->configs->current()->body['modules']['leave'] ?? false)) {
            return [];
        }

        $cards = [];
        $today = $this->settings->today();

        $approver = false;
        foreach (['leave.verify', 'leave.decide', 'leave.decide_principal', 'leave.fast_track', 'student_leave.approve'] as $ability) {
            $approver = $approver || $context->can($ability);
        }

        if ($approver) {
            $items = $this->approvals->itemsFor($user);
            if ($items !== []) {
                $cards[] = [
                    'id' => 'leave_waiting', 'kind' => 'todo', 'title' => 'dashboard.cards.leaveWaiting', 'priority' => 1, 'link' => '/approvals?tab=leave',
                    'data' => ['count' => count($items), 'items' => array_map(fn (array $i) => [
                        'kind' => 'leave', 'label' => $i['title'], 'detail' => $i['subtitle']['from'], 'link' => $i['link'], 'at' => $i['at'],
                    ], array_slice($items, 0, 5))],
                ];
            }
        }

        if ($context->can('leave.verify') || $context->can('leave.decide', 'school') || $context->can('leave.decide_principal')) {
            $away = LeaveRequest::query()->where('kind', 'staff')->where('status', LeaveRequest::APPROVED)
                ->where('from', '<=', $today)->where('to', '>=', $today)->get()
                ->filter(fn (LeaveRequest $r) => in_array($today, (array) ($r->approved['working'] ?? []), true));
            $cards[] = [
                'id' => 'staff_away', 'kind' => 'list', 'title' => 'dashboard.cards.staffAway', 'priority' => 6, 'link' => '/leave/calendar',
                'data' => ['count' => $away->count(), 'items' => $away->take(6)->map(fn (LeaveRequest $r) => [
                    'label' => $r->applicant['name'], 'detail' => null, 'link' => null,
                ])->values()->all()],
            ];
        }

        if ($context->can('leave.apply')) {
            $staff = Staff::query()->where('user_id', (string) $user->getKey())->where('status', Staff::STATUS_ACTIVE)->first();
            $year = AcademicYear::current();
            if ($staff instanceof Staff && $year instanceof AcademicYear) {
                $balances = array_values(array_filter($this->balances->all((string) $staff->getKey(), (string) $year->getKey()), fn (array $b) => $b['quota'] !== null));
                $cards[] = [
                    'id' => 'my_leave', 'kind' => 'list', 'title' => 'dashboard.cards.myLeave', 'priority' => 12, 'link' => '/leave',
                    'data' => ['items' => array_map(fn (array $b) => [
                        'label' => $b['type_name'], 'detail' => $b['available'].' / '.$b['quota'], 'link' => '/leave/apply?type='.$b['type_code'],
                    ], array_slice($balances, 0, 5))],
                ];
            }
        }

        return $cards;
    }
}
