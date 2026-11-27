<?php

namespace App\Modules\Dashboard\Domain\Cards;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Attendance\Domain\AttendanceReports;
use App\Modules\Attendance\Domain\AttendanceSettings;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\CalendarEvent;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Staff;
use App\Modules\Tenancy\Domain\SchoolConfigService;

/**
 * Attendance cards (Phase 4), only when the school has attendance switched on:
 *   take_attendance.<section>  the class teacher's register today: marked or not (top of the screen)
 *   check_in                   staff: today's check-in, or the button
 *   attendance_today           school or wing: sections marked, % present, staff in
 *   calendar_approvals         the Principal: holidays waiting for approval
 *   below_threshold            students under the school's % (for the sections the role covers)
 */
class AttendanceCards implements CardProvider
{
    public function __construct(
        private readonly SchoolConfigService $configs,
        private readonly AttendanceSettings $settings,
        private readonly AttendanceReports $reports,
    ) {}

    public function cards(User $user, DashboardContext $context): array
    {
        if (! ($this->configs->current()->body['modules']['attendance'] ?? false)) {
            return [];
        }

        $cards = [];
        $today = $this->settings->today();
        $mine = $user->accessScope();

        if ($context->role === 'class_teacher' && $context->can('attendance.mark', 'class')) {
            foreach (Section::query()->whereIn('_id', $mine['class_sections'])->get() as $section) {
                $day = AttendanceDay::query()->where('section_id', (string) $section->getKey())->where('date', $today)->where('period', 0)->first();
                $cards[] = [
                    'id' => 'take_attendance.'.$section->getKey(), 'kind' => 'action', 'title' => 'dashboard.cards.takeAttendance',
                    'params' => ['section' => $section->label], 'priority' => 0, 'link' => '/attendance/'.$section->getKey(),
                    'data' => [
                        'section_id' => (string) $section->getKey(),
                        'date' => $today,
                        'marked' => $day instanceof AttendanceDay,
                        // After the edit window a teacher can only look; the card says "View".
                        'editable' => ! ($day instanceof AttendanceDay) || $this->settings->now()->lessThan($this->settings->windowClosesAt($today)),
                        'absent' => $day instanceof AttendanceDay ? count(array_filter($day->entries, fn ($e) => $e['status'] === 'A')) : null,
                        'holiday' => CalendarEvent::holidayOn($today)?->title,
                    ],
                ];
            }
        }

        if ($context->can('staff_attendance.mark', 'own')) {
            $staff = Staff::query()->where('user_id', (string) $user->getKey())->where('status', Staff::STATUS_ACTIVE)->first();
            if ($staff instanceof Staff) {
                $record = StaffAttendance::query()->where('staff_id', (string) $staff->getKey())->where('date', $today)->first();
                $cards[] = [
                    'id' => 'check_in', 'kind' => 'action', 'title' => 'dashboard.cards.checkIn', 'priority' => 2, 'link' => '/attendance/me',
                    'data' => [
                        'checked_in' => $record instanceof StaffAttendance && $record->in !== null,
                        'in' => $record?->in, 'out' => $record?->out, 'late' => $record instanceof StaffAttendance && $record->late,
                        'late_after' => $this->settings->all()['staff']['late_after'],
                    ],
                ];
            }
        }

        if ($context->can('attendance.view', 'wing')) {
            $daily = $this->reports->daily($user, $today);
            $cards[] = [
                'id' => 'attendance_today', 'kind' => 'kpi', 'title' => 'dashboard.cards.attendanceToday', 'priority' => 4, 'link' => '/attendance/summary',
                'data' => ['date' => $today, 'totals' => $daily['totals'], 'staff' => $daily['staff'],
                    'unmarked' => array_slice(array_values(array_map(fn (array $s) => $s['section'], array_filter((array) $daily['sections'], fn (array $s) => ! $s['marked']))), 0, 8)],
            ];
        }

        if ($context->can('calendar.approve')) {
            $pending = CalendarEvent::query()->where('status', 'pending')->orderBy('date')->limit(5)->get();
            if ($pending->isNotEmpty()) {
                $cards[] = [
                    'id' => 'calendar_approvals', 'kind' => 'todo', 'title' => 'dashboard.cards.calendarApprovals', 'priority' => 1, 'link' => '/calendar?month='.substr((string) $pending->first()->date, 0, 7),
                    'data' => ['count' => CalendarEvent::query()->where('status', 'pending')->count(),
                        'items' => $pending->map(fn (CalendarEvent $e) => ['label' => $e->title, 'detail' => $e->date, 'link' => '/calendar?month='.substr((string) $e->date, 0, 7)])->values()->all()],
                ];
            }
        }

        if ($context->can('attendance.view', 'class') && ! $context->can('attendance.view', 'child') && ! $context->can('attendance.view', 'own')) {
            $below = $this->reports->belowThreshold($user);
            if ($below['students'] !== []) {
                $cards[] = [
                    'id' => 'below_threshold', 'kind' => 'list', 'title' => 'dashboard.cards.belowThreshold', 'params' => ['threshold' => $below['threshold']],
                    'priority' => 14, 'link' => '/attendance/summary',
                    'data' => ['count' => count($below['students']), 'items' => array_map(fn ($s) => [
                        'label' => $s['name'], 'detail' => $s['section'].' · '.$s['percent'].'%', 'link' => '/students/'.$s['id'],
                    ], array_slice($below['students'], 0, 5))],
                ];
            }
        }

        return $cards;
    }
}
