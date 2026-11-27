<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\MfaController;
use App\Modules\Identity\Http\Controllers\PlatformSupportController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\SupportGrantController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| Identity and access.
|
| Middleware groups (bootstrap/app.php):
|   enrol    auth:sanctum, tenant, device                     (enrol-only sessions allowed)
|   account  auth:sanctum, tenant, device, mfa.enrolled       (self-service; works on a read-only licence)
|   school   auth:sanctum|platform, tenant, device, mfa.enrolled, licence
|
| Every `school` route names its permission; tests/Feature/Identity/RoutePermissionTest
| fails if one does not.
*/

$id = '[a-f0-9]{24}';

// Signing in: no account yet, so no permission. Limits live in the services.
Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function (): void {
    Route::post('login', 'login')->name('login');
    Route::post('mfa/verify', 'verifyMfa')->name('mfa.verify');
    Route::post('otp/request', 'requestOtp')->name('otp.request');
    Route::post('otp/verify', 'verifyOtp')->name('otp.verify');
    Route::post('password/forgot', 'forgotPassword')->name('password.forgot');
    Route::post('password/set', 'setPassword')->name('password.set');
});

Route::middleware('enrol')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('me/mfa/enrol', [MfaController::class, 'start'])->name('me.mfa.enrol');
    Route::post('me/mfa/enrol/confirm', [MfaController::class, 'confirm'])->name('me.mfa.enrol.confirm');
});

Route::middleware('account')->group(function () use ($id): void {
    Route::post('auth/step-up', [AuthController::class, 'stepUp'])->name('auth.step-up');

    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me/preferences', [MeController::class, 'updatePreferences'])->name('me.preferences');
    Route::put('me/password', [MeController::class, 'changePassword'])->name('me.password');
    Route::get('me/sessions', [MeController::class, 'sessions'])->name('me.sessions.index');
    Route::delete('me/sessions/{id}', [MeController::class, 'revokeSession'])->where('id', $id)->name('me.sessions.destroy');
    Route::post('me/sessions/revoke-others', [MeController::class, 'revokeOthers'])->name('me.sessions.revoke-others');
    Route::post('me/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->name('me.mfa.recovery-codes');
    Route::delete('me/mfa', [MfaController::class, 'disable'])->name('me.mfa.disable');
});

Route::middleware('school')->group(function () use ($id): void {
    Route::controller(UserController::class)->prefix('users')->name('users.')->group(function () use ($id): void {
        Route::get('/', 'index')->middleware('permission:users.view')->name('index');
        Route::post('/', 'store')->middleware('permission:users.create')->name('store');
        Route::put('{id}/roles', 'updateRoles')->where('id', $id)
            ->middleware(['permission:users.edit_roles', 'step-up:role_edit'])->name('roles');
        Route::post('{id}/deactivate', 'deactivate')->where('id', $id)->middleware('permission:users.deactivate')->name('deactivate');
        // Account safety keeps working on a read-only licence.
        Route::post('{id}/unlock', 'unlock')->where('id', $id)->middleware('permission:users.unlock')
            ->defaults('licence_always', true)->name('unlock');
        Route::post('{id}/password-link', 'passwordLink')->where('id', $id)->middleware('permission:users.send_password_link')
            ->defaults('licence_always', true)->name('password-link');
        // Lost authenticator and no recovery code left.
        Route::post('{id}/mfa-reset', 'resetMfa')->where('id', $id)
            ->middleware(['permission:users.reset_mfa', 'step-up:mfa_reset'])
            ->defaults('licence_always', true)->name('mfa-reset');
    });

    Route::controller(RoleController::class)->group(function (): void {
        Route::get('roles', 'index')->middleware('permission:roles.view')->name('roles.index');
        Route::get('permissions', 'catalogue')->middleware('permission:roles.view')->name('permissions.index');
        Route::post('roles', 'store')->middleware(['permission:roles.edit', 'step-up:role_edit'])->name('roles.store');
        Route::put('roles/{name}', 'update')->where('name', '[a-z][a-z0-9_]{1,39}')
            ->middleware(['permission:roles.edit', 'step-up:role_edit'])->name('roles.update');
    });

    Route::controller(SupportGrantController::class)->prefix('support-grants')->name('support-grants.')->group(function () use ($id): void {
        Route::get('/', 'index')->middleware('permission:support.view')->name('index');
        // A school whose licence has lapsed may still need our help.
        Route::post('/', 'store')->middleware(['permission:support.grant', 'step-up:support_grant'])
            ->defaults('licence_always', true)->name('store');
        Route::get('{id}/activity', 'activity')->where('id', $id)->middleware('permission:support.view')->name('activity');
        Route::post('{id}/revoke', 'revoke')->where('id', $id)->middleware('permission:support.grant')
            ->defaults('licence_always', true)->name('revoke');
    });
});

// Our team's side of support access: platform accounts only.
Route::middleware(['auth:platform', 'throttle:activation'])
    ->post('platform/support/sessions', [PlatformSupportController::class, 'open'])
    ->name('platform.support.sessions');
