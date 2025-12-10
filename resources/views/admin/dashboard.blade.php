<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Admin Control Panel
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-card-section title="System Status" title-class="text-lg font-semibold" is-accordian="true">
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
            </x-card-section>

            <x-card-section
                title="Initialization"
                title-class="text-lg font-semibold"
                is-accordian="true"
                x-data="adminInitialization({
                    initialized: {{ $initialized ? 'true' : 'false' }},
                    endpoints: {
                        start: '{{ route('admin.initialize.run') }}',
                        status: '{{ route('admin.initialize.index') }}',
                    }
                })"
                x-init="bootstrap()"
                x-cloak
            >
                <div class="flex flex-col md:flex-row md:items-start md:space-x-6 space-y-4 md:space-y-0">
                    <div class="flex items-center space-x-3">
                        <x-primary-button
                            type="button"
                            x-on:click="startInitialization"
                            x-bind:disabled="initializing || initialized"
                            x-text="initializing ? 'Initializing...' : (initialized ? 'Initialized' : 'Bring Platform Online')"
                        ></x-primary-button>
                        <span class="text-sm text-gray-600" x-show="initializing" x-text="statusLabel"></span>
                    </div>

                    <div class="flex-1" x-show="batchId">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-gray-700">
                            <div>
                                <div class="font-semibold">Batch ID</div>
                                <div class="break-all" x-text="batchId"></div>
                            </div>
                            <div>
                                <div class="font-semibold">Progress</div>
                                <div><span x-text="progress"></span>%</div>
                            </div>
                            <div>
                                <div class="font-semibold">Processed jobs</div>
                                <div x-text="processed"></div>
                            </div>
                            <div>
                                <div class="font-semibold">Failed jobs</div>
                                <div x-text="failed"></div>
                            </div>
                        </div>

                        <div class="w-full bg-gray-200 rounded-full h-2.5 mt-3">
                            <div
                                class="bg-indigo-600 h-2.5 rounded-full"
                                :style="`width: ${progress}%`"
                            ></div>
                        </div>
                    </div>
                </div>
            </x-card-section>

            <x-card-section title="Imports" title-class="text-lg font-semibold" is-accordian="true">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($imports as $import)
                        <div class="border rounded-lg p-4 flex items-center justify-between">
                            <div>
                                <div class="font-semibold">{{ $import['label'] }}</div>
                                <div class="text-sm text-gray-600">Last run: {{ $import['last_run']?->toDateTimeString() ?? 'N/A' }}</div>
                            </div>
                            <form method="POST" action="{{ route('admin.imports.run', ['key' => $import['key']]) }}">
                                @csrf
                                <x-primary-button data-admin-import-button>Run Now</x-primary-button>
                            </form>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    <a href="{{ url('/admin/pbp-import') }}" class="text-indigo-600 font-semibold">Play-by-Play →</a>
                </div>
            </x-card-section>

            <x-card-section title="Player Triage" title-class="text-lg font-semibold" is-accordian="true">
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
            </x-card-section>

            <x-card-section title="Scheduler" title-class="text-lg font-semibold" is-accordian="true">
                <div class="space-y-2">
                    @foreach($events as $event)
                        <div class="border rounded-lg p-4">
                            <div class="font-semibold">{{ $event['command'] }}</div>
                            <div class="text-sm text-gray-600">Cron: {{ $event['expression'] }}</div>
                            <div class="text-sm text-gray-600">Last run: {{ $event['last'] ?? 'Unknown' }}</div>
                        </div>
                    @endforeach
                </div>
            </x-card-section>
        </div>
    </div>
</x-app-layout>

