<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/** The user's own records. */
class OwnScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        return in_array((string) $user->getKey(), $subject->accessFacts()['owner_ids'] ?? [], true);
    }
}
