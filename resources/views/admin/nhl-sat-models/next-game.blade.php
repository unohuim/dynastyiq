<x-app-layout>
    <main class="min-h-screen bg-gray-50 py-6" data-page="nhl-next-game-evaluation">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <header><a class="text-sm text-gray-600 underline" href="{{ route('admin.nhl-sat-models.index') }}">SAT Models</a><h1 class="mt-2 text-2xl font-semibold tracking-tight">Next-game evaluation</h1><p class="mt-2 text-sm text-gray-600">Does recent history improve individual shot-bucket SAT/60? This never changes live predictions.</p></header>
            <p hidden role="alert" data-evaluation-error class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"></p>
            <form method="POST" action="{{ route('admin.nhl-sat-models.next-game.store') }}" data-evaluation-action class="flex flex-wrap items-end gap-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                @csrf
                <label class="flex-1 text-sm font-medium">Model and test season<select name="model_run_id" required class="mt-1 block w-full rounded-md border-gray-300">@foreach($models as $model)<option value="{{ $model->id }}">{{ $model->name ?? 'Model '.$model->id }} · {{ $model->target_season_id }}</option>@endforeach</select></label>
                <button @disabled($models->isEmpty()) class="min-h-10 rounded-md bg-gray-950 px-4 text-sm font-semibold text-white transition-colors hover:bg-gray-800 disabled:opacity-50">Start evaluation</button>
            </form>
            <form method="GET" data-evaluation-filters class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <label class="text-sm">Evaluation<select name="evaluation" class="mt-1 block w-full rounded-md border-gray-300" data-evaluation-select>@foreach($runs as $run)<option value="{{ $run->id }}" @selected($evaluation?->id === $run->id)>#{{ $run->id }} · {{ $run->season_id }}</option>@endforeach</select></label>
                <label class="text-sm">Strength<select name="strength" class="mt-1 block w-full rounded-md border-gray-300">@foreach(['ev' => 'EV', 'pp' => 'PP', 'pk' => 'PK', 'all' => 'All'] as $value => $label)<option value="{{ $value }}" @selected($strength === $value)>{{ $label }}</option>@endforeach</select></label>
                <label class="text-sm">Player search<input name="q" value="{{ $filters['q'] ?? '' }}" class="mt-1 block w-full rounded-md border-gray-300" maxlength="100"></label>
                <label class="text-sm">NHL team ID<input name="team" type="number" min="1" value="{{ $filters['team'] ?? '' }}" class="mt-1 block w-full rounded-md border-gray-300"></label>
                <label class="text-sm">Player/detail method<select name="method" class="mt-1 block w-full rounded-md border-gray-300">@foreach($methods as $value => $label)<option value="{{ $value }}" @selected($method === $value)>{{ $label }}</option>@endforeach</select></label>
                <button class="mt-auto min-h-10 rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold transition-colors hover:bg-gray-100">Apply filters</button>
            </form>
            <div data-evaluation-content>@include('admin.nhl-sat-models.next-game-results')</div>
        </div>
    </main>
</x-app-layout>
