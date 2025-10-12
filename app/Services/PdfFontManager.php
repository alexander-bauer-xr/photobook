<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class PdfFontManager
{
    /**
     * Ensure the configured font directory exists and is writable.
     */
    public static function prepareFontDirectory(): string
    {
        $dir = (string) config('photobook.pdf.font_dir', storage_path('app/fonts'));
        if ($dir === '') {
            $dir = storage_path('app/fonts');
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Register configured font families with Dompdf and return a summary of successful registrations.
     *
     * @return array<string, array<string, string>>
     */
    public static function registerFonts(Dompdf $dompdf): array
    {
        $registered = [];
        $families = self::configuredFonts();
        if (empty($families)) {
            return $registered;
        }

        $metrics = $dompdf->getFontMetrics();
        foreach ($families as $family => $variants) {
            foreach ($variants as $variant => $meta) {
                $style = Arr::only($meta, ['style', 'weight']);
                $style['family'] = $family;
                try {
                    $metrics->registerFont($style, $meta['path']);
                    $registered[$family][$variant] = $meta['path'];
                } catch (\Throwable $e) {
                    Log::warning('PdfFontManager: failed to register font variant', [
                        'family' => $family,
                        'variant' => $variant,
                        'path' => $meta['path'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (!empty($registered)) {
            Log::info('PdfFontManager: registered custom PDF fonts', ['families' => array_keys($registered)]);
        }

        return $registered;
    }

    /**
     * Build @font-face payload for inline CSS.
     *
     * @return array<string, array<int, array<string, string|int>>>
     */
    public static function fontFacesForCss(): array
    {
        $faces = [];
        foreach (self::configuredFonts() as $family => $variants) {
            foreach ($variants as $variant => $meta) {
                $faces[$family][] = [
                    'style' => $meta['style'],
                    'weight' => $meta['weight'],
                    'src' => self::pathToFileUrl($meta['path']),
                    'format' => self::guessFontFormat($meta['path']),
                ];
            }
        }
        return $faces;
    }

    /**
     * Resolve configured fonts into absolute paths and metadata.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function configuredFonts(): array
    {
        $fontDir = self::prepareFontDirectory();
        $families = config('photobook.pdf.font_families', []);
        if (!is_array($families) || empty($families)) {
            return [];
        }

        $resolved = [];
        foreach ($families as $family => $variants) {
            if (!is_array($variants)) {
                continue;
            }
            foreach ($variants as $variant => $path) {
                $styleMeta = self::variantMeta((string) $variant);
                $resolvedPath = self::resolveFontPath($path, $fontDir);
                if (!$resolvedPath) {
                    Log::warning('PdfFontManager: font file missing or unreadable', [
                        'family' => $family,
                        'variant' => $variant,
                        'configured' => $path,
                    ]);
                    continue;
                }
                $resolved[$family][$variant] = [
                    'path' => $resolvedPath,
                    'style' => $styleMeta['style'],
                    'weight' => $styleMeta['weight'],
                ];
            }
        }

        return $resolved;
    }

    private static function resolveFontPath($path, string $fontDir): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }
        if (self::isAbsolutePath($path)) {
            $full = $path;
        } else {
            $full = rtrim($fontDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        }
        if (!is_readable($full)) {
            return null;
        }
        return realpath($full) ?: $full;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:\\\\?/', $path) === 1;
    }

    private static function pathToFileUrl(string $path): string
    {
        return 'file:///' . str_replace('\\', '/', $path);
    }

    private static function guessFontFormat(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'otf' => 'opentype',
            'woff' => 'woff',
            'woff2' => 'woff2',
            'ttc' => 'truetype-collection',
            default => 'truetype',
        };
    }

    /**
     * Map a variant key (e.g. "bold", "italic", "700") into CSS style + weight.
     */
    private static function variantMeta(string $variant): array
    {
        $key = strtolower(str_replace(['-', ' '], '_', $variant));
        return match ($key) {
            'bold' => ['style' => 'normal', 'weight' => 700],
            'italic', 'oblique' => ['style' => 'italic', 'weight' => 400],
            'bold_italic', 'italic_bold', 'bolditalic' => ['style' => 'italic', 'weight' => 700],
            'medium' => ['style' => 'normal', 'weight' => 500],
            'semibold', 'semi_bold', 'demibold', 'demi_bold' => ['style' => 'normal', 'weight' => 600],
            'light' => ['style' => 'normal', 'weight' => 300],
            'thin' => ['style' => 'normal', 'weight' => 200],
            'black' => ['style' => 'normal', 'weight' => 900],
            default => self::numericVariantMeta($key),
        };
    }

    private static function numericVariantMeta(string $key): array
    {
        if (preg_match('/^(\d{3})(?:_(italic|oblique))?$/', $key, $matches) === 1) {
            $weight = (int) $matches[1];
            $style = isset($matches[2]) ? 'italic' : 'normal';
            return ['style' => $style, 'weight' => $weight];
        }
        return ['style' => 'normal', 'weight' => 400];
    }
}
