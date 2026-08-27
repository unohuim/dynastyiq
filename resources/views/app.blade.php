<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap"
            rel="stylesheet"
        />
        <link
            href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800,900&display=swap"
            rel="stylesheet"
        />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead

        <script>
            window.DIQ = { userId: {{ auth()->id() ?? 'null' }} };
        </script>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 pb-16 md:pb-0">
            @if(request()->routeIs('admin.*'))
                @include('nav.main')
            @else
                @include('nav.stats')
            @endif

            <main>
                @inertia
            </main>
        </div>

        @include('partials.toast-container')
    </body>
</html>
