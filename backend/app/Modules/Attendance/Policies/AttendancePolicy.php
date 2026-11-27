<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Attendance\Domain\AttendanceRegister;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\CalendarEvent;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Attendance, staff attendance and the holiday calendar. Registered for
 * AttendanceDay, StaffAttendance and CalendarEvent. What a person may do to
 * a register on a given day (window, late entry) is AttendanceRegister::rules.
 */
class AttendancePolicy
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AttendanceRegister $register,
    ) {}

    public function viewRegister(User $actor, Section $section): Response
    {
        $subject = $this->register->subject($section);

        return $this->access->allows($actor, 'attendance.view', $subject) || $this->access->allows($actor, 'attendance.mark', $subject)
            ? Response::allow()
            : Response::denyAsNotFound('The record was not found.');
    }

    public function view(User $actor, AttendanceDay $day): Response
    {
        return $this->access->allows($actor, 'attendance.view', $day) || $this->access->allows($actor, 'attendance.mark', $day)
            ? Response::allow()
            : Response::denyAsNotFound('The record was not found.');
    }

    /** The photo of the paper page: whoever entered the late day, or the office. */
    public function addPhoto(User $actor, AttendanceDay $day): Response
    {
        if ($day->late_entry === null) {
            return Response::deny('Only a day entered late from the paper register takes a photo.', 'not_late_entry');
        }

        return ($day->late_entry['entered_by']['id'] ?? null) === (string) $actor->getKey() || $this->access->allows($actor, 'attendance.edit_after_window', $day)
            ? Response::allow()
            : $this->deny();
    }

    public function viewReports(User $actor): Response
    {
        return $this->access->holds($actor, 'attendance.view') ? Response::allow() : $this->deny();
    }

    public function viewStaffDay(User $actor): Response
    {
        return $this->access->allows($actor, 'staff_attendance.view') || $this->access->allows($actor, 'staff_attendance.manage') || $this->access->allows($actor, 'staff_attendance.enter')
            ? Response::allow()
            : $this->deny();
    }

    public function enterManual(User $actor): Response
    {
        return $this->access->allows($actor, 'staff_attendance.enter') ? Response::allow() : $this->deny();
    }

    public function manage(User $actor): Response
    {
        return $this->access->allows($actor, 'staff_attendance.manage') ? Response::allow() : $this->deny();
    }

    public function viewAny(User $actor): Response
    {
        return $this->access->holds($actor, 'calendar.view') ? Response::allow() : $this->deny();
    }

    public function create(User $actor): Response
    {
        return $this->access->allows($actor, 'calendar.manage') ? Response::allow() : $this->deny();
    }

    public function update(User $actor, CalendarEvent $event): Response
    {
        return $this->access->allows($actor, 'calendar.manage') ? Response::allow() : $this->deny();
    }

    public function approve(User $actor, CalendarEvent $event): Response
    {
        return $this->access->allows($actor, 'calendar.approve') ? Response::allow() : $this->deny();
    }

    private function deny(): Response
    {
        return Response::deny('You do not have permission to do this.', 'permission_denied');
    }
}
