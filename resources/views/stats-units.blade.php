<x-app-layout>
    <div data-page="stats-units">
        <p data-stats-units-status role="status" aria-live="polite" class="mx-auto max-w-7xl px-4 py-2 text-sm text-gray-600" hidden></p>
        <div data-stats-units-content>
            @include('partials._stats-units-content')
        </div>
    </div>
</x-app-layout>
