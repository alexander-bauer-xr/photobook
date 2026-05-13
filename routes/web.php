<?php

use App\Http\Controllers\PhotobookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Photobook Web API (folder-based) — muss vor /{hash} stehen! ──────────────
Route::get('/photobook/pages',        [PhotobookController::class, 'pagesJson']);
Route::get('/photobook/albums',       [PhotobookController::class, 'albums']);
Route::get('/photobook/templates',    [PhotobookController::class, 'templates']);
Route::get('/photobook/candidates',   [PhotobookController::class, 'candidates']);
Route::post('/photobook/override',    [PhotobookController::class, 'overrideTemplate']);
Route::post('/photobook/save-page',   [PhotobookController::class, 'savePage']);
Route::get('/photobook/asset/{hash}/{path}',
    [PhotobookController::class, 'asset'])
    ->where('path', '.*')
    ->name('photobook.asset');

Route::get('/photobook/preview/{hash}', [PhotobookController::class, 'preview'])
    ->name('photobook.preview');

Route::get('/photobook/pdf/{file}', [PhotobookController::class, 'servePdf'])
    ->where('file', '[^/]+\.pdf');

// ── Photobook Editor UI ───────────────────────────────────────────────────────
Route::get('/photobook', [PhotobookController::class, 'editor'])
    ->name('photobook.editor');

// Catch-all: load editor with a given hash/folder — muss als letztes stehen!
Route::get('/photobook/{hash}', [PhotobookController::class, 'editor'])
    ->name('photobook.editor.hash');

