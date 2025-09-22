{{--
    Copilot prompt:
    Minimal cover page: top image uses object-fit: cover to mirror the editor’s cover semantics.
    Title overlays on the image; subtitle/date appear in the lower white band.
    Next improvements:
    - Allow object-position override for the cover image (e.g., from UI state).
    - Optional: enable a full-bleed cover variant controlled via config.
--}}
{{-- build form/UI removed in PDF cover partial to avoid accidental render context issues --}}

@php
    $opts = (isset($options) && is_array($options)) ? $options : [];
    $coverTitle = (trim((string)($opts['title'] ?? '')) !== '')
        ? ($opts['title'] ?? '')
        : config('photobook.cover.title');
    $coverSubtitle = (trim((string)($opts['subtitle'] ?? '')) !== '')
        ? ($opts['subtitle'] ?? '')
        : config('photobook.cover.subtitle');
    $showDate = array_key_exists('cover_show_date', $opts) ? (bool)$opts['cover_show_date'] : (bool) config('photobook.cover.show_date');
@endphp

<div class="page">
    <div class="page-inner" style="position:relative;">
        <!-- Top image area (65% of page-inner height) -->
        <div style="position:absolute; left:0; top:0; right:0; height:65%; overflow:hidden; background:#000;">
            @if(!empty($opts['cover_image_src']) || !empty($opts['cover_image_web']))
                @php
                    $img = $opts['cover_image_src'] ?? $opts['cover_image_web'];
                @endphp
                <img src="{{ $img }}" alt="" style="position:absolute; left:50%; top:50%; transform: translate(-50%, -50%); width:100%; height:100%; max-width:none; max-height:none; object-fit: cover;" />
            @endif
            @if(!empty($coverTitle))
                <div style="position:absolute; left:0; right:0; bottom:12mm; z-index:2; text-align:center; color:#fff; font-size:28pt; font-weight:600; text-shadow:0 2px 6px rgba(0,0,0,.6);">
                    {{ $coverTitle }}
                </div>
            @endif
        </div>
        <!-- Bottom white band with title/subtitle/date (remaining 35%) -->
        <div style="position:absolute; left:0; right:0; top:65%; bottom:0; background:#fff; display:flex; align-items:center; justify-content:center;">
            <div style="text-align:center; padding: 8mm 6mm;">
                @if(!empty($coverTitle))
                    <div style="font-size: 16pt; color:#222; font-weight:600;">{{ $coverTitle }}</div>
                @endif
                @if(isset($coverSubtitle) && trim((string)$coverSubtitle) !== '')
                    <div style="font-size: 12pt; color:#555; margin-top: 6px;">{{ $coverSubtitle ?? '' }}</div>
                @endif
                @if ($showDate)
                    <div style="font-size: 10pt; color:#777; margin-top: 12px;">{{ now()->toDateString() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>