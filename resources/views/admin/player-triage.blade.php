<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Player Triage</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @if($records->isEmpty())
                <div class="bg-white p-6 shadow sm:rounded-lg">No unresolved platform players.</div>
            @else
                <div class="space-y-4">
                    @foreach($records as $record)
                        <div class="bg-white p-6 shadow sm:rounded-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-semibold">{{ $record['platform'] }} — {{ $record['name'] }}</div>
                                    <div class="text-sm text-gray-600">Suggested: {{ $record['suggested']->name ?? 'None' }}</div>
                                </div>
                                <div class="space-x-2">
                                    <form method="POST" action="{{ route('admin.player-triage.link', ['platform' => $record['platform'], 'id' => $record['id']]) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="player_id" value="{{ $record['suggested']->id ?? '' }}">
                                        <x-primary-button {{ empty($record['suggested']) ? 'disabled' : '' }}>Link</x-primary-button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.player-triage.variant', ['platform' => $record['platform'], 'id' => $record['id']]) }}" class="inline-flex items-center space-x-2">
                                        @csrf
                                        <input name="variant" class="border rounded px-2 py-1" placeholder="First-name variant" />
                                        <x-secondary-button>Add first-name variant</x-secondary-button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.player-triage.defer', ['platform' => $record['platform'], 'id' => $record['id']]) }}" class="inline">
                                        @csrf
                                        <x-secondary-button>Ignore</x-secondary-button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
