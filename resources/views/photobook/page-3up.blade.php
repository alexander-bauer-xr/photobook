{{-- Copilot prompt:
3-up page: three equal columns.
--}}
<div class="page" style="display:block; position:relative;">
<div class="page-inner" style="display:flex; align-items:stretch; gap: var(--page-gap, 3mm);">
@foreach($photos as $p)
    <div class="slot" style="flex:1 1 0;">
        @php $src = $asset_url($p); @endphp
    <div class="img" aria-label="{{ $p->filename }}" style="background-image:url('{{ $src }}');background-position:center center;aspect-ratio:4/3;"></div>
    </div>
@endforeach
</div>
</div>