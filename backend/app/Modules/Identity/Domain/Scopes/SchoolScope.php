<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/** The whole school. The tenant scope already keeps records to the user's school. */
class SchoolScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        return true;
    }
}
