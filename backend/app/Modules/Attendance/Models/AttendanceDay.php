<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Support\Models\SchoolModel;
use Illuminate\Support\Carbon;

/**
 * One section's register for one date (and one period in period mode;
 * period 0 is the daily register). The students' marks are embedded.
 *
 *   status  P present · A absent · L late · HD half-day · LV leave · H holiday
 *
 * A day marked after the date, from the paper register, is a late entry:
 * late_entry holds the reason, who entered it and when, and an optional
 * photo of the paper page. Every change is audited (Auditable).
 *
 * @property string $section_id
 * @property string $year_id
 * @property string $date Y-m-d
 * @property int $period
 * @property list<array{student_id: string, status: string}> $entries
 * @property array{id: string, name: string} $marked_by
 * @property Carbon $marked_at
 * @property string $source screen | excel
 * @property array{reason: string, entered_by: array{id: string, name: string}, entered_at: string, photo_path: string|null, by_office: bool}|null $late_entry
 * @property list<string> $notified_absent student ids whose parents were told
 * @property string|null $wing
 */
class AttendanceDay extends SchoolModel implements AccessSubject
{
    public const STATUSES = ['P', 'A', 'L', 'HD', 'LV', 'H'];

    /** Codes a teacher sets; LV and H come from leave and the calendar. */
    public const MARKABLE = ['P', 'A', 'L', 'HD'];

    protected $table = 'attendance_days';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'section_id', 'year_id', 'date', 'period', 'entries', 'marked_by', 'marked_at', 'source', 'late_entry', 'notified_absent', 'wing'];

    /** @var array<string, mixed> */
    protected $attributes = ['period' => 0, 'entries' => [], 'source' => 'screen', 'late_entry' => null, 'notified_absent' => []];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period' => 'integer', 'marked_at' => 'datetime'];
    }

    public function accessFacts(): array
    {
        return ['section_id' => $this->section_id, 'wing' => $this->wing];
    }

    /**
     * @return array<string, string> student id => status
     */
    public function statusByStudent(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $out[$entry['student_id']] = $entry['status'];
        }

        return $out;
    }
}
