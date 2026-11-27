<?php

use App\Modules\Dashboard\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| My dashboard and search: the signed-in person's own view. Each card and
| each result is built from what that person may see (no route permission:
| the cards themselves are the check).
*/

Route::middleware('account')->prefix('me')->name('me.')->controller(DashboardController::class)->group(function (): void {
    Route::get('dashboard', 'show')->name('dashboard');
    Route::get('search', 'search')->name('search');
});
