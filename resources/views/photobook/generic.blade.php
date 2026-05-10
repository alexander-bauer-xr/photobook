{{--
  Copilot prompt:
  Generic page renderer used for PDF:
  - Uses background-size: cover (see layout.blade.css) to match the editor’s "cover" behavior.
  - object-position per item is respected via inline style below.
  - Caption is rendered when provided.
  Next improvements:
  - If we support per-item crop = contain in PDF, switch background-size per item (inline) instead of global.
  - Add optional per-item rotation support in Blade if we later export rotated slot renders (currently rotation handled in UI only).
--}}
{{-- $slots: array of rects; $items: [{photo, slotIndex, crop, objectPosition, src, caption}] --}}
@php
  $formatNumber = static function ($value, int $precision = 4): string {
      $num = round((float) $value, $precision);
      $str = number_format($num, $precision, '.', '');
      $str = rtrim(rtrim($str, '0'), '.');
      if ($str === '' || $str === '-0') {
          $str = '0';
      }
      return $str;
  };
  if (!isset($gapHalfMm)) {
      $formatMm = static function (float $value): string {
          $formatted = number_format($value, 6, '.', '');
          return rtrim(rtrim($formatted, '0'), '.');
      };
      $gapHalfMm = $formatMm(((float) config('photobook.page_gap_mm', 2.5)) / 2);
  }
  $spineClass = $spineClass ?? '';
@endphp
<div class="page">
  <div class="page-inner {{ $spineClass }}">
    @foreach($items as $it)
      @php $s = $slots[$it['slotIndex']] ?? null; if(!$s) continue; @endphp
      <div class="slot"
           style="
             left:   {{ $s['x'] * 100 }}%;
             top:    {{ $s['y'] * 100 }}%;
             width:  calc({{ $s['w'] * 100 }}% - var(--eps-mm));
             height: calc({{ $s['h'] * 100 }}% - var(--eps-mm));
             padding: {{ $gapHalfMm }}mm;
           ">
        @php
          $src = $it['src'] ?? ($asset_url ? $asset_url($it['photo']) : '');
          $p = $it['photo'] ?? null;
          $label = '';
          if (is_object($p)) {
            $label = $p->filename ?? (isset($p->path) ? basename($p->path) : '');
          } elseif (is_array($p)) {
            $label = $p['filename'] ?? (isset($p['path']) ? basename($p['path']) : '');
          }
          $transform = is_array($it['transform'] ?? null) ? $it['transform'] : null;
          $hasTransform = $transform && ($transform['imgWidth'] ?? 0) > 0 && ($transform['imgHeight'] ?? 0) > 0 && $src;
          $captionRaw = '';
          if (array_key_exists('caption', $it)) {
            $val = $it['caption'];
            if (is_string($val) || is_numeric($val)) {
              $captionRaw = (string) $val;
            }
          }
          $caption = trim($captionRaw) !== '' ? $captionRaw : '';
        @endphp

        @if($hasTransform)
          @php
            $imgW = max(0, (int) round($transform['imgWidth']));
            $imgH = max(0, (int) round($transform['imgHeight']));
            if ($imgW <= 0 || $imgH <= 0) {
              $hasTransform = false;
            }
          @endphp
        @endif

        @if($hasTransform)
          @php
            $panX = $formatNumber($transform['panX'] ?? 0, 3) . 'px';
            $panY = $formatNumber($transform['panY'] ?? 0, 3) . 'px';
            $scaleStr = $formatNumber($transform['scale'] ?? 1, 6);
            $rotStr = $formatNumber($transform['rotation'] ?? 0, 4);
            $transformCss = "translate(-50%, -50%) translate({$panX}, {$panY}) rotate({$rotStr}deg) scale({$scaleStr})";
          @endphp
          <div class="slot-inner">
            <img src="{{ $src }}"
                 alt=""
                 aria-label="{{ $label }}"
                 style="width: {{ $imgW }}px; height: {{ $imgH }}px; max-width:none; max-height:none; transform: {{ $transformCss }};">
          </div>
        @else
          @php
            $pos = $it['objectPosition'] ?? '50% 50%';
            $fitMode = (($it['fit'] ?? ($it['crop'] ?? 'cover')) === 'contain') ? 'contain' : 'cover';
          @endphp
          <div class="slot-inner slot-inner-legacy"
               aria-label="{{ $label }}"
               style="background-image:url('{{ e($src) }}'); background-position: {{ $pos }}; background-size: {{ $fitMode }};">
          </div>
        @endif
        @if($caption !== '')
          <div class="caption">{{ $caption }}</div>
        @endif
      </div>
    @endforeach
  </div>

  {{-- Crop marks for print-ready mode --}}
  @if(!empty($showCropMarks))
    <div class="crop-marks">
      <div class="crop-mark h top-left-h"></div>
      <div class="crop-mark v top-left-v"></div>
      <div class="crop-mark h top-right-h"></div>
      <div class="crop-mark v top-right-v"></div>
      <div class="crop-mark h bottom-left-h"></div>
      <div class="crop-mark v bottom-left-v"></div>
      <div class="crop-mark h bottom-right-h"></div>
      <div class="crop-mark v bottom-right-v"></div>
    </div>
    @if(!empty($pageNumber))
      <div class="print-info">Page {{ $pageNumber }}</div>
    @endif
  @endif
</div>
