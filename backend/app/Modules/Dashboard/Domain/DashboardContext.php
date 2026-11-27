<?php

namespace App\Modules\Dashboard\Domain;

use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\PermissionCatalogue;
use App\Modules\Identity\Domain\RoleRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;

/**
 * What a dashboard is being built for: the person, their school, and the
 * role they are looking as. A teacher who is also a parent switches between
 * "teacher" and "parent" (the role switcher); each view shows only that
 * role's cards, built from the permissions that role gives.
 */
final class DashboardContext
{
    /**
     * @param  list<string>  $permissions  the permissions of the role being viewed
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $role,
        public readonly array $permissions,
        private readonly AccessControl $access,
    ) {}

    public static function for(User $user, Tenant $tenant, ?string $as, AccessControl $access): self
    {
        $registry = app(RoleRegistry::class);
        $roles = $user->roles;

        // The highest-level role unless another of the person's own roles is asked for.
        usort($roles, fn (string $a, string $b) => ($registry->level($b) ?? 0) <=> ($registry->level($a) ?? 0));
        $role = is_string($as) && in_array($as, $user->roles, true) ? $as : ($roles[0] ?? '');

        // Duties (Fee Collector, Attendance Officer) go with whichever level role is viewed.
        $duties = array_values(array_filter($user->roles, fn (string $r) => $registry->level($r) === null));
        $permissions = $registry->permissionsFor(array_values(array_unique([$role, ...$duties])));

        return new self($tenant, $role, $permissions, $access);
    }

    /** Does the viewed role hold this ability at any scope (or at this one, or wider)? */
    public function can(string $ability, ?string $scope = null): bool
    {
        foreach ($this->permissions as $permission) {
            [$held, $at] = array_pad(explode(':', $permission, 2), 2, '');

            if ($held === $ability && ($scope === null || PermissionCatalogue::scopeCovers($at, $scope))) {
                return true;
            }
        }

        return false;
    }

    public function access(): AccessControl
    {
        return $this->access;
    }
}
