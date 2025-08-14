{{-- Copilot prompt:
1-up page: single image, centered, full flex.
--}}
<div class="page" style="gap:0; display:block; position:relative;">
    <div class="page-inner">
    <div class="slot" style="position:absolute;left:0;top:0;width:100%;height:100%;">
        @php $src = $asset_url($photos[0]); @endphp
        <div class="img" aria-label="{{ $photos[0]->filename }}" style="background-image:url('{{ $src }}');background-position:center center;"></div>
    </div>
    </div>
</div>