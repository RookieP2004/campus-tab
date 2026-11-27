<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Domain\LeaveRouting;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Auth\Access\Response;

/**
 * Leave. A request (with its reason, remarks and documents) is visible only
 * to its applicant and the people in its chain; for everyone else it does
 * not exist (404). Who may make which move is checked in LeaveWorkflow.
 */
class LeavePolicy
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly LeaveRouting $routing,
    ) {}

    public function view(User $actor, LeaveRequest $request): Response
    {
        return $this->routing->canSeeDetails($actor, $request) ? Response::allow() : Response::denyAsNotFound('The record was not found.');
    }

    public function manageTypes(User $actor): Response
    {
        return $this->access->allows($actor, 'leave.manage_types') ? Response::allow() : $this->deny();
    }

    public function delegate(User $actor): Response
    {
        return $this->access->allows($actor, 'leave.delegate') ? Response::allow() : $this->deny();
    }

    public function viewCover(User $actor): Response
    {
        foreach (['leave.verify', 'leave.decide', 'leave.decide_principal'] as $ability) {
            if ($this->access->holds($actor, $ability)) {
                return Response::allow();
            }
        }

        return $this->deny();
    }

    private function deny(): Response
    {
        return Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
