<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Platform Initialization</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if($initialized)
                <div class="bg-green-100 p-4 rounded">Platform already initialized.</div>
            @else
                <div class="bg-white shadow sm:rounded-lg p-6 space-y-4">
                    <p class="text-gray-700">Use this one-time process to bring the platform online.</p>
                    <form method="POST" action="{{ route('admin.initialize.run') }}">
                        @csrf
                        <x-primary-button type="submit">Bring Platform Online</x-primary-button>
                    </form>

                    @isset($batch)
                        <div class="mt-4">
                            <h3 class="font-semibold">Batch Status</h3>
                            <p>ID: {{ $batch->id }}</p>
                            <p>Status: {{ $batch->progress() }}% ({{ $batch->state }})</p>
                            <p>Processed: {{ $batch->processedJobs() }} / {{ $batch->totalJobs }} jobs</p>
                            @if($batch->failedJobs->isNotEmpty())
                                <div class="text-red-600">Failed Jobs: {{ $batch->failedJobs->count() }}</div>
                            @endif
                        </div>
                    @endisset
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
