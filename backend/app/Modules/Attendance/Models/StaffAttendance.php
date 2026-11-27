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
 * @property string $staff_id
 * @property string $date Y-m-d
 * @property string $status P | A | L | HD | LV
 * @property string|null $in HH:MM, school time
 * @property string|null $out HH:MM
 * @property string $source app | qr | biometric | manual
 * @property bool|null $inside_area
 * @property bool $late
 * @property array{id: string, name: string}|null $entered_by
 * @property string|null $reason
 */
class StaffAttendance extends SchoolModel
{
    public const SOURCES = ['app', 'qr', 'biometric', 'manual'];

    protected $table = 'staff_attendance';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'staff_id', 'date', 'status', 'in', 'out', 'source', 'inside_area', 'late', 'entered_by', 'reason'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'P', 'late' => false, 'inside_area' => null, 'entered_by' => null, 'reason' => null, 'out' => null];

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
