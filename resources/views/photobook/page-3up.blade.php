<div class="page">
  <div class="page-inner">
    {{-- col 1 --}}
    <div class="slot"
         style="
           left: 0%;
           top:  0;
           width:  calc(33.3334% - var(--eps-mm));
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

    {{-- col 2 --}}
    <div class="slot"
         style="
           left: 33.3334%;
           top:  0;
           width:  calc(33.3333% - var(--eps-mm));
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

    {{-- col 3 --}}
    <div class="slot"
         style="
           left: 66.6667%;
           top:  0;
           width:  calc(33.3333% - var(--eps-mm));
           height: calc(100% - var(--eps-mm));
           padding: calc(var(--gap-mm)/2);
         ">
      @php
        $src = $asset_url($photos[2]);
        $p = $photos[2] ?? null;
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
