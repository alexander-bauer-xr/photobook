{{-- Copilot prompt:
Create the main layout for the PDF:
- @include a simple cover page
- Loop over $pages and include 'photobook/page-{{template}}.blade.php'
- Use minimal CSS for print, margins from config
- Support print-ready mode with bleed, crop marks, and binding margins
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ isset($options['title']) && trim((string)$options['title']) !== '' ? $options['title'] : config('photobook.cover.title') }}</title>
@php
    // === PRINT SETTINGS ===
    $printConfig = config('photobook.print', []);
    $printMode = !empty($printConfig['enabled']) || !empty($options['print_mode']);
    $bleedMm = $printMode ? (float) ($printConfig['bleed_mm'] ?? 3.0) : 0;
    $showCropMarks = $printMode && !empty($printConfig['crop_marks']);
    $spineMarginMm = $printMode ? (float) ($printConfig['spine_margin_mm'] ?? 10.0) : 0;
    $safeZoneMm = $printMode ? (float) ($printConfig['safe_zone_mm'] ?? 5.0) : 0;

    // === STANDARD SETTINGS ===
    $gapMm = (float) config('photobook.page_gap_mm', 2.5);
    $gapHalfMm = $gapMm / 2;
    $frameMm = (float) config('photobook.page_frame_mm', 6);
    $marginMm = (int) config('photobook.margin_mm', 0);

    // Format helper
    $formatMm = static function (float $value): string {
        $formatted = number_format($value, 6, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    };

    $gapMmStr = $formatMm($gapMm);
    $gapHalfMmStr = $formatMm($gapHalfMm);
    $frameMmStr = $formatMm($frameMm);
    $bleedMmStr = $formatMm($bleedMm);
    $spineMarginMmStr = $formatMm($spineMarginMm);
    $safeZoneMmStr = $formatMm($safeZoneMm);

    // Paper dimensions for crop mark positioning
    $paper = $options['paper'] ?? config('photobook.paper', 'a4');
    $orientation = $options['orientation'] ?? config('photobook.orientation', 'landscape');
    $paperSizes = ['a4' => [210, 297], 'a3' => [297, 420], 'letter' => [215.9, 279.4]];
    $baseSize = $paperSizes[strtolower($paper)] ?? $paperSizes['a4'];
    if ($orientation === 'landscape') {
        $baseSize = [$baseSize[1], $baseSize[0]];
    }
    $pageWidthMm = $baseSize[0];
    $pageHeightMm = $baseSize[1];

    // Total page with bleed
    $totalWidthMm = $pageWidthMm + ($bleedMm * 2);
    $totalHeightMm = $pageHeightMm + ($bleedMm * 2);

    // Crop mark settings
    $cropMarkLength = 5; // mm
    $cropMarkOffset = 2; // mm from trim edge

    // === FONT SETUP ===
    $pdfFontFaces = isset($fontFaces) && is_array($fontFaces) ? $fontFaces : [];
    $pdfFontFamily = isset($fontFamily) && is_string($fontFamily) && trim($fontFamily) !== ''
        ? trim($fontFamily)
        : 'Inter';
    $pdfFontCssValue = json_encode($pdfFontFamily, JSON_UNESCAPED_UNICODE);

    $fontFaceCss = '';
    if (!empty($pdfFontFaces)) {
        foreach ($pdfFontFaces as $familyName => $variants) {
            if (!is_array($variants) || empty($variants)) {
                continue;
            }
            $familyCss = json_encode((string) $familyName, JSON_UNESCAPED_UNICODE);
            foreach ($variants as $variantMeta) {
                if (!is_array($variantMeta)) {
                    continue;
                }
                $style = isset($variantMeta['style']) && in_array($variantMeta['style'], ['normal', 'italic', 'oblique'], true)
                    ? $variantMeta['style']
                    : 'normal';
                $weight = isset($variantMeta['weight']) && is_numeric($variantMeta['weight'])
                    ? (int) $variantMeta['weight']
                    : 400;
                $src = isset($variantMeta['src']) ? (string) $variantMeta['src'] : '';
                if ($src === '') {
                    continue;
                }
                $format = isset($variantMeta['format']) ? (string) $variantMeta['format'] : 'truetype';
                $srcCss = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $formatCss = json_encode($format, JSON_UNESCAPED_UNICODE);
                $fontFaceCss .= "@font-face {\n";
                $fontFaceCss .= "  font-family: {$familyCss};\n";
                $fontFaceCss .= "  font-style: {$style};\n";
                $fontFaceCss .= "  font-weight: {$weight};\n";
                $fontFaceCss .= "  src: url({$srcCss}) format({$formatCss});\n";
                $fontFaceCss .= "}\n";
            }
        }
    }

    // === BUILD CSS ===
    $printModeComment = $printMode ? "/* PRINT-READY MODE: bleed={$bleedMmStr}mm, spine={$spineMarginMmStr}mm */" : '';
    $pdfStyles = <<<CSS
{$printModeComment}
@page { margin: {$marginMm}mm; }

/* CSS variables for consistent units */
:root {
    --frame-mm: {$frameMmStr}mm;
    --gap-mm: {$gapMmStr}mm;
    --eps-mm: 0.15mm;
    --bleed-mm: {$bleedMmStr}mm;
    --spine-mm: {$spineMarginMmStr}mm;
    --safe-zone-mm: {$safeZoneMmStr}mm;
    --page-width: {$formatMm($pageWidthMm)}mm;
    --page-height: {$formatMm($pageHeightMm)}mm;
}

body,
.page {
    font-family: {$pdfFontCssValue}, "Helvetica Neue", sans-serif;
}

.page {
    position: relative;
    page-break-after: always;
    /* In print mode, page includes bleed area */
    width: calc(var(--page-width) + var(--bleed-mm) * 2);
    height: calc(var(--page-height) + var(--bleed-mm) * 2);
}

/* Content area: positioned inside bleed */
.page-inner {
    position: absolute;
    top: calc(var(--bleed-mm) + var(--frame-mm));
    left: calc(var(--bleed-mm) + var(--frame-mm));
    right: calc(var(--bleed-mm) + var(--frame-mm) + var(--eps-mm));
    bottom: calc(var(--bleed-mm) + var(--frame-mm) + var(--eps-mm));
    background: #fff;
    overflow: hidden;
}

/* For pages needing spine margin (even/odd pages in spreads) */
.page-inner.spine-left {
    left: calc(var(--bleed-mm) + var(--frame-mm) + var(--spine-mm));
}
.page-inner.spine-right {
    right: calc(var(--bleed-mm) + var(--frame-mm) + var(--spine-mm) + var(--eps-mm));
}

.slot {
    position: absolute;
    overflow: hidden;
    box-sizing: border-box;
    background: #fff;
}

.slot-inner {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: #fff;
}

.slot-inner img {
    position: absolute;
    left: 50%;
    top: 50%;
    max-width: none;
    max-height: none;
    transform-origin: center center;
    display: block;
}

.slot-inner-legacy {
    width: 100%;
    height: 100%;
    background-repeat: no-repeat;
    background-origin: content-box;
}

.caption {
    position: absolute;
    left: {$gapHalfMmStr}mm;
    right: {$gapHalfMmStr}mm;
    bottom: {$gapHalfMmStr}mm;
    font-size: 10pt;
    line-height: 1.25;
    padding: 1.4mm 1.8mm;
    background: rgba(255, 255, 255, 0.88);
    color: #1f2937;
    border-radius: 1.2mm;
    text-align: center;
    word-break: break-word;
    pointer-events: none;
    box-shadow: 0 0.6mm 2.4mm rgba(0, 0, 0, 0.12);
    z-index: 3;
}

/* === CROP MARKS === */
.crop-marks {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 9999;
}

.crop-mark {
    position: absolute;
    background: #000;
}

/* Horizontal marks */
.crop-mark.h {
    width: {$cropMarkLength}mm;
    height: 0.25mm;
}

/* Vertical marks */
.crop-mark.v {
    width: 0.25mm;
    height: {$cropMarkLength}mm;
}

/* Corner positions (outside the bleed area) */
.crop-mark.top-left-h { top: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); left: 0; }
.crop-mark.top-left-v { top: 0; left: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); }

.crop-mark.top-right-h { top: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); right: 0; }
.crop-mark.top-right-v { top: 0; right: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); }

.crop-mark.bottom-left-h { bottom: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); left: 0; }
.crop-mark.bottom-left-v { bottom: 0; left: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); }

.crop-mark.bottom-right-h { bottom: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); right: 0; }
.crop-mark.bottom-right-v { bottom: 0; right: calc(var(--bleed-mm) - {$cropMarkOffset}mm - 0.125mm); }

/* === BLEED BACKGROUND (ensures images extend to bleed) === */
.bleed-bg {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: #fff;
    z-index: -1;
}

/* === PRINT INFO (visible in bleed area for prepress) === */
.print-info {
    position: absolute;
    bottom: 1mm;
    left: calc(var(--bleed-mm) + 2mm);
    font-size: 6pt;
    color: #999;
    z-index: 9998;
}
CSS;

    if ($fontFaceCss !== '') {
        $pdfStyles = $fontFaceCss . "\n" . $pdfStyles;
    }

    // Track page number for spine margin alternation
    $pageIndex = 0;
@endphp
<style>
{!! $pdfStyles !!}
</style>
</head>
<body>
{{-- Cover page --}}
@include('photobook.cover', [
    'options' => $options,
    'fontFamily' => $fontFamily,
    'printMode' => $printMode,
    'bleedMm' => $bleedMmStr,
    'showCropMarks' => $showCropMarks,
])

{{-- Content pages --}}
@foreach($pages as $page)
    @php
        $pageIndex++;
        // Determine spine side: odd pages have spine on right, even on left
        // (assuming reader spread: page 1 on right, page 2 on left, etc.)
        $spineClass = '';
        if ($printMode && $spineMarginMm > 0) {
            $spineClass = ($pageIndex % 2 === 1) ? 'spine-right' : 'spine-left';
        }
    @endphp

    @if(($page['template'] ?? '') === 'generic')
        @include('photobook.generic', [
            'slots' => $page['slots'],
            'items' => $page['items'],
            'asset_url' => $asset_url,
            'gapMm' => $gapMmStr,
            'gapHalfMm' => $gapHalfMmStr,
            'printMode' => $printMode,
            'bleedMm' => $bleedMmStr,
            'showCropMarks' => $showCropMarks,
            'spineClass' => $spineClass,
            'pageNumber' => $pageIndex,
        ])
    @else
        @include('photobook.page-' . $page['template'], [
            'photos' => $page['photos'],
            'gapMm' => $gapMmStr,
            'gapHalfMm' => $gapHalfMmStr,
            'printMode' => $printMode ?? false,
            'bleedMm' => $bleedMmStr ?? '0',
            'showCropMarks' => $showCropMarks ?? false,
            'spineClass' => $spineClass ?? '',
            'pageNumber' => $pageIndex,
        ])
    @endif
@endforeach

</body>
</html>
