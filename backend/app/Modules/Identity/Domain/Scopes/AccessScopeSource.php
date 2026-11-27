<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/**
 * Where a user's scope facts come from. Identity does not know the school's
 * records, so the People module binds the real source
 * (People\Domain\RecordAccessScopes): guardians, sections, teaching
 * assignments and staff wings for the current year.
 */
interface AccessScopeSource
{
    /**
     * @return array{children: list<string>, class_sections: list<string>, subject_sections: list<string>, wings: list<string>}
     */
    public function for(User $user): array;

    /** Forget what was worked out, after the records change. */
    public function flush(): void;
}
