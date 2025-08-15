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
Route::post('/photobook/feedback', [PhotoBookFeedbackController::class, 'submit'])->name('photobook.feedback');
Route::post('/photobook/override', [PhotoBookFeedbackController::class, 'overrideTemplate'])->name('photobook.override');


Route::get('/photobook/pages', [PhotoBookController::class, 'pagesJson']);        // GET ?folder=...
Route::get('/photobook/overrides', [PhotoBookFeedbackController::class, 'listOverrides']); // GET ?folder=...
Route::post('/photobook/save-page', [PhotoBookFeedbackController::class, 'savePage']);     // POST JSON
// ======= PHOTOBOOK_EDITOR_UI =======
Route::get('/photobook/editor', function () {
    return view('photobook.editor');
});
// ======= /PHOTOBOOK_EDITOR_UI =======

// ======= PHOTOBOOK_EDITOR_ROUTES (auto-added) =======
use Illuminate\Http\Request;

Route::get('/photobook/pages', function (Request $r) {
    $folder = (string) $r->query('folder', config('photobook.folder'));
    $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
    $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
    if (!is_file($pagesPath)) {
        return response()->json(['ok'=>false, 'error'=>'pages.json missing'], 404);
    }
    $json = @file_get_contents($pagesPath) ?: '';
    $data = json_decode($json, true);
    return response()->json($data);
});

Route::post('/photobook/save-page', function (Request $r) {
    $folder = (string) $r->input('folder', config('photobook.folder'));
    $pageNo = (int) $r->input('page', 0);
    $items  = $r->input('items');
    $templateId = $r->input('templateId');

    if ($pageNo < 1 || !is_array($items)) {
        return response()->json(['ok'=>false, 'error'=>'Invalid payload'], 422);
    }

    $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
    if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0775, true);
    $ovPath = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';

    $ov = [];
    if (is_file($ovPath)) {
        $ov = json_decode(@file_get_contents($ovPath), true) ?: [];
    }
    $ov['pages'] = $ov['pages'] ?? [];
    $ov['pages'][(string)$pageNo] = [
        'templateId' => $templateId,
        'items' => $items,
        'updated_at' => date(DATE_ATOM),
    ];

    @file_put_contents($ovPath, json_encode($ov, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    return response()->json(['ok'=>true]);
});
// ======= /PHOTOBOOK_EDITOR_ROUTES =======
