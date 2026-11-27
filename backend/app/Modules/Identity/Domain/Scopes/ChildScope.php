<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/** A parent's linked children (links arrive with guardians in Phase 2). */
class ChildScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        $students = $subject->accessFacts()['student_ids'] ?? [];

        return $students !== [] && array_intersect($students, $user->accessScope()['children']) !== [];
    }
}
