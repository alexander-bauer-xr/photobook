{{-- Copilot prompt:
Create a minimal cover with title/subtitle and today's date if configured.
--}}
@if(!empty($show_form))
    @if(session('status'))
        <div style="background:#e8fff3;padding:8px 12px;margin:6px 0;border:1px solid #bde5c8;">
            {{ session('status') }}
        </div>
    @endif

    <form method="post" action="/photobook/build" style="margin:12px 0;">
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
        <label style="margin-left:12px;">
            <input type="checkbox" name="force_refresh" value="1">
            Force refresh (invalidate image cache)
        </label>
        <button type="submit">Build PDF</button>
    </form>
    <p style="margin:8px 0;">
        <a href="/photobook/review">Open review UI</a>
    </p>
@endif

<div class="page" style="align-items:center; justify-content:center;">
    <div style="text-align:center;">
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
        <h1 style="margin:0;">{{ $coverTitle }}</h1>
        <p style="margin:.5em 0 0;">{{ $coverSubtitle }}</p>
        @if (config('photobook.cover.show_date'))
            <p style="margin-top:2em; font-size: 10pt;">{{ now()->toDateString() }}</p>
        @endif
    </div>
</div>