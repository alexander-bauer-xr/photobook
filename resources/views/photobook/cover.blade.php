{{-- Minimal cover inspired by Google Photobook: large image on top, white band with title below. --}}
@if(!empty($show_form))
    @if(session('status'))
        <div style="background:#e8fff3;padding:8px 12px;margin:6px 0;border:1px solid #bde5c8;">
            {{ session('status') }}
        </div>
    @endif

    <form method="post" action="/photobook/build" style="margin:12px 0; display:flex; flex-direction: column; gap:10px;">
        @csrf
        <label>Nextcloud folder:
            <input type="text" name="folder" value="{{ $defaults['folder'] }}" style="width:320px;">
        </label>
        <label>Titel:
            <input type="text" name="title" value="{{ $defaults['title'] }}" style="width:320px;">
        </label>
        <label>Subtitle:
            <input type="text" name="subtitle" value="{{ $defaults['subtitle'] }}" style="width:320px;">
        </label>
                <label style="display:inline-flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="show_date" value="1" {{ !empty($defaults['show_date']) ? 'checked' : '' }}>
                        Show date on cover
                </label>
        <label>Paper:
            <select name="paper">
                <option value="a4" {{ $defaults['paper']=='a4'?'selected':'' }}>A4</option>
                <option value="a3" {{ $defaults['paper']=='a3'?'selected':'' }}>A3</option>
            </select>
        </label>
        <label>Orientation:
            <select name="orientation">
                @php($ori = $defaults['orientation'] ?? config('photobook.orientation','landscape'))
                <option value="portrait" {{ $ori=='portrait'?'selected':'' }}>Portrait</option>
                <option value="landscape" {{ $ori=='landscape'?'selected':'' }}>Landscape</option>
            </select>
        </label>
        <label>DPI:
            <input type="number" name="dpi" value="{{ $defaults['dpi'] }}" min="72" max="300">
        </label>
        <label style="">
            <input type="checkbox" name="force_refresh" value="1">
            Force refresh (invalidate image cache)
        </label>
        <button type="submit">Build PDF</button>
    </form>
    <p style="margin:8px 0;">
        <a href="/photobook/review">Open review UI</a>
    </p>
@endif

@php(
        $coverTitle = (isset($options) && trim((string)($options['title'] ?? '')) !== '')
                ? ($options['title'] ?? '')
                : config('photobook.cover.title')
)
@php(
        $coverSubtitle = (isset($options) && trim((string)($options['subtitle'] ?? '')) !== '')
                ? ($options['subtitle'] ?? '')
                : config('photobook.cover.subtitle')
)
@php($showDate = (isset($options) && array_key_exists('cover_show_date', $options)) ? (bool)$options['cover_show_date'] : (bool) config('photobook.cover.show_date'))

<div class="page">
    <div class="page-inner" style="display:flex; flex-direction:column;">
        <!-- Top image area taking ~65% height -->
        <div style="flex: 0 0 65%; position:relative; overflow:hidden; background:#f4f4f4;">
            <!-- Optional: pick first render image from first planned page if available in options; otherwise blank gray -->
            {{-- You can wire a specific cover image here in future --}}
        </div>
        <!-- Bottom white band with title/subtitle/date -->
        <div style="flex: 1 1 auto; background:#fff; display:flex; align-items:center; justify-content:center;">
            <div style="text-align:center; padding: 8mm 6mm;">
                <div style="font-size: 22pt; font-weight: 600; line-height: 1.2;">{{ $coverTitle }}</div>
                @if(trim((string)$coverSubtitle) !== '')
                    <div style="font-size: 12pt; color:#555; margin-top: 6px;">{{ $coverSubtitle }}</div>
                @endif
                @if ($showDate)
                    <div style="font-size: 10pt; color:#777; margin-top: 12px;">{{ now()->toDateString() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>