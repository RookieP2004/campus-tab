<?php

use App\Modules\Reports\Http\Controllers\PromotionController;
use App\Modules\Reports\Http\Controllers\ReportCardController;
use App\Modules\Reports\Http\Controllers\TemplateController;
use App\Modules\Reports\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

/*
| Report cards and year-end (Phase 7). Routes name the abilities that may
| reach them; which card or section a person may see is decided in
| CardAccess (families: published versions of their own children only).
| Re-issue is the Principal's alone (report_cards.reissue plus a role check).
*/

$id = '[a-f0-9]{24}';
$cards = 'permission:results.publish,remarks.edit,results.view';

Route::middleware(['school', 'module:reports'])->group(function () use ($id, $cards): void {
    Route::get('report-templates', [TemplateController::class, 'index'])->middleware('permission:report_templates.manage,results.publish')->name('report-templates.index');
    Route::get('report-templates/presets', [TemplateController::class, 'presets'])->middleware('permission:report_templates.manage')->name('report-templates.presets');
    Route::post('report-templates', [TemplateController::class, 'store'])->middleware(['permission:report_templates.manage', 'idempotent'])->name('report-templates.store');
    Route::put('report-templates/{id}', [TemplateController::class, 'update'])->where('id', $id)->middleware('permission:report_templates.manage')->name('report-templates.update');
    Route::post('report-templates/{id}/letterhead', [TemplateController::class, 'letterhead'])->where('id', $id)->middleware('permission:report_templates.manage')->name('report-templates.letterhead');
    Route::delete('report-templates/{id}/letterhead', [TemplateController::class, 'removeLetterhead'])->where('id', $id)->middleware('permission:report_templates.manage')->name('report-templates.letterhead.destroy');

    Route::middleware('permission:results.publish,remarks.edit')->group(function () use ($id): void {
        Route::get('report-cards/sections', [ReportCardController::class, 'sections'])->name('report-cards.sections');
        Route::get('report-cards/sections/{id}', [ReportCardController::class, 'section'])->where('id', $id)->name('report-cards.section');
        Route::get('report-cards/runs/{id}', [ReportCardController::class, 'showRun'])->where('id', $id)->name('report-cards.runs.show');
        Route::put('report-cards/{id}/remarks', [ReportCardController::class, 'remarks'])->where('id', $id)->middleware('permission:remarks.edit')->name('report-cards.remarks');
    });
    Route::middleware(['permission:results.publish', 'idempotent'])->group(function () use ($id): void {
        Route::post('report-cards/sections/{id}/generate', [ReportCardController::class, 'generate'])->where('id', $id)->name('report-cards.generate');
        Route::post('report-cards/sections/{id}/publish', [ReportCardController::class, 'publish'])->where('id', $id)->name('report-cards.publish');
        Route::post('report-cards/{id}/review', [ReportCardController::class, 'review'])->where('id', $id)->name('report-cards.review');
    });
    Route::post('report-cards/{id}/reissue', [ReportCardController::class, 'reissue'])->where('id', $id)->middleware(['permission:report_cards.reissue', 'idempotent'])->name('report-cards.reissue');

    Route::get('report-cards/mine', [ReportCardController::class, 'mine'])->middleware('permission:results.view')->name('report-cards.mine');
    Route::get('report-cards/students/{id}', [ReportCardController::class, 'student'])->where('id', $id)->middleware($cards)->name('report-cards.student');
    Route::get('report-cards/{id}', [ReportCardController::class, 'show'])->where('id', $id)->middleware($cards)->name('report-cards.show');
    Route::post('report-cards/{id}/link', [ReportCardController::class, 'link'])->where('id', $id)->middleware($cards)->name('report-cards.link');

    Route::post('promotions/preview', [PromotionController::class, 'preview'])->middleware('permission:year.promote')->name('promotions.preview');
    Route::post('promotions/apply', [PromotionController::class, 'apply'])->middleware(['permission:year.promote', 'idempotent'])->name('promotions.apply');
});

// A report card PDF, only through the signed, short-lived link above.
Route::get('files/report-cards/{tenant}/{card}', [ReportCardController::class, 'file'])
    ->where(['tenant' => 't_[0-9A-Z]{26}', 'card' => $id])
    ->middleware(['signed:relative', 'throttle:public'])
    ->name('files.report-card');

// The public page a card's QR code opens: identity facts only, no marks.
Route::get('public/verify/{school}/{code}', VerificationController::class)
    ->where(['school' => '[A-Za-z0-9-]{2,40}', 'code' => '[A-Za-z0-9_-]{40,64}'])
    ->middleware('throttle:verify')
    ->name('public.verify');
