<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\SchoolRole;
use App\Modules\Identity\Models\User;

/**
 * The level rules ("Role hierarchy and authority"): a user manages only
 * people and roles below their own level. The one exception is the top of
 * the school: a Chief may manage other Chiefs, or there could never be a
 * second Chief. The Chief role's own permissions are fixed.
 */
class Authority
{
    public const TOP = 90;

    public function outranks(User $actor, User $target): bool
    {
        $a = $actor->level();
        $t = $target->level();

        return $a > $t || ($a >= self::TOP && $t >= self::TOP);
    }

    /** May the actor give this role to someone, or take it away? */
    public function canAssign(User $actor, SchoolRole $role): bool
    {
        $level = $role->level ?? 0;
        $a = $actor->level();

        return $level < $a || ($a >= self::TOP && $level === self::TOP);
    }

    /** May the actor change this role's permissions? */
    public function canEditRole(User $actor, SchoolRole $role): bool
    {
        if ($role->name === Role::Chief->value) {
            return false;
        }

        return ($role->level ?? 0) < $actor->level();
    }
}
