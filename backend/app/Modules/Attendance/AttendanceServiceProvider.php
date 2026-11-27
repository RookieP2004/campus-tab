<?php

namespace App\Modules\Attendance;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\CalendarEvent;
use App\Modules\Attendance\Models\StaffAttendance;
use App\Modules\Attendance\Policies\AttendancePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Attendance: student registers, staff check-in and the holiday calendar.
 */
class AttendanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([AttendanceDay::class, StaffAttendance::class, CalendarEvent::class] as $model) {
            Gate::policy($model, AttendancePolicy::class);
        }
    }
}
