<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Scheduler</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @foreach($events as $event)
                <div class="bg-white shadow sm:rounded-lg p-6">
                    <div class="font-semibold">{{ $event['command'] }}</div>
                    <div class="text-sm text-gray-600">Expression: {{ $event['expression'] }}</div>
                    <div class="text-sm text-gray-600">Next run: {{ $event['next'] }}</div>
                    <div class="text-sm text-gray-600">Previous run: {{ $event['last'] ?? 'Unknown' }}</div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
