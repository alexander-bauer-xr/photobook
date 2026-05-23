<?php

use App\Http\Controllers\PhotobookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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

