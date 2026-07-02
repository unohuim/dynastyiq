<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1">
            <h2 class="text-xl font-semibold leading-tight text-gray-900">Validation {{ $validation->nhl_game_id }}</h2>
            <p class="text-sm text-gray-600">{{ str_replace('_', ' ', $validation->status) }} with {{ $validation->mismatch_count }} deltas.</p>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @if(session('status'))
                <div class="mb-4 border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3 bg-white px-5 py-4 shadow-sm">
                <div>
                    <div class="text-sm font-semibold text-gray-900">
                        {{ optional($validation->game)->away_team_abbrev ?? 'Away' }}
                        at
                        {{ optional($validation->game)->home_team_abbrev ?? 'Home' }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ optional($validation->game)->game_date ?? 'No game date' }} · checked {{ optional($validation->checked_at)->toDateTimeString() ?? 'N/A' }}
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.nhl-validations.rerun-summary', $validation) }}">
                        @csrf
                        <x-secondary-button type="submit">Rerun summary</x-secondary-button>
                    </form>

                    <form method="POST" action="{{ route('admin.nhl-validations.rerun-boxscore', $validation) }}">
                        @csrf
                        <x-secondary-button type="submit">Rerun boxscore</x-secondary-button>
                    </form>

                    <form method="POST" action="{{ route('admin.nhl-validations.rebuild-game', $validation) }}">
                        @csrf
                        <x-secondary-button type="submit">Rebuild game imports</x-secondary-button>
                    </form>

                    <form method="POST" action="{{ route('admin.nhl-validations.rerun', $validation) }}">
                        @csrf
                        <x-secondary-button type="submit">Rerun validation</x-secondary-button>
                    </form>

                    @if($validation->status !== \App\Models\NhlGameValidation::STATUS_ACCEPTED_EXCEPTION)
                        <form method="POST" action="{{ route('admin.nhl-validations.accept-exception', $validation) }}">
                            @csrf
                            <x-primary-button type="submit">Accept exception</x-primary-button>
                        </form>
                    @endif
                </div>
            </div>

            @include('admin.nhl-validations._detail-content', ['embedded' => false])

            <div class="mt-4">
                <a href="{{ route('admin.nhl-validations.index') }}" class="text-sm font-medium text-gray-900 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-900">
                    Back to validations
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
