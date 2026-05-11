<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Photobook Print – {{ $hash }}</title>
    @viteReactRefresh
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/photobook-editor/main.tsx'])
    <style>
        /* ------------------------------------------------------------------ */
        /* Screen: neutral background so Playwright has a clean canvas         */
        /* ------------------------------------------------------------------ */
        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
        }

        /* ------------------------------------------------------------------ */
        /* Print layout                                                         */
        /* Each .print-page becomes one PDF page.                              */
        /* ------------------------------------------------------------------ */
        @media print {
            html, body {
                width: 100%;
                height: 100%;
                margin: 0;
                padding: 0;
                background: #fff;
            }

            /* Force every .print-page onto its own PDF page */
            .print-page {
                page-break-after: always;
                break-after: page;
                width: 100%;
                height: 100vh;
                overflow: hidden;
                position: relative;
            }

            .print-page:last-child {
                page-break-after: avoid;
                break-after: avoid;
            }

            /* Hide interactive UI elements */
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    {{-- The React editor mounts here. When ?print=1 is present it renders    --}}
    {{-- all pages as .print-page divs and sets window.__printReady = true.   --}}
    <div
        id="photobook-root"
        data-hash="{{ $hash }}"
        data-print="{{ request()->boolean('print') ? 'true' : 'false' }}"
    ></div>

    <script>
        // Safety net: signal ready after 30s regardless, so Playwright doesn't hang.
        setTimeout(function () {
            if (!window.__printReady) {
                console.warn('photobook print: safety timeout — signalling ready');
                window.__printReady = true;
            }
        }, 30000);
    </script>
</body>
</html>
