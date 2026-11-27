<?php

namespace App\Modules\Licensing\Domain;

use App\Modules\Identity\Models\User;
use App\Modules\Licensing\Models\Licence;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use App\Support\Tenancy\TenantContext;

/**
 * The licence's two limits, counted inside the school:
 *
 *   students  active students (not left, not in the recycle bin)
 *   staff     every school account that is not inactive, except accounts
 *             whose roles are only Parent and/or Student. Parents never
 *             count; a teacher who is also a parent counts once, as staff.
 *             A Clerk's draft has no account yet, so it does not count.
 *
 * Creating or importing past either limit is refused with one clear
 * message; an import's dry run shows the check first. A warning shows from
 * 90%. Renewing or changing seats in the console takes effect at once:
 * every check reads the current licence.
 */
class Seats
{
    public const KINDS = ['students', 'staff'];

    /** Roles that never use a staff seat. */
    public const NON_STAFF_ROLES = ['parent', 'student'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{limit: int, used: int, available: int, warning: bool}
     */
    public function status(string $kind = 'students'): array
    {
        $licence = Licence::currentFor($this->context->require());
        $limit = (int) ($licence?->verifiedPayload()['seats'][$kind] ?? 0);
        $used = $kind === 'students' ? self::studentsUsed() : self::staffUsed();

        return [
            'limit' => $limit,
            'used' => $used,
            'available' => max($limit - $used, 0),
            'warning' => $limit > 0 && $used >= (int) ceil($limit * 0.9),
        ];
    }

    /**
     * @return array{students: array{limit: int, used: int, available: int, warning: bool}, staff: array{limit: int, used: int, available: int, warning: bool}}
     */
    public function both(): array
    {
        return ['students' => $this->status('students'), 'staff' => $this->status('staff')];
    }

    public function assertRoom(string $kind, int $adding = 1): void
    {
        if ($adding <= 0) {
            return;
        }

        $seats = $this->status($kind);

        if ($adding > $seats['available']) {
            throw new ApiException('seat_limit_reached', self::message($kind, $seats, $adding), 422, array_merge($seats, ['kind' => $kind, 'adding' => $adding]));
        }
    }

    /**
     * @param  array{limit: int, used: int, available: int}  $seats
     */
    public static function message(string $kind, array $seats, int $adding): string
    {
        $what = $kind === 'students' ? 'students' : 'staff accounts';
        $inUse = $kind === 'students' ? 'are enrolled' : 'are in use';

        return "The licence allows {$seats['limit']} {$what} and {$seats['used']} {$inUse}, so {$seats['available']} more can be added, not {$adding}. "
            .'Ask the Chief Admin to contact Campus TAB to add seats.';
    }

    /**
     * Does an account with these roles use a staff seat?
     *
     * @param  list<string>  $roles
     */
    public static function usesStaffSeat(array $roles): bool
    {
        return array_diff($roles, self::NON_STAFF_ROLES) !== [];
    }

    public static function studentsUsed(): int
    {
        return Student::query()->where('status', 'active')->count();
    }

    public static function staffUsed(): int
    {
        // Any role other than Parent or Student, custom roles included.
        return User::query()
            ->where('status', '!=', User::STATUS_INACTIVE)
            ->where('roles', 'elemMatch', ['$nin' => self::NON_STAFF_ROLES])
            ->count();
    }
}
