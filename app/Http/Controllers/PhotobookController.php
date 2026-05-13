<?php

namespace App\Http\Controllers;

use App\Services\LayoutTemplates;
use App\Services\PlaywrightPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

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

    private function pagesPathForHash(string $hash): string
    {
        return storage_path('app/pdf-exports/_cache/' . $hash) . DIRECTORY_SEPARATOR . 'pages.json';
    }

    private function cacheRootForHash(string $hash): string
    {
        return storage_path('app/pdf-exports/_cache/' . $hash);
    }

    private function isCoverPage(array $page): bool
    {
        $id = strtolower((string) ($page['id'] ?? ''));
        $template = strtolower((string) ($page['templateId'] ?? $page['template'] ?? ''));

        return $id === 'cover' || $template === 'cover';
    }

    private function defaultCoverSlots(): array
    {
        return [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.649]];
    }

    private function coverItemFromMeta(array $cover, string $hash): ?array
    {
        $image = trim((string) ($cover['image'] ?? ''));
        if ($image === '') return null;

        $imageRel = ltrim($image, '/');
        $encodedRel = implode('/', array_map('rawurlencode', explode('/', $imageRel)));
        $sourcePath = trim((string) ($cover['source_path'] ?? $cover['sourcePath'] ?? $imageRel));
        $sourcePath = ltrim($sourcePath !== '' ? $sourcePath : $imageRel, '/');
        $fit = (($cover['fit'] ?? $cover['crop'] ?? 'cover') === 'contain') ? 'contain' : 'cover';
        $align = is_array($cover['align'] ?? null) ? $cover['align'] : ['x' => 0, 'y' => 0];
        $offset = is_array($cover['offset'] ?? null) ? $cover['offset'] : ['x' => 0, 'y' => 0];
        $zoom = isset($cover['zoom']) && is_numeric($cover['zoom']) && (float) $cover['zoom'] > 0
            ? (float) $cover['zoom']
            : (isset($cover['scale']) && is_numeric($cover['scale']) && (float) $cover['scale'] > 0 ? (float) $cover['scale'] : 1.0);
        $rotation = isset($cover['rotation']) && is_numeric($cover['rotation'])
            ? (float) $cover['rotation']
            : (isset($cover['rotate']) && is_numeric($cover['rotate']) ? (float) $cover['rotate'] : 0.0);
        $objectPosition = (string) ($cover['object_position'] ?? $cover['objectPosition'] ?? '50% 50%');

        return [
            'slotIndex' => 0,
            'photo' => [
                'path' => $sourcePath,
                'filename' => basename($sourcePath),
            ],
            'src' => '/photobook/asset/' . rawurlencode($hash) . '/' . $encodedRel,
            'fit' => $fit,
            'crop' => $fit,
            'align' => [
                'x' => max(-1, min(1, (float) ($align['x'] ?? 0))),
                'y' => max(-1, min(1, (float) ($align['y'] ?? 0))),
            ],
            'offset' => [
                'x' => is_numeric($offset['x'] ?? null) ? (float) $offset['x'] : 0.0,
                'y' => is_numeric($offset['y'] ?? null) ? (float) $offset['y'] : 0.0,
            ],
            'zoom' => $zoom,
            'rotation' => $rotation,
            'auto' => (bool) ($cover['auto'] ?? false),
            'objectPosition' => $objectPosition,
            'scale' => $zoom,
            'rotate' => $rotation,
        ];
    }

    private function normalizePagesDocument(array $data, string $hash): array
    {
        $pages = is_array($data['pages'] ?? null) ? array_values($data['pages']) : [];
        $coverPage = null;
        $interiorPages = [];
        $hasCoverMeta = !empty($data['cover']) && is_array($data['cover']);

        foreach ($pages as $page) {
            if (!is_array($page)) continue;
            $itemCount = is_array($page['items'] ?? null) ? count($page['items']) : 0;
            $legacyTemplate = strtolower((string) ($page['template'] ?? ''));
            $looksLikeAccidentalCover = !$hasCoverMeta && $itemCount > 1 && $legacyTemplate !== 'cover';
            if ($this->isCoverPage($page) && !$looksLikeAccidentalCover && $coverPage === null) {
                $coverPage = $page;
                continue;
            }
            if ($this->isCoverPage($page) && $looksLikeAccidentalCover) {
                unset($page['id'], $page['templateId']);
            }
            $interiorPages[] = $page;
        }

        $coverPage = is_array($coverPage) ? $coverPage : [];
        $coverPage['id'] = 'cover';
        $coverPage['n'] = 0;
        $coverPage['templateId'] = 'cover';
        $coverPage['slots'] = $this->defaultCoverSlots();
        $coverPage['items'] = is_array($coverPage['items'] ?? null) ? array_values($coverPage['items']) : [];
        if (count($coverPage['items']) > 1) {
            $coverPage['items'] = [array_values($coverPage['items'])[0]];
        }

        $metaCoverItem = $hasCoverMeta
            ? $this->coverItemFromMeta($data['cover'], $hash)
            : null;
        if ($metaCoverItem && empty($coverPage['items'])) {
            $coverPage['items'] = [$metaCoverItem];
        }
        if (empty($coverPage['items'])) {
            $coverPage['items'] = [[
                'slotIndex' => 0,
                'fit' => 'cover',
                'crop' => 'cover',
                'align' => ['x' => 0, 'y' => 0],
                'offset' => ['x' => 0, 'y' => 0],
                'zoom' => 1,
                'rotation' => 0,
                'auto' => false,
                'objectPosition' => '50% 50%',
                'scale' => 1,
                'rotate' => 0,
                'photo' => null,
            ]];
        }

        $normalized = [$coverPage];
        $pageNo = 1;
        $usedIds = ['cover' => true];
        foreach ($interiorPages as $page) {
            $existingId = trim((string) ($page['id'] ?? ''));
            $pageId = ($existingId !== '' && strtolower($existingId) !== 'cover' && empty($usedIds[$existingId]))
                ? $existingId
                : 'page-' . $pageNo;
            $page['id'] = $pageId;
            $usedIds[$pageId] = true;
            $page['n'] = $pageNo;
            $page['slots'] = is_array($page['slots'] ?? null) ? array_values($page['slots']) : [];
            $page['items'] = is_array($page['items'] ?? null) ? array_values($page['items']) : [];
            $normalized[] = $page;
            $pageNo++;
        }

        $data['pages'] = $normalized;
        $data['count'] = max(0, count($normalized) - 1);

        return $data;
    }

    private function findPageIndex(array $pages, array $needle): ?int
    {
        $id = trim((string) ($needle['id'] ?? ''));
        if ($id !== '') {
            foreach ($pages as $idx => $page) {
                if (is_array($page) && (string) ($page['id'] ?? '') === $id) {
                    return (int) $idx;
                }
            }
        }

        if (array_key_exists('n', $needle)) {
            $n = (int) $needle['n'];
            foreach ($pages as $idx => $page) {
                if (is_array($page) && (int) ($page['n'] ?? -1) === $n) {
                    return (int) $idx;
                }
            }
        }

        return null;
    }

    private function normalizeOverrideItem(array $item): array
    {
        $out = ['slotIndex' => (int) ($item['slotIndex'] ?? 0)];
        foreach (['crop', 'objectPosition', 'src'] as $key) {
            if (array_key_exists($key, $item)) $out[$key] = $item[$key];
        }
        foreach (['scale', 'rotate', 'zoom', 'rotation'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) $out[$key] = (float) $item[$key];
        }
        foreach (['fit', 'align', 'offset', 'auto'] as $key) {
            if (array_key_exists($key, $item)) $out[$key] = $item[$key];
        }
        if (array_key_exists('caption', $item)) {
            $out['caption'] = is_string($item['caption']) || is_numeric($item['caption']) ? (string) $item['caption'] : null;
        }
        if (!empty($item['photo']) && is_array($item['photo'])) {
            $photo = $item['photo'];
            $out['photo'] = [
                'path'     => (string) ($photo['path'] ?? ''),
                'filename' => (string) ($photo['filename'] ?? ''),
                'width'    => $photo['width']   ?? null,
                'height'   => $photo['height']  ?? null,
                'ratio'    => $photo['ratio']   ?? null,
                'takenAt'  => $photo['takenAt'] ?? null,
            ];
        }

        return $out;
    }

    private function mirrorPageToOverrides(string $hash, array $document, array $page): void
    {
        if ($this->isCoverPage($page)) return;
        $pageNo = (int) ($page['n'] ?? 0);
        if ($pageNo < 1) return;

        $dir = $this->cacheRootForHash($hash);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $jsonPath = $dir . DIRECTORY_SEPARATOR . 'overrides.json';
        $overrides = is_file($jsonPath) ? (json_decode((string) @file_get_contents($jsonPath), true) ?: ['pages' => []]) : ['pages' => []];
        $overrides['folder'] = (string) ($document['folder'] ?? ($overrides['folder'] ?? ''));
        $overrides['pages'] = is_array($overrides['pages'] ?? null) ? $overrides['pages'] : [];
        $entry = is_array($overrides['pages'][(string) $pageNo] ?? null) ? $overrides['pages'][(string) $pageNo] : [];

        if (!empty($page['templateId'])) {
            $entry['templateId'] = (string) $page['templateId'];
        }
        if (is_array($page['items'] ?? null)) {
            $entry['items'] = array_map(fn ($item) => is_array($item) ? $this->normalizeOverrideItem($item) : [], $page['items']);
        }
        if (isset($page['layoutFeedback']) && is_array($page['layoutFeedback'])) {
            $entry['layoutFeedback'] = $page['layoutFeedback'];
        }
        $entry['updated_at'] = date(DATE_ATOM);
        $overrides['pages'][(string) $pageNo] = $entry;

        @file_put_contents($jsonPath, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    public function editor(?string $hash = null)
    {
        return Inertia::render('Photobook/Editor', [
            'hash' => $hash ?? '',
        ]);
    }

    public function preview(Request $request, string $hash)
    {
        $props = ['hash' => $hash];

        if ($request->boolean('print')) {
            $props['printSettings'] = [
                'bleed_mm'       => (float)  $request->input('bleed', 0),
                'crop_marks'     => $request->input('crop_marks', '0') === '1',
                'spine_margin_mm'=> (float)  $request->input('spine', 0),
            ];
        }

        return Inertia::render('Photobook/Print', $props);
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
        $normalized = $this->normalizePagesDocument($data, $hash);
        if ($normalized !== $data) {
            @file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $data = $normalized;

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

    public function getPages(Request $request, string $hash)
    {
        $path = $this->pagesPathForHash($hash);
        if (!is_file($path)) abort(404, 'pages.json not found');
        $data = json_decode(file_get_contents($path) ?: '', true);
        if (!is_array($data)) abort(422, 'invalid json');
        $normalized = $this->normalizePagesDocument($data, $hash);
        if ($normalized !== $data) {
            @file_put_contents($path, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $data = $normalized;
        try { $this->injectWebSrc($data, $hash, $request); } catch (\Throwable) {}
        return response()->json($data);
    }

    // --------------------------------------------------------------------------
    // PATCH /api/photobook/pages/{hash}
    // --------------------------------------------------------------------------

    public function patchPages(Request $request, string $hash)
    {
        $path = $this->pagesPathForHash($hash);
        if (!is_file($path)) abort(404);

        $data  = $this->normalizePagesDocument(json_decode(file_get_contents($path) ?: '', true) ?: [], $hash);
        $patch = $request->json()->all();
        $patchedPageRefs = [];

        if (isset($patch['pages']) && is_array($patch['pages'])) {
            $pages = is_array($data['pages'] ?? null) ? array_values($data['pages']) : [];
            foreach ($patch['pages'] as $pp) {
                if (!is_array($pp)) continue;
                $idx = $this->findPageIndex($pages, $pp);
                if ($idx === null) {
                    $pages[] = $pp;
                    $patchedPageRefs[] = $pp;
                    continue;
                }

                $pages[$idx] = array_merge($pages[$idx], $pp);
                $patchedPageRefs[] = $pages[$idx];
            }
            $data['pages'] = $pages;
        }

        if (isset($patch['cover']) && is_array($patch['cover'])) {
            $data['cover'] = array_merge(is_array($data['cover'] ?? null) ? $data['cover'] : [], $patch['cover']);
        }

        $data = $this->normalizePagesDocument($data, $hash);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        foreach ($patchedPageRefs as $ref) {
            if (!is_array($ref)) continue;
            $idx = $this->findPageIndex($data['pages'] ?? [], $ref);
            if ($idx !== null && isset($data['pages'][$idx]) && is_array($data['pages'][$idx])) {
                $this->mirrorPageToOverrides($hash, $data, $data['pages'][$idx]);
            }
        }

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/feedback/{hash}
    // --------------------------------------------------------------------------

    public function saveLayoutFeedback(Request $request, string $hash)
    {
        $path = $this->pagesPathForHash($hash);
        if (!is_file($path)) abort(404);

        $payload = $request->json()->all();
        $action = (string) ($payload['action'] ?? '');
        $preferred = in_array($action, ['prefer_layout', 'prefer', 'like'], true);
        $clear = in_array($action, ['clear_preferred_layout', 'clear', 'unlike'], true);
        if (!$preferred && !$clear) {
            return response()->json(['ok' => false, 'error' => 'Invalid feedback action'], 422);
        }

        $data = $this->normalizePagesDocument(json_decode(file_get_contents($path) ?: '', true) ?: [], $hash);
        $needle = [
            'id' => (string) ($payload['pageId'] ?? ''),
            'n' => $payload['page'] ?? null,
        ];
        $idx = $this->findPageIndex($data['pages'] ?? [], $needle);
        if ($idx === null || !isset($data['pages'][$idx]) || !is_array($data['pages'][$idx])) abort(404);

        $page = $data['pages'][$idx];
        if ($this->isCoverPage($page)) {
            return response()->json(['ok' => false, 'error' => 'Cover layouts cannot be preferred'], 422);
        }

        $templateId = trim((string) ($payload['templateId'] ?? ($page['templateId'] ?? '')));
        if ($preferred && $templateId === '') {
            return response()->json(['ok' => false, 'error' => 'Missing templateId'], 422);
        }

        $feedback = [
            'preferred' => $preferred,
            'templateId' => $preferred ? $templateId : null,
            'reason' => isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null,
            'updated_at' => now()->toIso8601String(),
        ];

        $data['pages'][$idx]['layoutFeedback'] = $feedback;
        $feedbackKey = (string) ($page['id'] ?? $page['n']);
        $data['layout_feedback'] = is_array($data['layout_feedback'] ?? null) ? $data['layout_feedback'] : [];
        $data['layout_feedback'][$feedbackKey] = $feedback;

        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->mirrorPageToOverrides($hash, $data, $data['pages'][$idx]);

        $dir = $this->cacheRootForHash($hash);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'feedback.log',
            json_encode([
                'at' => now()->toIso8601String(),
                'pageId' => $feedbackKey,
                'page' => (int) ($page['n'] ?? 0),
                'action' => $action,
                'templateId' => $templateId !== '' ? $templateId : null,
                'reason' => $feedback['reason'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );

        return response()->json(['ok' => true, 'feedback' => $feedback]);
    }

    // --------------------------------------------------------------------------
    // POST /api/photobook/cover/{hash}
    // --------------------------------------------------------------------------

    public function setCover(Request $request, string $hash)
    {
        $path = $this->pagesPathForHash($hash);
        if (!is_file($path)) abort(404);

        $data  = $this->normalizePagesDocument(json_decode(file_get_contents($path) ?: '', true) ?: [], $hash);
        $cover = $request->json()->all();
        $data['cover'] = $cover;

        $image = trim((string) ($cover['image'] ?? ''));
        if ($image !== '') {
            $sourcePath = trim((string) ($cover['source_path'] ?? $cover['sourcePath'] ?? $image));
            $sourcePath = ltrim($sourcePath !== '' ? $sourcePath : $image, '/');
            $imageRel = ltrim($image, '/');
            $encodedRel = implode('/', array_map('rawurlencode', explode('/', $imageRel)));
            $fit = (($cover['fit'] ?? $cover['crop'] ?? 'cover') === 'contain') ? 'contain' : 'cover';
            $align = is_array($cover['align'] ?? null) ? $cover['align'] : ['x' => 0, 'y' => 0];
            $offset = is_array($cover['offset'] ?? null) ? $cover['offset'] : ['x' => 0, 'y' => 0];
            $zoom = isset($cover['zoom']) && is_numeric($cover['zoom']) && (float) $cover['zoom'] > 0
                ? (float) $cover['zoom']
                : (isset($cover['scale']) && is_numeric($cover['scale']) && (float) $cover['scale'] > 0 ? (float) $cover['scale'] : 1.0);
            $rotation = isset($cover['rotation']) && is_numeric($cover['rotation'])
                ? (float) $cover['rotation']
                : (isset($cover['rotate']) && is_numeric($cover['rotate']) ? (float) $cover['rotate'] : 0.0);
            $objectPosition = (string) ($cover['object_position'] ?? $cover['objectPosition'] ?? '50% 50%');

            $pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];
            $coverIndex = null;
            foreach ($pages as $idx => $page) {
                $id = strtolower((string) ($page['id'] ?? ''));
                $template = strtolower((string) ($page['templateId'] ?? $page['template'] ?? ''));
                if ((string) ($page['n'] ?? '') === '0' || $id === 'cover' || $template === 'cover') {
                    $coverIndex = $idx;
                    break;
                }
            }

            if ($coverIndex === null) {
                $pages[] = [
                    'id' => 'cover',
                    'n' => 0,
                    'templateId' => 'cover',
                    'slots' => [['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.649]],
                    'items' => [],
                ];
                $coverIndex = array_key_last($pages);
            }

            $page = is_array($pages[$coverIndex] ?? null) ? $pages[$coverIndex] : [];
            $items = is_array($page['items'] ?? null) ? $page['items'] : [];
            $item = is_array($items[0] ?? null) ? $items[0] : [];
            $item['slotIndex'] = 0;
            $item['photo'] = array_filter([
                'path' => $sourcePath,
                'filename' => basename($sourcePath),
                'width' => $item['photo']['width'] ?? null,
                'height' => $item['photo']['height'] ?? null,
                'ratio' => $item['photo']['ratio'] ?? null,
                'takenAt' => $item['photo']['takenAt'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');
            $item['src'] = '/photobook/asset/' . rawurlencode($hash) . '/' . $encodedRel;
            $item['fit'] = $fit;
            $item['crop'] = $fit;
            $item['align'] = [
                'x' => max(-1, min(1, (float) ($align['x'] ?? 0))),
                'y' => max(-1, min(1, (float) ($align['y'] ?? 0))),
            ];
            $item['offset'] = [
                'x' => is_numeric($offset['x'] ?? null) ? (float) $offset['x'] : 0.0,
                'y' => is_numeric($offset['y'] ?? null) ? (float) $offset['y'] : 0.0,
            ];
            $item['zoom'] = $zoom;
            $item['rotation'] = $rotation;
            $item['auto'] = (bool) ($cover['auto'] ?? false);
            $item['objectPosition'] = $objectPosition;
            $item['scale'] = $zoom;
            $item['rotate'] = $rotation;
            $items[0] = $item;
            $page['items'] = $items;
            $pages[$coverIndex] = $page;
            $data['pages'] = $pages;
        }

        $data = $this->normalizePagesDocument($data, $hash);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // GET /api/photobook/settings
    // POST /api/photobook/settings
    // --------------------------------------------------------------------------

    private function settingsOverridePath(): string
    {
        return storage_path('app/settings.json');
    }

    private function loadSettingsOverrides(): array
    {
        $path = $this->settingsOverridePath();
        if (!is_file($path)) return [];
        return json_decode(@file_get_contents($path) ?: '', true) ?: [];
    }

    public function getSettings()
    {
        $overrides = $this->loadSettingsOverrides();
        $printConfig = Config::get('photobook.print', []);
        $printOverride = $overrides['print'] ?? [];
        $merged = [
            'paper'         => Config::get('photobook.paper', 'a4'),
            'orientation'   => Config::get('photobook.orientation', 'landscape'),
            'dpi'           => Config::get('photobook.dpi', 150),
            'page_frame_mm' => Config::get('photobook.page_frame_mm', 6),
            'page_gap_mm'   => Config::get('photobook.page_gap_mm', 2.5),
            'print'         => array_merge(is_array($printConfig) ? $printConfig : [], $printOverride),
            'cover'         => Config::get('photobook.cover', []),
            'nextcloud'     => ['configured' => env('NEXTCLOUD_BASE_URI') !== null],
        ];
        return response()->json(['ok' => true, 'settings' => $merged]);
    }

    public function updateSettings(Request $request)
    {
        $incoming = $request->input('settings', []);
        if (!is_array($incoming) || empty($incoming)) {
            return response()->json(['ok' => true, 'updated' => []]);
        }

        $current = $this->loadSettingsOverrides();

        // Only allow overriding the print sub-key for now
        if (isset($incoming['print']) && is_array($incoming['print'])) {
            $allowed = ['enabled', 'bleed_mm', 'crop_marks', 'spine_margin_mm', 'safe_zone_mm'];
            $printPatch = array_intersect_key($incoming['print'], array_flip($allowed));
            $current['print'] = array_merge($current['print'] ?? [], $printPatch);
        }

        @file_put_contents(
            $this->settingsOverridePath(),
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return response()->json(['ok' => true, 'updated' => $current]);
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
        $path = $this->pagesPathForHash($hash);
        if (!is_file($path)) abort(404);
        $data  = $this->normalizePagesDocument(json_decode(file_get_contents($path) ?: '', true) ?: [], $hash);
        $page  = $request->json()->all();
        $data['pages'][] = $page;
        $data = $this->normalizePagesDocument($data, $hash);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------------------
    // DELETE /api/photobook/pages/{hash}/page/{id}
    // --------------------------------------------------------------------------

    public function deletePage(string $hash, string $id)
    {
        $path = $this->pagesPathForHash($hash);
        if (!is_file($path)) abort(404);
        $data = $this->normalizePagesDocument(json_decode(file_get_contents($path) ?: '', true) ?: [], $hash);
        $data['pages'] = array_values(array_filter(
            $data['pages'] ?? [],
            fn($p) => (string) ($p['id'] ?? $p['n'] ?? '') !== $id
        ));
        $data = $this->normalizePagesDocument($data, $hash);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

        // Merge config defaults with any persisted overrides
        $printConfig   = Config::get('photobook.print', []);
        $printOverride = $this->loadSettingsOverrides()['print'] ?? [];
        $print = array_merge(is_array($printConfig) ? $printConfig : [], $printOverride);

        $printEnabled  = !empty($print['enabled']);
        $bleedMm       = (float) ($print['bleed_mm'] ?? 3.0);
        $cropMarks     = !empty($print['crop_marks']);
        $spineMarginMm = (float) ($print['spine_margin_mm'] ?? 10.0);

        // Pass print settings to the preview page via query params
        $query = [];
        if ($printEnabled) {
            $query['bleed']       = $bleedMm;
            $query['crop_marks']  = $cropMarks ? '1' : '0';
            $query['spine']       = $spineMarginMm;
        }

        $previewUrl = PlaywrightPdfRenderer::previewUrl($hash, $query);

        // Expand page dimensions to include bleed on all sides
        $paper       = Config::get('photobook.paper', 'a4');
        $orientation = Config::get('photobook.orientation', 'landscape');
        [$baseW, $baseH] = match (strtolower($paper)) {
            'a3'    => [297.0, 420.0],
            default => [210.0, 297.0],
        };
        if ($orientation === 'landscape') [$baseW, $baseH] = [$baseH, $baseW];

        $renderW = $printEnabled ? $baseW + 2 * $bleedMm : $baseW;
        $renderH = $printEnabled ? $baseH + 2 * $bleedMm : $baseH;

        try {
            app(PlaywrightPdfRenderer::class)->render($previewUrl, $outputPath, [
                'width'  => $renderW,
                'height' => $renderH,
            ]);
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
