@extends('layouts.stats')

@section('content')
    <div class="stats-view">
        <script>
            window.__statsPageConfig = {
                initialPayload: @json($payload),
                apiUrl: @json(url('/api/stats')),
                connectedLeagues: @json($connectedLeagues),
                perspectives: @json($perspectives),
                selectedPerspective: @json($selectedSlug),
                mobileBreakpoint: @json(config('viewports.mobile', 640)),
            };
        </script>

        <noscript>
            <div class="mx-auto max-w-7xl px-4 py-6">
                <div class="rounded-md bg-white p-4 text-sm text-gray-700 shadow">
                    JavaScript is required to view stats.
                </div>
            </div>
        </noscript>

        <div id="stats-page" class="mx-auto max-w-7xl"></div>
    </div>
@endsection
