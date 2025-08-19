<div class="page">
  <div class="page-inner">
    <div class="slot" style="left:0; top:0; width:calc(100% - var(--eps-mm)); height:calc(100% - var(--eps-mm)); padding: calc(var(--gap-mm)/2);">
      @php
        $src = $asset_url($photos[0]);
        $p = $photos[0] ?? null;
        $label = is_object($p) ? ($p->filename ?? basename($p->path ?? '')) : (is_array($p) ? ($p['filename'] ?? basename($p['path'] ?? '')) : '');
      @endphp
      <div class="img" aria-label="{{ $label }}"
           style="background-image:url('{{ $src }}'); background-position:center center;"></div>
    </div>
  </div>
</div>
