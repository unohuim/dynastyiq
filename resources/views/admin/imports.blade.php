<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">Imports</h2>
            <p class="text-sm text-gray-600">Manual admin controls for supported data import workflows.</p>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="border border-gray-200 bg-white">
                <div class="border-b border-gray-200 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-900">Import Workflows</h3>
                    <p class="mt-1 text-sm text-gray-600">Run the current import commands without changing queue or dispatch behavior.</p>
                </div>

                <div class="divide-y divide-gray-100">
                    @foreach($imports as $import)
                        <div class="grid gap-4 px-5 py-4 lg:grid-cols-[1fr_auto] lg:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-base font-semibold text-gray-900">{{ $import['label'] }}</h4>
                                    @if(!empty($import['batch']))
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                            {{ $import['batch']->state }}
                                        </span>
                                    @endif
                                </div>

                                <dl class="mt-3 grid gap-3 text-sm text-gray-600 sm:grid-cols-3">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Last run</dt>
                                        <dd class="mt-1 text-gray-900">{{ $import['last_run'] ?? 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Duration</dt>
                                        <dd class="mt-1 text-gray-900">{{ $import['duration'] ?? 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Workload</dt>
                                        <dd class="mt-1 text-gray-900">{{ $import['counts'] ?? 'N/A' }}</dd>
                                    </div>
                                </dl>

                                @if(!empty($import['batch']))
                                    <div class="mt-3 text-xs text-gray-500">Batch {{ $import['batch']->id }}</div>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-2 lg:justify-end">
                                @if(!empty($import['run_url']))
                                    <form method="POST" action="{{ $import['run_url'] }}">
                                        @csrf
                                        <x-primary-button type="submit" data-admin-import-button>Run Now</x-primary-button>
                                    </form>
                                @else
                                    <x-secondary-button type="button" disabled>Coming Soon</x-secondary-button>
                                @endif

                                @if($import['can_rerun_failed'] && !empty($import['run_url']))
                                    <form method="POST" action="{{ route('admin.imports.retry', ['key' => $import['key']]) }}">
                                        @csrf
                                        <x-secondary-button type="submit" data-admin-import-button>Retry failed</x-secondary-button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-4 border border-gray-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Play-by-Play</h3>
                        <p class="mt-1 text-sm text-gray-600">Open the existing play-by-play import workflow.</p>
                    </div>
                    <a href="{{ url('/admin/pbp-import') }}" class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Open workflow
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
