<?php

use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Audit\Http\Controllers\RecycleBinController;
use Illuminate\Support\Facades\Route;

/*
| Audit: the append-only change log and the recycle bin. There is no route
| that changes or deletes an audit entry.
*/

Route::middleware('school')->group(function (): void {
    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:audit.view,audit.view_academic')
        ->name('audit-logs.index');

    Route::controller(RecycleBinController::class)->prefix('recycle-bin')->name('recycle-bin.')->group(function (): void {
        Route::get('/', 'index')->middleware('permission:records.restore')->name('index');
        Route::post('{type}/{id}/restore', 'restore')->where(['type' => '[a-z_]+', 'id' => '[a-f0-9]{24}'])
            ->middleware('permission:records.restore')->name('restore');
        Route::delete('{type}/{id}', 'purge')->where(['type' => '[a-z_]+', 'id' => '[a-f0-9]{24}'])
            ->middleware(['permission:records.purge', 'step-up:permanent_delete'])->name('purge');
    });
});
