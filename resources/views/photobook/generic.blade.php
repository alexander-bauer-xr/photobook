{{--
  Copilot prompt:
  Generic page renderer used for PDF:
  - Uses background-size: cover (see layout.blade.css) to match the editor’s "cover" behavior.
  - object-position per item is respected via inline style below.
  - Caption is rendered when provided.
  Next improvements:
  - If we support per-item crop = contain in PDF, switch background-size per item (inline) instead of global.
  - Add optional per-item rotation support in Blade if we later export rotated slot renders (currently rotation handled in UI only).
--}}
{{-- $slots: array of rects; $items: [{photo, slotIndex, crop, objectPosition, src, caption}] --}}
<div class="page">
  <div class="page-inner">
    @foreach($items as $it)
      @php $s = $slots[$it['slotIndex']] ?? null; if(!$s) continue; @endphp
      <div class="slot"
           style="
             left:   {{ $s['x'] * 100 }}%;
             top:    {{ $s['y'] * 100 }}%;
             width:  calc({{ $s['w'] * 100 }}% - var(--eps-mm));
             height: calc({{ $s['h'] * 100 }}% - var(--eps-mm));
             padding: calc(var(--gap-mm) / 2);
           ">
        @php
          $src = $it['src'] ?? ($asset_url ? $asset_url($it['photo']) : '');
          $p = $it['photo'] ?? null;
          $label = '';
          if (is_object($p)) {
            $label = $p->filename ?? (isset($p->path) ? basename($p->path) : '');
          } elseif (is_array($p)) {
            $label = $p['filename'] ?? (isset($p['path']) ? basename($p['path']) : '');
          }
        @endphp
  <div class="img"
             aria-label="{{ $label }}"
             style="background-image:url('{{ $src }}'); background-position: {{ $it['objectPosition'] ?? '50% 50%' }};">
        </div>
        @if(!empty($it['caption']))
          <div class="caption">{{ $it['caption'] }}</div>
        @endif
      </div>
    @endforeach
  </div>
</div>
