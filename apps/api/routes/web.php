<?php

use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FaceController;
use App\Http\Controllers\Admin\ImageController;
use App\Http\Controllers\Admin\ImageTagController;
use App\Http\Controllers\Admin\PersonController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\TagCandidateController;
use App\Http\Controllers\Admin\TagController;
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
            Route::get('/dashboard', DashboardController::class);

            Route::get('/batches', [BatchController::class, 'index']);
            Route::post('/batches', [BatchController::class, 'store']);
            Route::get('/batches/{batch}', [BatchController::class, 'show'])->whereNumber('batch');
            Route::post('/batches/{batch}/images', [BatchController::class, 'uploadImage'])->whereNumber('batch');
            Route::post('/batches/{batch}/complete', [BatchController::class, 'complete'])->whereNumber('batch');

            Route::get('/images', [ImageController::class, 'index']);
            Route::get('/images/{image}', [ImageController::class, 'show'])->whereNumber('image');
            Route::get('/images/{image}/similar', [ImageController::class, 'similar'])->whereNumber('image');
            Route::get('/images/trash', [ImageController::class, 'trash']);
            Route::get('/images/{image}/faces', [FaceController::class, 'imageFaces'])->whereNumber('image');

            // People and faces (ADR-0015).
            Route::get('/people', [PersonController::class, 'index']);
            Route::post('/people', [PersonController::class, 'store']);
            Route::get('/people/{person}', [PersonController::class, 'show'])->whereNumber('person');
            Route::patch('/people/{person}', [PersonController::class, 'update'])->whereNumber('person');
            Route::delete('/people/{person}', [PersonController::class, 'destroy'])->whereNumber('person');
            Route::get('/people/{person}/faces', [PersonController::class, 'faces'])->whereNumber('person');
            Route::get('/faces/clusters', [FaceController::class, 'clusters']);
            Route::post('/faces/assign', [FaceController::class, 'assign']);
            Route::post('/faces/{face}/reject', [FaceController::class, 'reject'])->whereNumber('face');
            Route::post('/images/download', [ImageController::class, 'download']);
            Route::post('/images/{image}/restore', [ImageController::class, 'restore'])->whereNumber('image');
            Route::patch('/images/{image}', [ImageController::class, 'update'])->whereNumber('image');
            Route::delete('/images/{image}', [ImageController::class, 'destroy'])->whereNumber('image');
            Route::post('/images/{image}/reprocess', [ImageController::class, 'reprocess'])->whereNumber('image');
            Route::post('/images/{image}/retry', [ImageController::class, 'retry'])->whereNumber('image');

            Route::post('/images/{image}/tags', [ImageTagController::class, 'store'])->whereNumber('image');
            Route::delete('/images/{image}/tags/{tag}', [ImageTagController::class, 'destroy'])
                ->whereNumber(['image', 'tag']);

            Route::get('/tags', [TagController::class, 'index']);
            Route::post('/tags', [TagController::class, 'store']);
            Route::get('/tags/{tag}', [TagController::class, 'show'])->whereNumber('tag');
            Route::patch('/tags/{tag}', [TagController::class, 'update'])->whereNumber('tag');
            Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag');
            Route::post('/tags/{tag}/aliases', [TagController::class, 'storeAlias'])->whereNumber('tag');
            Route::delete('/tags/{tag}/aliases/{alias}', [TagController::class, 'destroyAlias'])
                ->whereNumber(['tag', 'alias']);

            Route::get('/tag-candidates', [TagCandidateController::class, 'index']);
            Route::post('/tag-candidates/{candidate}/approve', [TagCandidateController::class, 'approve'])
                ->whereNumber('candidate');
            Route::post('/tag-candidates/{candidate}/merge', [TagCandidateController::class, 'merge'])
                ->whereNumber('candidate');
            Route::post('/tag-candidates/{candidate}/reject', [TagCandidateController::class, 'reject'])
                ->whereNumber('candidate');
        });

        Route::post('/search', SearchController::class);

        Route::get('/media/images/{image}/{variant}', [MediaController::class, 'show'])
            ->whereNumber('image')
            ->whereIn('variant', ['thumbnail', 'preview', 'original']);
    });
});
