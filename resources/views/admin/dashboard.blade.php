<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Control Panel
        </h2>
    </x-slot>

    @if (! $initialized)
        @include('admin.bootstrap', [
            'seeded' => $seeded,
            'initialized' => $initialized,
            'upToDate' => $upToDate,
        ])
    @else
        @include('admin.operational', [
            'imports' => $imports,
            'initialized' => $initialized,
            'unmatchedPlayersCount' => $unmatchedPlayersCount,
            'events' => $events,
        ])
    @endif
</x-app-layout>
