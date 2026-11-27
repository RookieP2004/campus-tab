<?php

use App\Modules\Tenancy\Http\Controllers\PublicConfigController;
use App\Modules\Tenancy\Http\Controllers\SchoolConfigController;
use Illuminate\Support\Facades\Route;

/*
| Tenancy: public branding and the versioned school config.
*/

Route::get('public/tenants/{code}/config', [PublicConfigController::class, 'show'])
    ->middleware('throttle:public')
    ->name('public.tenants.config');

Route::middleware(['auth:sanctum', 'tenant', 'mfa.enrolled', 'licence'])
    ->prefix('config')
    ->name('config.')
    ->controller(SchoolConfigController::class)
    ->group(function (): void {
        Route::get('/', 'show')->name('show');
        Route::put('/', 'update')->name('update');
        Route::get('versions', 'versions')->name('versions.index');
        Route::get('versions/{version}', 'version')->whereNumber('version')->name('versions.show');
        Route::post('versions/{version}/rollback', 'rollback')->whereNumber('version')->name('versions.rollback');
    });
