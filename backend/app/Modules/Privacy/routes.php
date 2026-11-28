<?php

use App\Modules\Privacy\Http\Controllers\PrivacyController;
use App\Modules\Privacy\Http\Controllers\PrivacyOfficeController;
use Illuminate\Support\Facades\Route;

/*
| Privacy (DPDP, Phase 9). Not a licensed module: every school has it.
| privacy.self: everyone's own page (notice, consents for parents, own
| requests). The office abilities are the Chief's (and the Principal's for
| the requests inbox and incidents); deleting anything asks for step-up.
*/

$id = '[0-9a-f]{24}';

Route::middleware('school')->group(function () use ($id): void {
    Route::get('privacy', [PrivacyController::class, 'mine'])->middleware('permission:privacy.self')->name('privacy.mine');
    Route::post('privacy/consents', [PrivacyController::class, 'answer'])->middleware(['permission:privacy.self', 'idempotent'])->name('privacy.consents.answer');
    Route::post('privacy/requests', [PrivacyController::class, 'ask'])->middleware(['permission:privacy.self', 'idempotent'])->name('privacy.requests.store');

    Route::get('privacy/notices', [PrivacyController::class, 'notices'])->middleware('permission:privacy.notices')->name('privacy.notices.index');
    Route::put('privacy/notices/draft', [PrivacyController::class, 'saveDraft'])->middleware('permission:privacy.notices')->name('privacy.notices.draft');
    Route::post('privacy/notices/publish', [PrivacyController::class, 'publish'])->middleware(['permission:privacy.notices', 'idempotent'])->name('privacy.notices.publish');

    Route::get('privacy/requests', [PrivacyOfficeController::class, 'inbox'])->middleware('permission:privacy.requests')->name('privacy.requests.index');
    Route::post('privacy/requests/{id}/move', [PrivacyOfficeController::class, 'move'])->where('id', $id)->middleware(['permission:privacy.requests', 'idempotent'])->name('privacy.requests.move');
    Route::get('privacy/requests/{id}/copy', [PrivacyOfficeController::class, 'copy'])->where('id', $id)->middleware(['permission:privacy.requests', 'step-up:export'])->name('privacy.requests.copy');
    Route::post('privacy/requests/{id}/erase', [PrivacyOfficeController::class, 'erase'])->where('id', $id)->middleware(['permission:privacy.erase', 'step-up:permanent_delete', 'idempotent'])->name('privacy.requests.erase');

    Route::get('privacy/retention', [PrivacyOfficeController::class, 'retention'])->middleware('permission:privacy.retention')->name('privacy.retention.index');
    Route::post('privacy/retention/{kind}/dry-run', [PrivacyOfficeController::class, 'dryRun'])->where('kind', '[a-z_]+')->middleware(['permission:privacy.retention', 'idempotent'])->name('privacy.retention.dry-run');
    Route::post('privacy/retention/runs/{id}/confirm', [PrivacyOfficeController::class, 'confirm'])->where('id', $id)->middleware(['permission:privacy.retention', 'step-up:permanent_delete', 'idempotent'])->name('privacy.retention.confirm');

    Route::get('privacy/incidents', [PrivacyOfficeController::class, 'incidents'])->middleware('permission:privacy.incidents')->name('privacy.incidents.index');
    Route::post('privacy/incidents', [PrivacyOfficeController::class, 'report'])->middleware(['permission:privacy.incidents', 'idempotent'])->name('privacy.incidents.store');
    Route::post('privacy/incidents/{id}/entries', [PrivacyOfficeController::class, 'addEntry'])->where('id', $id)->middleware(['permission:privacy.incidents', 'idempotent'])->name('privacy.incidents.entries');
});
