<?php

namespace App\Modules\Leave\Models;

use App\Support\Models\SchoolModel;

/**
 * One document per staff member, leave type and year whose only job is to
 * make two transactions on the same balance collide. Every ledger write
 * bumps `version` inside its transaction, so when two approvals (or a
 * submit and an approval) touch the same balance at once, MongoDB aborts
 * one of them with a write conflict and it runs again on the new balance.
 * The balance itself is always worked out from the ledger.
 *
 * @property string $staff_id
 * @property string $year_id
 * @property string $type_code
 * @property int $version
 */
class LeaveBalanceLock extends SchoolModel
{
    protected $table = 'leave_balance_locks';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'staff_id', 'year_id', 'type_code', 'version'];

    /** @var array<string, mixed> */
    protected $attributes = ['version' => 0];

    /**
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return ['updated_at', 'version'];
    }

    public function auditCategory(): string
    {
        return 'admin';
    }
}
