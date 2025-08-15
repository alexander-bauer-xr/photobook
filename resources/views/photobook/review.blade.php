{{-- resources/views/photobook/review.blade.php --}}
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Photobook Review</title>
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

        .thumb>div {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
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
        </form>
    </header>

    @if(empty($pages))
        <p class="small">No pages.json found. Build a book first.</p>
    @else
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
            @php($pos = $it['objectPosition'] ?? '50% 50%')
            @php($src = $it['webSrc'] ?? ($it['web'] ?? ($it['src'] ?? '')))
            <div class="thumb">
                <div style="background-image:url('{{ $src }}'); background-position: {{ $pos }};"></div>
            </div>
            @endforeach
        </div>
    </div>
    @endforeach
    @endif

    <script>
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