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
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; }
.page { page-break-after: always; position: relative; width: 100%; height: 100%; }
/* Slots are absolutely positioned by generic template; no fixed mm height */
.slot { position: relative; overflow: hidden; border:0; }
/* Preserve aspect ratio in legacy page-* templates too */
.slot img { width:100%; height:100%; object-fit: cover; }
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