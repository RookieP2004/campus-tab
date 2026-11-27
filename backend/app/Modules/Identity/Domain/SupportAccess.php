<?php

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Models\SupportGrant;

/**
 * Turns a support grant into permission strings: the abilities of the
 * granted areas' modules at school scope; view abilities only when the grant
 * is read-only; never anything in config('identity.support.never').
 */
final class SupportAccess
{
    /**
     * @return list<string>
     */
    public static function permissionsFor(SupportGrant $grant): array
    {
        /** @var array<string, list<string>> $areas */
        $areas = config('identity.support.areas', []);
        /** @var list<string> $never */
        $never = config('identity.support.never', []);

        $modules = [];

        foreach ($grant->scope ?? [] as $area) {
            foreach ($areas[$area] ?? [] as $module) {
                $modules[$module] = true;
            }
        }

        $permissions = [];

        foreach (PermissionCatalogue::abilities() as $ability => $scopes) {
            [$module, $action] = explode('.', $ability, 2);

            if (! isset($modules[$module]) || ! in_array('school', $scopes, true)) {
                continue;
            }

            if ($grant->read_only && ! str_contains($action, 'view')) {
                continue;
            }

            foreach ($never as $pattern) {
                if (fnmatch($pattern, $ability)) {
                    continue 2;
                }
            }

            $permissions[] = "{$ability}:school";
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function areas(): array
    {
        return array_keys((array) config('identity.support.areas', []));
    }
}
