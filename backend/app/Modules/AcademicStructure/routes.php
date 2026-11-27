<?php

use App\Modules\AcademicStructure\Http\Controllers\StructureController;
use Illuminate\Support\Facades\Route;

/*
| Academic structure: years, classes, sections, subjects, teaching
| assignments. Viewing: academic.view; changes: academic.manage.
*/

$id = '[a-f0-9]{24}';

Route::middleware('school')->controller(StructureController::class)->group(function () use ($id): void {
    Route::get('academic-years', 'years')->middleware('permission:academic.view')->name('academic-years.index');
    Route::post('academic-years', 'storeYear')->middleware('permission:academic.manage')->name('academic-years.store');
    Route::post('academic-years/{id}/make-current', 'makeCurrent')->where('id', $id)->middleware('permission:academic.manage')->name('academic-years.make-current');

    Route::get('classes', 'classes')->middleware('permission:academic.view')->name('classes.index');
    Route::post('classes', 'storeClass')->middleware('permission:academic.manage')->name('classes.store');
    Route::patch('classes/{id}', 'updateClass')->where('id', $id)->middleware('permission:academic.manage')->name('classes.update');
    Route::delete('classes/{id}', 'destroyClass')->where('id', $id)->middleware('permission:academic.manage')->name('classes.destroy');

    Route::post('sections', 'storeSection')->middleware('permission:academic.manage')->name('sections.store');
    Route::patch('sections/{id}', 'updateSection')->where('id', $id)->middleware('permission:academic.manage')->name('sections.update');
    Route::delete('sections/{id}', 'destroySection')->where('id', $id)->middleware('permission:academic.manage')->name('sections.destroy');

    Route::get('subjects', 'subjects')->middleware('permission:academic.view')->name('subjects.index');
    Route::post('subjects', 'storeSubject')->middleware('permission:academic.manage')->name('subjects.store');
    Route::patch('subjects/{id}', 'updateSubject')->where('id', $id)->middleware('permission:academic.manage')->name('subjects.update');
    Route::delete('subjects/{id}', 'destroySubject')->where('id', $id)->middleware('permission:academic.manage')->name('subjects.destroy');

    Route::get('teaching-assignments', 'assignments')->middleware('permission:academic.view')->name('teaching-assignments.index');
    Route::post('teaching-assignments', 'storeAssignment')->middleware('permission:academic.manage')->name('teaching-assignments.store');
    Route::delete('teaching-assignments/{id}', 'destroyAssignment')->where('id', $id)->middleware('permission:academic.manage')->name('teaching-assignments.destroy');
});
