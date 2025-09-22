<div class="page">
  <div class="page-inner">
    {{-- left column --}}
    <div class="slot"
         style="
           left: 0;
           top: 0;
           width:  calc(50% - var(--eps-mm));
           height: calc(100% - var(--eps-mm));
           padding: calc(var(--gap-mm)/2);
         ">
      @php
        $src = $asset_url($photos[0]);
        $p = $photos[0] ?? null;
        $label = is_object($p) ? ($p->filename ?? basename($p->path ?? '')) : (is_array($p) ? ($p['filename'] ?? basename($p['path'] ?? '')) : '');
        $caption = '';
        if (is_object($p) && isset($p->caption)) {
          $caption = (string) $p->caption;
        } elseif (is_array($p) && array_key_exists('caption', $p)) {
          $caption = (string) $p['caption'];
        }
      @endphp
      <div class="slot-inner slot-inner-legacy" aria-label="{{ $label }}"
           style="background-image:url('{{ $src }}'); background-position:center center; background-size:cover;"></div>
      @if(trim($caption) !== '')
        <div class="caption">{{ $caption }}</div>
      @endif
    </div>

    {{-- right column --}}
    <div class="slot"
         style="
           left: 50%;
           top:  0;
           width:  calc(50% - var(--eps-mm));
           height: calc(100% - var(--eps-mm));
           padding: calc(var(--gap-mm)/2);
         ">
      @php
        $src = $asset_url($photos[1]);
        $p = $photos[1] ?? null;
        $label = is_object($p) ? ($p->filename ?? basename($p->path ?? '')) : (is_array($p) ? ($p['filename'] ?? basename($p['path'] ?? '')) : '');
        $caption = '';
        if (is_object($p) && isset($p->caption)) {
          $caption = (string) $p->caption;
        } elseif (is_array($p) && array_key_exists('caption', $p)) {
          $caption = (string) $p['caption'];
        }
      @endphp
      <div class="slot-inner slot-inner-legacy" aria-label="{{ $label }}"
           style="background-image:url('{{ $src }}'); background-position:center center; background-size:cover;"></div>
      @if(trim($caption) !== '')
        <div class="caption">{{ $caption }}</div>
      @endif
    </div>
  </div>
</div>
