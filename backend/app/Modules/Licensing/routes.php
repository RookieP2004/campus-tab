<?php

use App\Modules\Licensing\Http\Controllers\ActivationController;
use Illuminate\Support\Facades\Route;

/*
| Licensing: one-time activation of a school with its licence key.
*/

Route::middleware('throttle:activation')
    ->prefix('licences/activate')
    ->controller(ActivationController::class)
    ->group(function (): void {
        Route::post('otp', 'requestOtp')->name('licences.activate.otp');
        Route::post('/', 'activate')->name('licences.activate');
    });
