<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Control Panel
        </h2>
    </x-slot>

    @include('admin.operational', [
        'imports' => $imports,
        'unmatchedPlayersCount' => $unmatchedPlayersCount,
        'events' => $events,
        'hasPlayers' => $hasPlayers,
    ])
</x-app-layout>
