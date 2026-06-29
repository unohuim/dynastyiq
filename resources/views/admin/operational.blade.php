<div class="py-6">
    <div
        class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
        x-data="adminHub({
            imports: @js($imports),
            hasPlayers: {{ $hasPlayers ? 'true' : 'false' }},
            hasFantrax: {{ $hasFantraxPlayers ? 'true' : 'false' }},
            triageUrl: @js(route('admin.player-triage', ['admin_panel' => 1])),
        })"
        x-init="init()"
        x-cloak
    >
        <div class="border-b border-gray-200">
            <div class="flex items-center gap-6">
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('imports')"
                    :class="activeTab === 'imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Data Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('triage')"
                    :class="activeTab === 'triage' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Triage
                </button>
            </div>
        </div>

        <div class="py-4">
            <div x-show="activeTab === 'imports'" x-cloak>
                <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
                    @foreach($imports as $import)
                        <div class="px-4 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900">{{ $import['label'] }}</div>
                                    <div class="mt-1 text-sm text-gray-600">
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
                                class="mt-4 space-y-2"
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

                            <div class="mt-4 space-y-2">
                                <button
                                    type="button"
                                    class="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
                                    x-on:click="toggleStream('{{ $import['key'] }}')"
                                >
                                    <span x-text="streams['{{ $import['key'] }}']?.open ? 'Hide Output' : 'Show Output'"></span>
                                </button>
                                <div
                                    class="h-40 overflow-y-auto bg-gray-950 p-3 font-mono text-xs text-green-200"
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

            <div x-show="activeTab === 'triage'" x-cloak>
                <div
                    x-ref="triageMount"
                    data-admin-triage-mount
                    class="min-h-64"
                >
                    <div
                        x-show="triageLoading"
                        class="border-y border-gray-200 bg-white px-4 py-10"
                    >
                        <div class="mx-auto max-w-3xl space-y-4">
                            <div class="h-4 w-32 animate-pulse rounded bg-gray-200"></div>
                            <div class="space-y-3">
                                <div class="h-16 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-16 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-16 animate-pulse rounded bg-gray-100"></div>
                            </div>
                        </div>
                    </div>

                    <div
                        x-show="triageError"
                        class="border-y border-gray-200 bg-white px-4 py-6 text-sm text-red-600"
                        x-text="triageError"
                    ></div>
                </div>
            </div>
        </div>
    </div>
</div>
