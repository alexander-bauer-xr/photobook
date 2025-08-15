<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'Photobook') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="margin:0;">
    @yield('content')

    @stack('scripts')
</body>

</html>
