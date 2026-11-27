<?php

use App\Modules\People\Http\Controllers\ImportController;
use App\Modules\People\Http\Controllers\StaffController;
use App\Modules\People\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

/*
| People: students, guardians, staff and Excel imports. Every route names
| its permission; record-level scope (class, subject, child, wing) is
| checked in PeoplePolicy.
*/

$id = '[a-f0-9]{24}';
$entity = 'students|staff';

Route::middleware('school')->group(function () use ($id, $entity): void {
    Route::controller(StudentController::class)->group(function () use ($id): void {
        Route::get('students', 'index')->middleware('permission:students.view')->name('students.index');
        Route::get('students/seats', 'seats')->middleware('permission:students.view')->name('students.seats');
        Route::post('students', 'store')->middleware('permission:students.admit')->name('students.store');
        Route::get('students/{id}', 'show')->where('id', $id)->middleware('permission:students.view')->name('students.show');
        Route::patch('students/{id}', 'update')->where('id', $id)->middleware('permission:students.edit')->name('students.update');
        Route::delete('students/{id}', 'destroy')->where('id', $id)->middleware('permission:students.admit')->name('students.destroy');
        Route::post('students/{id}/guardians', 'addGuardian')->where('id', $id)->middleware('permission:guardians.manage')->name('students.guardians.store');
        Route::patch('guardians/{id}', 'updateGuardian')->where('id', $id)->middleware('permission:guardians.manage')->name('guardians.update');
        Route::post('guardians/{id}/parent-login', 'enableParentLogin')->where('id', $id)->middleware('permission:guardians.manage')->name('guardians.parent-login.store');
        Route::delete('guardians/{id}/parent-login', 'disableParentLogin')->where('id', $id)->middleware('permission:guardians.manage')->name('guardians.parent-login.destroy');
        Route::post('sections/{id}/student-setup-codes', 'setupCodes')->where('id', $id)->middleware('permission:students.login_codes')->name('sections.student-setup-codes');
    });

    Route::controller(StaffController::class)->prefix('staff')->name('staff.')->group(function () use ($id): void {
        Route::get('/', 'index')->middleware('permission:staff.view')->name('index');
        Route::post('/', 'store')->middleware('permission:staff.create,staff.draft')->name('store');
        Route::get('{id}', 'show')->where('id', $id)->middleware('permission:staff.view')->name('show');
        Route::patch('{id}', 'update')->where('id', $id)->middleware('permission:staff.edit')->name('update');
        Route::post('{id}/approve', 'approve')->where('id', $id)->middleware('permission:staff.approve')->name('approve');
        Route::post('{id}/reject', 'reject')->where('id', $id)->middleware('permission:staff.approve')->name('reject');
        Route::post('{id}/deactivate', 'deactivate')->where('id', $id)->middleware('permission:staff.deactivate')->name('deactivate');
    });

    Route::controller(ImportController::class)->prefix('imports')->name('imports.')->group(function () use ($id, $entity): void {
        Route::get('{entity}/template', 'template')->where('entity', $entity)->middleware('permission:imports.students,imports.staff')->name('template');
        Route::post('{entity}', 'store')->where('entity', $entity)->middleware('permission:imports.students,imports.staff')->name('store');
        Route::get('{id}', 'show')->where('id', $id)->middleware('permission:imports.students,imports.staff')->name('show');
        Route::get('{id}/report', 'report')->where('id', $id)->middleware('permission:imports.students,imports.staff')->name('report');
        Route::post('{id}/commit', 'commit')->where('id', $id)->middleware('permission:imports.students,imports.staff')->name('commit');
    });
});
