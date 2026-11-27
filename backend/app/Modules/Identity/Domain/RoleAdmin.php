<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\SchoolRole;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiException;

/**
 * Editing the permission matrix and adding custom roles, within the guard
 * rules:
 *
 *  - only permission strings from the catalogue (config/permissions.php);
 *  - no escalation: every permission added must be one the editor holds,
 *    at the same or a wider scope;
 *  - only roles below the editor's level; the Chief role is fixed;
 *  - a duty (Fee Collector, Attendance Officer) may hold only the abilities
 *    in its allow-list (config/permissions.php 'duties').
 */
class RoleAdmin
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly Authority $authority,
        private readonly RoleRegistry $roles,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public function update(User $actor, SchoolRole $role, array $permissions, ?string $label = null): SchoolRole
    {
        if (! $this->authority->canEditRole($actor, $role)) {
            throw $role->name === Role::Chief->value
                ? new ApiException('role_locked', 'The Chief Admin role always has every school permission; it cannot be edited.', 409)
                : new ApiException('escalation_blocked', 'You can only edit roles below your own level.', 403);
        }

        $permissions = $this->checkPermissions($actor, $role->duty, $permissions, $role->permissions ?? []);

        $role->forceFill(array_filter([
            'permissions' => $permissions,
            'label' => $label,
        ], fn ($v) => $v !== null))->save();

        return $role;
    }

    /**
     * @param  array{name: string, label: string, level: int, permissions: list<string>}  $input
     */
    public function create(User $actor, array $input): SchoolRole
    {
        if ($this->roles->find($input['name']) !== null || Role::tryFrom($input['name']) !== null) {
            throw new ApiException('role_exists', 'A role with this name already exists.', 422);
        }

        if ($input['level'] >= $actor->level()) {
            throw new ApiException('escalation_blocked', 'A new role must be below your own level.', 403);
        }

        $permissions = $this->checkPermissions($actor, null, $input['permissions'], []);

        return SchoolRole::create([
            'name' => $input['name'],
            'label' => $input['label'],
            'level' => $input['level'],
            'duty' => null,
            'permissions' => $permissions,
            'is_system' => false,
        ]);
    }

    /**
     * @param  list<string>  $wanted
     * @param  list<string>  $current
     * @return list<string>
     */
    private function checkPermissions(User $actor, ?string $duty, array $wanted, array $current): array
    {
        $wanted = array_values(array_unique($wanted));
        $unknown = array_values(array_filter($wanted, fn (string $p) => ! PermissionCatalogue::isValid($p)));

        if ($unknown !== []) {
            throw new ApiException('permission_unknown', 'These permissions do not exist.', 422, ['permissions' => $unknown]);
        }

        if ($duty !== null) {
            $outside = array_values(array_filter($wanted, fn (string $p) => ! self::dutyAllows($duty, $p)));

            if ($outside !== []) {
                throw new ApiException('duty_limited', 'A duty can only hold the rights it was made for.', 422, [
                    'permissions' => $outside,
                    'allowed' => config("permissions.duties.{$duty}", []),
                ]);
            }
        }

        $notHeld = array_values(array_filter(
            array_diff($wanted, $current),
            fn (string $p) => ! $this->access->canGrant($actor, $p),
        ));

        if ($notHeld !== []) {
            throw new ApiException('escalation_blocked', 'You cannot give permissions you do not hold yourself.', 403, ['permissions' => $notHeld]);
        }

        return $wanted;
    }

    /** A duty's allow-list (config/permissions.php 'duties'); an unknown duty allows nothing. */
    public static function dutyAllows(string $duty, string $permission): bool
    {
        $ability = PermissionCatalogue::parse($permission)['ability'] ?? null;

        foreach ((array) config("permissions.duties.{$duty}", []) as $pattern) {
            if (is_string($ability) && fnmatch($pattern, $ability)) {
                return true;
            }
        }

        return false;
    }
}
