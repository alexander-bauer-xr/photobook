<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Http\Controllers\PhotoBookController;
use App\Http\Controllers\PhotoBookFeedbackController;

Route::get('/photobook', [PhotoBookController::class, 'index']);
Route::match(['GET','POST'], '/photobook/build', [PhotoBookController::class, 'build']);
Route::get('/photobook/review', [PhotoBookController::class, 'review']);
Route::get('/photobook/asset/{hash}/{path}', [PhotoBookController::class, 'asset'])->where('path', '.*')->name('photobook.asset');

// Feedback & override endpoints
Route::post('/photobook/feedback', [PhotoBookFeedbackController::class, 'submit']);
Route::post('/photobook/override', [PhotoBookFeedbackController::class, 'overrideTemplate']);
