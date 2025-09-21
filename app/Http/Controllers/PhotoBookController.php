<?php

namespace App\Http\Controllers;

use App\Jobs\BuildPhotoBook;
use App\Services\LayoutTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class PhotoBookController extends Controller
{
    private function normalizeAssetUrl(string $hash, $value): ?string
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
            $withLeadingSlash = $trimmed !== '' && $trimmed[0] !== '/' ? '/' . $trimmed : $trimmed;

            if ($withLeadingSlash !== '' && preg_match('#/photobook/asset/' . preg_quote($hash, '#') . '/(.+)$#', $withLeadingSlash, $m)) {
                $rel = ltrim($m[1], '/');
                if ($rel !== '') {
                    return route('photobook.asset', ['hash' => $hash, 'path' => $rel], false);
                }
            }

            $needle = '/_cache/' . $hash . '/';
            $pos = strpos($trimmed, $needle);
            if ($pos !== false) {
                $rel = ltrim(substr($trimmed, $pos + strlen($needle)), '/');
                if ($rel !== '') {
                    return route('photobook.asset', ['hash' => $hash, 'path' => $rel], false);
                }
            }
        }

        return null;
    }

    public function index()
    {
        return view('photobook.cover', [
            'defaults' => [
                'folder' => Config::get('photobook.folder'),
                'paper' => Config::get('photobook.paper'),
                'orientation' => Config::get('photobook.orientation', 'landscape'),
                'dpi' => Config::get('photobook.dpi'),
                'title' => Config::get('photobook.cover.title'),
                'subtitle' => Config::get('photobook.cover.subtitle'),
                'show_date' => (bool) Config::get('photobook.cover.show_date', false),
            ],
            'show_form' => true,
        ]);
    }

    public function build(Request $request)
    {
        // Accept both GET and POST; default missing values from config
        $folder = $request->string('folder', Config::get('photobook.folder'))->toString();
        $paper = $request->string('paper', Config::get('photobook.paper'))->toString();
        $orientation = $request->string('orientation', Config::get('photobook.orientation', 'landscape'))->toString();
        $dpi = (int) $request->input('dpi', (int) Config::get('photobook.dpi', 150));

        BuildPhotoBook::dispatch([
            'folder' => $folder,
            'paper' => $paper,
            'orientation' => $orientation,
            'dpi' => $dpi,
            'force_refresh' => (bool) $request->boolean('force_refresh'),
            // Cover overrides (only apply when provided)
            'title' => $request->has('title') ? $request->string('title')->toString() : null,
            'subtitle' => $request->has('subtitle') ? $request->string('subtitle')->toString() : null,
            'cover_show_date' => $request->boolean('show_date', (bool) Config::get('photobook.cover.show_date', false)),
        ]);

        return back()->with('status', 'Build started. Check logs.');
    }

    /**
     * Simple review UI listing pages and allowing feedback / template override.
     */
    public function review(Request $request)
    {
        $folder = $request->string('folder', Config::get('photobook.folder'))->toString();
        $hash = sha1($folder);
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . $hash);
        $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';

        $pages = [];
        if (is_file($pagesPath)) {
            $json = @file_get_contents($pagesPath) ?: '';
            $data = json_decode($json, true);
            $pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];
            // Normalize src -> webSrc using our HTTP asset endpoint (handles Windows or WSL file URIs)
            foreach ($pages as &$p) {
                $p['hash'] = $hash;
                foreach (($p['items'] ?? []) as &$it) {
                    // 1) Prefer relative cache path mapping (stable across hosts)
                    if (!empty($it['rel'])) {
                        // Use relative URL to avoid host issues in various environments
                        $it['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                        continue;
                    }
                    // 2) If builder provided web, use it (may be absolute or relative)
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
        }

        // Overlay templateId from overrides.json so the UI reflects user's latest choices
        try {
            $ovJson = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
            if (is_file($ovJson)) {
                $ov = json_decode((string) @file_get_contents($ovJson), true) ?: [];
                $pagesOv = is_array($ov['pages'] ?? null) ? $ov['pages'] : [];
                foreach ($pages as &$p) {
                    $n = (int) ($p['n'] ?? 0);
                    if ($n < 1)
                        continue;
                    $po = $pagesOv[(string) $n] ?? null;
                    if (is_array($po) && !empty($po['templateId'])) {
                        $p['overrideTemplateId'] = (string) $po['templateId'];
                    }
                }
                unset($p);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Build template options grouped by photo count
        $tpls = LayoutTemplates::all();
        $tplOptions = [];
        foreach ($tpls as $count => $arr) {
            $tplOptions[(string) $count] = array_values(array_unique(array_map(fn($t) => (string) ($t['id'] ?? ''), $arr)));
        }

        return view('photobook.review', [
            'folder' => $folder,
            'pages' => $pages,
            'tplOptions' => $tplOptions,
        ]);
    }

    /** Serve the most recent generated PDF (by mtime) for convenience. */
    public function latestPdf()
    {
        $dir = storage_path('app/pdf-exports');
        if (!is_dir($dir))
            abort(404);
        $files = array_values(array_filter(scandir($dir) ?: [], function ($f) use ($dir) {
            return is_file($dir . DIRECTORY_SEPARATOR . $f) && preg_match('/^book-\\d{8}-\\d{6}\\.pdf$/', $f);
        }));
        if (empty($files))
            abort(404, 'No PDFs found');
        usort($files, function ($a, $b) use ($dir) {
            return (@filemtime($dir . DIRECTORY_SEPARATOR . $b) <=> @filemtime($dir . DIRECTORY_SEPARATOR . $a));
        });
        $latest = $dir . DIRECTORY_SEPARATOR . $files[0];
        return response()->download($latest, $files[0], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /** Serve a specific PDF by name, validating expected pattern. */
    public function pdf(string $name)
    {
        if (!preg_match('/^book-\\d{8}-\\d{6}\\.pdf$/', $name))
            abort(404);
        $path = storage_path('app/pdf-exports/' . $name);
        if (!is_file($path))
            abort(404);
        return response()->download($path, $name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Serve cached assets (renders) from the pdf-exports/_cache directory via HTTP.
     * Path traversal is prevented by realpath checks.
     */
    public function asset(Request $request, string $hash, string $path)
    {
        $base = storage_path('app/pdf-exports/_cache/' . $hash);
        $full = realpath($base . DIRECTORY_SEPARATOR . $path);
        $baseReal = realpath($base);
        if (!$full || !$baseReal || strncmp($full, $baseReal, strlen($baseReal)) !== 0 || !is_file($full)) {
            abort(404);
        }
        // Let Laravel infer content-type
        return response()->file($full);
    }

    public function pagesJson(Request $request)
    {
        $folder = $request->string('folder', Config::get('photobook.folder'))->toString();
        $hash = sha1($folder);
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . $hash);
        $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($pagesPath))
            return response()->json(['ok' => false, 'error' => 'pages.json not found'], 404);
        $json = @file_get_contents($pagesPath) ?: '';
        $data = json_decode($json, true);
        if (!is_array($data))
            return response()->json(['ok' => false, 'error' => 'invalid json'], 422);

        // Inject webSrc per item when possible
        try {
            foreach (($data['pages'] ?? []) as &$p) {
                foreach (($p['items'] ?? []) as &$it) {
                    if (!empty($it['rel'])) { // relative cache path preferred
                        $it['webSrc'] = route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                        continue;
                    }
                    if (!empty($it['web'])) { // builder provided web URL
                        $normalized = $this->normalizeAssetUrl($hash, $it['web']);
                        if ($normalized) {
                            $it['webSrc'] = $normalized;
                        } else {
                            $it['webSrc'] = (string) $it['web'];
                        }
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
            // ignore inject errors; fall back to raw src
        }

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * GET /photobook/albums
     * List available cached albums (folders) that have a pages.json in the cache root.
     */
    public function albums(Request $request)
    {
        $root = storage_path('app/pdf-exports/_cache');
        $out = [];
        if (is_dir($root)) {
            $dirs = @scandir($root) ?: [];
            foreach ($dirs as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $dir = $root . DIRECTORY_SEPARATOR . $d;
                if (!is_dir($dir))
                    continue;
                $pages = $dir . DIRECTORY_SEPARATOR . 'pages.json';
                if (!is_file($pages))
                    continue;
                $json = @file_get_contents($pages) ?: '';
                $data = json_decode($json, true);
                if (!is_array($data))
                    continue;
                $folder = (string) ($data['folder'] ?? '');
                $count = (int) ($data['count'] ?? 0);
                $createdAt = (string) ($data['created_at'] ?? date(DATE_ATOM, @filemtime($pages) ?: time()));
                $out[] = [
                    'hash' => $d,
                    'folder' => $folder,
                    'count' => $count,
                    'created_at' => $createdAt,
                ];
            }
        }
        // Sort by created_at desc
        usort($out, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return response()->json(['ok' => true, 'albums' => $out]);
    }

    /**
     * GET /photobook/candidates?folder=&page=
     * For now return a simple set of candidate photos: all photos from this page and adjacent pages as a starting point.
     */
    public function candidates(Request $request)
    {
        $folder = $request->string('folder', Config::get('photobook.folder'))->toString();
        $pageNo = (int) $request->query('page', 0);
        if ($pageNo < 1)
            return response()->json(['ok' => false, 'error' => 'invalid page'], 422);

        $hash = sha1($folder);
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . $hash);
        $pagesPath = $cacheRoot . DIRECTORY_SEPARATOR . 'pages.json';
        if (!is_file($pagesPath))
            return response()->json(['ok' => false, 'error' => 'pages.json not found'], 404);
        $json = @file_get_contents($pagesPath) ?: '';
        $data = json_decode($json, true);
        $pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];

        $photos = [];
        foreach ($pages as $p) {
            $n = (int) ($p['n'] ?? 0);
            if ($n >= $pageNo - 1 && $n <= $pageNo + 1) {
                foreach (($p['items'] ?? []) as $it) {
                    $ph = $it['photo'] ?? null;
                    if (is_array($ph) && !empty($ph['path'])) {
                        // Build a web-friendly URL
                        $web = null;
                        if (!empty($it['rel'])) {
                            $web = route('photobook.asset', ['hash' => $hash, 'path' => $it['rel']], false);
                        } elseif (!empty($it['web'])) {
                            $normalized = $this->normalizeAssetUrl($hash, $it['web']);
                            $web = $normalized ?? (string) $it['web'];
                        } else {
                            $normalizedSrc = $this->normalizeAssetUrl($hash, $it['src'] ?? null);
                            if ($normalizedSrc) {
                                $web = $normalizedSrc;
                            }
                        }
                        $w = isset($ph['width']) ? (int) $ph['width'] : null;
                        $h = isset($ph['height']) ? (int) $ph['height'] : null;
                        $orientation = null;
                        if ($w && $h) {
                            if ($w > $h)
                                $orientation = 'landscape';
                            elseif ($w < $h)
                                $orientation = 'portrait';
                            else
                                $orientation = 'square';
                        }
                        $photos[$ph['path']] = [
                            'path' => $ph['path'],
                            'filename' => $ph['filename'] ?? basename($ph['path']),
                            'src' => $web,
                            'width' => $w,
                            'height' => $h,
                            'ratio' => $ph['ratio'] ?? null,
                            'takenAt' => $ph['takenAt'] ?? null,
                            'orientation' => $orientation,
                        ];
                    }
                }
            }
        }
        return response()->json(['ok' => true, 'candidates' => array_values($photos)]);
    }
}