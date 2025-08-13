<?php

namespace App\Http\Controllers;

use App\Jobs\BuildPhotoBook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Copilot prompt:
 * Build a small controller with:
 * - index(): show a minimal form (folder, paper, dpi) + "Build" button
 * - build(Request): dispatch BuildPhotoBook job and redirect back with flash
 */
class PhotoBookController extends Controller
{
    public function index()
    {
        return view('photobook.cover', [
            'defaults' => [
                'folder' => Config::get('photobook.folder'),
                'paper' => Config::get('photobook.paper'),
                'orientation' => Config::get('photobook.orientation', 'landscape'),
                'dpi' => Config::get('photobook.dpi'),
            ],
            'show_form' => true,
        ]);
    }

    public function build(Request $request)
    {
        BuildPhotoBook::dispatch([
            'folder' => $request->string('folder')->toString(),
            'paper'  => $request->string('paper')->toString(),
            'orientation' => $request->string('orientation', Config::get('photobook.orientation', 'landscape'))->toString(),
            'dpi'    => (int) $request->input('dpi', 150),
            'force_refresh' => (bool) $request->boolean('force_refresh'),
        ]);

        return back()->with('status', 'Build started. Check logs.');
    }
}