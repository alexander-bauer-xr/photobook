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
      @php $src = $asset_url($photos[0]); @endphp
      <div class="img" aria-label="{{ $photos[0]->filename }}"
           style="background-image:url('{{ $src }}'); background-position:center center;"></div>
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
      @php $src = $asset_url($photos[1]); @endphp
      <div class="img" aria-label="{{ $photos[1]->filename }}"
           style="background-image:url('{{ $src }}'); background-position:center center;"></div>
    </div>
  </div>
</div>
