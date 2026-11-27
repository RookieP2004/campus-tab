<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\SchoolRole;

/**
 * Seeds the system roles (nine levels and two duties, config/permissions.php)
 * into the current school. Existing roles are left alone, so a Chief's edits
 * survive.
 */
class SystemRoles
{
    /** @return int how many roles were created */
    public static function seed(): int
    {
        $existing = SchoolRole::query()->pluck('name')->all();
        $created = 0;

        /** @var array<string, array{label: string, level: int|null, duty?: string, permissions: list<string>}> $roles */
        $roles = config('permissions.roles', []);

        foreach ($roles as $name => $definition) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            SchoolRole::create([
                'name' => $name,
                'label' => $definition['label'],
                'level' => $definition['level'],
                'duty' => $definition['duty'] ?? null,
                'permissions' => array_values($definition['permissions']),
                'seeded_permissions' => array_values($definition['permissions']),
                'is_system' => true,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Gives system roles the permissions added to config/permissions.php
     * since they were seeded (a new phase's abilities). A permission the
     * Chief removed after seeding stays removed: only strings not in the
     * role's seeded_permissions are added. Returns the number added, per role.
     *
     * @return array<string, list<string>>
     */
    public static function addNewPermissions(): array
    {
        /** @var array<string, array{permissions: list<string>}> $roles */
        $roles = config('permissions.roles', []);
        $added = [];

        foreach (SchoolRole::query()->where('is_system', true)->get() as $role) {
            $definition = $roles[$role->name] ?? null;

            if ($definition === null) {
                continue;
            }

            /** @var list<string> $baseline */
            $baseline = $role->getAttribute('seeded_permissions') ?? $role->permissions;
            $new = array_values(array_diff($definition['permissions'], $baseline, $role->permissions));

            $role->forceFill([
                'permissions' => array_values(array_unique([...$role->permissions, ...$new])),
                'seeded_permissions' => array_values(array_unique([...$baseline, ...$definition['permissions']])),
            ])->save();

            if ($new !== []) {
                $added[$role->name] = $new;
            }
        }

        return $added;
    }
}
