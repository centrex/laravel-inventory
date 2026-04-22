<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>POS Terminal — {{ config('app.name', 'Laravel') }}</title>

    <script>
        (() => {
            try {
                const theme = localStorage.getItem('theme') || 'light';
                const mode  = localStorage.getItem('theme-mode') || (theme === 'dark' ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.classList.toggle('dark', mode === 'dark');
            } catch (_) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-base-100 text-base-content antialiased overflow-hidden">
    {{ $slot }}

    @livewireScripts
</body>
</html>
