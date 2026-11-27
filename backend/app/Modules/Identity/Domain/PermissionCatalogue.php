<?php

namespace App\Modules\Identity\Domain;

/**
 * The permission strings the code knows (config/permissions.php).
 *
 * A permission is "module.action:scope". Scopes, widest first:
 * school > wing > class > subject > own. "child" (a parent's children) is
 * covered only by itself or by school.
 */
final class PermissionCatalogue
{
    private const RANKED = ['school', 'wing', 'class', 'subject', 'own'];

    /**
     * @return array<string, list<string>> ability => allowed scopes
     */
    public static function abilities(): array
    {
        /** @var array<string, list<string>> $abilities */
        $abilities = config('permissions.abilities', []);

        return $abilities;
    }

    /**
     * @return array{ability: string, module: string, action: string, scope: string}|null
     */
    public static function parse(string $permission): ?array
    {
        if (preg_match('/^([a-z_]+)\.([a-z_]+):([a-z]+)$/', $permission, $m) !== 1) {
            return null;
        }

        return ['ability' => $m[1].'.'.$m[2], 'module' => $m[1], 'action' => $m[2], 'scope' => $m[3]];
    }

    public static function isValid(string $permission): bool
    {
        $parsed = self::parse($permission);

        return $parsed !== null && in_array($parsed['scope'], self::abilities()[$parsed['ability']] ?? [], true);
    }

    public static function knowsAbility(string $ability): bool
    {
        return isset(self::abilities()[$ability]);
    }

    /** Does holding a permission at $held cover the same ability at $wanted? */
    public static function scopeCovers(string $held, string $wanted): bool
    {
        if ($held === $wanted || $held === 'school') {
            return true;
        }

        if ($held === 'child' || $wanted === 'child') {
            return false;
        }

        $h = array_search($held, self::RANKED, true);
        $w = array_search($wanted, self::RANKED, true);

        return $h !== false && $w !== false && $h <= $w;
    }

    /**
     * Every valid permission string, for the role editor.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::abilities() as $ability => $scopes) {
            foreach ($scopes as $scope) {
                $all[] = "{$ability}:{$scope}";
            }
        }

        return $all;
    }
}
