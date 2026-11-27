<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\ConfigSections;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and change the school config.
 *
 *  - view:   the Chief and the Principal see all of it.
 *  - update: checked per changed path. The Chief may change anything; the
 *            Principal only the sections in ConfigSections::PRINCIPAL_EDITABLE.
 *            One forbidden path refuses the whole save.
 *
 * Phase 1 part B maps these checks onto the RBAC permission strings without
 * changing the section rules.
 */
class SchoolConfigPolicy
{
    public function view(User $user): Response
    {
        return $user->hasRole(Role::Chief) || $user->hasRole(Role::Principal)
            ? Response::allow()
            : Response::deny('Only the Chief Admin and the Principal can see the school settings.');
    }

    /**
     * @param  list<string>  $paths  every changed path in the diff
     */
    public function update(User $user, array $paths): Response
    {
        if ($user->hasRole(Role::Chief)) {
            return Response::allow();
        }

        if (! $user->hasRole(Role::Principal)) {
            return Response::deny('Only the Chief Admin and the Principal can change the school settings.');
        }

        $forbidden = ConfigSections::forbiddenForPrincipal($paths);

        return $forbidden === []
            ? Response::allow()
            : Response::deny('Only the Chief Admin can change: '.implode(', ', $forbidden).'.', 'config_section_forbidden');
    }
}
