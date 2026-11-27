<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\SchoolRole;
use App\Support\Tenancy\TenantContext;

/**
 * The current school's roles, loaded once per request (and reloaded after
 * any role is saved). System role levels are fixed in code (Domain\Role);
 * custom roles carry their own level.
 */
class RoleRegistry
{
    /** @var array<string, array<string, SchoolRole>> tenant id => role name => role */
    private array $roles = [];

    /**
     * @return array<string, SchoolRole>
     */
    public function all(): array
    {
        $tenantId = app(TenantContext::class)->require();

        if (! isset($this->roles[$tenantId])) {
            $this->roles[$tenantId] = [];

            foreach (SchoolRole::query()->get() as $role) {
                $this->roles[$tenantId][$role->name] = $role;
            }
        }

        return $this->roles[$tenantId];
    }

    public function find(string $name): ?SchoolRole
    {
        return $this->all()[$name] ?? null;
    }

    /** A role's level; null for a duty or an unknown role. */
    public function level(string $name): ?int
    {
        $system = Role::tryFrom($name);

        if ($system !== null && $system !== Role::PlatformAdmin) {
            return $system->level();
        }

        if ($name === Role::PlatformAdmin->value) {
            return null;
        }

        return $this->find($name)?->level;
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    public function permissionsFor(array $roleNames): array
    {
        $permissions = [];

        foreach ($roleNames as $name) {
            foreach ($this->find($name)->permissions ?? [] as $permission) {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    public function flush(): void
    {
        $this->roles = [];
    }
}
