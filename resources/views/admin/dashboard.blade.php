<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Control Panel
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-4">System Status</h3>
                <div class="space-y-2 text-gray-800">
                    <div class="flex items-center space-x-2">
                        <span>{{ $seeded ? '✅' : '❌' }}</span>
                        <span>{{ $seeded ? 'Seeded' : 'Not Seeded' }}</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span>{{ $initialized ? '✅' : '❌' }}</span>
                        <span>{{ $initialized ? 'Initialized' : 'Not Initialized' }}</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span>{{ $upToDate ? '✅' : '❌' }}</span>
                        <span>{{ $upToDate ? 'Up to Date' : 'Behind' }}</span>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Initialization</h3>
                @if(!$initialized)
                    <form method="POST" action="{{ route('admin.initialize.run') }}">
                        @csrf
                        <x-primary-button>Bring Platform Online</x-primary-button>
                    </form>
                @else
                    <div class="text-green-700 font-semibold flex items-center space-x-2">
                        <span>✅</span>
                        <span>Platform Initialized</span>
                    </div>
                @endif
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Imports</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($imports as $import)
                        <div class="border rounded-lg p-4 flex items-center justify-between">
                            <div>
                                <div class="font-semibold">{{ $import['label'] }}</div>
                                <div class="text-sm text-gray-600">Last run: {{ $import['last_run']?->toDateTimeString() ?? 'N/A' }}</div>
                            </div>
                            <form method="POST" action="{{ route('admin.imports.run', ['key' => $import['key']]) }}">
                                @csrf
                                <x-primary-button>Run Now</x-primary-button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Player Triage</h3>
                <div class="flex items-center justify-between">
                    <div>
                        @if ($unmatchedPlayersCount > 0)
                            <span>{{ $unmatchedPlayersCount }} unmatched players</span>
                        @else
                            <span>No unmatched players</span>
                        @endif
                    </div>
                    <a href="{{ route('admin.player-triage') }}" class="text-indigo-600 font-semibold">Go to Triage</a>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Scheduler</h3>
                <div class="space-y-2">
                    @foreach($events as $event)
                        <div class="border rounded-lg p-4">
                            <div class="font-semibold">{{ $event['command'] }}</div>
                            <div class="text-sm text-gray-600">Cron: {{ $event['expression'] }}</div>
                            <div class="text-sm text-gray-600">Last run: {{ $event['last'] ?? 'Unknown' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
