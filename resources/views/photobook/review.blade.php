{{-- resources/views/photobook/review.blade.php --}}
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Photobook Review</title>
    <meta name="photobook-asset-origin" content="{{ request()->getSchemeAndHttpHost() }}">
    <style>
        body {
            font-family: system-ui, Segoe UI, Roboto, Arial, sans-serif;
            margin: 16px;
        }

        header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .page {
            border: 1px solid #ddd;
            padding: 8px;
            margin: 12px 0;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 8px;
        }

        .thumb {
            position: relative;
            width: 100%;
            padding-top: 66%;
            background: #f4f4f4;
            overflow: hidden;
        }

        .thumb-slot {
            position: absolute;
            inset: 0;
            box-sizing: border-box;
        }

        .thumb-caption {
            position: absolute;
            left: 6px;
            right: 6px;
            bottom: 6px;
            padding: 6px 8px;
            background: rgba(255, 255, 255, 0.9);
            color: #1f2937;
            font-size: 12px;
            line-height: 1.3;
            text-align: center;
            border-radius: 6px;
            word-break: break-word;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            pointer-events: none;
        }

        .thumb-inner {
            position: absolute;
            inset: 0;
            overflow: hidden;
            background: #fff;
        }

        .thumb-inner img {
            position: absolute;
            left: 50%;
            top: 50%;
            max-width: none;
            max-height: none;
            transform-origin: center center;
            display: block;
        }

        .thumb-fallback {
            position: absolute;
            inset: 0;
            background-position: center;
            background-size: cover;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            align-items: center;
        }

        label {
            font-size: 12px;
            color: #444;
        }

        select,
        input[type="text"] {
            padding: 4px;
        }

        button {
            padding: 6px 10px;
        }

        .small {
            color: #666;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <header>
        <h2 style="margin:0;">Review photobook</h2>
        <form method="get" action="/photobook/review">
            <label>Folder <input name="folder" value="{{ $folder }}" style="width:360px"></label>
            <button type="submit">Load</button>
            <a class="small" href="/photobook">Back</a>
            <a class="small" href="{{ route('photobook.pdf.latest', [], false) }}" target="_blank" rel="noopener">Download latest PDF</a>
        </form>
    </header>

    @if(empty($pages))
        <p class="small">No pages.json found. Build a book first.</p>
    @else
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
    @endphp
    @foreach($pages as $p)
    @php($n = (int) ($p['n'] ?? 0))
    @php($items = $p['items'] ?? [])
    @php($tplId = $p['templateId'] ?? $p['template'] ?? '')
    @php($ovr = $p['overrideTemplateId'] ?? null)
    <div class="page">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <strong>Page {{ $n }}</strong>
                <span class="small">Template: {{ $tplId }}</span>
                @if($ovr && $ovr !== $tplId)
                    <span class="small" style="color:#b26;">(override pending: {{ $ovr }})</span>
                @endif
            </div>
            <div class="controls">
                <form method="post" action="{{ route('photobook.feedback', [], false) }}" onsubmit="return send(event, this)">
                    @csrf
                    <input type="hidden" name="folder" value="{{ $folder }}">
                    <input type="hidden" name="page" value="{{ $n }}">
                    <label>Action
                        <select name="action">
                            <option value="like">Like</option>
                            <option value="dislike">Dislike</option>
                            <option value="faces-cropped">Faces Cropped</option>
                            <option value="too-repetitive">Too Repetitive</option>
                            <option value="low-confidence">Low Confidence</option>
                        </select>
                    </label>
                    <input type="text" name="reason" placeholder="Optional note" style="width:220px;">
                    <button type="submit">Send</button>
                </form>

                <form method="post" action="{{ route('photobook.override', [], false) }}" onsubmit="return send(event, this)">
                    @csrf
                    <input type="hidden" name="folder" value="{{ $folder }}">
                    <input type="hidden" name="page" value="{{ $n }}">
                    <label>Override template
                        @php($count = count($items))
                        <select name="templateId">
                            @foreach(($tplOptions[(string) $count] ?? []) as $opt)
                                @php($sel = $ovr ? ($opt === $ovr) : ($opt === $tplId))
                                <option value="{{ $opt }}" {{ $sel ? 'selected' : '' }}>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit">Apply</button>
                </form>
            </div>
        </div>

        <div class="grid" style="margin-top:8px;">
            @foreach($items as $it)
            @php
                $pos = $it['objectPosition'] ?? '50% 50%';
                $src = $it['webSrc'] ?? ($it['web'] ?? ($it['src'] ?? ''));
                $transform = is_array($it['transform'] ?? null) ? $it['transform'] : null;
                $hasTransform = $transform && ($transform['imgWidth'] ?? 0) > 0 && ($transform['imgHeight'] ?? 0) > 0 && $src;
                $slotW = $hasTransform ? max(0.0, (float) ($transform['slotWidth'] ?? 0)) : 0.0;
                $slotH = $hasTransform ? max(0.0, (float) ($transform['slotHeight'] ?? 0)) : 0.0;
                $aspectPct = ($slotW > 0 && $slotH > 0) ? ($slotH / $slotW * 100.0) : 66.0;
                $captionRaw = $it['caption'] ?? null;
                $captionText = is_string($captionRaw) ? $captionRaw : (is_numeric($captionRaw) ? (string) $captionRaw : '');
                $captionDisplay = trim((string) $captionText) !== '' ? $captionText : '';
            @endphp
            <div class="thumb" style="padding-top: {{ $formatNumber($aspectPct, 4) }}%;">
                @if($hasTransform)
                    @php
                        $innerPad = max(0.0, (float) ($transform['innerPad'] ?? 0));
                        $contentW = max(1e-6, (float) ($transform['contentWidth'] ?? 0));
                        $contentH = max(1e-6, (float) ($transform['contentHeight'] ?? 0));
                        $padX = ($slotW > 0) ? ($innerPad / $slotW * 100.0) : 0.0;
                        $padY = ($slotH > 0) ? ($innerPad / $slotH * 100.0) : 0.0;
                        $imgWPercent = ($transform['imgWidth'] ?? 0) / $contentW * 100.0;
                        $imgHPercent = ($transform['imgHeight'] ?? 0) / $contentH * 100.0;
                        $panXPercent = ($transform['panX'] ?? 0) / $contentW * 100.0;
                        $panYPercent = ($transform['panY'] ?? 0) / $contentH * 100.0;
                        $scaleStr = $formatNumber($transform['scale'] ?? 1, 6);
                        $rotStr = $formatNumber($transform['rotation'] ?? 0, 4);
                        $padXStr = $formatNumber($padX, 3) . '%';
                        $padYStr = $formatNumber($padY, 3) . '%';
                        $imgWStr = $formatNumber($imgWPercent, 4) . '%';
                        $imgHStr = $formatNumber($imgHPercent, 4) . '%';
                        $panXStr = $formatNumber($panXPercent, 3) . '%';
                        $panYStr = $formatNumber($panYPercent, 3) . '%';
                        $transformCss = "translate(-50%, -50%) translate({$panXStr}, {$panYStr}) rotate({$rotStr}deg) scale({$scaleStr})";
                    @endphp
                    <div class="thumb-slot" style="padding: {{ $padYStr }} {{ $padXStr }};">
                        <div class="thumb-inner">
                            <img src="{{ $src }}" alt="" style="width: {{ $imgWStr }}; height: {{ $imgHStr }}; max-width:none; max-height:none; transform: {{ $transformCss }};">
                        </div>
                    </div>
                @else
                    @php
                        $fitMode = (($it['fit'] ?? ($it['crop'] ?? 'cover')) === 'contain') ? 'contain' : 'cover';
                    @endphp
                    <div class="thumb-fallback" style="background-image:url('{{ e($src) }}'); background-position: {{ $pos }}; background-size: {{ $fitMode }};"></div>
                @endif
                @if($captionDisplay !== '')
                    <div class="thumb-caption">{{ $captionDisplay }}</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endforeach
    @endif

    <script>
        // If any src accidentally remains root-relative, make it absolute to current origin
        (function fixThumbUrls(){
            try {
                const origin = location.origin;
                document.querySelectorAll('.thumb-fallback').forEach((el)=>{
                    const st = el.style.backgroundImage || '';
                    const m = st.match(/url\(["']?(.*?)["']?\)/);
                    if (m && m[1] && m[1].startsWith('/')) {
                        el.style.backgroundImage = `url(${origin}${m[1]})`;
                    }
                });
            } catch {}
        })();
        
        async function send(ev, form) {
            ev.preventDefault();
            const fd = new FormData(form);
            const url = form.getAttribute('action');
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin', // include session cookie for CSRF
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: fd
            });
            try {
                if (!res.ok) {
                    const txt = await res.text();
                    alert('Error ' + res.status + ': ' + txt.slice(0, 200));
                    return false;
                }
                const json = await res.json();
                alert(json.ok ? 'Saved' : (json.error || 'Error'));
            } catch (e) {
                alert('Saved');
            }
            return false;
        }
    </script>
</body>

</html>