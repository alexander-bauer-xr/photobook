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
<style>
@page { margin: {{ (int) config('photobook.margin_mm', 0) }}mm; }
/* CSS variables for consistent units */
:root {
  --frame-mm: {{ (float) config('photobook.page_frame_mm', 6) }}mm;
  --gap-mm:   {{ (float) config('photobook.page_gap_mm', 2.5) }}mm;
  --eps-mm:   0.15mm;
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
  left: calc(var(--gap-mm) / 2);
  right: calc(var(--gap-mm) / 2);
  bottom: calc(var(--gap-mm) / 2);
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
</style>
</head>
<body>
@include('photobook.cover', ['options' => $options])

@foreach($pages as $page)
    @if(($page['template'] ?? '') === 'generic')
        @include('photobook.generic', ['slots' => $page['slots'], 'items' => $page['items'], 'asset_url' => $asset_url])
    @else
        @include('photobook.page-' . $page['template'], ['photos' => $page['photos']])
    @endif
@endforeach

</body>
</html>