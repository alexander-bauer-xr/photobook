<?php

use App\Http\Controllers\PhotobookController;
use Illuminate\Support\Facades\Route;

// Hash-based API (used by PB.* helpers in lib/api.ts)
Route::prefix('photobook')->group(function () {
    Route::get('/albums',                    [PhotobookController::class, 'albums']);
    Route::get('/templates',                 [PhotobookController::class, 'templates']);
    Route::get('/candidates/{hash}',         [PhotobookController::class, 'candidatesForHash']);

    Route::get('/pages/{hash}',              [PhotobookController::class, 'getPages']);
    Route::patch('/pages/{hash}',            [PhotobookController::class, 'patchPages']);
    Route::post('/pages/{hash}/page',        [PhotobookController::class, 'addPage']);
    Route::delete('/pages/{hash}/page/{id}', [PhotobookController::class, 'deletePage']);

    Route::post('/cover/{hash}',             [PhotobookController::class, 'setCover']);
    Route::post('/feedback/{hash}',          [PhotobookController::class, 'saveLayoutFeedback']);

    Route::post('/build/{hash}',             [PhotobookController::class, 'startBuild']);
    Route::post('/build-folder',             [PhotobookController::class, 'startBuildByFolder']);

    Route::post('/export/{hash}',            [PhotobookController::class, 'exportPdf']);
    Route::get('/progress/{hash}',           [PhotobookController::class, 'progress']);

    Route::get('/settings',                  [PhotobookController::class, 'getSettings']);
    Route::post('/settings',                 [PhotobookController::class, 'updateSettings']);
});
