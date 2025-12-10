<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
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

            <div class="rounded-lg border border-indigo-200 shadow-lg">
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
                >
                    <div class="space-y-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-gray-800 font-semibold" x-show="!initialized">Bring platform online</div>
                                <div class="text-sm text-gray-600" x-show="!initialized">
                                    Provision the platform before running imports.
                                </div>
                                <div class="text-gray-800 font-semibold" x-show="initialized">Initialization complete</div>
                                <div class="text-sm text-gray-600" x-show="initialized">
                                    Tools remain available if you need to rerun setup.
                                </div>
                            </div>
                            <div class="flex items-center space-x-3">
                                <x-primary-button
                                    type="button"
                                    x-on:click="startInitialization"
                                    x-bind:disabled="initializing || initialized"
                                    x-text="initializing ? 'Initializing...' : (initialized ? 'Initialized' : 'Bring Online')"
                                ></x-primary-button>
                                <span class="text-sm text-gray-600" x-show="initializing" x-text="statusLabel"></span>
                            </div>
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
            </div>
        </div>
    </div>
</div>
