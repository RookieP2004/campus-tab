<?php

namespace App\Modules\Attendance\Models;

use App\Support\Models\SchoolModel;

/**
 * One staff member's attendance for one date: check-in and check-out, how
 * it came in (app, qr, biometric, manual), and whether it was late.
 *
 * For a geofenced app check-in only inside_area (true or false) is kept;
 * the location itself is never stored or logged.
 *
 * A manual entry names who entered it and why; it always comes from
 * someone at a higher level than the person it is for (StaffAttendanceService).
 *
 * Approved leave writes LV with source "leave" (Leave\Domain\LeaveEffects):
 * a system entry from the approved request, not a manual one, so the
 * "nobody enters their own attendance" rule is not touched. If it replaced
 * a record, `replaced` keeps that record's values so cancelling the leave
 * puts them back.
 *
 * @property string $staff_id
 * @property string $date Y-m-d
 * @property string $status P | A | L | HD | LV
 * @property string|null $in HH:MM, school time
 * @property string|null $out HH:MM
 * @property string $source app | qr | biometric | manual | leave
 * @property bool|null $inside_area
 * @property bool $late
 * @property array{id: string, name: string}|null $entered_by
 * @property string|null $reason
 * @property string|null $leave_request_id
 * @property array<string, mixed>|null $replaced
 */
class StaffAttendance extends SchoolModel
{
    public const SOURCES = ['app', 'qr', 'biometric', 'manual', 'leave'];

    protected $table = 'staff_attendance';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'staff_id', 'date', 'status', 'in', 'out', 'source', 'inside_area', 'late', 'entered_by', 'reason', 'leave_request_id', 'replaced'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'P', 'late' => false, 'inside_area' => null, 'entered_by' => null, 'reason' => null, 'out' => null, 'leave_request_id' => null, 'replaced' => null];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['late' => 'boolean'];
    }

    public function auditCategory(): string
    {
        return 'admin';
    }
}
