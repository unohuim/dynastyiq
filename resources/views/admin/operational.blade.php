<div class="py-12">
    <div
        class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6"
        x-data="adminHub({
            imports: @js($imports),
            hasPlayers: {{ $hasPlayers ? 'true' : 'false' }},
            hasFantrax: {{ $hasFantraxPlayers ? 'true' : 'false' }},
        })"
        x-init="init()"
        x-cloak
    >
        <div class="bg-white shadow rounded-lg">
            <div class="border-b px-6 pt-4 flex items-center space-x-4">
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    @click="setTab('triage')"
                    :class="activeTab === 'triage' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Triage
                </button>
                <button
                    type="button"
                    class="pb-3 text-sm font-semibold border-b-2"
                    @click="setTab('imports')"
                    :class="activeTab === 'imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Data Imports
                </button>
            </div>

            <div class="p-6">
                <div x-show="activeTab === 'triage'" x-cloak>
                    @include('admin.player-triage', array_merge($triage, ['embedded' => true]))
                </div>

                <div x-show="activeTab === 'imports'" x-cloak>
                    <div class="space-y-4">
                        @foreach($imports as $import)
                            <div class="border rounded-lg p-4 space-y-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-800">{{ $import['label'] }}</div>
                                        <div class="text-sm text-gray-600">
                                            Last run:
                                            <span x-text="formatLastRun('{{ $import['key'] }}')"></span>
                                        </div>
                                    </div>
                                    <x-primary-button
                                        type="button"
                                        x-on:click="startImport('{{ $import['key'] }}')"
                                        x-bind:disabled="streams['{{ $import['key'] }}']?.running === true"
                                    >
                                        Run Now
                                    </x-primary-button>
                                </div>

                                <div
                                    class="space-y-2"
                                    x-show="shouldShowImportProgress('{{ $import['key'] }}')"
                                    x-cloak
                                >
                                    <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                        <span x-text="importProgressText('{{ $import['key'] }}')"></span>
                                        <span x-text="`${importProgressPercentage('{{ $import['key'] }}')}%`"></span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                                        <div
                                            class="h-full rounded-full bg-indigo-600 transition-all duration-300"
                                            x-bind:style="`width: ${importProgressPercentage('{{ $import['key'] }}')}%`"
                                        ></div>
                                    </div>
                                    <div class="text-xs text-gray-500" x-text="importProgressDetailText('{{ $import['key'] }}')"></div>
                                </div>

                                <div class="space-y-2">
                                    <button
                                        type="button"
                                        class="text-sm text-indigo-600 font-semibold"
                                        x-on:click="toggleStream('{{ $import['key'] }}')"
                                    >
                                        <span x-text="streams['{{ $import['key'] }}']?.open ? 'Hide Output' : 'Show Output'"></span>
                                    </button>
                                    <div
                                        class="bg-black text-green-200 font-mono text-xs rounded-md p-3 h-40 overflow-y-auto"
                                        x-show="streams['{{ $import['key'] }}']?.open"
                                    >
                                        <template x-if="(streams['{{ $import['key'] }}']?.messages?.length ?? 0) === 0">
                                            <div class="text-gray-400">Awaiting output...</div>
                                        </template>
                                        <template x-for="(entry, idx) in streams['{{ $import['key'] }}']?.messages" :key="idx">
                                            <div class="whitespace-pre-wrap" x-text="entry.message"></div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
