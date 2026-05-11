<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Phase 7: Playwright print-ready preview
// Playwright opens this URL with ?print=1 to render the PDF.
Route::get('/photobook/preview/{hash}', function (string $hash) {
    return view('photobook.print', ['hash' => $hash]);
})->name('photobook.preview');
