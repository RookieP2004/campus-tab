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

Route::middleware('school')
    ->prefix('config')
    ->name('config.')
    ->controller(SchoolConfigController::class)
    ->group(function (): void {
        Route::get('/', 'show')->middleware('permission:config.view')->name('show');
        Route::put('/', 'update')->middleware('permission:config.edit,config.edit_policies')->name('update');
        Route::get('versions', 'versions')->middleware('permission:config.view')->name('versions.index');
        Route::get('versions/{version}', 'version')->whereNumber('version')->middleware('permission:config.view')->name('versions.show');
        Route::post('versions/{version}/rollback', 'rollback')->whereNumber('version')->middleware('permission:config.edit,config.edit_policies')->name('versions.rollback');
    });
