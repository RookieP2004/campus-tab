<?php

use App\Modules\Fees\Http\Controllers\AdjustmentController;
use App\Modules\Fees\Http\Controllers\CounterController;
use App\Modules\Fees\Http\Controllers\FeeReportController;
use App\Modules\Fees\Http\Controllers\FeeSetupController;
use App\Modules\Fees\Http\Controllers\OnlinePaymentController;
use App\Modules\Fees\Http\Controllers\StudentFeesController;
use Illuminate\Support\Facades\Route;

/*
| Fees (Phase 8). Routes name the abilities that may reach them; the
| separation-of-duty rules (nobody approves their own adjustment,
| cancellation or day-end closing) are checked again in the domain classes.
| Approving an adjustment or a receipt cancellation is a step-up action;
| so is exporting a fee report (export). Creates and workflow moves take an
| Idempotency-Key.
*/

$id = '[a-f0-9]{24}';
$office = 'permission:fees.dues_view,fees.collect,fees.setup';

Route::middleware(['school', 'module:fees'])->group(function () use ($id, $office): void {
    // Set-up: heads, categories, plans, a student's set-up, raising dues.
    Route::middleware('permission:fees.setup')->group(function () use ($id): void {
        Route::get('fees/heads', [FeeSetupController::class, 'heads'])->name('fees.heads.index');
        Route::post('fees/heads', [FeeSetupController::class, 'storeHead'])->middleware('idempotent')->name('fees.heads.store');
        Route::patch('fees/heads/{id}', [FeeSetupController::class, 'updateHead'])->where('id', $id)->name('fees.heads.update');
        Route::get('fees/categories', [FeeSetupController::class, 'categories'])->name('fees.categories.index');
        Route::post('fees/categories', [FeeSetupController::class, 'storeCategory'])->middleware('idempotent')->name('fees.categories.store');
        Route::get('fees/plans', [FeeSetupController::class, 'plans'])->name('fees.plans.index');
        Route::post('fees/plans', [FeeSetupController::class, 'storePlan'])->middleware('idempotent')->name('fees.plans.store');
        Route::put('fees/plans/{id}', [FeeSetupController::class, 'updatePlan'])->where('id', $id)->name('fees.plans.update');
        Route::get('fees/students/{id}/setup', [FeeSetupController::class, 'studentSetup'])->where('id', $id)->name('fees.students.setup');
        Route::put('fees/students/{id}/setup', [FeeSetupController::class, 'updateStudentSetup'])->where('id', $id)->name('fees.students.setup.update');
        Route::post('fees/demands/raise', [FeeSetupController::class, 'raise'])->middleware('idempotent')->name('fees.demands.raise');
    });

    // Reading dues: the office, the family; a class teacher sees paid or pending only.
    Route::get('fees/students', [StudentFeesController::class, 'search'])->middleware('permission:fees.dues_view')->name('fees.students.search');
    Route::get('students/{id}/fees', [StudentFeesController::class, 'show'])->where('id', $id)->middleware('permission:fees.dues_view')->name('students.fees');
    Route::get('fees/sections/{id}/status', [StudentFeesController::class, 'sectionStatus'])->where('id', $id)->middleware('permission:fees.dues_view')->name('fees.sections.status');
    Route::get('receipts/{id}', [StudentFeesController::class, 'receipt'])->where('id', $id)->middleware('permission:fees.dues_view')->name('receipts.show');

    // The counter, cheques and day-end closing.
    Route::post('fees/payments', [CounterController::class, 'store'])->middleware(['permission:fees.collect', 'idempotent'])->name('fees.payments.store');
    Route::post('fees/payments/{id}/clear', [CounterController::class, 'clear'])->where('id', $id)->middleware(['permission:fees.collect', 'idempotent'])->name('fees.payments.clear');
    Route::post('fees/payments/{id}/bounce', [CounterController::class, 'bounce'])->where('id', $id)->middleware(['permission:fees.collect', 'idempotent'])->name('fees.payments.bounce');
    Route::get('fees/closings', [CounterController::class, 'closings'])->middleware('permission:fees.collect,fees.closing_confirm')->name('fees.closings.index');
    Route::post('fees/closings', [CounterController::class, 'close'])->middleware(['permission:fees.collect', 'idempotent'])->name('fees.closings.store');
    Route::get('fees/closings/{id}', [CounterController::class, 'showClosing'])->where('id', $id)->middleware('permission:fees.collect,fees.closing_confirm')->name('fees.closings.show');
    Route::post('fees/closings/{id}/confirm', [CounterController::class, 'confirm'])->where('id', $id)->middleware(['permission:fees.closing_confirm', 'idempotent'])->name('fees.closings.confirm');

    // Adjustments: the Clerk proposes, the Principal approves (step-up), never their own.
    Route::get('fees/adjustments', [AdjustmentController::class, 'index'])->middleware('permission:fees.adjust_propose,fees.adjust_approve,fees.adjust')->name('fees.adjustments.index');
    Route::post('fees/adjustments', [AdjustmentController::class, 'store'])->middleware(['permission:fees.adjust_propose,fees.adjust', 'idempotent'])->name('fees.adjustments.store');
    Route::get('fees/adjustments/{id}', [AdjustmentController::class, 'show'])->where('id', $id)->middleware('permission:fees.adjust_propose,fees.adjust_approve,fees.adjust')->name('fees.adjustments.show');
    Route::post('fees/adjustments/{id}/approve', [AdjustmentController::class, 'approve'])->where('id', $id)
        ->middleware(['permission:fees.adjust_approve,fees.adjust', 'step-up:fees_adjust_approve', 'idempotent'])->name('fees.adjustments.approve');
    Route::post('fees/adjustments/{id}/reject', [AdjustmentController::class, 'reject'])->where('id', $id)->middleware(['permission:fees.adjust_approve,fees.adjust', 'idempotent'])->name('fees.adjustments.reject');

    // Receipt cancellations: request, propose or forward, approve (step-up), never their own.
    $cancel = 'permission:fees.receipt_cancel_request,fees.receipt_cancel_propose,fees.receipt_cancel_approve,fees.receipt_cancel';
    Route::post('receipts/{id}/cancel', [AdjustmentController::class, 'cancelReceipt'])->where('id', $id)
        ->middleware(['permission:fees.receipt_cancel_request,fees.receipt_cancel_propose,fees.receipt_cancel', 'idempotent'])->name('receipts.cancel');
    Route::get('fees/cancellations/{id}', [AdjustmentController::class, 'showCancellation'])->where('id', $id)->middleware($cancel)->name('fees.cancellations.show');
    Route::post('fees/cancellations/{id}/forward', [AdjustmentController::class, 'forward'])->where('id', $id)->middleware(['permission:fees.receipt_cancel_propose,fees.receipt_cancel', 'idempotent'])->name('fees.cancellations.forward');
    Route::post('fees/cancellations/{id}/approve', [AdjustmentController::class, 'approveCancellation'])->where('id', $id)
        ->middleware(['permission:fees.receipt_cancel_approve,fees.receipt_cancel', 'step-up:receipt_cancel_approve', 'idempotent'])->name('fees.cancellations.approve');
    Route::post('fees/cancellations/{id}/reject', [AdjustmentController::class, 'rejectCancellation'])->where('id', $id)
        ->middleware(['permission:fees.receipt_cancel_propose,fees.receipt_cancel_approve,fees.receipt_cancel', 'idempotent'])->name('fees.cancellations.reject');

    // Online payments.
    Route::get('fees/gateway', [OnlinePaymentController::class, 'gateway'])->middleware('permission:fees.gateway_view,fees.gateway_edit')->name('fees.gateway.show');
    Route::put('fees/gateway', [OnlinePaymentController::class, 'updateGateway'])->middleware('permission:fees.gateway_edit')->name('fees.gateway.update');
    Route::post('fees/checkout', [OnlinePaymentController::class, 'checkout'])->middleware(['permission:fees.pay_online', 'idempotent'])->name('fees.checkout');
    Route::get('fees/checkout/{id}', [OnlinePaymentController::class, 'order'])->where('id', $id)->middleware('permission:fees.pay_online')->name('fees.checkout.show');
    Route::post('fees/checkout/{id}/fake', [OnlinePaymentController::class, 'fake'])->where('id', $id)->middleware(['permission:fees.pay_online', 'idempotent'])->name('fees.checkout.fake');
    Route::get('fees/reconciliations', [OnlinePaymentController::class, 'reconciliations'])->middleware($office)->name('fees.reconciliations');

    // Reports and the audited Excel export.
    Route::get('fees/reports/{type}', [FeeReportController::class, 'show'])->where('type', '[a-z]+')->middleware('permission:fees.reports')->name('fees.reports.show');
    Route::get('fees/reports/{type}/export', [FeeReportController::class, 'export'])->where('type', '[a-z]+')->middleware(['permission:fees.reports', 'step-up:export'])->name('fees.reports.export');
});

// The gateway's callback: no signed-in user, no school from a session. The
// school is found from our order record (decision 0015), the signature is
// checked on the raw body, and replays change nothing.
Route::post('webhooks/payments/{gateway}', [OnlinePaymentController::class, 'webhook'])
    ->where('gateway', '[a-z]{2,20}')
    ->middleware('throttle:webhooks')
    ->name('webhooks.payments');
