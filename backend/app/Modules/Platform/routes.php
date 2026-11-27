<?php

use App\Modules\Platform\Http\Controllers\ConsoleAlertController;
use App\Modules\Platform\Http\Controllers\ConsoleAuditController;
use App\Modules\Platform\Http\Controllers\ConsoleAuthController;
use App\Modules\Platform\Http\Controllers\ConsoleSchoolController;
use App\Modules\Platform\Http\Controllers\ConsoleSessionController;
use Illuminate\Support\Facades\Route;

/*
| The platform console: our team only. Web app only (session cookie,
| guard "console"), password and MFA at every sign-in. Every console route
| after sign-in runs behind `console` (tests/Feature/Platform enforce it);
| revoking a licence and sole-Chief recovery add console.step-up:<action>.
*/

$code = '[A-Za-z0-9-]{3,30}';
$id = '[a-f0-9]{24}';

Route::prefix('platform')->name('platform.')->group(function () use ($code, $id): void {
    Route::middleware('throttle:console')->prefix('auth')->name('auth.')->controller(ConsoleAuthController::class)->group(function (): void {
        Route::post('login', 'login')->name('login');
        Route::post('mfa/verify', 'verifyMfa')->name('mfa.verify');
        Route::post('password/set', 'setPassword')->name('password.set');
    });

    // An enrol-only session (first sign-in) reaches these.
    Route::middleware('console')->group(function (): void {
        Route::post('auth/logout', [ConsoleAuthController::class, 'logout'])->defaults('console_enrol', true)->name('auth.logout');
        Route::get('me', [ConsoleAuthController::class, 'me'])->defaults('console_enrol', true)->name('me');
        Route::post('me/mfa/enrol', [ConsoleAuthController::class, 'startEnrolment'])->defaults('console_enrol', true)->name('me.mfa.enrol');
        Route::post('me/mfa/enrol/confirm', [ConsoleAuthController::class, 'confirmEnrolment'])->defaults('console_enrol', true)->name('me.mfa.enrol.confirm');
    });

    Route::middleware('console')->group(function () use ($code, $id): void {
        Route::post('auth/step-up', [ConsoleAuthController::class, 'stepUp'])->middleware('throttle:console')->name('auth.step-up');
        Route::get('me/sessions', [ConsoleSessionController::class, 'index'])->name('me.sessions.index');
        Route::delete('me/sessions/{id}', [ConsoleSessionController::class, 'destroy'])->where('id', $id)->name('me.sessions.destroy');

        Route::get('audit-logs', [ConsoleAuditController::class, 'index'])->name('audit-logs.index');
        Route::get('alerts', [ConsoleAlertController::class, 'index'])->name('alerts.index');
        Route::post('alerts/{id}/acknowledge', [ConsoleAlertController::class, 'acknowledge'])->where('id', $id)->name('alerts.acknowledge');

        Route::controller(ConsoleSchoolController::class)->group(function () use ($code): void {
            Route::get('options', 'options')->name('options');
            Route::get('schools', 'index')->name('schools.index');
            Route::post('schools', 'store')->name('schools.store');
            Route::get('schools/{code}', 'show')->where('code', $code)->name('schools.show');
            Route::post('schools/{code}/licences', 'issueLicence')->where('code', $code)->name('schools.licences.store');
            Route::patch('schools/{code}/licence', 'amendLicence')->where('code', $code)->name('schools.licence.update');
            Route::post('schools/{code}/licence/renew', 'renewLicence')->where('code', $code)->name('schools.licence.renew');
            Route::put('schools/{code}/client', 'updateClient')->where('code', $code)->name('schools.client.update');
            Route::post('schools/{code}/payments', 'storePayment')->where('code', $code)->name('schools.payments.store');
            Route::post('schools/{code}/stats/refresh', 'refreshStats')->where('code', $code)->name('schools.stats.refresh');
            Route::post('schools/{code}/licence/revoke', 'revokeLicence')->where('code', $code)
                ->middleware('console.step-up:licence_revoke')->name('schools.licence.revoke');
            Route::post('schools/{code}/chief-recovery', 'startChiefRecovery')->where('code', $code)
                ->middleware('console.step-up:chief_recovery')->name('schools.chief-recovery.start');
            Route::post('schools/{code}/chief-recovery/complete', 'completeChiefRecovery')->where('code', $code)
                ->middleware(['console.step-up:chief_recovery', 'throttle:console'])->name('schools.chief-recovery.complete');
        });
    });
});
