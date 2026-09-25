<?php

use Goldnead\AppApi\Http\Controllers\Cp\OverviewController;
use Illuminate\Support\Facades\Route;

/*
 * Every route carries `can:` middleware AND the controller checks again.
 */
Route::prefix('app-api')->name('app-api.')->group(function () {
    Route::get('/', [OverviewController::class, 'index'])->name('index')->middleware('can:view app api');
    Route::get('/openapi.json', [OverviewController::class, 'openapi'])->name('openapi')->middleware('can:view app api');
    Route::delete('/tokens/{token}', [OverviewController::class, 'revokeToken'])->name('tokens.destroy')->whereNumber('token')->middleware('can:manage app api tokens');
});
