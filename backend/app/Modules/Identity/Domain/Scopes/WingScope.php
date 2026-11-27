<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/** A Vice Principal's wing, for example "primary". */
class WingScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        $wing = $subject->accessFacts()['wing'] ?? null;

        return is_string($wing) && in_array($wing, $user->accessScope()['wings'], true);
    }
}
