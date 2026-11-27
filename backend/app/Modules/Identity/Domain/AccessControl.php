<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Modules\Identity\Domain\Scopes\ChildScope;
use App\Modules\Identity\Domain\Scopes\ClassScope;
use App\Modules\Identity\Domain\Scopes\OwnScope;
use App\Modules\Identity\Domain\Scopes\SchoolScope;
use App\Modules\Identity\Domain\Scopes\ScopeResolver;
use App\Modules\Identity\Domain\Scopes\SubjectScope;
use App\Modules\Identity\Domain\Scopes\WingScope;
use App\Modules\Identity\Models\User;

/**
 * Permission checks. Every endpoint checks on the server: routes carry
 * permission:<ability> and Policies call allows() with the record. The front
 * end only reads GET /me to hide what a user cannot do.
 */
class AccessControl
{
    /** @var array<string, class-string<ScopeResolver>> */
    public const RESOLVERS = [
        'school' => SchoolScope::class,
        'wing' => WingScope::class,
        'class' => ClassScope::class,
        'subject' => SubjectScope::class,
        'own' => OwnScope::class,
        'child' => ChildScope::class,
    ];

    /**
     * The scopes at which the user holds an ability.
     *
     * @return list<string>
     */
    public function scopesFor(User $user, string $ability): array
    {
        $scopes = [];

        foreach ($user->permissions() as $permission) {
            $parsed = PermissionCatalogue::parse($permission);

            if ($parsed !== null && $parsed['ability'] === $ability) {
                $scopes[] = $parsed['scope'];
            }
        }

        return $scopes;
    }

    /** Holds the ability at any scope (the route-level check). */
    public function holds(User $user, string $ability): bool
    {
        return $this->scopesFor($user, $ability) !== [];
    }

    /** Holds the ability at this scope or a wider one. */
    public function holdsAt(User $user, string $ability, string $scope): bool
    {
        foreach ($this->scopesFor($user, $ability) as $held) {
            if (PermissionCatalogue::scopeCovers($held, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * May the user do this to this record? Without a record, only a
     * school-wide permission counts.
     */
    public function allows(User $user, string $ability, ?AccessSubject $subject = null): bool
    {
        foreach ($this->scopesFor($user, $ability) as $scope) {
            if ($scope === 'school') {
                return true;
            }

            $resolver = self::RESOLVERS[$scope] ?? null;

            if ($subject !== null && $resolver !== null && app($resolver)->covers($user, $subject)) {
                return true;
            }
        }

        return false;
    }

    /** No escalation: you may grant only what you hold, at the same or a narrower scope. */
    public function canGrant(User $actor, string $permission): bool
    {
        $parsed = PermissionCatalogue::parse($permission);

        return $parsed !== null && PermissionCatalogue::isValid($permission) && $this->holdsAt($actor, $parsed['ability'], $parsed['scope']);
    }
}
