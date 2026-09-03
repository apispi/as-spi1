<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Spi - API Testing</title>
        <link rel="icon" type="image/x-icon" href="/favicon.ico">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

        <!-- Resolve the theme before first paint to avoid a flash of the wrong
             colours. Mirrors resources/js/theme.js. -->
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('spi-theme');
                    if (!t) {
                        t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
                    }
                    document.documentElement.setAttribute('data-theme', t);
                } catch (e) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Vite Assets -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @if ($gaId = config('services.google_analytics.id'))
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @json($gaId));
        </script>
        @endif
    </head>
    <body class="antialiased">
        <div id="app"></div>
    </body>
</html>
