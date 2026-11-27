<?php

namespace App\Modules\Dashboard\Domain\Cards;

use App\Modules\Audit\Domain\RecycleBin;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Domain\Seats;
use App\Modules\Licensing\Models\Licence;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * The Chief's and the Principal's cards: licence and seats, new modules the
 * licence brought, approvals waiting (staff drafts now; leave and marks
 * later), accounts by role, recent audit events and the recycle bin.
 */
class SchoolAdminCards implements CardProvider
{
    public function __construct(
        private readonly Seats $seats,
        private readonly SchoolConfigService $configs,
        private readonly RecycleBin $recycleBin,
    ) {}

    public function cards(User $user, DashboardContext $context): array
    {
        $cards = [];
        $licence = Licence::currentFor($context->tenant->id);

        // Chief: licence status and seats at the top.
        if ($context->can('config.edit', 'school') && $licence instanceof Licence) {
            $cards[] = [
                'id' => 'licence', 'kind' => 'licence', 'title' => 'dashboard.cards.licence', 'priority' => 0, 'link' => null,
                'data' => [
                    'plan' => $licence->plan,
                    'state' => $licence->state()->value,
                    'valid_to' => $licence->valid_to,
                    'students' => $this->seats->status('students'),
                    'staff' => $this->seats->status('staff'),
                ],
            ];

            // The licence added a module the school has not switched on: the
            // console never changes school settings, so the Chief is told.
            $switchedOn = (array) ($this->configs->current()->body['modules'] ?? []);
            foreach ($licence->licensedModules() as $module) {
                if (! ($switchedOn[$module] ?? false)) {
                    $cards[] = [
                        'id' => 'new_module.'.$module, 'kind' => 'banner', 'title' => 'dashboard.cards.newModule', 'params' => ['module' => $module],
                        'priority' => 1, 'link' => '/settings/school', 'data' => ['module' => $module],
                    ];
                }
            }
        }

        if ($context->can('staff.approve')) {
            $drafts = Staff::query()->where('status', Staff::STATUS_DRAFT)->orderBy('created_at')->limit(5)->get();
            $cards[] = [
                'id' => 'approvals', 'kind' => 'todo', 'title' => 'dashboard.cards.approvals',
                'priority' => $context->can('config.edit', 'school') ? 5 : 0, 'link' => '/staff?status=draft',
                'data' => [
                    'count' => Staff::query()->where('status', Staff::STATUS_DRAFT)->count(),
                    'items' => $drafts->map(fn (Staff $s) => [
                        'kind' => 'staff_draft', 'label' => $s->name, 'detail' => $s->designation, 'by' => $s->drafted_by['name'] ?? null,
                        'link' => '/staff/'.$s->getKey(), 'at' => $s->created_at?->toIso8601String(),
                    ])->values()->all(),
                ],
            ];
        }

        if ($context->can('users.view', 'school') && $context->can('roles.edit')) {
            $byRole = [];
            foreach (User::query()->where('status', '!=', User::STATUS_INACTIVE)->get(['roles']) as $u) {
                foreach ($u->roles as $role) {
                    $byRole[$role] = ($byRole[$role] ?? 0) + 1;
                }
            }
            arsort($byRole);
            $cards[] = ['id' => 'users_by_role', 'kind' => 'kpi', 'title' => 'dashboard.cards.usersByRole', 'priority' => 20, 'link' => '/staff', 'data' => ['by_role' => $byRole]];
        }

        if ($context->can('audit.view', 'school')) {
            $events = AuditLog::query()->orderBy('at', 'desc')->limit(6)->get();
            $cards[] = [
                'id' => 'recent_audit', 'kind' => 'list', 'title' => 'dashboard.cards.recentAudit', 'priority' => 40, 'link' => null,
                'data' => ['items' => $events->map(fn (AuditLog $e) => [
                    'label' => $e->action, 'detail' => $e->entity, 'audit' => true, 'by' => $e->actor['name'] ?? null, 'at' => $e->at->toIso8601String(), 'tone' => $e->category === 'security' ? 'warning' : null,
                ])->values()->all()],
            ];
        }

        if ($context->can('records.restore')) {
            $cards[] = ['id' => 'recycle_bin', 'kind' => 'kpi', 'title' => 'dashboard.cards.recycleBin', 'priority' => 45, 'link' => null, 'data' => ['count' => count($this->recycleBin->list())]];
        }

        return $cards;
    }
}
