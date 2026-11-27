<?php

use App\Modules\Customisation\Http\Controllers\CustomFieldController;
use Illuminate\Support\Facades\Route;

/*
| Customisation: custom fields with owner level and scope. The route checks
| the permission; FieldRules checks level and scope on every change.
*/

$id = '[a-f0-9]{24}';
$manage = 'permission:custom_fields.manage,custom_fields.clerical';

Route::middleware('school')->prefix('custom-fields')->name('custom-fields.')->controller(CustomFieldController::class)->group(function () use ($id, $manage): void {
    Route::get('/', 'index')->middleware('permission:custom_fields.manage,custom_fields.clerical,students.view,staff.view')->name('index');
    Route::post('/', 'store')->middleware($manage)->name('store');
    Route::patch('{id}', 'update')->where('id', $id)->middleware($manage)->name('update');
    Route::post('{id}/archive', 'archive')->where('id', $id)->middleware($manage)->name('archive');
    Route::post('{id}/restore', 'restore')->where('id', $id)->middleware($manage)->name('restore');
    Route::post('{id}/promote', 'promote')->where('id', $id)->middleware('permission:custom_fields.manage')->name('promote');
});
