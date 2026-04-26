<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" type="image/png" href="{{ asset('images/icon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/icon.png') }}">
        <title inertia>{{ config('app.name', 'YouGo') }}</title>
        <script>
            (() => {
                const saved = localStorage.getItem('yougo-theme');
                const dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>
        @viteReactRefresh
        @vite(['resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
