{{-- resources/views/photobook/generic.blade.php --}}
@php
    // $slots: array of rects
    // $items: array with ['photo'=>PhotoDto,'slotIndex'=>int,'crop'=>'cover','objectPosition'=>string,'src'=>string]
@endphp
<div class="page" style="position: relative; width: 100%; height: 100%; display:block;">
<div class="page-inner">
@foreach($items as $it)
    @php $s = $slots[$it['slotIndex']]; @endphp
    <div class="slot" style="
        position:absolute;
        left: {{ $s['x'] * 100 }}%;
        top: {{ $s['y'] * 100 }}%;
        width: {{ $s['w'] * 100 }}%;
        height: {{ $s['h'] * 100 }}%;
        overflow:hidden;
        ">
    @php $src = $it['src'] ?? ($asset_url ? $asset_url($it['photo']) : ''); @endphp
    <div class="img" aria-label="{{ $it['photo']->filename }}" style="
            background-image:url('{{ $src }}');
            background-position: {{ $it['objectPosition'] }};
        "></div>
    </div>
@endforeach
</div>
</div>
