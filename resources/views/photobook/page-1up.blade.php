{{-- Copilot prompt:
1-up page: single image, centered, full flex.
--}}
<div class="page" style="gap:0; display:block;">
    <div class="slot" style="position:absolute;left:0;top:0;width:100%;height:100%;">
        @php $src = $asset_url($photos[0]); @endphp
        <div aria-label="{{ $photos[0]->filename }}" style="width:100%;height:100%;background-image:url('{{ $src }}');background-size:cover;background-position:center center;background-repeat:no-repeat;"></div>
    </div>
</div>