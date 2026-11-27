<?php

namespace App\Modules\People\Models;

use App\Modules\Audit\Domain\Recyclable;
use App\Support\Models\SchoolModel;

/**
 * A staff member's record (HR side). The sign-in account is a separate
 * Identity user, linked by user_id.
 *
 * status:
 *   draft     made by a Clerk (draft for the Principal); no account exists
 *             until the Principal approves it (requested_roles wait here)
 *   active    has, or may have, an account
 *   inactive  deactivated; history stays
 *
 * @property string $employee_code
 * @property string $name
 * @property string|null $designation
 * @property string $type teaching | non_teaching
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $joined_on Y-m-d
 * @property string|null $user_id
 * @property list<string> $wings a Vice Principal's wings
 * @property string $status
 * @property list<string> $requested_roles roles a draft asks for
 * @property array{id: string, name: string}|null $drafted_by
 * @property array<string, mixed> $custom
 * @property string|null $import_batch_id
 */
class Staff extends SchoolModel implements Recyclable
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'staff';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'employee_code', 'name', 'designation', 'type', 'email', 'phone', 'joined_on',
        'user_id', 'wings', 'status', 'requested_roles', 'drafted_by', 'custom', 'import_batch_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'teaching', 'wings' => [], 'status' => self::STATUS_ACTIVE, 'requested_roles' => [],
        'custom' => [], 'user_id' => null, 'drafted_by' => null,
    ];

    public function auditCategory(): string
    {
        return 'admin';
    }

    public function recycleType(): string
    {
        return 'staff';
    }

    public function recycleLabel(): string
    {
        return $this->name;
    }
}
