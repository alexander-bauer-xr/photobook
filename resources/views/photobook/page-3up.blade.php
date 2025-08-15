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
      @php $src = $asset_url($photos[0]); @endphp
      <div class="img" aria-label="{{ $photos[0]->filename }}"
           style="background-image:url('{{ $src }}'); background-position:center center;"></div>
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
      @php $src = $asset_url($photos[1]); @endphp
      <div class="img" aria-label="{{ $photos[1]->filename }}"
           style="background-image:url('{{ $src }}'); background-position:center center;"></div>
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
      @php $src = $asset_url($photos[2]); @endphp
      <div class="img" aria-label="{{ $photos[2]->filename }}"
           style="background-image:url('{{ $src }}'); background-position:center center;"></div>
    </div>
  </div>
</div>
