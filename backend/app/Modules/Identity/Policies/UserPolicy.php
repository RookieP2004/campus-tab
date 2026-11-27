<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Authority;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Staff accounts. Each action needs its permission string, and every action
 * on an existing account also needs the actor to outrank that account
 * (Domain\Authority). Role changes are checked role by role in UserAdmin.
 */
class UserPolicy
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly Authority $authority,
    ) {}

    public function viewAny(User $actor): Response
    {
        return $this->can($actor, 'users.view');
    }

    public function create(User $actor): Response
    {
        return $this->can($actor, 'users.create');
    }

    public function changeRoles(User $actor, User $target): Response
    {
        return $this->onTarget($actor, $target, 'users.edit_roles');
    }

    public function deactivate(User $actor, User $target): Response
    {
        return $this->onTarget($actor, $target, 'users.deactivate');
    }

    public function unlock(User $actor, User $target): Response
    {
        return $this->onTarget($actor, $target, 'users.unlock');
    }

    public function sendPasswordLink(User $actor, User $target): Response
    {
        return $this->onTarget($actor, $target, 'users.send_password_link');
    }

    public function resetMfa(User $actor, User $target): Response
    {
        return $this->onTarget($actor, $target, 'users.reset_mfa');
    }

    private function onTarget(User $actor, User $target, string $ability): Response
    {
        $allowed = $this->can($actor, $ability);

        if ($allowed->denied()) {
            return $allowed;
        }

        return $this->authority->outranks($actor, $target)
            ? Response::allow()
            : Response::deny('You can only manage people below your own level.', 'level_too_low');
    }

    private function can(User $actor, string $ability): Response
    {
        return $this->access->allows($actor, $ability)
            ? Response::allow()
            : Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
