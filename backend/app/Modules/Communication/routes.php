<?php

use App\Modules\Communication\Http\Controllers\NoticeController;
use App\Modules\Communication\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| Communication: notices and notifications. Who may read or send a notice
| is decided on the server (NoticeAudience); attachments go out only as
| short-lived signed links.
*/

$id = '[a-f0-9]{24}';
$attachment = '[0-9A-HJKMNP-TV-Z]{26}';

Route::middleware('school')->group(function () use ($id, $attachment): void {
    Route::get('notices', [NoticeController::class, 'index'])->middleware('permission:notices.view')->name('notices.index');
    Route::post('notices', [NoticeController::class, 'store'])->middleware('permission:notices.send')->name('notices.store');
    Route::get('notices/{id}', [NoticeController::class, 'show'])->where('id', $id)->middleware('permission:notices.view')->name('notices.show');
    Route::get('notices/{id}/receipts', [NoticeController::class, 'receipts'])->where('id', $id)->middleware('permission:notices.view')->name('notices.receipts');
    Route::post('notices/{id}/attachments/{attachment}/link', [NoticeController::class, 'attachmentLink'])
        ->where(['id' => $id, 'attachment' => $attachment])->middleware('permission:notices.view')->name('notices.attachments.link');
});

// The file itself: only through the signed, short-lived link above.
Route::get('files/notices/{tenant}/{notice}/{attachment}', [NoticeController::class, 'download'])
    ->where(['tenant' => 't_[0-9A-Z]{26}', 'notice' => $id, 'attachment' => $attachment])
    ->middleware(['signed:relative', 'throttle:public'])
    ->name('files.notice-attachment');

// The signed-in person's own notifications and preferences (self-service).
Route::middleware('account')->prefix('me')->name('me.')->controller(NotificationController::class)->group(function () use ($id): void {
    Route::get('notifications', 'index')->name('notifications.index');
    Route::post('notifications/{id}/read', 'read')->where('id', $id)->name('notifications.read');
    Route::post('notifications/read-all', 'readAll')->name('notifications.read-all');
    Route::get('notification-preferences', 'preferences')->name('notification-preferences.show');
    Route::put('notification-preferences', 'updatePreferences')->name('notification-preferences.update');
    Route::post('push-devices', 'registerDevice')->name('push-devices.store');
    Route::delete('push-devices', 'forgetDevice')->name('push-devices.destroy');
});
