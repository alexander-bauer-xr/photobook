<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;


/**
 * Copilot prompt:
 * Render HTML to PDF using Dompdf.
 * - renderTo(string $fullPath, string $html, string $paper='a4', string $orientation='portrait', int $dpi=150): void
 * - Save to pdf_exports disk if path is relative
 * - Support custom page sizes for print with bleed
 */
class PdfRenderer
{
    /**
     * Standard paper sizes in mm (width x height in portrait)
     */
    private const PAPER_SIZES_MM = [
        'a4' => [210, 297],
        'a3' => [297, 420],
        'letter' => [215.9, 279.4],
    ];

    /**
     * Render HTML to PDF with optional print-ready settings
     */
    public function renderTo(
        string $fullPath,
        string $html,
        string $paper = 'a4',
        string $orientation = 'portrait',
        int $dpi = 150,
        array $printOptions = []
    ): void {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $t0 = microtime(true);

        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('dpi', $dpi);
        // Reduce size: subset fonts and respect image DPI
        $opts->set('isFontSubsettingEnabled', true);
        $opts->set('enable_html5_parser', true);
        $fontDir = PdfFontManager::prepareFontDirectory();
        $opts->setFontDir($fontDir);
        $opts->setFontCache($fontDir);
        // Use DejaVu Sans as fallback - Dompdf has wide glyph coverage
        $opts->set('defaultFont', 'DejaVu Sans');
        // Restrict Dompdf to storage/app so file:// paths are accessible
        $opts->setChroot(storage_path('app'));

        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html);

        // Calculate page size with bleed if print mode enabled
        $paperSize = $this->calculatePaperSize($paper, $orientation, $printOptions);
        $dompdf->setPaper($paperSize, $orientation);

        Log::info('PDF: starting render', [
            'paper' => $paper,
            'orientation' => $orientation,
            'dpi' => $dpi,
            'html_kb' => round(strlen($html) / 1024, 1),
            'print_mode' => !empty($printOptions['enabled']),
            'bleed_mm' => $printOptions['bleed_mm'] ?? 0,
        ]);

        $dompdf->render();

        Log::info('PDF: render finished', [
            'secs' => round(microtime(true) - $t0, 2),
            'mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1)
        ]);

        if (!str_starts_with($fullPath, '/')) {
            $disk = Storage::disk('pdf_exports');
            $disk->put($fullPath, $dompdf->output());
        } else {
            file_put_contents($fullPath, $dompdf->output());
        }
    }

    /**
     * Calculate paper size array for Dompdf, including bleed if print mode
     *
     * @return array [0, 0, width_pt, height_pt] for Dompdf
     */
    private function calculatePaperSize(string $paper, string $orientation, array $printOptions): array|string
    {
        $bleedMm = (float) ($printOptions['bleed_mm'] ?? 0);
        $printEnabled = !empty($printOptions['enabled']);

        // If no bleed needed, return standard paper name
        if (!$printEnabled || $bleedMm <= 0) {
            return $paper;
        }

        // Get base paper size in mm
        $baseSizeMm = self::PAPER_SIZES_MM[strtolower($paper)] ?? self::PAPER_SIZES_MM['a4'];

        // Add bleed to all edges (2x bleed for total width/height)
        $widthMm = $baseSizeMm[0] + ($bleedMm * 2);
        $heightMm = $baseSizeMm[1] + ($bleedMm * 2);

        // Swap for landscape
        if ($orientation === 'landscape') {
            [$widthMm, $heightMm] = [$heightMm, $widthMm];
        }

        // Convert mm to points (1 inch = 72pt, 1 inch = 25.4mm)
        $ptPerMm = 72 / 25.4;
        $widthPt = $widthMm * $ptPerMm;
        $heightPt = $heightMm * $ptPerMm;

        // Return Dompdf custom size format: [0, 0, width, height]
        return [0, 0, $widthPt, $heightPt];
    }
}