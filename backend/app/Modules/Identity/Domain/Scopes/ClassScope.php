<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/** The sections a class teacher is assigned for the year. */
class ClassScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        $section = $subject->accessFacts()['section_id'] ?? null;

        return is_string($section) && in_array($section, $user->accessScope()['class_sections'], true);
    }
}
