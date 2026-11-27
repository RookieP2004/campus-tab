<?php

namespace App\Modules\Attendance\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\CalendarEvent;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Staff;
use App\Modules\People\Models\Student;
use Carbon\CarbonImmutable;

/**
 * Attendance reports, always limited to the sections the person may see
 * (attendance.view: school, wing, class, subject; parents their children).
 *
 *   daily summary   per section: marked or not, counts, % present; staff today
 *   monthly register  students x days, printable, with each student's %
 *   percentage      present = P + L + half of HD, over marked days that were not holidays
 *   below threshold students under the school's min_percent_alert, year to date
 */
class AttendanceReports
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AttendanceRegister $register,
        private readonly AttendanceSettings $settings,
    ) {}

    /**
     * @return list<string>|null null = every section
     */
    public function visibleSections(User $user): ?array
    {
        $scopes = $this->access->scopesFor($user, 'attendance.view');

        if (in_array('school', $scopes, true)) {
            return null;
        }

        $mine = $user->accessScope();
        $sections = [];

        if (in_array('wing', $scopes, true) && $mine['wings'] !== []) {
            $sections = array_merge($sections, Section::query()->whereIn('wing', $mine['wings'])->get()->map(fn (Section $s) => (string) $s->getKey())->all());
        }
        if (in_array('class', $scopes, true)) {
            $sections = array_merge($sections, $mine['class_sections']);
        }
        if (in_array('subject', $scopes, true)) {
            $sections = array_merge($sections, array_map(fn (string $k) => strtok($k, ':') ?: '', $mine['subject_sections']));
        }

        return array_values(array_unique(array_filter($sections)));
    }

    /**
     * @return array<string, mixed>
     */
    public function daily(User $user, string $date): array
    {
        $year = AcademicYear::current();
        $visible = $this->visibleSections($user);
        $query = Section::query()->where('year_id', (string) $year?->getKey())->orderBy('label');
        if ($visible !== null) {
            $query->whereIn('_id', $visible);
        }

        $sections = $query->get();
        $days = AttendanceDay::query()->where('date', $date)->whereIn('section_id', $sections->map(fn (Section $s) => (string) $s->getKey())->all())->get()->groupBy('section_id');
        $rows = [];
        $totals = ['students' => 0, 'present' => 0, 'absent' => 0, 'marked_sections' => 0];

        foreach ($sections as $section) {
            $sectionDays = $days[(string) $section->getKey()] ?? collect();
            $day = $sectionDays->sortBy('period')->first();
            $counts = ['P' => 0, 'A' => 0, 'L' => 0, 'HD' => 0, 'LV' => 0, 'H' => 0];

            if ($day instanceof AttendanceDay) {
                foreach ($day->entries as $entry) {
                    $counts[$entry['status']] = ($counts[$entry['status']] ?? 0) + 1;
                }
            }

            $total = array_sum($counts);
            $present = $counts['P'] + $counts['L'] + $counts['HD'] * 0.5;
            $rows[] = [
                'section_id' => (string) $section->getKey(),
                'section' => $section->label,
                'marked' => $day instanceof AttendanceDay,
                'late_entry' => $day instanceof AttendanceDay && $day->late_entry !== null,
                'counts' => $counts,
                'percent' => $total > 0 ? round($present * 100 / $total, 1) : null,
            ];

            if ($day instanceof AttendanceDay) {
                $totals['marked_sections']++;
                $totals['students'] += $total;
                $totals['present'] += $present;
                $totals['absent'] += $counts['A'];
            }
        }

        $staff = null;
        if ($this->access->allows($user, 'staff_attendance.view') || $this->access->allows($user, 'staff_attendance.manage')) {
            $records = StaffAttendance::query()->where('date', $date)->get();
            $active = Staff::query()->where('status', Staff::STATUS_ACTIVE)->count();
            $staff = [
                'total' => $active,
                'present' => $records->whereIn('status', ['P', 'L', 'HD'])->count(),
                'late' => $records->where('late', true)->count(),
                'not_in' => max($active - $records->whereNotNull('in')->count() - $records->where('source', 'manual')->whereNull('in')->count(), 0),
            ];
        }

        return [
            'date' => $date,
            'sections' => $rows,
            'totals' => array_merge($totals, [
                'sections' => count($rows),
                'percent' => $totals['students'] > 0 ? round($totals['present'] * 100 / $totals['students'], 1) : null,
            ]),
            'staff' => $staff,
        ];
    }

    /**
     * The printable monthly register for one section.
     *
     * @return array<string, mixed>
     */
    public function monthly(Section $section, string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');
        $end = $start->endOfMonth();
        $dates = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $days = AttendanceDay::query()->where('section_id', (string) $section->getKey())->where('period', 0)
            ->where('date', '>=', $start->toDateString())->where('date', '<=', $end->toDateString())->get()->keyBy('date');
        $holidays = CalendarEvent::query()->where('type', 'holiday')->where('status', 'approved')
            ->where('date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())->get();

        $students = [];
        foreach ($this->register->roll($section) as $student) {
            $marks = [];
            foreach ($dates as $date) {
                $day = $days[$date] ?? null;
                $holiday = $holidays->first(fn ($h) => $h->date <= $date && $h->end_date >= $date);
                $marks[$date] = $holiday !== null ? 'H' : ($day instanceof AttendanceDay ? ($day->statusByStudent()[$student['id']] ?? null) : null);
            }

            $students[] = array_merge($student, ['marks' => $marks, 'percent' => self::percentOf(array_values($marks))]);
        }

        return [
            'section' => ['id' => (string) $section->getKey(), 'label' => $section->label],
            'month' => $month,
            'dates' => $dates,
            'late_entries' => $days->filter(fn (AttendanceDay $d) => $d->late_entry !== null)->keys()->values()->all(),
            'students' => $students,
        ];
    }

    /**
     * One student's days (for the parent, the student, and their teachers):
     * the month's marks and the % this year. The class's other students are
     * never included.
     *
     * @return array<string, mixed>
     */
    public function student(Student $student, string $month): array
    {
        $sectionId = $student->currentEnrolment?->section_id;
        $id = (string) $student->getKey();
        $days = $sectionId === null ? collect() : AttendanceDay::query()->where('section_id', $sectionId)->where('period', 0)->get();
        $year = $days->map(fn (AttendanceDay $d) => $d->statusByStudent()[$id] ?? null)->values()->all();
        $inMonth = $days->filter(fn (AttendanceDay $d) => str_starts_with($d->date, $month))->sortBy('date');

        return [
            'student' => ['id' => $id, 'name' => $student->name],
            'month' => $month,
            'days' => $inMonth->map(fn (AttendanceDay $d) => [
                'date' => $d->date, 'status' => $d->statusByStudent()[$id] ?? null, 'late_entry' => $d->late_entry !== null,
            ])->values()->all(),
            'percent' => self::percentOf($year),
            'threshold' => $this->settings->all()['min_percent_alert'],
        ];
    }

    /**
     * Each student's % this year, for the sections the person may see, and
     * those below the school's threshold.
     *
     * @return array{threshold: int, students: list<array<string, mixed>>}
     */
    public function belowThreshold(User $user, ?string $sectionId = null): array
    {
        $threshold = $this->settings->all()['min_percent_alert'];
        $year = AcademicYear::current();
        $visible = $this->visibleSections($user);
        $query = Section::query()->where('year_id', (string) $year?->getKey());

        if ($visible !== null) {
            $query->whereIn('_id', $visible);
        }
        if ($sectionId !== null) {
            $query->where('_id', $sectionId);
        }

        $out = [];
        foreach ($query->get() as $section) {
            $days = AttendanceDay::query()->where('section_id', (string) $section->getKey())->where('period', 0)->get();

            foreach ($this->register->roll($section) as $student) {
                $marks = $days->map(fn (AttendanceDay $d) => $d->statusByStudent()[$student['id']] ?? null)->all();
                $percent = self::percentOf(array_values($marks));

                if ($percent !== null && $percent < $threshold) {
                    $out[] = array_merge($student, ['section' => $section->label, 'section_id' => (string) $section->getKey(), 'percent' => $percent, 'days' => count(array_filter($marks))]);
                }
            }
        }

        usort($out, fn ($a, $b) => $a['percent'] <=> $b['percent']);

        return ['threshold' => $threshold, 'students' => $out];
    }

    /**
     * @param  list<string|null>  $marks
     */
    public static function percentOf(array $marks): ?float
    {
        $counted = array_filter($marks, fn ($m) => $m !== null && $m !== 'H');

        if ($counted === []) {
            return null;
        }

        $present = 0.0;
        foreach ($counted as $mark) {
            $present += match ($mark) {
                'P', 'L' => 1.0,
                'HD' => 0.5,
                default => 0.0,
            };
        }

        return round($present * 100 / count($counted), 1);
    }
}
