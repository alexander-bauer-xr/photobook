<?php

namespace App\Http\Controllers;

use App\Services\LayoutTemplates;
use App\Services\PlaywrightPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class PhotobookController extends Controller
{
    // --------------------------------------------------------------------------
    // Internal helpers
    // --------------------------------------------------------------------------

    private function cacheRoot(string $folder): string
    {
        return storage_path('app/pdf-exports/_cache/' . sha1($folder));
    }

    private function pagesPath(string $folder): string
    {
        return $this->cacheRoot($folder) . DIRECTORY_SEPARATOR . 'pages.json';
    }

    private function normalizeAssetUrl(string $hash, mixed $value): ?string
    {
        if (!is_string($value) || $value === '') return null;
        $candidate = preg_replace('#^file:/{2,}#i', '', $value) ?? $value;
        $candidate = str_replace('\\\\', '/', $candidate);
        foreach ([$candidate] as $path) {
            $trimmed = preg_split('/[?#]/', $path, 2)[0] ?? $path;
            $withSlash = ($trimmed !== '' && $trimmed[0] !== '/') ? '/'.$trimmed : $trimmed;
            if (preg_match('#/photobook/asset/' . preg_quote($hash, '#') . '/(.+)$#', $withSlash, $m)) {
                $rel = ltrim($m[1], '/');
                if ($rel !== '') return route('photobook.asset', ['hash' => $hash, 'path' => $rel], false);
            }
            $needle = '/_cache/' . $hash . '/';
            $pos = strpos($trimmed, $needle);
            if ($pos !== false) {
                $rel = ltrim(substr($trimmed, $pos + strlen($needle)), '/');
                if ($rel !== '') return route('photobook.asset', ['hash' => $hash, 'path' => $rel], false);
            }
        }
        return null;
    }

    private function injectWebSrc(array &$data, string $hash, Request $request): void
    {
        $origin = $request->getSchemeAndHttpHost();
        foreach (($data['pages'] ?? []) as &$p) {
            foreach (($p['items'] ?? []) as &$it) {
                if (!empty($it['rel'])) {
                    $it['webSrc'] = $origin . route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                    continue;
                }
                if (!empty($it['web'])) {
                    $n = $this->normalizeAssetUrl($hash, $it['web']);
                    $it['webSrc'] = $n ? $origin . $n : (string) $it['web'];
                    continue;
                }
                $n = $this->normalizeAssetUrl($hash, $it['src'] ?? null);
                if ($n) $it['webSrc'] = $origin . $n;
            }
            unset($it);
        }
        unset($p);
        if (isset($data['cover']['webSrc']) && is_string($data['cover']['webSrc']) && str_starts_with($data['cover']['webSrc'], '/')) {
            $data['cover']['webSrc'] = $origin . $data['cover']['webSrc'];
        }
    }

    // --------------------------------------------------------------------------
    // GET /photobook/pages?folder=...
    // --------------------------------------------------------------------------

    public function pagesJson(Request $request)
    {
        $folder = $request->string('folder', Config::get('photobook.folder'))->toString();
        $hash   = sha1($folder);
        $path   = $this->pagesPath($folder);

        if (!is_file($path)) {
            return response()->json(['ok' => false, 'error' => 'pages.json not found'], 404);
        }
        $data = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($data)) {
            return response()->json(['ok' => false, 'error' => 'invalid json'], 422);
        }

        try { $this->injectWebSrc($data, $hash, $request); } catch (\Throwable) {}

        return response()->json(['ok' => true, 'data' => $data]);
    }

    // --------------------------------------------------------------------------
    // GET /photobook/albums
    // --------------------------------------------------------------------------

    public function albums()
    {
        $root = storage_path('app/pdf-exports/_cache');
        $out  = [];
        foreach ((is_dir($root) ? @scandir($root) ?: [] : []) as $d) {
            if ($d === '.' || $d === '..') continue;
            $dir   = $root . DIRECTORY_SEPARATOR . $d;
            $pages = $dir . DIRECTORY_SEPARATOR . 'pages.json';
            if (!is_dir($dir) || !is_file($pages)) continue;
            $data = json_decode(@file_get_contents($pages) ?: '', true);
            if (!is_array($data)) continue;
            $out[] = [
                'hash'       => $d,
                'folder'     => (string) ($data['folder'] ?? ''),
                'count'      => (int)   ($data['count']  ?? 0),
                'created_at' => (string) ($data['created_at'] ?? date(DATE_ATOM, @filemtime($pages) ?: time())),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return response()->json(['ok' => true, 'albums' => $out]);
    }

    // --------------------------------------------------------------------------
    // GET /photobook/templates
    // --------------------------------------------------------------------------

    public function templates()
    {
        return response()->json(LayoutTemplates::all());
    }

    // --------------------------------------------------------------------------
    // GET /photobook/candidates?folder=&page=&all=
    // --------------------------------------------------------------------------

    public function candidates(Request $request)
    {
        $folder = $request->string('folder', Config::get('photobook.folder'))->toString();
        $pageNo = (int) $request->query('page', 0);
        $all    = $request->boolean('all', false);

        if (!$all && $pageNo < 1) {
            return response()->json(['ok' => false, 'error' => 'invalid page'], 422);
        }

        $hash  = sha1($folder);
        $path  = $this->pagesPath($folder);
        if (!is_file($path)) {
            return response()->json(['ok' => false, 'error' => 'pages.json not found'], 404);
        }
        $data  = json_decode(file_get_contents($path) ?: '', true);
        $pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];
        $origin = $request->getSchemeAndHttpHost();
        $photos = [];

        foreach ($pages as $p) {
            $n = (int) ($p['n'] ?? 0);
            if (!$all && !($n >= $pageNo - 1 && $n <= $pageNo + 1)) continue;
            foreach (($p['items'] ?? []) as $it) {
                $ph = $it['photo'] ?? null;
                if (!is_array($ph) || empty($ph['path'])) continue;
                $web = null;
                if (!empty($it['rel'])) {
                    $web = $origin . route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                } elseif (!empty($it['web'])) {
                    $n2 = $this->normalizeAssetUrl($hash, $it['web']);
                    $web = $n2 ? $origin . $n2 : (string) $it['web'];
                } else {
                    $n2 = $this->normalizeAssetUrl($hash, $it['src'] ?? null);
                    if ($n2) $web = $origin . $n2;
                }
                $photos[] = [
                    'path'     => (string) $ph['path'],
                    'filename' => (string) ($ph['filename'] ?? basename((string) $ph['path'])),
                    'src'      => $web,
                ];
            }
        }

        // Deduplicate by path
        $seen = []; $unique = [];
        foreach ($photos as $ph) {
            if (!isset($seen[$ph['path']])) { $seen[$ph['path']] = true; $unique[] = $ph; }
        }
        return response()->json(['ok' => true, 'candidates' => $unique]);
    }

    // --------------------------------------------------------------------------
    // GET /photobook/asset/{hash}/{path}
    // --------------------------------------------------------------------------

    public function asset(Request $request, string $hash, string $path)
    {
        $root    = storage_path('app/pdf-exports/_cache/' . $hash);
        $absPath = realpath($root . DIRECTORY_SEPARATOR . $path);
        $rootReal = realpath($root);

        // Prevent path traversal (only if root exists)
        if ($rootReal && $absPath && str_starts_with($absPath, $rootReal . DIRECTORY_SEPARATOR) && is_file($absPath)) {
            $mime = mime_content_type($absPath) ?: 'application/octet-stream';
            return Response::file($absPath, ['Content-Type' => $mime, 'Cache-Control' => 'max-age=86400']);
        }

        // File not cached locally — proxy from Nextcloud WebDAV and cache
        try {
            $dav = app(\App\Services\WebDavClient::class);
            $content = $dav->download($path);
            if ($content === null || $content === false || $content === '') abort(404);

            // Cache locally for subsequent requests
            $target = $root . DIRECTORY_SEPARATOR . $path;
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($target, $content);

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match($ext) {
                'jpg','jpeg' => 'image/jpeg',
                'png'        => 'image/png',
                'webp'       => 'image/webp',
                'gif'        => 'image/gif',
                default      => 'application/octet-stream',
            };
            return response($content, 200, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'max-age=86400',
            ]);
        } catch (\Throwable $e) {
            logger()->warning('PB: asset proxy failed', ['path' => $path, 'error' => $e->getMessage()]);
            abort(404);
        }
    }

    // --------------------------------------------------------------------------
    // POST /photobook/override
    // --------------------------------------------------------------------------

    public function overrideTemplate(Request $request)
    {
        $folder     = (string) $request->input('folder', Config::get('photobook.folder'));
        $page       = (int)    $request->input('page', 0);
        $templateId = (string) $request->input('templateId', '');

        if ($page < 1 || $templateId === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid page/templateId'], 422);
        }

        $cacheRoot = $this->cacheRoot($folder);
        if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0775, true);

        // Write to overrides.json
        $jsonPath = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
        $data = is_file($jsonPath) ? (json_decode(@file_get_contents($jsonPath), true) ?: ['pages' => []]) : ['pages' => []];
        $data['pages'][(string) $page] = array_merge($data['pages'][(string) $page] ?? [], [
            'templateId' => $templateId,
            'updated_at' => date(DATE_ATOM),
        ]);
        @file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // POST /photobook/save-page
    // --------------------------------------------------------------------------

    public function savePage(Request $request)
    {
        $folder     = (string) $request->input('folder', Config::get('photobook.folder'));
        $page       = (int)    $request->input('page');
        $items      = $request->input('items');
        $templateId = $request->input('templateId');

        if ($page < 1) {
            return response()->json(['ok' => false, 'error' => 'Invalid page'], 422);
        }

        $cacheRoot = $this->cacheRoot($folder);
        if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0775, true);

        $jsonPath = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
        $data = is_file($jsonPath) ? (json_decode(@file_get_contents($jsonPath), true) ?: ['pages' => []]) : ['pages' => []];
        $entry = $data['pages'][(string) $page] ?? [];

        if (is_array($items)) {
            $norm = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $out = ['slotIndex' => (int) ($it['slotIndex'] ?? 0)];
                foreach (['crop', 'objectPosition', 'src'] as $k) {
                    if (array_key_exists($k, $it)) $out[$k] = $it[$k];
                }
                foreach (['scale', 'rotate', 'zoom', 'rotation'] as $k) {
                    if (isset($it[$k])) $out[$k] = (float) $it[$k];
                }
                foreach (['fit', 'align', 'offset', 'auto'] as $k) {
                    if (array_key_exists($k, $it)) $out[$k] = $it[$k];
                }
                if (array_key_exists('caption', $it)) {
                    $out['caption'] = is_string($it['caption']) || is_numeric($it['caption']) ? (string) $it['caption'] : null;
                }
                if (!empty($it['photo']) && is_array($it['photo'])) {
                    $ph = $it['photo'];
                    $out['photo'] = [
                        'path'     => (string) ($ph['path'] ?? ''),
                        'filename' => (string) ($ph['filename'] ?? ''),
                        'width'    => $ph['width']   ?? null,
                        'height'   => $ph['height']  ?? null,
                        'ratio'    => $ph['ratio']   ?? null,
                        'takenAt'  => $ph['takenAt'] ?? null,
                    ];
                }
                $norm[] = $out;
            }
            if (!empty($norm)) $entry['items'] = $norm;
        }
        if (is_string($templateId) && $templateId !== '') $entry['templateId'] = $templateId;

        $data['pages'][(string) $page] = $entry;
        @file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // GET /api/photobook/pages/{hash}  — hash-based (new editor)
    // --------------------------------------------------------------------------

    public function getPages(string $hash)
    {
        $dir  = storage_path('app/pdf-exports/_cache/' . $hash);
        $path = $dir . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($path)) abort(404, 'pages.json not found');
        $data = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($data)) abort(422, 'invalid json');
        return response()->json($data);
    }

    // --------------------------------------------------------------------------
    // PATCH /api/photobook/pages/{hash}
    // --------------------------------------------------------------------------

    public function patchPages(Request $request, string $hash)
    {
        $dir  = storage_path('app/pdf-exports/_cache/' . $hash);
        $path = $dir . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($path)) abort(404);

        $data  = json_decode(file_get_contents($path) ?: '', true) ?: [];
        $patch = $request->json()->all();

        // Simple merge: patch is expected to contain a partial pages array
        if (isset($patch['pages']) && is_array($patch['pages'])) {
            $indexed = [];
            foreach ($data['pages'] ?? [] as $p) $indexed[$p['n']] = $p;
            foreach ($patch['pages'] as $pp) {
                $n = $pp['n'] ?? null;
                if ($n !== null) $indexed[$n] = array_merge($indexed[$n] ?? [], $pp);
            }
            ksort($indexed);
            $data['pages'] = array_values($indexed);
        }

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/cover/{hash}
    // --------------------------------------------------------------------------

    public function setCover(Request $request, string $hash)
    {
        $dir  = storage_path('app/pdf-exports/_cache/' . $hash);
        $path = $dir . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($path)) abort(404);

        $data          = json_decode(file_get_contents($path) ?: '', true) ?: [];
        $data['cover'] = $request->json()->all();
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // GET /api/photobook/settings
    // POST /api/photobook/settings
    // --------------------------------------------------------------------------

    public function getSettings()
    {
        return response()->json([
            'ok' => true,
            'settings' => [
                'paper'       => Config::get('photobook.paper', 'a4'),
                'orientation' => Config::get('photobook.orientation', 'landscape'),
                'dpi'         => Config::get('photobook.dpi', 150),
                'page_frame_mm' => Config::get('photobook.page_frame_mm', 6),
                'page_gap_mm'   => Config::get('photobook.page_gap_mm', 2.5),
                'print'  => Config::get('photobook.print'),
                'cover'  => Config::get('photobook.cover', []),
                'nextcloud' => ['configured' => env('NEXTCLOUD_BASE_URI') !== null],
            ],
        ]);
    }

    public function updateSettings(Request $request)
    {
        // Settings are read-only at runtime (env-based); just echo back.
        return response()->json(['ok' => true, 'updated' => []]);
    }

    // --------------------------------------------------------------------------
    // GET /api/photobook/progress/{hash}
    // --------------------------------------------------------------------------

    public function progress(string $hash)
    {
        $dir    = storage_path('app/pdf-exports/_cache/' . $hash);
        $status = $dir . DIRECTORY_SEPARATOR . 'task.status.json';
        if (!is_file($status)) {
            return response()->json(['ok' => true, 'status' => ['progress' => 0, 'state' => 'pending']]);
        }
        $data = json_decode(@file_get_contents($status) ?: '', true) ?: [];
        return response()->json(['ok' => true, 'status' => $data]);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/build-folder
    // --------------------------------------------------------------------------

    public function startBuildByFolder(Request $request)
    {
        $folder = (string) $request->input('folder', '');
        if ($folder === '') {
            return response()->json(['ok' => false, 'error' => 'folder required'], 422);
        }

        $hash = sha1($folder);
        $dir  = storage_path('app/pdf-exports/_cache/' . $hash);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'task.status.json', json_encode([
            'state'     => 'queued',
            'progress'  => 0,
            'startedAt' => now()->toIso8601String(),
            'step'      => 'Queued',
        ]));

        \App\Jobs\BuildPhotoBook::dispatch([
            'folder'          => $folder,
            'title'           => trim((string) $request->input('title', '')),
            'cover_image'     => (string) $request->input('cover_image', ''),
            'cover_subtitle'  => (string) $request->input('cover_subtitle', ''),
            'cover_date'      => (string) $request->input('cover_date', ''),
            'cover_show_date' => (bool)   $request->input('cover_show_date', false),
            'ui_triggered'    => true,
        ]);

        return response()->json(['ok' => true, 'status' => 'started', 'hash' => $hash]);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/build/{hash}
    // --------------------------------------------------------------------------

    public function startBuild(Request $request, string $hash)
    {
        // Resolve folder from existing pages.json or request
        $path   = storage_path('app/pdf-exports/_cache/' . $hash) . DIRECTORY_SEPARATOR . 'pages.json';
        $folder = null;
        if (is_file($path)) {
            $doc    = json_decode(@file_get_contents($path) ?: '', true) ?: [];
            $folder = $doc['folder'] ?? null;
        }
        if (!$folder) {
            $folder = (string) $request->input('folder', '');
        }

        $dir = storage_path('app/pdf-exports/_cache/' . $hash);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'task.status.json', json_encode([
            'state'     => 'queued',
            'progress'  => 0,
            'startedAt' => now()->toIso8601String(),
            'step'      => 'Queued',
        ]));

        \App\Jobs\BuildPhotoBook::dispatch([
            'folder'          => $folder,
            'title'           => trim((string) $request->input('title', '')),
            'cover_image'     => (string) $request->input('cover_image', ''),
            'cover_subtitle'  => (string) $request->input('cover_subtitle', ''),
            'cover_date'      => (string) $request->input('cover_date', ''),
            'cover_show_date' => (bool)   $request->input('cover_show_date', false),
            'ui_triggered'    => true,
        ]);

        return response()->json(['ok' => true, 'status' => 'started']);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/pages/{hash}/page
    // --------------------------------------------------------------------------

    public function addPage(Request $request, string $hash)
    {
        $path = storage_path('app/pdf-exports/_cache/' . $hash) . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($path)) abort(404);
        $data  = json_decode(file_get_contents($path) ?: '', true) ?: [];
        $page  = $request->json()->all();
        $data['pages'][] = $page;
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // DELETE /api/photobook/pages/{hash}/page/{id}
    // --------------------------------------------------------------------------

    public function deletePage(string $hash, string $id)
    {
        $path = storage_path('app/pdf-exports/_cache/' . $hash) . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($path)) abort(404);
        $data = json_decode(file_get_contents($path) ?: '', true) ?: [];
        $data['pages'] = array_values(array_filter(
            $data['pages'] ?? [],
            fn($p) => (string) ($p['id'] ?? $p['n'] ?? '') !== $id
        ));
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/export/{hash}  — Playwright PDF export
    // --------------------------------------------------------------------------

    public function exportPdf(Request $request, string $hash)
    {
        $pagesPath = storage_path('app/pdf-exports/_cache/' . $hash) . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($pagesPath)) {
            return response()->json(['ok' => false, 'error' => 'pages.json not found — build first'], 404);
        }

        $outputDir = storage_path('app/pdf-exports');
        if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true);

        $filename   = 'book-' . now()->format('Ymd-His') . '.pdf';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

        $previewUrl = config('app.url') . '/photobook/preview/' . $hash;

        try {
            app(PlaywrightPdfRenderer::class)->render($previewUrl, $outputPath);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok'  => true,
            'url' => '/photobook/pdf/' . rawurlencode($filename),
        ]);
    }

    // --------------------------------------------------------------------------
    // GET /photobook/pdf/{file}  — serve exported PDF
    // --------------------------------------------------------------------------

    public function servePdf(string $file)
    {
        // Sanitize: only allow alphanumeric + dash + dot filenames
        if (!preg_match('/^[a-z0-9_\-]+\.pdf$/i', $file)) {
            abort(400, 'Invalid filename');
        }
        $path = storage_path('app/pdf-exports/' . $file);
        if (!is_file($path)) abort(404);
        return response()->download($path, $file, ['Content-Type' => 'application/pdf']);
    }
}
