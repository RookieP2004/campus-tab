<?php

namespace App\Modules\AcademicStructure\Policies;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Years, classes, sections, subjects and teaching assignments. Staff can
 * view them (academic.view); the Chief and Principal set them up
 * (academic.manage).
 */
class AcademicStructurePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $actor): Response
    {
        return $this->can($actor, 'academic.view');
    }

    public function manage(User $actor): Response
    {
        return $this->can($actor, 'academic.manage');
    }

    private function can(User $actor, string $ability): Response
    {
        return $this->access->allows($actor, $ability)
            ? Response::allow()
            : Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
