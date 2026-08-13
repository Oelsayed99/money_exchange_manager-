<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locale::direction(app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

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
