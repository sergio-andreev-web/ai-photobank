<?php

use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\ImageController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Public API (contracts/public-api.openapi.yaml)
|--------------------------------------------------------------------------
|
| Registered on the default "web" middleware group (session + CSRF), not
| "api", because ADR-0001 uses a cookie session rather than bearer tokens —
| same origin behind nginx means no CORS/stateful-domain handling is needed.
*/

Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent();
})->name('sanctum.csrf-cookie');

Route::prefix('api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me'])->middleware('auth');
    });

    Route::middleware(['auth', 'admin.role'])->group(function () {
        Route::prefix('admin')->group(function () {
            Route::get('/batches', [BatchController::class, 'index']);
            Route::post('/batches', [BatchController::class, 'store']);
            Route::get('/batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch');
            Route::post('/batches/{batch}/images', [BatchController::class, 'uploadImage'])->whereNumber('batch');
            Route::post('/batches/{batch}/complete', [BatchController::class, 'complete'])->whereNumber('batch');

            Route::get('/images', [ImageController::class, 'index']);
            Route::get('/images/{image}', [ImageController::class, 'show'])->whereNumber('image');
            Route::patch('/images/{image}', [ImageController::class, 'update'])->whereNumber('image');
            Route::delete('/images/{image}', [ImageController::class, 'destroy'])->whereNumber('image');
        });

        Route::get('/media/images/{image}/{variant}', [MediaController::class, 'show'])
            ->whereNumber('image')
            ->whereIn('variant', ['thumbnail', 'preview', 'original']);
    });
});
