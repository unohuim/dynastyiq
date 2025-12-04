<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Imports</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @foreach($imports as $import)
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-lg">{{ $import['label'] }}</h3>
                            <p class="text-sm text-gray-600">Last run: {{ $import['last_run'] ?? 'N/A' }}</p>
                            <p class="text-sm text-gray-600">Duration: {{ $import['duration'] ?? 'N/A' }}</p>
                            <p class="text-sm text-gray-600">Counts: {{ $import['counts'] ?? 'N/A' }}</p>
                        </div>
                        <div class="space-x-2">
                            <form method="POST" action="{{ route('admin.imports.run', ['key' => $import['key']]) }}" class="inline">
                                @csrf
                                <x-primary-button type="submit">Run Now</x-primary-button>
                            </form>
                            @if($import['can_rerun_failed'])
                                <form method="POST" action="{{ route('admin.imports.retry', ['key' => $import['key']]) }}" class="inline">
                                    @csrf
                                    <x-secondary-button type="submit">Re-run failed</x-secondary-button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @if(!empty($import['batch']))
                        <div class="mt-4 text-sm text-gray-700">Batch: {{ $import['batch']->id }} ({{ $import['batch']->state }})</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
