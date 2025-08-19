<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Jobs\BuildPhotoBook;

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
                if ($d === '.' || $d === '..') continue;
                $pages = $root . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR . 'pages.json';
                if (!is_file($pages)) continue;
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

    public function getPages(string $hash)
    {
        $path = $this->pagesPath($hash);
        if (!is_file($path)) return response()->json(['ok' => false, 'error' => 'pages.json missing'], 404);
        $json = @file_get_contents($path) ?: '';
        $data = json_decode($json, true) ?: [];

        // Inject webSrc like legacy endpoint
        try {
            foreach (($data['pages'] ?? []) as &$p) {
                foreach (($p['items'] ?? []) as &$it) {
                    if (!empty($it['web'])) { $it['webSrc'] = $it['web']; continue; }
                    if (!empty($it['rel'])) { $it['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']]); continue; }
                    $src = (string) ($it['src'] ?? '');
                    if ($src !== '') {
                        $s = preg_replace('#^file:/{2,}#i', '', $src) ?? $src;
                        $needle = '/_cache/' . $hash . '/';
                        $pos = strpos(str_replace('\\', '/', $s), $needle);
                        if ($pos !== false) {
                            $rel = substr(str_replace('\\', '/', $s), $pos + strlen($needle));
                            $it['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => ltrim($rel, '/')]);
                        }
                    }
                }
                unset($it);
            }
            unset($p);
        } catch (\Throwable $e) {}

        // Merge overrides.json so UI sees latest changes
        try {
            $ovPath = $this->albumDir($hash) . DIRECTORY_SEPARATOR . 'overrides.json';
            $overrides = is_file($ovPath) ? (json_decode(@file_get_contents($ovPath), true) ?: ['pages'=>[]]) : ['pages'=>[]];
            if (is_array($overrides['pages'] ?? null)) {
                // Check for cover override (page "1" with templateId "cover")
                $coverOv = $overrides['pages']['1'] ?? null;
                if (is_array($coverOv) && ($coverOv['templateId'] ?? '') === 'cover') {
                    if (is_array($coverOv['items'] ?? null) && !empty($coverOv['items'])) {
                        $coverItem = $coverOv['items'][0] ?? null;
                        if (is_array($coverItem)) {
                            // Update cover data from overrides
                            if (!empty($coverItem['photo']['path'])) {
                                $data['cover']['image'] = $coverItem['photo']['path'];
                            }
                            // Add positioning and transformation properties
                            foreach (['objectPosition', 'scale', 'rotate'] as $prop) {
                                if (isset($coverItem[$prop])) {
                                    $data['cover'][$prop] = $coverItem[$prop];
                                }
                            }
                            // Add webSrc for cover if we have a path
                            if (!empty($data['cover']['image'])) {
                                $src = $data['cover']['image'];
                                $s = preg_replace('#^file:/{2,}#i', '', $src) ?? $src;
                                $needle = '/_cache/' . $hash . '/';
                                $pos = strpos(str_replace('\\', '/', $s), $needle);
                                if ($pos !== false) {
                                    $rel = substr(str_replace('\\', '/', $s), $pos + strlen($needle));
                                    $data['cover']['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => ltrim($rel, '/')]);
                                }
                            }
                        }
                    }
                }
                
                foreach ($data['pages'] as $idx => &$p) {
                    $pageNo = ($p['n'] ?? ($idx + 1));
                    $ov = $overrides['pages'][(string) $pageNo] ?? null;
                    if (is_array($ov)) {
                        if (!empty($ov['templateId'])) $p['templateId'] = (string) $ov['templateId'];
                        if (is_array($ov['items'] ?? null) && !empty($ov['items'])) {
                            $bySlot = [];
                            foreach ($ov['items'] as $it) { $bySlot[(int) ($it['slotIndex'] ?? 0)] = $it; }
                            foreach ($p['items'] as &$it) {
                                $si = (int) ($it['slotIndex'] ?? 0);
                                if (isset($bySlot[$si])) {
                                    $ovI = $bySlot[$si];
                                    foreach (['crop','objectPosition','scale','rotate'] as $k) if (isset($ovI[$k])) $it[$k] = $ovI[$k];
                                    if (!empty($ovI['photo'])) $it['photo'] = $ovI['photo'];
                                    if (!empty($ovI['src'])) $it['src'] = $ovI['src'];
                                }
                            }
                            unset($it);
                        }
                    }
                }
                unset($p);
            }
        } catch (\Throwable $e) {}

    return response()->json($data);
    }

    // Accept either JSON-Patch array or partial object (merged recursively)
    public function patchPages(Request $req, string $hash)
    {
        $path = $this->pagesPath($hash);
        if (!is_file($path)) return response()->json(['error' => 'pages.json not found'], 404);

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
        if (!is_file($path)) return response()->json(['error' => 'pages.json not found'], 404);

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
        if (!is_file($path)) return response()->json(['error' => 'pages.json not found'], 404);

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
        ]);
        $path = $this->pagesPath($hash);
        if (!is_file($path)) return response()->json(['error' => 'pages.json not found'], 404);

        $lock = Cache::lock("pb:pages:$hash", 10);
        return $lock->block(10, function () use ($path, $data) {
            $doc = json_decode((string) @file_get_contents($path), true) ?: [];
            $doc['cover'] = [
                'image' => $data['image'],
                'title' => $data['title'] ?? ($doc['manifest']['title'] ?? ''),
            ];
            $doc['updatedAt'] = now()->toIso8601String();
            $this->writeJsonAtomic($path, $doc);
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
            if ($folder === '') $folder = null;
        }

        $options = [
            'folder' => $folder,
            'title' => (string) $req->input('title', ''),
            'cover_image' => (string) $req->input('cover_image', ''),
            'ui_triggered' => true,
        ];
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

    private function applyPatch(array $doc, array $op): array
    {
        $path = ltrim((string) ($op['path'] ?? ''), '/');
        $parts = $path === '' ? [] : explode('/', $path);
        $ref = &$doc;
        for ($i = 0; $i < max(0, count($parts) - 1); $i++) {
            $k = ctype_digit($parts[$i]) ? (int) $parts[$i] : $parts[$i];
            if (!isset($ref[$k])) $ref[$k] = [];
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
                if (is_array($ref) && array_key_exists($key, $ref)) unset($ref[$key]);
                break;
        }
        return $doc;
    }
}
