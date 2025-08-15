{{-- $slots: array of rects; $items: [{photo, slotIndex, crop, objectPosition, src}] --}}
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
        @php $src = $it['src'] ?? ($asset_url ? $asset_url($it['photo']) : ''); @endphp
        <div class="img"
             aria-label="{{ $it['photo']->filename }}"
             style="background-image:url('{{ $src }}'); background-position: {{ $it['objectPosition'] ?? '50% 50%' }};">
        </div>
      </div>
    @endforeach
  </div>
</div>
