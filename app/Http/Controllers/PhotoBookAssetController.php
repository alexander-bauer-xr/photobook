<?php
// app/Http/Controllers/PhotoBookAssetController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoBookAssetController extends Controller
{
    public function show(Request $request)
    {
        $p = (string) $request->query('p', '');
        if ($p === '') abort(404);

        // Resolve real path and lock it down to storage/app/pdf-exports/_cache
        $real = realpath($p);
        $root = realpath(storage_path('app/pdf-exports/_cache'));
        if (!$real || !$root || strncmp($real, $root, strlen($root)) !== 0) {
            abort(403, 'Forbidden');
        }

        $mime = @mime_content_type($real) ?: 'image/jpeg';
        return new StreamedResponse(function () use ($real) {
            $fp = @fopen($real, 'rb');
            if ($fp) {
                fpassthru($fp);
                fclose($fp);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
