<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locale::direction(app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Matches the two background colours set below, so the mobile browser chrome
             follows the theme instead of framing a dark page in white. --}}
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">

        <title inertia>{{ config('app.name', 'MonyMonk') }}</title>

        {{--
            The .ico carries 16/32/48 for the tab and for Windows; the 96 png is what
            modern browsers pick up. There is a favicon.svg in the supplied set, but it
            is a 940 kB raster wrapped in an <svg> element rather than a drawing — it
            would be downloaded on every cold load to render at 16 pixels, so it is not
            linked here.
        --}}
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">

        {{--
            Theme is applied here, synchronously, before anything paints.

            Section 13 requires no theme flash during page load. A React effect cannot
            satisfy that: it runs after first paint, so a dark-mode user sees a white
            frame first. This blocking script must stay in the server-rendered head.
        --}}
        <script>
            (function () {
                // The signed-in user's saved preference wins over this browser's:
                // it is the choice that follows them between devices. For a guest
                // there is nobody to have a preference, so localStorage is all there is.
                var saved = @js(auth()->user()?->theme);

                try {
                    if (saved) {
                        localStorage.setItem('appearance', saved);
                    }

                    var appearance = saved || localStorage.getItem('appearance') || 'system';
                    var isDark = appearance === 'dark' || (appearance === 'system'
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);

                    if (isDark) {
                        document.documentElement.classList.add('dark');
                    }
                } catch (e) {
                    // Private browsing can throw on localStorage. Falling back to the
                    // light theme is preferable to failing the render.
                }
            })();
        </script>

        {{-- Matches the app background so the first painted frame is never white in dark mode. --}}
        <style>
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
