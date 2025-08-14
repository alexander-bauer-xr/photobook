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
        if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0775, true);
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
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

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
        if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0775, true);
        $file = $cacheRoot . DIRECTORY_SEPARATOR . 'overrides.log';

        $entry = [
            'ts' => date(DATE_ATOM),
            'folder' => $folder,
            'page' => $page,
            'templateId' => $templateId,
            'ip' => $request->ip(),
            'ua' => (string) $request->header('User-Agent'),
        ];
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        return response()->json(['ok' => true]);
    }
}
