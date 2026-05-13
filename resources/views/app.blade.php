<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    <x-inertia::head />
    <style>
        html,
        body,
        #app {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        @media print {
            html,
            body {
                width: 100%;
                min-height: 100%;
                background: #fff;
            }

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

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <x-inertia::app />
</body>
</html>
