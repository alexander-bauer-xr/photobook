<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Copilot prompt:
 * Render HTML to PDF using Dompdf.
 * - renderTo(string $fullPath, string $html, string $paper='a4', string $orientation='portrait', int $dpi=150): void
 * - Save to pdf_exports disk if path is relative
 */
class PdfRenderer
{
    public function renderTo(string $fullPath, string $html, string $paper='a4', string $orientation='portrait', int $dpi=150): void
    {
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $t0 = microtime(true);
    $opts = new Options();
    $opts->set('isRemoteEnabled', true);
    $opts->set('dpi', $dpi);
    // Reduce size: subset fonts and respect image DPI
    $opts->set('isFontSubsettingEnabled', true);
    $opts->set('enable_html5_parser', true);
    // Restrict Dompdf to storage/app so file:// paths are accessible
    $opts->setChroot(storage_path('app'));

        $dompdf = new Dompdf($opts);
    $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
    \Log::info('PDF: starting render', ['paper' => $paper, 'orientation' => $orientation, 'dpi' => $dpi, 'html_kb' => round(strlen($html)/1024,1)]);
    $dompdf->render();
    \Log::info('PDF: render finished', ['secs' => round(microtime(true) - $t0, 2), 'mem_mb' => round(memory_get_peak_usage(true)/1048576,1)]);

        if (!str_starts_with($fullPath, '/')) {
            $disk = Storage::disk('pdf_exports');
            $disk->put($fullPath, $dompdf->output());
        } else {
            file_put_contents($fullPath, $dompdf->output());
        }
    }
}