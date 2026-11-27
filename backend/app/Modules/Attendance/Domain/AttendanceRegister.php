<?php

namespace App\Modules\Attendance\Domain;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\CalendarEvent;
use App\Modules\Audit\Domain\AuditRecorder;
use App\Modules\Communication\Domain\Notifier;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Scopes\AccessSubject;
use App\Modules\Identity\Domain\Scopes\ClassScope;
use App\Modules\Identity\Domain\Scopes\WingScope;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;

/**
 * A section's register for a date: who may mark it, when, and what happens.
 *
 *  - Everyone starts present; approved holidays close the day (H) and
 *    approved leave will fill LV (Phase 5).
 *  - Daily mode: the class teacher (or anyone with class, wing or school
 *    scope) marks period 0. Period mode: subject teachers too, periods 1..N.
 *  - Today, until the edit window closes (school start + edit_window_hours),
 *    the teacher marks and changes it freely.
 *  - After the window, a change needs the Clerk or the Principal
 *    (attendance.edit_after_window) and a reason.
 *  - A past day never marked is a late entry from the paper register: the
 *    teacher may enter it within late_entry_days, with a reason (and a photo
 *    of the page if they like). Past that limit only the Clerk or the
 *    Principal may, with a reason. A late entry never overwrites a day that
 *    was already marked.
 *  - Every save is audited (with the reason); parents of newly absent
 *    students are told, naming the date for a late entry.
 */
class AttendanceRegister
{
    public function __construct(
        private readonly AttendanceSettings $settings,
        private readonly AccessControl $access,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The register screen's data.
     *
     * @return array<string, mixed>
     */
    public function day(User $user, Section $section, string $date, int $period): array
    {
        $this->assertMayView($user, $section);
        $period = $this->checkPeriod($period);
        $existing = $this->find($section, $date, $period);
        $holiday = CalendarEvent::holidayOn($date);
        $students = $this->roll($section);
        $marks = $existing?->statusByStudent() ?? [];

        return [
            'section' => ['id' => (string) $section->getKey(), 'label' => $section->label],
            'date' => $date,
            'period' => $period,
            'mode' => $this->settings->all()['student_mode'],
            'holiday' => $holiday === null ? null : ['title' => $holiday->title],
            'marked' => $existing !== null,
            'marked_by' => $existing?->marked_by['name'] ?? null,
            'marked_at' => $existing?->marked_at->toIso8601String(),
            'late_entry' => $existing?->late_entry === null ? null : [
                'reason' => $existing->late_entry['reason'],
                'entered_by' => $existing->late_entry['entered_by']['name'] ?? null,
                'entered_at' => $existing->late_entry['entered_at'],
                'has_photo' => is_string($existing->late_entry['photo_path'] ?? null),
                'by_office' => (bool) ($existing->late_entry['by_office'] ?? false),
            ],
            'source' => $existing?->source,
            'day_id' => $existing === null ? null : (string) $existing->getKey(),
            'rules' => $this->rules($user, $section, $date, $existing, $holiday !== null),
            'students' => array_map(fn (array $s) => array_merge($s, [
                'status' => $holiday !== null ? 'H' : ($marks[$s['id']] ?? 'P'),
            ]), $students),
        ];
    }

    /**
     * @param  array<string, string>  $statuses  student id => P | A | L | HD (missing students stay present)
     */
    public function save(User $user, Tenant $tenant, Section $section, string $date, int $period, array $statuses, ?string $reason, string $source = 'screen'): AttendanceDay
    {
        $period = $this->checkPeriod($period);
        $existing = $this->find($section, $date, $period);
        $rules = $this->rules($user, $section, $date, $existing, CalendarEvent::holidayOn($date) !== null);

        if (! $rules['can_save']) {
            throw new ApiException($rules['refusal'] ?? 'attendance_forbidden', $rules['refusal_message'] ?? 'You cannot mark this register.', $rules['refusal'] === 'future_date' || $rules['refusal'] === 'holiday' ? 422 : 403);
        }

        $reason = trim((string) $reason);
        if ($rules['needs_reason'] && mb_strlen($reason) < 5) {
            throw new ApiException('reason_required', $rules['reason_kind'] === 'late_entry'
                ? 'Say why this day is being entered late (for example "No internet on 3 December; entered from the paper register").'
                : 'Say why the register is being changed after the edit window.', 422);
        }

        $roll = array_column($this->roll($section), 'id');
        foreach ($statuses as $studentId => $status) {
            if (! in_array($studentId, $roll, true)) {
                throw new ApiException('student_not_in_section', 'One of the students is not in this section.', 422, ['student_id' => $studentId]);
            }
            if (! in_array($status, AttendanceDay::MARKABLE, true)) {
                throw new ApiException('attendance_status_invalid', 'Use P (present), A (absent), L (late) or HD (half-day).', 422, ['student_id' => $studentId]);
            }
        }

        $entries = array_map(fn (string $id) => ['student_id' => $id, 'status' => $statuses[$id] ?? 'P'], $roll);
        $by = ['id' => (string) $user->getKey(), 'name' => $user->name];
        $year = AcademicYear::current();

        $day = AuditRecorder::withMeta(array_filter([
            'reason' => $reason !== '' ? $reason : null,
            'late_entry' => $rules['reason_kind'] === 'late_entry' ? true : null,
            'after_window' => $rules['reason_kind'] === 'late_edit' ? true : null,
            'source' => $source,
        ], fn ($v) => $v !== null), function () use ($existing, $section, $date, $period, $entries, $by, $year, $rules, $reason, $source): AttendanceDay {
            if ($existing instanceof AttendanceDay) {
                $existing->fill(['entries' => $entries, 'marked_by' => $by, 'marked_at' => now(), 'source' => $source])->save();

                return $existing;
            }

            return AttendanceDay::create([
                'section_id' => (string) $section->getKey(),
                'year_id' => $year instanceof AcademicYear ? (string) $year->getKey() : null,
                'date' => $date,
                'period' => $period,
                'wing' => $section->wing,
                'entries' => $entries,
                'marked_by' => $by,
                'marked_at' => now(),
                'source' => $source,
                'late_entry' => $rules['reason_kind'] === 'late_entry' ? [
                    'reason' => $reason,
                    'entered_by' => $by,
                    'entered_at' => now()->toIso8601String(),
                    'photo_path' => null,
                    'by_office' => $rules['office_only'],
                ] : null,
            ]);
        });

        $this->notifyAbsences($tenant, $day);

        return $day;
    }

    /**
     * What this person may do with this register now.
     *
     * @return array{can_save: bool, needs_reason: bool, reason_kind: string|null, office_only: bool, window_closes_at: string, late_entry_until: string, refusal: string|null, refusal_message: string|null}
     */
    public function rules(User $user, Section $section, string $date, ?AttendanceDay $existing, bool $holiday): array
    {
        $settings = $this->settings->all();
        $today = $this->settings->today();
        $closes = $this->settings->windowClosesAt($date);
        $lateUntil = CarbonImmutable::parse($date, $this->settings->timezone())->addDays($settings['late_entry_days'])->toDateString();
        $subject = $this->subject($section);
        $marker = $this->mayMark($user, $subject, $settings['student_mode']);
        $office = $this->access->allows($user, 'attendance.edit_after_window', $subject);

        $result = fn (bool $can, bool $reason, ?string $kind, bool $officeOnly, ?string $refusal = null, ?string $message = null) => [
            'can_save' => $can, 'needs_reason' => $reason, 'reason_kind' => $kind, 'office_only' => $officeOnly,
            'window_closes_at' => $closes->toIso8601String(), 'late_entry_until' => $lateUntil,
            'refusal' => $refusal, 'refusal_message' => $message,
        ];

        if ($date > $today) {
            return $result(false, false, null, false, 'future_date', 'A register cannot be marked for a day that has not come yet.');
        }

        if ($holiday) {
            return $result(false, false, null, false, 'holiday', 'This day is a holiday in the school calendar.');
        }

        if (! $marker && ! $office) {
            return $result(false, false, null, false, 'attendance_forbidden', 'You cannot mark this section\'s register.');
        }

        // Already marked: free changes until the window closes, then the office with a reason.
        if ($existing instanceof AttendanceDay) {
            if ($this->settings->now()->lessThanOrEqualTo($closes) && $existing->late_entry === null) {
                return $result(true, false, null, false);
            }

            return $office
                ? $result(true, true, 'late_edit', true)
                : $result(false, true, 'late_edit', true, 'attendance_window_closed', 'The edit window for this day has closed. Ask the Clerk or the Principal to make the change, with a reason.');
        }

        // Never marked: today is ordinary marking; a past day is a late entry.
        if ($date === $today) {
            return $result(true, false, null, false);
        }

        if ($today <= $lateUntil && $marker) {
            return $result(true, true, 'late_entry', false);
        }

        return $office
            ? $result(true, true, 'late_entry', true)
            : $result(false, true, 'late_entry', true, 'late_entry_past_limit', "Days more than {$settings['late_entry_days']} days old can be entered only by the Clerk or the Principal, with a reason.");
    }

    /** Daily mode needs class, wing or school scope; period mode also lets subject teachers mark. */
    private function mayMark(User $user, AccessSubject $subject, string $mode): bool
    {
        if (! $this->access->allows($user, 'attendance.mark', $subject)) {
            return false;
        }

        if ($mode === 'period') {
            return true;
        }

        $scopes = $this->access->scopesFor($user, 'attendance.mark');

        return in_array('school', $scopes, true)
            || (in_array('wing', $scopes, true) && app(WingScope::class)->covers($user, $subject))
            || (in_array('class', $scopes, true) && app(ClassScope::class)->covers($user, $subject));
    }

    public function assertMayView(User $user, Section $section): void
    {
        if (! $this->access->allows($user, 'attendance.view', $this->subject($section)) && ! $this->access->allows($user, 'attendance.mark', $this->subject($section))) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }
    }

    public function subject(Section $section): AccessSubject
    {
        return new class($section) implements AccessSubject
        {
            public function __construct(private readonly Section $section) {}

            public function accessFacts(): array
            {
                return ['section_id' => (string) $this->section->getKey(), 'wing' => $this->section->wing];
            }
        };
    }

    public function find(Section $section, string $date, int $period): ?AttendanceDay
    {
        return AttendanceDay::query()->where('section_id', (string) $section->getKey())->where('date', $date)->where('period', $period)->first();
    }

    /**
     * The section's students this year, by roll number.
     *
     * @return list<array{id: string, name: string, roll_no: int|null, admission_no: string}>
     */
    public function roll(Section $section): array
    {
        $year = AcademicYear::current();
        if (! $year instanceof AcademicYear) {
            return [];
        }

        $enrolments = Enrolment::query()->where('year_id', (string) $year->getKey())->where('section_id', (string) $section->getKey())->get()->keyBy('student_id');
        $students = Student::query()->whereIn('_id', $enrolments->keys()->all())->where('status', 'active')->get();

        return $students->map(fn (Student $s) => [
            'id' => (string) $s->getKey(),
            'name' => $s->name,
            'roll_no' => $enrolments[(string) $s->getKey()]->roll_no ?? null,
            'admission_no' => $s->admission_no,
        ])->sortBy([['roll_no', 'asc'], ['name', 'asc']])->values()->all();
    }

    private function checkPeriod(int $period): int
    {
        $settings = $this->settings->all();

        if ($settings['student_mode'] === 'daily') {
            return 0;
        }

        if ($period < 1 || $period > $settings['periods_per_day']) {
            throw new ApiException('period_invalid', "Choose a period from 1 to {$settings['periods_per_day']}.", 422);
        }

        return $period;
    }

    /** Parents of students newly marked absent are told once per day. */
    private function notifyAbsences(Tenant $tenant, AttendanceDay $day): void
    {
        $absent = array_keys(array_filter($day->statusByStudent(), fn (string $s) => $s === 'A'));
        // Once per student per date: in period mode the other periods' registers count too.
        $already = AttendanceDay::query()->where('section_id', $day->section_id)->where('date', $day->date)->get()
            ->flatMap(fn (AttendanceDay $d) => $d->notified_absent)->unique()->all();
        $new = array_values(array_diff($absent, $already));

        if ($new === []) {
            return;
        }

        $late = $day->late_entry !== null || $day->date < $this->settings->today();
        $when = CarbonImmutable::parse($day->date)->format('j F');

        foreach (Student::query()->whereIn('_id', $new)->get() as $student) {
            $parents = User::query()->whereIn('_id', Guardian::query()->whereIn('_id', $student->guardian_ids)->whereNotNull('user_id')->pluck('user_id')->all())->get();
            $title = $late ? "{$student->name} was absent on {$when}" : "{$student->name} is marked absent today ({$when})";
            $this->notifier->notify($tenant, $parents, 'attendance.absent', $title, null, '/students/'.$student->getKey(), [
                'student_id' => (string) $student->getKey(), 'date' => $day->date,
            ]);
        }

        AuditRecorder::muted(fn () => $day->forceFill(['notified_absent' => array_values(array_unique([...$day->notified_absent, ...$new]))])->save());
    }
}
