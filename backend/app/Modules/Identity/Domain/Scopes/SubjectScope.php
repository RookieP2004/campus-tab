<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/** The subject-sections a subject teacher teaches ("<section_id>:<subject_id>"). */
class SubjectScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        $key = $subject->accessFacts()['subject_key'] ?? null;

        return is_string($key) && in_array($key, $user->accessScope()['subject_sections'], true);
    }
}
