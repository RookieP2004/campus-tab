<?php

use App\Modules\Assessment\Http\Controllers\AssessmentController;
use App\Modules\Assessment\Http\Controllers\CorrectionController;
use App\Modules\Assessment\Http\Controllers\PaperController;
use App\Modules\Assessment\Http\Controllers\SchemeController;
use Illuminate\Support\Facades\Route;

/*
| Tests and marks. Routes name the abilities that may reach them; what a
| person may do to a given paper (scope, state) is decided in PaperAccess,
| PaperLifecycle and Corrections. Approving a correction to a locked mark
| asks for step-up MFA and is the Principal's alone (a fixed rule in code).
*/

$id = '[a-f0-9]{24}';
$markbook = 'permission:marks.enter,marks.verify,marks.lock,results.publish,marks.correction_request,marks.correction_approve,exams.schedule,tests.create';

Route::middleware(['school', 'module:assessment'])->group(function () use ($id, $markbook): void {
    Route::get('assessment-schemes', [SchemeController::class, 'index'])->middleware('permission:assessment.scheme,assessment.setup,exams.schedule,tests.create')->name('assessment-schemes.index');
    Route::get('assessment-schemes/presets', [SchemeController::class, 'presets'])->middleware('permission:assessment.scheme,assessment.setup')->name('assessment-schemes.presets');
    Route::post('assessment-schemes', [SchemeController::class, 'store'])->middleware(['permission:assessment.scheme,assessment.setup', 'idempotent'])->name('assessment-schemes.store');
    Route::put('assessment-schemes/{id}', [SchemeController::class, 'update'])->where('id', $id)->middleware('permission:assessment.scheme,assessment.setup')->name('assessment-schemes.update');

    Route::middleware($markbook)->group(function () use ($id): void {
        Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
        Route::post('assessments', [AssessmentController::class, 'store'])->middleware(['permission:exams.schedule,tests.create', 'idempotent'])->name('assessments.store');
        Route::get('assessments/{id}', [AssessmentController::class, 'show'])->where('id', $id)->name('assessments.show');
        Route::get('assessments/{id}/completion', [AssessmentController::class, 'completion'])->where('id', $id)->name('assessments.completion');
        Route::post('assessments/{id}/open', [AssessmentController::class, 'openAll'])->where('id', $id)->middleware(['permission:exams.schedule,tests.create', 'idempotent'])->name('assessments.open');
        Route::post('assessments/{id}/lock', [AssessmentController::class, 'lockAll'])->where('id', $id)->middleware(['permission:marks.lock', 'idempotent'])->name('assessments.lock');
        Route::post('assessments/{id}/publish', [AssessmentController::class, 'publishAll'])->where('id', $id)->middleware(['permission:results.publish', 'idempotent'])->name('assessments.publish');

        Route::get('papers/{id}', [PaperController::class, 'show'])->where('id', $id)->name('papers.show');
        Route::put('papers/{id}/marks', [PaperController::class, 'saveMarks'])->where('id', $id)->middleware('permission:marks.enter')->name('papers.marks');
        Route::middleware('idempotent')->group(function () use ($id): void {
            Route::post('papers/{id}/open', [PaperController::class, 'open'])->where('id', $id)->middleware('permission:exams.schedule,tests.create')->name('papers.open');
            Route::post('papers/{id}/submit', [PaperController::class, 'submit'])->where('id', $id)->middleware('permission:marks.enter')->name('papers.submit');
            Route::post('papers/{id}/verify', [PaperController::class, 'verify'])->where('id', $id)->middleware('permission:marks.verify')->name('papers.verify');
            Route::post('papers/{id}/send-back', [PaperController::class, 'sendBack'])->where('id', $id)->middleware('permission:marks.verify,marks.lock')->name('papers.send-back');
            Route::post('papers/{id}/lock', [PaperController::class, 'lock'])->where('id', $id)->middleware('permission:marks.lock')->name('papers.lock');
            Route::post('papers/{id}/publish', [PaperController::class, 'publish'])->where('id', $id)->middleware('permission:results.publish')->name('papers.publish');
        });

        Route::get('corrections', [CorrectionController::class, 'index'])->name('corrections.index');
        Route::get('corrections/{id}', [CorrectionController::class, 'show'])->where('id', $id)->name('corrections.show');
        Route::post('corrections', [CorrectionController::class, 'store'])->middleware(['permission:marks.correction_request', 'idempotent'])->name('corrections.store');
        Route::post('corrections/{id}/approve', [CorrectionController::class, 'approve'])->where('id', $id)
            ->middleware(['permission:marks.correction_approve', 'step-up:marks_correction_approve', 'idempotent'])->name('corrections.approve');
        Route::post('corrections/{id}/reject', [CorrectionController::class, 'reject'])->where('id', $id)
            ->middleware(['permission:marks.correction_approve', 'idempotent'])->name('corrections.reject');
    });

    // Published marks only: the student, their parents, and staff who may see results.
    Route::get('results/mine', [AssessmentController::class, 'myResults'])->middleware('permission:results.view')->name('results.mine');
    Route::get('results/students/{id}', [AssessmentController::class, 'studentResults'])->where('id', $id)->middleware('permission:results.view')->name('results.student');
});
