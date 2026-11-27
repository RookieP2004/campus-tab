<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The permission matrix. The Chief edits it, the Principal views it (the
 * defaults). Which roles and permissions an editor may touch is checked in
 * Domain\RoleAdmin (no escalation, Chief role fixed, duties limited).
 */
class RolePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $actor): Response
    {
        return $this->can($actor, 'roles.view');
    }

    public function edit(User $actor): Response
    {
        return $this->can($actor, 'roles.edit');
    }

    private function can(User $actor, string $ability): Response
    {
        return $this->access->allows($actor, $ability)
            ? Response::allow()
            : Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
