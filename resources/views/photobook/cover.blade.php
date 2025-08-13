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
        <label>Paper:
            <select name="paper">
                <option value="a4" {{ $defaults['paper']=='a4'?'selected':'' }}>A4</option>
                <option value="a3" {{ $defaults['paper']=='a3'?'selected':'' }}>A3</option>
            </select>
        </label>
        <label>Orientation:
            <select name="orientation">
                @php($ori = config('photobook.orientation','landscape'))
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
@endif

<div class="page" style="align-items:center; justify-content:center;">
    <div style="text-align:center;">
        <h1 style="margin:0;">{{ config('photobook.cover.title') }}</h1>
        <p style="margin:.5em 0 0;">{{ config('photobook.cover.subtitle') }}</p>
        @if (config('photobook.cover.show_date'))
            <p style="margin-top:2em; font-size: 10pt;">{{ now()->toDateString() }}</p>
        @endif
    </div>
</div>