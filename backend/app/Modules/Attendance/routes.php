<?php

use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Http\Controllers\CalendarController;
use App\Modules\Attendance\Http\Controllers\StaffAttendanceController;
use Illuminate\Support\Facades\Route;

/*
| Attendance. Who may mark a register on which day (edit window, late entry
| from the paper register) is decided in AttendanceRegister::rules; manual
| staff entries go through the same level check as managing users.
*/

$id = '[a-f0-9]{24}';
$date = '\d{4}-\d{2}-\d{2}';

Route::middleware('school')->group(function () use ($id, $date): void {
    Route::get('attendance/settings', [AttendanceController::class, 'settings'])->middleware('permission:attendance.view,attendance.mark,staff_attendance.mark,staff_attendance.manage')->name('attendance.settings');

    Route::middleware('permission:attendance.view,attendance.mark')->group(function () use ($id, $date): void {
        Route::get('attendance/sections/{id}/days/{date}', [AttendanceController::class, 'show'])->where(['id' => $id, 'date' => $date])->name('attendance.days.show');
        Route::put('attendance/sections/{id}/days/{date}', [AttendanceController::class, 'update'])->where(['id' => $id, 'date' => $date])->name('attendance.days.update');
        Route::get('attendance/sections/{id}/template', [AttendanceController::class, 'template'])->where('id', $id)->name('attendance.template');
        Route::post('attendance/sections/{id}/days/{date}/import', [AttendanceController::class, 'import'])->where(['id' => $id, 'date' => $date])->name('attendance.days.import');
        Route::post('attendance/days/{id}/photo', [AttendanceController::class, 'uploadPhoto'])->where('id', $id)->name('attendance.days.photo');
        Route::post('attendance/days/{id}/photo/link', [AttendanceController::class, 'photoLink'])->where('id', $id)->name('attendance.days.photo-link');
        Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
        Route::get('attendance/register', [AttendanceController::class, 'monthly'])->name('attendance.register');
        Route::get('attendance/below-threshold', [AttendanceController::class, 'belowThreshold'])->name('attendance.below-threshold');
        Route::get('attendance/students/{id}', [AttendanceController::class, 'student'])->where('id', $id)->name('attendance.student');
    });

    Route::middleware('permission:staff_attendance.mark,staff_attendance.view,staff_attendance.manage,staff_attendance.enter')->group(function () use ($id): void {
        Route::post('attendance/staff/check-in', [StaffAttendanceController::class, 'checkIn'])->middleware('permission:staff_attendance.mark')->name('staff-attendance.check-in');
        Route::post('attendance/staff/check-out', [StaffAttendanceController::class, 'checkOut'])->middleware('permission:staff_attendance.mark')->name('staff-attendance.check-out');
        Route::get('attendance/staff/me', [StaffAttendanceController::class, 'mine'])->name('staff-attendance.mine');
        Route::get('attendance/staff', [StaffAttendanceController::class, 'index'])->name('staff-attendance.index');
        Route::post('attendance/staff/{staffId}/manual', [StaffAttendanceController::class, 'manual'])->where('staffId', $id)->middleware('permission:staff_attendance.enter')->name('staff-attendance.manual');
        Route::get('attendance/staff/qr', [StaffAttendanceController::class, 'qr'])->middleware('permission:staff_attendance.manage')->name('staff-attendance.qr');
        Route::post('attendance/staff/import', [StaffAttendanceController::class, 'import'])->middleware('permission:staff_attendance.manage')->name('staff-attendance.import');
    });

    Route::middleware('permission:calendar.view')->group(function () use ($id): void {
        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::post('calendar', [CalendarController::class, 'store'])->middleware('permission:calendar.manage')->name('calendar.store');
        Route::patch('calendar/{id}', [CalendarController::class, 'update'])->where('id', $id)->middleware('permission:calendar.manage')->name('calendar.update');
        Route::delete('calendar/{id}', [CalendarController::class, 'destroy'])->where('id', $id)->middleware('permission:calendar.manage')->name('calendar.destroy');
        Route::post('calendar/{id}/approve', [CalendarController::class, 'approve'])->where('id', $id)->middleware('permission:calendar.approve')->name('calendar.approve');
        Route::post('calendar/{id}/reject', [CalendarController::class, 'reject'])->where('id', $id)->middleware('permission:calendar.approve')->name('calendar.reject');
    });
});

// The paper-register photo: only through the signed, short-lived link above.
Route::get('files/attendance/{tenant}/{day}', [AttendanceController::class, 'photo'])
    ->where(['tenant' => 't_[0-9A-Z]{26}', 'day' => $id])
    ->middleware(['signed:relative', 'throttle:public'])
    ->name('files.attendance-photo');
