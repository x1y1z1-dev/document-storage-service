<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'security.headers'])->group(function () {
    Route::get('/', [FileController::class, 'index'])
        ->name('files.index');

    Route::post('/files', [FileController::class, 'store'])
        ->middleware('throttle:upload')
        ->name('files.store');

    Route::get('/files/{fileRecord}/download', [FileController::class, 'download'])
        ->middleware('throttle:download')
        ->name('files.download');

    Route::delete('/files/{fileRecord}', [FileController::class, 'destroy'])
        ->middleware('throttle:delete')
        ->name('files.destroy');

    // Health check — no CSRF, no rate limiting
    Route::get('/health', HealthController::class)
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->name('health');
});
