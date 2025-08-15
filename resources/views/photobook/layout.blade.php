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
.slot > .img { width:100%; height:100%; background-size:cover; background-repeat:no-repeat; background-origin: content-box; }
.caption { font-size: 10pt; margin-top: 2mm; }
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