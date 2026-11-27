<?php

use App\Modules\Approvals\Http\Controllers\ApprovalInboxController;
use Illuminate\Support\Facades\Route;

/*
| The shared approvals inbox. Each source decides what this person may act on.
*/

Route::middleware('school')->group(function (): void {
    Route::get('approvals/inbox', ApprovalInboxController::class)
        ->middleware('permission:leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve,marks.correction_approve,staff.approve')
        ->name('approvals.inbox');
});
