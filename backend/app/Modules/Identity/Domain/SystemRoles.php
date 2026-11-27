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
                'is_system' => true,
            ]);
            $created++;
        }

        return $created;
    }
}
