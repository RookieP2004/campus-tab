<?php

namespace App\Modules\Leave\Models;

use App\Support\Http\ApiException;
use App\Support\Models\SchoolModel;

/**
 * One movement on a staff member's leave balance. Append-only: an entry is
 * never changed or deleted; a mistake is put right by a new entry.
 *
 *   reserve   days held when a request is submitted         reserved += days
 *   release   held days given back (reject, withdraw, return
 *             with new dates, approval of the held days)     reserved -= days
 *   deduct    days taken when the leave is approved          taken    += days
 *   restore   days given back when an approved leave is
 *             cancelled before those days were taken         taken    -= days
 *
 * Available = quota - reserved - taken (LeaveBalances).
 *
 * @property string $staff_id
 * @property string $year_id
 * @property string $type_code
 * @property string $kind
 * @property float $days
 * @property string|null $request_id
 * @property array{id: string, name: string}|null $by
 */
class LeaveLedgerEntry extends SchoolModel
{
    public const KINDS = ['reserve', 'release', 'deduct', 'restore'];

    protected $table = 'leave_ledger';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'staff_id', 'year_id', 'type_code', 'kind', 'days', 'request_id', 'by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['days' => 'float'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new ApiException('ledger_append_only', 'Leave ledger entries are never changed; add a new entry instead.', 409));
        static::deleting(fn () => throw new ApiException('ledger_append_only', 'Leave ledger entries are never deleted; add a new entry instead.', 409));
    }

    public function auditCategory(): string
    {
        return 'admin';
    }
}
