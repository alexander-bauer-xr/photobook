<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PhotobookApiController;

Route::prefix('photobook')->group(function () {
    Route::get('/albums', [PhotobookApiController::class, 'albums']);
    Route::get('/pages/{hash}', [PhotobookApiController::class, 'getPages']);
    Route::patch('/pages/{hash}', [PhotobookApiController::class, 'patchPages']);
    Route::post('/pages/{hash}/page', [PhotobookApiController::class, 'addPage']);
    Route::delete('/pages/{hash}/page/{pageId}', [PhotobookApiController::class, 'deletePage']);
    Route::post('/cover/{hash}', [PhotobookApiController::class, 'setCover']);
    Route::post('/upload/{hash}', [PhotobookApiController::class, 'uploadImage']);
    Route::post('/build/{hash}', [PhotobookApiController::class, 'startBuild']);
    Route::post('/build-folder', [PhotobookApiController::class, 'startBuildByFolder']);
    Route::get('/progress/{hash}', [PhotobookApiController::class, 'progress']);
    Route::get('/templates', [PhotobookApiController::class, 'templates']);
    // Settings API
    Route::get('/settings', [PhotobookApiController::class, 'getSettings']);
    Route::post('/settings', [PhotobookApiController::class, 'updateSettings']);
});
