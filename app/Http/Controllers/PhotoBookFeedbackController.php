<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class PhotoBookFeedbackController extends Controller
{
    /**
     * POST /photobook/feedback
     * Body: { folder?: string, page: int, action: string, reason?: string }
     * Appends a JSON line to feedback.log in the cache root.
     */
    public function submit(Request $request)
    {
        $folder = (string) $request->input('folder', Config::get('photobook.folder'));
        $page = (int) $request->input('page', 0);
        $action = (string) $request->input('action', '');
        $reason = (string) $request->input('reason', '');

        if ($page < 1 || $action === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid page/action'], 422);
        }

        $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
        if (!is_dir($cacheRoot))
            @mkdir($cacheRoot, 0775, true);
        $file = $cacheRoot . DIRECTORY_SEPARATOR . 'feedback.log';

        $entry = [
            'ts' => date(DATE_ATOM),
            'folder' => $folder,
            'page' => $page,
            'action' => $action,
            'reason' => ($reason !== '') ? $reason : null,
            'ip' => $request->ip(),
            'ua' => (string) $request->header('User-Agent'),
        ];
        $ok = @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        if ($ok === false) {
            \Log::error('Photobook feedback save failed', ['file' => $file, 'entry' => $entry]);
            return response()->json(['ok' => false, 'error' => 'Write failed: ' . $file], 500);
        }
        \Log::info('Photobook feedback saved', ['file' => $file, 'page' => $page, 'action' => $action]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /photobook/override
     * Body: { folder?: string, page: int, templateId: string }
     * Appends a JSON line to overrides.log in the cache root.
     */
    public function overrideTemplate(Request $request)
    {
        $folder = (string) $request->input('folder', Config::get('photobook.folder'));
        $page = (int) $request->input('page', 0);
        $templateId = (string) $request->input('templateId', '');

        if ($page < 1 || $templateId === '') {
            return response()->json(['ok' => false, 'error' => 'Invalid page/templateId'], 422);
        }

        $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
        if (!is_dir($cacheRoot))
            @mkdir($cacheRoot, 0775, true);
        $file = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.log';

        $entry = [
            'ts' => date(DATE_ATOM),
            'folder' => $folder,
            'page' => $page,
            'templateId' => $templateId,
            'ip' => $request->ip(),
            'ua' => (string) $request->header('User-Agent'),
        ];
        $ok = @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        if ($ok === false) {
            \Log::error('Photobook override save failed', ['file' => $file, 'entry' => $entry]);
            return response()->json(['ok' => false, 'error' => 'Write failed: ' . $file], 500);
        }
        \Log::info('Photobook override saved', ['file' => $file, 'page' => $page, 'templateId' => $templateId]);

        return response()->json(['ok' => true]);
    }

    public function listOverrides(Request $request)
    {
        $folder = (string) $request->input('folder', Config::get('photobook.folder'));
        $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
        $file = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
        $data = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: ['pages' => []]) : ['pages' => []];
        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function savePage(Request $request)
    {
        $folder = (string) $request->input('folder', Config::get('photobook.folder'));
        $page = (int) $request->input('page');
    $order = (array) $request->input('order', []);   // legacy: array of photo paths
    $slots = (array) $request->input('slots', []);   // legacy: slotIndex => { pos:[fx,fy], zoom:number, path?:string }
    $items = $request->input('items');               // new: full items array with slotIndex/objectPosition/scale/rotate/photo/src
    $templateId = $request->input('templateId');     // optional template override

        if ($page < 1)
            return response()->json(['ok' => false, 'error' => 'Invalid page'], 422);

        $cacheRoot = storage_path('app/pdf-exports/_cache/' . sha1($folder));
        if (!is_dir($cacheRoot))
            @mkdir($cacheRoot, 0775, true);
        $file = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.json';
        $data = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: ['pages' => []]) : ['pages' => []];

        $entry = $data['pages'][(string) $page] ?? [];
        // Keep legacy fields for backward compatibility
        if (!empty($order)) $entry['order'] = $order;
        if (!empty($slots)) $entry['slots'] = $slots;
        // New format: items array
        if (is_array($items)) {
            $norm = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $out = [
                    'slotIndex' => (int) ($it['slotIndex'] ?? 0),
                ];
                foreach (['crop','objectPosition','src'] as $k) if (isset($it[$k])) $out[$k] = $it[$k];
                if (isset($it['scale'])) $out['scale'] = (float) $it['scale'];
                if (isset($it['rotate'])) $out['rotate'] = (float) $it['rotate'];
                if (!empty($it['photo']) && is_array($it['photo'])) {
                    $ph = $it['photo'];
                    $out['photo'] = [
                        'path' => (string) ($ph['path'] ?? ''),
                        'filename' => (string) ($ph['filename'] ?? ''),
                        'width' => $ph['width'] ?? null,
                        'height' => $ph['height'] ?? null,
                        'ratio' => $ph['ratio'] ?? null,
                        'takenAt' => $ph['takenAt'] ?? null,
                    ];
                }
                $norm[] = $out;
            }
            if (!empty($norm)) $entry['items'] = $norm;
        }
        if (is_string($templateId) && $templateId !== '') $entry['templateId'] = $templateId;
        $data['pages'][(string) $page] = $entry;

        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return response()->json(['ok' => true]);
    }
}
