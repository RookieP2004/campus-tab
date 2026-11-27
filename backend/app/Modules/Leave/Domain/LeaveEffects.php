<?php

namespace App\Modules\Leave\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\AcademicStructure\Models\Subject;
use App\Modules\AcademicStructure\Models\TeachingAssignment;
use App\Modules\Attendance\Domain\AttendanceSettings;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Leave\Models\LeaveRequest;
use Carbon\CarbonImmutable;

/**
 * What an approved (or cancelled) leave changes elsewhere. Called inside the
 * workflow's transaction, so approval, the ledger and attendance commit
 * together or not at all.
 *
 *  - Staff: each working day of the leave gets an LV staff attendance
 *    entry with source "leave" (a system entry, not a manual one). A day
 *    the person already checked in on is left alone.
 *  - Students: registers already saved for those days get LV for the child;
 *    registers opened later pre-fill LV (AttendanceRegister asks
 *    studentsOnLeave()).
 *  - Cancelling removes what it added for the days not yet taken, and puts
 *    back anything it had replaced.
 *  - The needs-cover list: the person's teaching assignments (and the
 *    register of a class they teach) for each working day.
 */
class LeaveEffects
{
    public function __construct(
        private readonly LeaveSettings $settings,
        private readonly AttendanceSettings $attendance,
    ) {}

    /**
     * @param  list<string>  $dates  working days of the leave
     */
    public function applyStaff(LeaveRequest $request, array $dates): void
    {
        $staffId = (string) $request->applicant['staff_id'];

        foreach ($dates as $date) {
            $existing = StaffAttendance::query()->where('staff_id', $staffId)->where('date', $date)->first();

            if ($existing instanceof StaffAttendance && $existing->in !== null) {
                continue; // they came in that day
            }

            $values = [
                'staff_id' => $staffId, 'date' => $date, 'status' => 'LV', 'in' => null, 'out' => null, 'source' => 'leave',
                'inside_area' => null, 'late' => false, 'entered_by' => null, 'reason' => null, 'leave_request_id' => (string) $request->getKey(),
            ];

            if ($existing instanceof StaffAttendance) {
                $values['replaced'] = array_intersect_key($existing->getAttributes(), array_flip(['status', 'source', 'entered_by', 'reason', 'late']));
                $existing->fill($values)->save();
            } else {
                StaffAttendance::create($values);
            }
        }
    }

    /**
     * @param  list<string>  $dates
     */
    public function revertStaff(LeaveRequest $request, array $dates): void
    {
        $rows = StaffAttendance::query()->where('leave_request_id', (string) $request->getKey())->whereIn('date', $dates)->get();

        foreach ($rows as $row) {
            if (is_array($row->replaced)) {
                $row->fill(array_merge($row->replaced, ['leave_request_id' => null, 'replaced' => null]))->save();
            } else {
                $row->forceDelete();
            }
        }
    }

    /**
     * @param  list<string>  $dates
     */
    public function applyStudent(LeaveRequest $request, array $dates, string $status = 'LV', string $from = 'P'): void
    {
        $studentId = (string) $request->applicant['student_id'];
        $days = AttendanceDay::query()->where('section_id', (string) $request->section_id)->whereIn('date', $dates)->get();

        foreach ($days as $day) {
            $changed = false;
            $entries = array_map(function (array $e) use ($studentId, $status, $from, &$changed) {
                // Only a "present" default becomes leave (and only leave goes back to present):
                // a teacher's own A, L or HD is left as they marked it.
                if ($e['student_id'] === $studentId && $e['status'] === $from) {
                    $e['status'] = $status;
                    $changed = true;
                }

                return $e;
            }, $day->entries);

            if ($changed) {
                $day->fill(['entries' => $entries])->save();
            }
        }
    }

    /**
     * @param  list<string>  $dates
     */
    public function revertStudent(LeaveRequest $request, array $dates): void
    {
        $this->applyStudent($request, $dates, 'P', 'LV');
    }

    /**
     * The days of an approved leave not taken yet: after today, and today too
     * when school has not started.
     *
     * @param  list<string>  $dates
     * @return list<string>
     */
    public function untaken(array $dates): array
    {
        $now = $this->settings->now();
        $today = $now->toDateString();
        $startsAt = $this->attendance->all()['school_starts_at'];
        $todayUntaken = $now->format('H:i') < $startsAt;

        return array_values(array_filter($dates, fn (string $d) => $d > $today || ($d === $today && $todayUntaken)));
    }

    /**
     * @param  list<string>  $dates  working days
     * @return list<array{date: string, section_id: string, section: string, subject: string|null, kind: string}>
     */
    public function coverFor(string $staffId, array $dates): array
    {
        $year = AcademicYear::current();
        if (! $year instanceof AcademicYear || $dates === []) {
            return [];
        }

        $assignments = TeachingAssignment::query()->where('year_id', (string) $year->getKey())->where('teacher_id', $staffId)->get();
        $registers = Section::query()->where('year_id', (string) $year->getKey())->where('class_teacher_id', $staffId)->get();
        $sections = Section::query()->whereIn('_id', array_values(array_unique([...$assignments->pluck('section_id')->all(), ...$registers->map(fn ($s) => (string) $s->getKey())->all()])))->get()->keyBy(fn ($s) => (string) $s->getKey());
        $subjects = Subject::query()->whereIn('_id', $assignments->pluck('subject_id')->unique()->values()->all())->get()->keyBy(fn ($s) => (string) $s->getKey());

        $rows = [];
        foreach ($dates as $date) {
            foreach ($registers as $section) {
                $rows[] = ['date' => $date, 'section_id' => (string) $section->getKey(), 'section' => $section->label, 'subject' => null, 'kind' => 'register'];
            }
            foreach ($assignments as $a) {
                $rows[] = [
                    'date' => $date, 'section_id' => $a->section_id, 'section' => $sections[$a->section_id]->label ?? '', 'subject' => $subjects[$a->subject_id]->name ?? null, 'kind' => 'lesson',
                ];
            }
        }

        usort($rows, fn ($x, $y) => [$x['date'], $x['kind'] === 'register' ? 0 : 1, $x['section']] <=> [$y['date'], $y['kind'] === 'register' ? 0 : 1, $y['section']]);

        return $rows;
    }

    /** Is the date a Sunday or other weekly off day? */
    public static function weeklyOff(string $date): bool
    {
        return in_array(CarbonImmutable::parse($date)->isoWeekday(), array_map('intval', (array) config('leave.weekly_off', [7])), true);
    }
}
