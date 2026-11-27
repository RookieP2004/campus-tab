<?php

namespace App\Modules\Tenancy\Domain;

/**
 * Which parts of the school config each role may change.
 *
 *  - The Chief may change every setting.
 *  - The Principal may change the policies (attendance, leave, marks, fees)
 *    and the academic settings only. Branding, modules, school identity,
 *    locale, sign-in methods and anything licence-related stay with the
 *    Chief. Inside those, a few switches stay the Chief's (CHIEF_ONLY):
 *    letting the Clerk reject leave is "the Chief's call" in the blueprint.
 *    This is an allow-list: a section added to the schema later is
 *    Chief-only until it is listed here.
 *  - Both may view all of it.
 *
 * SchoolConfigPolicy checks every changed path in the diff against this list.
 */
class ConfigSections
{
    /** @var list<string> */
    public const PRINCIPAL_EDITABLE = [
        'policies.attendance',
        'policies.leave',
        'policies.marks',
        'policies.fees',
        'academic',
    ];

    /** @var list<string> Chief-only even inside a Principal-editable section. */
    public const CHIEF_ONLY = [
        'policies.leave.clerk_can_reject',
    ];

    /**
     * @param  list<string>  $paths
     * @return list<string> the paths the Principal may not change
     */
    public static function forbiddenForPrincipal(array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path) => ! self::principalMayEdit($path)));
    }

    public static function principalMayEdit(string $path): bool
    {
        foreach (self::CHIEF_ONLY as $locked) {
            if ($path === $locked || str_starts_with($path, $locked.'.') || str_starts_with($locked, $path.'.')) {
                return false;
            }
        }

        foreach (self::PRINCIPAL_EDITABLE as $section) {
            if ($path === $section || str_starts_with($path, $section.'.')) {
                return true;
            }
        }

        return false;
    }
}
