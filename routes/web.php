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
Route::get('/photobook/albums', [PhotoBookController::class, 'albums']); // List cached albums (pages.json)
Route::get('/photobook/candidates', [PhotoBookController::class, 'candidates']); // GET ?folder=...&page=...
// ======= PHOTOBOOK_EDITOR_UI =======
Route::get('/photobook/editor', function () {
    return view('photobook.editor');
});
// ======= /PHOTOBOOK_EDITOR_UI =======

// ======= PHOTOBOOK_EDITOR_ROUTES (auto-added) =======
use Illuminate\Http\Request;

Route::get('/photobook/pages', function (Request $r) {
    $folder = (string) $r->query('folder', config('photobook.folder'));
    $hash = sha1($folder);
    $cacheRoot = storage_path('app/pdf-exports/_cache/' . $hash);
    $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
    if (!is_file($pagesPath)) {
        return response()->json(['ok'=>false, 'error'=>'pages.json missing'], 404);
    }
    $json = @file_get_contents($pagesPath) ?: '';
    $data = json_decode($json, true);
    if (!is_array($data)) return response()->json(['ok'=>false,'error'=>'invalid json'], 422);

    // Overlay overrides.json if present (templateId + per-item fields), preserving src/photo/web/rel
    try {
        $ovPath = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
        $ov = is_file($ovPath) ? (json_decode(@file_get_contents($ovPath), true) ?: []) : [];
        $ovPages = (array) ($ov['pages'] ?? []);
        if (!empty($ovPages)) {
            // Special-case cover: if page "1" with templateId cover exists, reflect its item[0] into top-level cover
            try {
                $cov = $ovPages['1'] ?? null;
                if (is_array($cov) && (($cov['templateId'] ?? '') === 'cover') && !empty($cov['items'][0])) {
                    $ci = $cov['items'][0];
                    $data['cover'] = $data['cover'] ?? [];
                    if (!empty($ci['photo']['path'])) {
                        // store relative cache path if provided via src URL
                        if (!empty($ci['src']) && is_string($ci['src'])) {
                            $data['cover']['webSrc'] = $ci['src'];
                        }
                        $data['cover']['image'] = $ci['photo']['path'];
                        foreach (['objectPosition','scale','rotate'] as $k) if (isset($ci[$k])) $data['cover'][$k] = $ci[$k];
                    }
                }
            } catch (\Throwable $e) {}
            foreach (($data['pages'] ?? []) as &$p) {
                $n = (string) ($p['n'] ?? '');
                if ($n === '' || !isset($ovPages[$n]) || !is_array($ovPages[$n])) continue;
                $ovp = $ovPages[$n];
                if (!empty($ovp['templateId'])) {
                    $p['templateId'] = (string) $ovp['templateId'];
                }
                if (isset($ovp['items']) && is_array($ovp['items']) && isset($p['items']) && is_array($p['items'])) {
                    // Merge by slotIndex; keep base photo/web/rel unless overridden, update visual fields
                    $baseItems = $p['items'];
                    foreach ($ovp['items'] as $ovIt) {
                        $slotIdx = (int) ($ovIt['slotIndex'] ?? -1);
                        if ($slotIdx < 0) continue;
                        // find first item in base with same slotIndex
                        foreach ($baseItems as $j => $bi) {
                            if ((int) ($bi['slotIndex'] ?? -1) === $slotIdx) {
                                foreach (['objectPosition','crop','scale','rotate','caption','x','y','width','height','src'] as $k) {
                                    if (array_key_exists($k, $ovIt)) {
                                        $baseItems[$j][$k] = $ovIt[$k];
                                    }
                                }
                                // If override provides a concrete src (URL), reflect it into web/webSrc so UI prefers it
                                if (!empty($ovIt['src']) && is_string($ovIt['src'])) {
                                    $baseItems[$j]['web'] = $ovIt['src'];
                                    $baseItems[$j]['webSrc'] = $ovIt['src'];
                                    // rel is now ambiguous; drop it to avoid stale mapping
                                    unset($baseItems[$j]['rel']);
                                }
                            }
                        }
                    }
                    $p['items'] = $baseItems;
                }
            }
            unset($p);
        }
    } catch (\Throwable $e) {
        // ignore
    }
    // Inject webSrc for each item if missing
    foreach (($data['pages'] ?? []) as &$p) {
        foreach (($p['items'] ?? []) as &$it) {
            if (empty($it['webSrc']) && !empty($it['rel'])) {
                $it['webSrc'] = route('photobook.asset', ['hash'=>$hash, 'path'=>$it['rel']]);
            }
        }
        unset($it);
    }
    unset($p);
    return response()->json(['ok'=>true, 'data'=>$data]);
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
