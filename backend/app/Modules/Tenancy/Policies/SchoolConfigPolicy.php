<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Domain\ConfigSections;
use Illuminate\Auth\Access\Response;

/**
 * Who may see and change the school config, on the permission strings:
 *
 *  - view:   config.view:school (Chief and Principal by default).
 *  - update: checked per changed path.
 *      config.edit:school           any path (the Chief)
 *      config.edit_policies:school  only the sections in
 *                                   ConfigSections::PRINCIPAL_EDITABLE (the Principal)
 *    One forbidden path refuses the whole save.
 */
class SchoolConfigPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function view(User $user): Response
    {
        return $this->access->allows($user, 'config.view')
            ? Response::allow()
            : Response::deny('Only the Chief Admin and the Principal can see the school settings.', 'permission_denied');
    }

    /**
     * @param  list<string>  $paths  every changed path in the diff
     */
    public function update(User $user, array $paths): Response
    {
        if ($this->access->allows($user, 'config.edit')) {
            return Response::allow();
        }

        if (! $this->access->allows($user, 'config.edit_policies')) {
            return Response::deny('Only the Chief Admin and the Principal can change the school settings.', 'permission_denied');
        }

        $forbidden = ConfigSections::forbiddenForPrincipal($paths);

        return $forbidden === []
            ? Response::allow()
            : Response::deny('Only the Chief Admin can change: '.implode(', ', $forbidden).'.', 'config_section_forbidden');
    }
}
