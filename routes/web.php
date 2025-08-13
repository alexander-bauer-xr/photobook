<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Http\Controllers\PhotoBookController;

Route::get('/photobook', [PhotoBookController::class, 'index']);
Route::post('/photobook/build', [PhotoBookController::class, 'build']);
