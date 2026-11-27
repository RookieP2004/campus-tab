<?php

use App\Modules\Leave\Http\Controllers\LeaveRequestController;
use App\Modules\Leave\Http\Controllers\LeaveSetupController;
use Illuminate\Support\Facades\Route;

/*
| Leave. Routes name the abilities that may reach them; who may make which
| move on which request (own-leave routing, stages, delegation, fast-track)
| is decided in LeaveWorkflow and LeaveRouting. Creates and workflow moves
| take an Idempotency-Key.
*/

$id = '[a-f0-9]{24}';
$anyLeave = 'permission:leave.apply,leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.apply,student_leave.approve';

Route::middleware(['school', 'module:leave'])->group(function () use ($id, $anyLeave): void {
    Route::get('leave-types', [LeaveSetupController::class, 'types'])->middleware($anyLeave.',leave.manage_types')->name('leave-types.index');
    Route::post('leave-types', [LeaveSetupController::class, 'storeType'])->middleware(['permission:leave.manage_types', 'idempotent'])->name('leave-types.store');
    Route::post('leave-types/starter', [LeaveSetupController::class, 'starterTypes'])->middleware(['permission:leave.manage_types', 'idempotent'])->name('leave-types.starter');
    Route::patch('leave-types/{id}', [LeaveSetupController::class, 'updateType'])->where('id', $id)->middleware('permission:leave.manage_types')->name('leave-types.update');

    Route::get('leave/balances/me', [LeaveSetupController::class, 'myBalances'])->middleware('permission:leave.apply')->name('leave.balances.me');
    Route::get('leave/calendar', [LeaveSetupController::class, 'calendar'])->middleware('permission:leave.view')->name('leave.calendar');
    Route::get('leave/cover', [LeaveSetupController::class, 'cover'])->middleware('permission:leave.verify,leave.decide,leave.decide_principal')->name('leave.cover');
    Route::get('leave/delegations', [LeaveSetupController::class, 'delegations'])->middleware('permission:leave.delegate')->name('leave.delegations.index');
    Route::post('leave/delegations', [LeaveSetupController::class, 'delegate'])->middleware(['permission:leave.delegate', 'idempotent'])->name('leave.delegations.store');
    Route::post('leave/delegations/{id}/end', [LeaveSetupController::class, 'endDelegation'])->where('id', $id)->middleware('permission:leave.delegate')->name('leave.delegations.end');

    Route::middleware($anyLeave)->group(function () use ($id): void {
        Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
        Route::get('leave-requests/preview', [LeaveRequestController::class, 'preview'])->middleware('permission:leave.apply')->name('leave-requests.preview');
        Route::post('leave-requests', [LeaveRequestController::class, 'store'])->middleware(['permission:leave.apply,student_leave.apply', 'idempotent'])->name('leave-requests.store');
        Route::get('leave-requests/{id}', [LeaveRequestController::class, 'show'])->where('id', $id)->name('leave-requests.show');
        Route::patch('leave-requests/{id}', [LeaveRequestController::class, 'update'])->where('id', $id)->middleware('permission:leave.apply,student_leave.apply')->name('leave-requests.update');

        Route::middleware('idempotent')->group(function () use ($id): void {
            foreach ([
                'submit' => ['submit', 'leave.apply,student_leave.apply'],
                'withdraw' => ['withdraw', 'leave.apply,student_leave.apply'],
                'cancel-request' => ['requestCancel', 'leave.apply,student_leave.apply'],
                'verify' => ['verify', 'leave.verify,leave.decide,leave.fast_track'],
                'return' => ['sendBack', 'leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve'],
                'approve' => ['approve', 'leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve'],
                'reject' => ['reject', 'leave.verify,leave.decide,leave.decide_principal,leave.fast_track,student_leave.approve'],
                'cancel' => ['cancel', 'leave.decide,leave.decide_principal,student_leave.approve'],
                'keep' => ['keep', 'leave.decide,leave.decide_principal,student_leave.approve'],
            ] as $move => [$method, $abilities]) {
                Route::post("leave-requests/{id}/{$move}", [LeaveRequestController::class, $method])->where('id', $id)->middleware('permission:'.$abilities)->name("leave-requests.{$move}");
            }

            Route::post('leave-requests/{id}/documents', [LeaveRequestController::class, 'addDocument'])->where('id', $id)->middleware('permission:leave.apply,student_leave.apply')->name('leave-requests.documents.store');
        });

        Route::post('leave-requests/{id}/documents/{doc}/link', [LeaveRequestController::class, 'documentLink'])->where(['id' => $id, 'doc' => '[a-z0-9]{16}'])->name('leave-requests.documents.link');
    });
});

// A leave document, decrypted, only through the signed, short-lived link above.
Route::get('files/leave/{tenant}/{request}/{doc}', [LeaveRequestController::class, 'document'])
    ->where(['tenant' => 't_[0-9A-Z]{26}', 'request' => $id, 'doc' => '[a-z0-9]{16}'])
    ->middleware(['signed:relative', 'throttle:public'])
    ->name('files.leave-document');
