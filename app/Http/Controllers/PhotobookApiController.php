<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Jobs\BuildPhotoBook;
use App\Services\LayoutTemplates;

class PhotobookApiController extends Controller
{
    private function cacheRoot(): string
    {
        return storage_path('app/pdf-exports/_cache');
    }

    private function albumDir(string $hash): string
    {
        return $this->cacheRoot() . DIRECTORY_SEPARATOR . basename($hash);
    }

    private function pagesPath(string $hash): string
    {
        return $this->albumDir($hash) . DIRECTORY_SEPARATOR . 'pages.json';
    }

    private function normalizeAssetUrl(string $hash, $value): ?string
    {
        $relative = $this->extractRelativeAssetPath($hash, $value);
        if ($relative) {
            return route('photobook.asset', ['hash' => $hash, 'path' => $relative], false);
        }

        return null;
    }

    private function extractRelativeAssetPath(string $hash, $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $candidate = preg_replace('#^file:/{2,}#i', '', $value) ?? $value;
        $candidate = str_replace('\\\\', '/', $candidate);

        $paths = [$candidate];
        $parsedPath = @parse_url($candidate, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '' && $parsedPath !== $candidate) {
            $paths[] = $parsedPath;
        }

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }

            $trimmed = preg_split('/[?#]/', $path, 2)[0] ?? $path;
            if ($trimmed === '') {
                continue;
            }

            $candidates = [$trimmed];
            if ($trimmed[0] !== '/') {
                $candidates[] = '/' . $trimmed;
            }

            foreach ($candidates as $candidatePath) {
                if ($candidatePath !== '' && preg_match('#/photobook/asset/' . preg_quote($hash, '#') . '/(.+)$#', $candidatePath, $m)) {
                    $rel = ltrim($m[1], '/');
                    if ($rel !== '') {
                        return $rel;
                    }
                }
            }

            foreach ($candidates as $candidatePath) {
                $needle = '/_cache/' . $hash . '/';
                $pos = strpos($candidatePath, $needle);
                if ($pos !== false) {
                    $rel = ltrim(substr($candidatePath, $pos + strlen($needle)), '/');
                    if ($rel !== '') {
                        return $rel;
                    }
                }
            }

            if ($trimmed[0] !== '/' && !preg_match('#^[a-zA-Z]:/#', $trimmed) && strpos($trimmed, '://') === false) {
                $rel = ltrim($trimmed, '/');
                if ($rel !== '') {
                    return $rel;
                }
            }
        }

        return null;
    }

    private function writeJsonAtomic(string $path, array $data): void
    {
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @rename($tmp, $path);
    }

    public function albums()
    {
        $root = $this->cacheRoot();
        $out = [];
        if (is_dir($root)) {
            foreach (scandir($root) ?: [] as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $pages = $root . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR . 'pages.json';
                if (!is_file($pages))
                    continue;
                $data = json_decode((string) @file_get_contents($pages), true) ?: [];
                $out[] = [
                    'hash' => $d,
                    'title' => $data['manifest']['title'] ?? ($data['folder'] ?? $d),
                    'count' => $data['count'] ?? (isset($data['pages']) ? count($data['pages']) : 0),
                    'updatedAt' => $data['updatedAt'] ?? date(DATE_ATOM, (int) @filemtime($pages)),
                ];
            }
        }
        return response()->json($out);
    }

    public function templates()
    {
        return response()->json(LayoutTemplates::all());
    }

    public function getPages(Request $request, string $hash)
    {
        $path = $this->pagesPath($hash);
        if (!is_file($path))
            return response()->json(['ok' => false, 'error' => 'pages.json missing'], 404);
        $json = @file_get_contents($path) ?: '';
        $data = json_decode($json, true) ?: [];

        // Inject webSrc like legacy endpoint (prefer relative asset URLs)
        try {
            foreach (($data['pages'] ?? []) as &$p) {
                foreach (($p['items'] ?? []) as &$it) {
                    if (!empty($it['rel'])) {
                        $it['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                        continue;
                    }
                    if (!empty($it['webSrc'])) {
                        $normalized = $this->normalizeAssetUrl($hash, $it['webSrc']);
                        if ($normalized) {
                            $it['webSrc'] = $normalized;
                            continue;
                        }
                    }
                    if (!empty($it['web'])) {
                        $normalized = $this->normalizeAssetUrl($hash, $it['web']);
                        $it['webSrc'] = $normalized ?? (string) $it['web'];
                        continue;
                    }
                    $normalizedSrc = $this->normalizeAssetUrl($hash, $it['src'] ?? null);
                    if ($normalizedSrc) {
                        $it['webSrc'] = $normalizedSrc;
                    }
                }
                unset($it);
            }
            unset($p);
        } catch (\Throwable $e) {
        }

        // Merge overrides.json so UI sees latest changes
        try {
            $ovPath = $this->albumDir($hash) . DIRECTORY_SEPARATOR . 'overrides.json';
            $overrides = is_file($ovPath) ? (json_decode(@file_get_contents($ovPath), true) ?: ['pages' => []]) : ['pages' => []];
            if (is_array($overrides['pages'] ?? null)) {
                // Build template index id -> slots for quick lookup
                $tplIndex = [];
                try {
                    $all = LayoutTemplates::all();
                    foreach ($all as $count => $arr) {
                        foreach ((array) $arr as $tpl) {
                            $id = (string) ($tpl['id'] ?? '');
                            if ($id !== '' && !empty($tpl['slots']) && is_array($tpl['slots'])) {
                                $tplIndex[$id] = $tpl['slots'];
                            }
                        }
                    }
                } catch (\Throwable $e) {}
                // Check for cover override (page "1" with templateId "cover")
                $coverOv = $overrides['pages']['1'] ?? null;
                if (is_array($coverOv) && ($coverOv['templateId'] ?? '') === 'cover') {
                    if (is_array($coverOv['items'] ?? null) && !empty($coverOv['items'])) {
                        $coverItem = $coverOv['items'][0] ?? null;
                        if (is_array($coverItem)) {
                            // Update cover data from overrides
                            $existingCoverImage = $data['cover']['image'] ?? null;
                            if (is_string($existingCoverImage) && $existingCoverImage !== '') {
                                $normalizedExisting = $this->extractRelativeAssetPath($hash, $existingCoverImage);
                                if ($normalizedExisting) {
                                    $data['cover']['image'] = $normalizedExisting;
                                }
                            }
                            if (!empty($coverItem['photo']['path'])) {
                                $coverPhotoPath = (string) $coverItem['photo']['path'];
                                $normalizedPhoto = $this->extractRelativeAssetPath($hash, $coverPhotoPath);
                                if ($normalizedPhoto) {
                                    $data['cover']['image'] = $normalizedPhoto;
                                } else {
                                    $data['cover']['sourcePath'] = $coverPhotoPath;
                                }
                            }
                            // Add positioning and transformation properties (legacy and canonical)
                            foreach (['objectPosition', 'scale', 'rotate'] as $prop) {
                                if (isset($coverItem[$prop])) {
                                    $data['cover'][$prop] = $coverItem[$prop];
                                }
                            }
                            foreach (['align','offset','zoom','rotation','auto'] as $prop) {
                                if (isset($coverItem[$prop])) {
                                    $data['cover'][$prop] = $coverItem[$prop];
                                }
                            }
                            // Add webSrc for cover if we have a path
                            if (!empty($coverItem['src'])) {
                                $normalizedFromSrc = $this->extractRelativeAssetPath($hash, $coverItem['src']);
                                if ($normalizedFromSrc) {
                                    $data['cover']['image'] = $normalizedFromSrc;
                                }
                                $coverWeb = $this->normalizeAssetUrl($hash, $coverItem['src']);
                                if ($coverWeb) {
                                    $data['cover']['webSrc'] = $coverWeb;
                                }
                            }
                            if (!empty($data['cover']['image'])) {
                                $coverWeb = $this->normalizeAssetUrl($hash, $data['cover']['image']);
                                if ($coverWeb) {
                                    $data['cover']['webSrc'] = $coverWeb;
                                }
                            }
                        }
                    }
                }

                foreach ($data['pages'] as $idx => &$p) {
                    $pageNo = ($p['n'] ?? ($idx + 1));
                    $ov = $overrides['pages'][(string) $pageNo] ?? null;
                    if (is_array($ov)) {
                        if (!empty($ov['templateId'])) {
                            $p['templateId'] = (string) $ov['templateId'];
                            // If we know the template slots, reflect them so UI sees new geometry on reload
                            if (!empty($tplIndex[$p['templateId']])) {
                                $p['slots'] = $tplIndex[$p['templateId']];
                            }
                        }
                        if (is_array($ov['items'] ?? null) && !empty($ov['items'])) {
                            $bySlot = [];
                            foreach ($ov['items'] as $it) {
                                $bySlot[(int) ($it['slotIndex'] ?? 0)] = $it;
                            }
                            foreach ($p['items'] as &$it) {
                                $si = (int) ($it['slotIndex'] ?? 0);
                                if (isset($bySlot[$si])) {
                                    $ovI = $bySlot[$si];
                                    // Legacy keys
                                    foreach (['crop', 'objectPosition', 'scale', 'rotate'] as $k)
                                        if (array_key_exists($k, $ovI))
                                            $it[$k] = $ovI[$k];
                                    // Canonical keys (align/offset/zoom/rotation/auto)
                                    foreach (['align','offset','zoom','rotation','auto'] as $k)
                                        if (array_key_exists($k, $ovI))
                                            $it[$k] = $ovI[$k];
                                    if (!empty($ovI['photo']))
                                        $it['photo'] = $ovI['photo'];
                                    if (!empty($ovI['src']))
                                        $it['src'] = $ovI['src'];
                                }
                            }
                            unset($it);
                        }
                    }
                }
                unset($p);

                // Re-normalize webSrc after overrides have been applied
                foreach (($data['pages'] ?? []) as &$pp) {
                    foreach (($pp['items'] ?? []) as &$it) {
                        if (!empty($it['rel'])) {
                            $it['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                            continue;
                        }
                        if (!empty($it['webSrc'])) {
                            $normalized = $this->normalizeAssetUrl($hash, $it['webSrc']);
                            if ($normalized) {
                                $it['webSrc'] = $normalized;
                                continue;
                            }
                        }
                        if (!empty($it['web'])) {
                            $normalized = $this->normalizeAssetUrl($hash, $it['web']);
                            if ($normalized) {
                                $it['webSrc'] = $normalized;
                                continue;
                            }
                            $it['webSrc'] = (string) $it['web'];
                            continue;
                        }
                        $normalizedSrc = $this->normalizeAssetUrl($hash, $it['src'] ?? null);
                        if ($normalizedSrc) {
                            $it['webSrc'] = $normalizedSrc;
                        }
                    }
                    unset($it);
                }
                unset($pp);
            }
        } catch (\Throwable $e) {
        }

        // Make webSrc absolute using current request host if it's a root-relative path
        try {
            $origin = $request->getSchemeAndHttpHost();
            foreach (($data['pages'] ?? []) as &$p) {
                foreach (($p['items'] ?? []) as &$it) {
                    if (isset($it['webSrc']) && is_string($it['webSrc']) && $it['webSrc'] !== '') {
                        if ($it['webSrc'][0] === '/') {
                            $it['webSrc'] = $origin . $it['webSrc'];
                        } else if (preg_match('#^https?://#i', $it['webSrc'])) {
                            $path = parse_url($it['webSrc'], PHP_URL_PATH);
                            if (is_string($path) && preg_match('#^/photobook/asset/' . preg_quote($hash, '#') . '/#', $path)) {
                                $it['webSrc'] = $origin . $path;
                            }
                        }
                    }
                }
                unset($it);
            }
            unset($p);
            if (isset($data['cover']) && is_array($data['cover']) && isset($data['cover']['webSrc']) && is_string($data['cover']['webSrc']) && $data['cover']['webSrc'] !== '' && $data['cover']['webSrc'][0] === '/') {
                $data['cover']['webSrc'] = $origin . $data['cover']['webSrc'];
            }
        } catch (\Throwable $e) {
        }

        return response()->json($data);
    }

    public function patchPages(Request $req, string $hash)
    {
        $path = $this->pagesPath($hash);
        if (!is_file($path))
            return response()->json(['error' => 'pages.json not found'], 404);

        $lock = Cache::lock("pb:pages:$hash", 10);
        return $lock->block(10, function () use ($req, $path) {
            $doc = json_decode((string) @file_get_contents($path), true) ?: [];
            $payload = $req->json()->all();

            if (isset($payload[0]) && is_array($payload[0]) && isset($payload[0]['op'])) {
                foreach ($payload as $op) {
                    $doc = $this->applyPatch($doc, $op);
                }
            } else {
                $doc = array_replace_recursive($doc, $payload);
            }
            $doc['updatedAt'] = now()->toIso8601String();
            $this->writeJsonAtomic($path, $doc);
            return response()->json(['ok' => true, 'updatedAt' => $doc['updatedAt']]);
        });
    }

    public function addPage(Request $req, string $hash)
    {
        $data = $req->validate([
            'id' => 'nullable|string',
            'templateId' => 'required|string',
            'items' => 'array',
        ]);
        $path = $this->pagesPath($hash);
        if (!is_file($path))
            return response()->json(['error' => 'pages.json not found'], 404);

        $lock = Cache::lock("pb:pages:$hash", 10);
        return $lock->block(10, function () use ($path, $data) {
            $doc = json_decode((string) @file_get_contents($path), true) ?: [];
            $doc['pages'] = $doc['pages'] ?? [];
            $new = [
                'id' => $data['id'] ?? ('p-' . Str::uuid()),
                'templateId' => $data['templateId'],
                'items' => $data['items'] ?? [],
            ];
            $doc['pages'][] = $new;
            $doc['count'] = count($doc['pages']);
            $doc['updatedAt'] = now()->toIso8601String();
            $this->writeJsonAtomic($path, $doc);
            return response()->json(['ok' => true, 'page' => $new, 'count' => $doc['count']]);
        });
    }

    public function deletePage(string $hash, string $pageId)
    {
        $path = $this->pagesPath($hash);
        if (!is_file($path))
            return response()->json(['error' => 'pages.json not found'], 404);

        $lock = Cache::lock("pb:pages:$hash", 10);
        return $lock->block(10, function () use ($path, $pageId) {
            $doc = json_decode((string) @file_get_contents($path), true) ?: [];
            $doc['pages'] = array_values(array_filter($doc['pages'] ?? [], fn($p) => ($p['id'] ?? '') !== $pageId));
            $doc['count'] = count($doc['pages']);
            $doc['updatedAt'] = now()->toIso8601String();
            $this->writeJsonAtomic($path, $doc);
            return response()->json(['ok' => true, 'count' => $doc['count']]);
        });
    }

    public function setCover(Request $req, string $hash)
    {
        $data = $req->validate([
            'image' => 'required|string',
            'title' => 'nullable|string',
            'subtitle' => 'nullable|string',
            'date' => 'nullable|string',
            'show_date' => 'nullable|boolean',
            'source_path' => 'nullable|string',
            'fit' => 'nullable|string',
            'crop' => 'nullable|string',
            'align' => 'nullable|array',
            'align.x' => 'nullable|numeric',
            'align.y' => 'nullable|numeric',
            'offset' => 'nullable|array',
            'offset.x' => 'nullable|numeric',
            'offset.y' => 'nullable|numeric',
            'zoom' => 'nullable|numeric',
            'rotation' => 'nullable|numeric',
            'auto' => 'nullable|boolean',
            'scale' => 'nullable|numeric',
            'rotate' => 'nullable|numeric',
            'object_position' => 'nullable|string',
        ]);
        $path = $this->pagesPath($hash);
        if (!is_file($path))
            return response()->json(['error' => 'pages.json not found'], 404);

        $lock = Cache::lock("pb:pages:$hash", 10);
        return $lock->block(10, function () use ($path, $data, $hash) {
            $doc = json_decode((string) @file_get_contents($path), true) ?: [];
            $existingCover = is_array($doc['cover'] ?? null) ? $doc['cover'] : [];

            $imageRel = ltrim((string) $data['image'], '/');

            $titleValue = array_key_exists('title', $data)
                ? trim((string) ($data['title'] ?? ''))
                : (string) ($existingCover['title'] ?? ($doc['manifest']['title'] ?? ''));
            if ($titleValue === '') {
                $titleValue = (string) ($doc['manifest']['title'] ?? '');
            }

            $cover = $existingCover;
            $cover['image'] = $imageRel;
            $cover['title'] = $titleValue;

            $cover['webSrc'] = $this->normalizeAssetUrl($hash, $imageRel) ?? ($cover['webSrc'] ?? null);

            if (array_key_exists('subtitle', $data)) {
                $subtitleValue = trim((string) ($data['subtitle'] ?? ''));
                $cover['subtitle'] = $subtitleValue;
                $cover['cover_subtitle'] = $subtitleValue;
            }

            if (array_key_exists('date', $data)) {
                $dateValue = trim((string) ($data['date'] ?? ''));
                $cover['date'] = $dateValue;
                $cover['cover_date'] = $dateValue;
            }

            if (array_key_exists('show_date', $data)) {
                $showDateValue = (bool) $data['show_date'];
                $cover['show_date'] = $showDateValue;
                $cover['cover_show_date'] = $showDateValue;
            }

            if (array_key_exists('source_path', $data)) {
                $sourcePath = is_string($data['source_path']) ? trim($data['source_path']) : $data['source_path'];
                if (is_string($sourcePath) && $sourcePath !== '') {
                    $cover['sourcePath'] = $sourcePath;
                } else {
                    unset($cover['sourcePath']);
                }
            }

            if (array_key_exists('fit', $data) && is_string($data['fit'])) {
                $cover['fit'] = strtolower($data['fit']) === 'contain' ? 'contain' : 'cover';
            }
            if (array_key_exists('crop', $data) && is_string($data['crop'])) {
                $cover['crop'] = strtolower($data['crop']) === 'contain' ? 'contain' : 'cover';
            } elseif (isset($cover['fit'])) {
                $cover['crop'] = $cover['fit'];
            }

            if (isset($data['align']) && is_array($data['align'])) {
                $ax = is_numeric($data['align']['x'] ?? null) ? (float) $data['align']['x'] : 0.0;
                $ay = is_numeric($data['align']['y'] ?? null) ? (float) $data['align']['y'] : 0.0;
                $cover['align'] = [
                    'x' => max(-1.0, min(1.0, $ax)),
                    'y' => max(-1.0, min(1.0, $ay)),
                ];
            }

            if (isset($data['offset']) && is_array($data['offset'])) {
                $ox = is_numeric($data['offset']['x'] ?? null) ? (float) $data['offset']['x'] : 0.0;
                $oy = is_numeric($data['offset']['y'] ?? null) ? (float) $data['offset']['y'] : 0.0;
                $cover['offset'] = ['x' => $ox, 'y' => $oy];
            }

            if (array_key_exists('zoom', $data) && $data['zoom'] !== null) {
                $zoomVal = (float) $data['zoom'];
                if ($zoomVal > 0) {
                    $cover['zoom'] = $zoomVal;
                }
            }
            if (array_key_exists('scale', $data) && $data['scale'] !== null) {
                $scaleVal = (float) $data['scale'];
                if ($scaleVal > 0) {
                    $cover['scale'] = $scaleVal;
                }
            }

            if (array_key_exists('rotation', $data) && $data['rotation'] !== null) {
                $cover['rotation'] = (float) $data['rotation'];
            }
            if (array_key_exists('rotate', $data) && $data['rotate'] !== null) {
                $cover['rotate'] = (float) $data['rotate'];
            }

            if (array_key_exists('auto', $data)) {
                $cover['auto'] = (bool) $data['auto'];
            }

            if (array_key_exists('object_position', $data) && is_string($data['object_position'])) {
                $pos = trim($data['object_position']);
                if ($pos !== '') {
                    $cover['objectPosition'] = $pos;
                    $cover['object_position'] = $pos;
                }
            }

            $doc['cover'] = $cover;
            $doc['updatedAt'] = now()->toIso8601String();
            $this->writeJsonAtomic($path, $doc);

            // Remove legacy cover override entry so the first interior page no longer references the cover photo
            try {
                $ovPath = $this->albumDir($hash) . DIRECTORY_SEPARATOR . 'overrides.json';
                if (is_file($ovPath)) {
                    $ov = json_decode((string) @file_get_contents($ovPath), true) ?: [];
                    if (isset($ov['pages']) && is_array($ov['pages'])) {
                        $first = $ov['pages']['1'] ?? null;
                        if (is_array($first) && (($first['templateId'] ?? '') === 'cover')) {
                            unset($ov['pages']['1']);
                            if (empty($ov['pages'])) {
                                $ov['pages'] = [];
                            }
                            @file_put_contents($ovPath, json_encode($ov, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore cleanup failures
            }
            return response()->json(['ok' => true, 'cover' => $doc['cover']]);
        });
    }

    public function uploadImage(Request $req, string $hash)
    {
        $req->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp']);
        $dir = $this->albumDir($hash) . DIRECTORY_SEPARATOR . 'images';
        @mkdir($dir, 0775, true);
        $file = $req->file('file');
        $name = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name = $name . '.' . $ext;
        $file->move($dir, $name);
        return response()->json(['ok' => true, 'path' => 'images/' . $name]);
    }

    public function startBuild(Request $req, string $hash)
    {
        // Try to resolve folder from pages.json
        $path = $this->pagesPath($hash);
        $folder = null;
        if (is_file($path)) {
            $doc = json_decode((string) @file_get_contents($path), true) ?: [];
            $folder = $doc['folder'] ?? null;
        }
        // Fallback: accept folder from request (first build or no pages.json yet)
        if (!$folder) {
            $folder = (string) $req->input('folder', '');
            if ($folder === '')
                $folder = null;
        }

        $options = [
            'folder' => $folder,
            'title' => trim((string) $req->input('title', '')),
            'cover_image' => (string) $req->input('cover_image', ''),
            'ui_triggered' => true,
        ];
        $options = array_merge($options, $this->extractCoverOptionPayload($req));
        BuildPhotoBook::dispatch($options);

        // Initialize progress file
        $dir = $this->albumDir($hash);
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'task.status.json', json_encode([
            'state' => 'queued',
            'progress' => 0,
            'startedAt' => now()->toIso8601String(),
            'step' => 'Queued',
        ]));

        return response()->json(['ok' => true, 'status' => 'started']);
    }

    /**
     * POST /api/photobook/build-folder
     * Start a build for a folder that may not yet have an album hash (new album).
     * Computes the hash from the folder, initializes the progress file, and dispatches the job.
     * Returns the computed hash so the client can poll /api/photobook/progress/{hash}.
     */
    public function startBuildByFolder(Request $req)
    {
        $folder = (string) $req->input('folder', '');
        if ($folder === '') {
            return response()->json(['ok' => false, 'error' => 'folder required'], 422);
        }

        $options = [
            'folder' => $folder,
            'title' => trim((string) $req->input('title', '')),
            'cover_image' => (string) $req->input('cover_image', ''),
            'ui_triggered' => true,
        ];
        $options = array_merge($options, $this->extractCoverOptionPayload($req));
        BuildPhotoBook::dispatch($options);

        $hash = sha1($folder);
        // Initialize progress file
        $dir = $this->albumDir($hash);
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'task.status.json', json_encode([
            'state' => 'queued',
            'progress' => 0,
            'startedAt' => now()->toIso8601String(),
            'step' => 'Queued',
        ]));

        return response()->json(['ok' => true, 'status' => 'started', 'hash' => $hash]);
    }

    public function progress(string $hash)
    {
        $dir = $this->albumDir($hash);
        $status = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'task.status.json');
        $logTail = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'rebuild.log');
        return response()->json([
            'ok' => (bool) $status,
            'status' => $status ? json_decode($status, true) : null,
            'logTail' => $logTail ? mb_substr($logTail, -8000) : '',
        ]);
    }

    private function extractCoverOptionPayload(Request $req): array
    {
        $payload = [];

        $subtitle = trim((string) $req->input('cover_subtitle', ''));
        if ($subtitle !== '') {
            $payload['cover_subtitle'] = $subtitle;
            $payload['subtitle'] = $subtitle;
        }

        $date = trim((string) $req->input('cover_date', ''));
        if ($date !== '') {
            $payload['cover_date'] = $date;
            $payload['date'] = $date;
        }

        if ($req->has('cover_show_date')) {
            $show = $req->boolean('cover_show_date');
            $payload['cover_show_date'] = $show;
            $payload['show_date'] = $show;
        }

        $sourcePath = trim((string) $req->input('cover_source_path', ''));
        if ($sourcePath !== '') {
            $payload['cover_source_path'] = $sourcePath;
        }

        $align = $req->input('cover_align');
        if (is_array($align)) {
            $payload['cover_align'] = [
                'x' => $this->clampAlignComponent($align['x'] ?? 0),
                'y' => $this->clampAlignComponent($align['y'] ?? 0),
            ];
        }

        $offset = $req->input('cover_offset');
        if (is_array($offset)) {
            $payload['cover_offset'] = [
                'x' => $this->floatValue($offset['x'] ?? 0.0),
                'y' => $this->floatValue($offset['y'] ?? 0.0),
            ];
        }

        if ($req->filled('cover_zoom')) {
            $payload['cover_zoom'] = $this->positiveFloatValue($req->input('cover_zoom'), 1.0);
        }

        if ($req->filled('cover_rotation')) {
            $payload['cover_rotation'] = $this->floatValue($req->input('cover_rotation'), 0.0);
        }

        if ($req->has('cover_auto')) {
            $payload['cover_auto'] = $req->boolean('cover_auto');
        }

        $fit = $req->input('cover_fit');
        if (is_string($fit) && $fit !== '') {
            $payload['cover_fit'] = strtolower($fit) === 'contain' ? 'contain' : 'cover';
        }

        $crop = $req->input('cover_crop');
        if (is_string($crop) && $crop !== '') {
            $payload['cover_crop'] = strtolower($crop) === 'contain' ? 'contain' : 'cover';
        }

        if ($req->filled('cover_scale')) {
            $payload['cover_scale'] = $this->positiveFloatValue($req->input('cover_scale'), 1.0);
        }

        if ($req->filled('cover_rotate')) {
            $payload['cover_rotate'] = $this->floatValue($req->input('cover_rotate'), 0.0);
        }

        $objectPos = trim((string) $req->input('cover_object_position', ''));
        if ($objectPos !== '') {
            $payload['cover_object_position'] = $objectPos;
        }

        return $payload;
    }

    private function clampAlignComponent($value): float
    {
        $num = $this->floatValue($value, 0.0);
        return max(-1.0, min(1.0, $num));
    }

    private function floatValue($value, float $default = 0.0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }

    private function positiveFloatValue($value, float $default = 1.0): float
    {
        $num = $this->floatValue($value, $default);
        return $num > 0 ? $num : $default;
    }

    private function applyPatch(array $doc, array $op): array
    {
        $path = ltrim((string) ($op['path'] ?? ''), '/');
        $parts = $path === '' ? [] : explode('/', $path);
        $ref = &$doc;
        for ($i = 0; $i < max(0, count($parts) - 1); $i++) {
            $k = ctype_digit($parts[$i]) ? (int) $parts[$i] : $parts[$i];
            if (!isset($ref[$k]))
                $ref[$k] = [];
            $ref = &$ref[$k];
        }
        $last = end($parts);
        $key = ctype_digit((string) $last) ? (int) $last : $last;

        switch ($op['op'] ?? 'replace') {
            case 'add':
            case 'replace':
                $ref[$key] = $op['value'] ?? null;
                break;
            case 'remove':
                if (is_array($ref) && array_key_exists($key, $ref))
                    unset($ref[$key]);
                break;
        }
        return $doc;
    }
}
