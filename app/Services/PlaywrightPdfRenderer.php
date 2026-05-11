<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PlaywrightPdfRenderer
{
    /**
     * Render a photobook to PDF via Playwright.
     *
     * @param  string $previewUrl  Full URL to the print-ready preview page
     * @param  string $outputPath  Absolute path where the PDF should be written
     * @param  array  $options     Optional: width (mm), height (mm), timeout (s)
     * @throws \RuntimeException   On Playwright failure
     */
    public function render(string $previewUrl, string $outputPath, array $options = []): void
    {
        $widthMm  = $options['width']   ?? 210;
        $heightMm = $options['height']  ?? 297;
        $timeout  = $options['timeout'] ?? 180;

        $renderScript = base_path('playwright/render.js');

        if (!is_file($renderScript)) {
            throw new \RuntimeException("Playwright render script not found: {$renderScript}");
        }

        $cmd = [
            'node',
            $renderScript,
            '--url',    $previewUrl,
            '--output', $outputPath,
            '--width',  (string) $widthMm,
            '--height', (string) $heightMm,
        ];

        Log::info('PlaywrightPdfRenderer: starting', [
            'url'    => $previewUrl,
            'output' => $outputPath,
            'size'   => "{$widthMm}×{$heightMm}mm",
        ]);

        $result = Process::timeout($timeout)->run($cmd);

        if (!$result->successful()) {
            Log::error('PlaywrightPdfRenderer: failed', [
                'exitCode' => $result->exitCode(),
                'stderr'   => $result->errorOutput(),
                'stdout'   => $result->output(),
            ]);
            throw new \RuntimeException(
                'Playwright PDF render failed (exit ' . $result->exitCode() . '): ' . $result->errorOutput()
            );
        }

        if (!is_file($outputPath)) {
            throw new \RuntimeException("Playwright completed but output file not found: {$outputPath}");
        }

        Log::info('PlaywrightPdfRenderer: done', [
            'output' => $outputPath,
            'size_kb' => round(filesize($outputPath) / 1024, 1),
        ]);
    }

    /**
     * Convenience: build the preview URL for a given folder hash.
     */
    public static function previewUrl(string $hash, array $query = []): string
    {
        $q = array_merge(['print' => '1'], $query);
        return url('/photobook/preview/' . $hash) . '?' . http_build_query($q);
    }
}
