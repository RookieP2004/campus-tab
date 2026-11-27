<?php

namespace App\Modules\Identity\Domain\Scopes;

use App\Modules\Identity\Models\User;

/**
 * Decides whether a permission held at one scope covers a given record.
 * One resolver per scope: own, child, subject, class, wing, school.
 */
interface ScopeResolver
{
    public function covers(User $user, AccessSubject $subject): bool;
}
