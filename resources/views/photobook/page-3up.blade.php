{{-- Copilot prompt:
3-up page: three equal columns.
--}}
<div class="page" style="gap:4mm; display:flex; align-items:stretch;">
@foreach($photos as $p)
    <div class="slot" style="flex:1 1 0;">
        @php $src = $asset_url($p); @endphp
        <div aria-label="{{ $p->filename }}" style="width:100%;height:100%;background-image:url('{{ $src }}');background-size:cover;background-position:center center;background-repeat:no-repeat;aspect-ratio:4/3;"></div>
    </div>
@endforeach
</div>