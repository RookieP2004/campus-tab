<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/**
 * The subject-sections a subject teacher teaches ("<section_id>:<subject_id>",
 * from teaching assignments).
 *
 *  - A record about one subject-section (a paper, a markbook column) is
 *    covered when its subject_key is one of them.
 *  - A record about a student, which has a section but no subject, is
 *    covered when the teacher teaches any subject in that section.
 */
class SubjectScope implements ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool
    {
        $facts = $subject->accessFacts();
        $mine = $user->accessScope()['subject_sections'];
        $key = $facts['subject_key'] ?? null;

        if (is_string($key)) {
            return in_array($key, $mine, true);
        }

        $section = $facts['section_id'] ?? null;

        if (! is_string($section)) {
            return false;
        }

        foreach ($mine as $taught) {
            if (str_starts_with($taught, $section.':')) {
                return true;
            }
        }

        return false;
    }
}
