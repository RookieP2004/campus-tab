<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\SupportAgentUser;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Support access to our team. Only the Chief grants and revokes it (and,
 * by default, only the Chief sees it). A support agent can never touch
 * grants, whatever a grant says.
 */
class SupportGrantPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $actor): Response
    {
        return $this->can($actor, 'support.view');
    }

    public function manage(User $actor): Response
    {
        return $this->can($actor, 'support.grant');
    }

    private function can(User $actor, string $ability): Response
    {
        if ($actor instanceof SupportAgentUser) {
            return Response::deny('Support access cannot change support access.', 'support_not_allowed');
        }

        return $this->access->allows($actor, $ability)
            ? Response::allow()
            : Response::deny('Only the Chief Admin can manage support access.', 'permission_denied');
    }
}
