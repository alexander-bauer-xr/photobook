{{-- Copilot prompt:
Create the main layout for the PDF:
- @include a simple cover page
- Loop over $pages and include 'photobook/page-{{template}}.blade.php'
- Use minimal CSS for print, margins from config
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ isset($options['title']) && trim((string)$options['title']) !== '' ? $options['title'] : config('photobook.cover.title') }}</title>
@php
        $gapMm = (float) config('photobook.page_gap_mm', 2.5);
        $gapHalfMm = $gapMm / 2;
        $frameMm = (float) config('photobook.page_frame_mm', 6);
        $marginMm = (int) config('photobook.margin_mm', 0);
    $formatMm = static function (float $value): string {
        $formatted = number_format($value, 6, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    };
    $gapMmStr = $formatMm($gapMm);
    $gapHalfMmStr = $formatMm($gapHalfMm);
        $frameMmStr = $formatMm($frameMm);
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
        $pdfStyles = <<<CSS
@page { margin: {$marginMm}mm; }
/* CSS variables for consistent units */
:root {
    --frame-mm: {$frameMmStr}mm;
    --gap-mm:   {$gapMmStr}mm;
    --eps-mm:   0.15mm;
}
body,
.page {
    font-family: {$pdfFontCssValue}, "Helvetica Neue", sans-serif;
}
.page { position: relative; page-break-after: always; }
.page-inner {
    position: absolute;
    top: var(--frame-mm);
    left: var(--frame-mm);
    right: calc(var(--frame-mm) + var(--eps-mm));
    bottom: calc(var(--frame-mm) + var(--eps-mm));
    background:#fff;
    overflow:hidden;
}
.slot { position:absolute; overflow:hidden; box-sizing:border-box; background:#fff; }
.slot-inner { position:relative; width:100%; height:100%; overflow:hidden; background:#fff; }
.slot-inner img { position:absolute; left:50%; top:50%; max-width:none; max-height:none; transform-origin:center center; display:block; }
.slot-inner-legacy { width:100%; height:100%; background-repeat:no-repeat; background-origin: content-box; }
.caption {
    position:absolute;
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
CSS;
            if ($fontFaceCss !== '') {
                    $pdfStyles = $fontFaceCss . "\n" . $pdfStyles;
            }
@endphp
<style>
{!! $pdfStyles !!}
</style>
</head>
<body>
@include('photobook.cover', ['options' => $options, 'fontFamily' => $fontFamily])

@foreach($pages as $page)
    @if(($page['template'] ?? '') === 'generic')
        @include('photobook.generic', [
            'slots' => $page['slots'],
            'items' => $page['items'],
            'asset_url' => $asset_url,
            'gapMm' => $gapMmStr,
            'gapHalfMm' => $gapHalfMmStr,
        ])
    @else
        @include('photobook.page-' . $page['template'], [
            'photos' => $page['photos'],
            'gapMm' => $gapMmStr,
            'gapHalfMm' => $gapHalfMmStr,
        ])
    @endif
@endforeach

</body>
</html>