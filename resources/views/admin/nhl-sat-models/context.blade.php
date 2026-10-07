<x-app-layout>
    <div class="min-h-screen bg-gray-50 py-6" data-admin-sat-model-context>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-500">
                        <a href="{{ route('admin.nhl-sat-models.index') }}" class="transition-colors hover:text-gray-950">SAT Models</a>
                        <span>/</span><span>Context</span>
                    </div>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950">Prediction Context</h1>
                    <p class="mt-1 max-w-3xl text-sm text-gray-600">Build pregame-only evidence for venue, schedule, travel, opponent history, momentum, Corsi, PDO, IPP, and strength splits. This never changes predictions.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.nhl-sat-models.context.effects') }}" class="inline-flex min-h-10 items-center rounded-md bg-gray-950 px-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">Context effects</a>
                    <a href="{{ route('admin.nhl-sat-models.index') }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50">Models</a>
                </div>
            </div>

            @if(session('status'))<div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-5 py-4"><h2 class="text-sm font-semibold text-gray-950">Season coverage</h2><p class="mt-1 text-sm text-gray-500">Select completed seasons. Work is stored by game and dispatched in small date-ordered pages.</p></div>
                <form method="POST" action="{{ route('admin.nhl-sat-models.context.store') }}" class="px-5 py-4" data-pregame-context-build-form>
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse($seasons as $season)
                            <label class="flex min-h-16 items-center gap-3 rounded-md border border-gray-200 px-3 transition-colors hover:bg-gray-50"><input type="checkbox" name="season_ids[]" value="{{ $season->season_id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"><span><span class="block text-sm font-semibold text-gray-900">{{ $season->season_id }}</span><span class="block text-xs text-gray-500">{{ number_format($season->completed_games) }} games · {{ number_format($season->player_summary_count) }} player summaries</span></span></label>
                        @empty
                            <p class="text-sm text-gray-500">No completed regular-season games are available.</p>
                        @endforelse
                    </div>
                    <div class="mt-4 flex items-center gap-3"><button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-gray-950 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800">Backfill context</button><p class="text-xs text-gray-500">No player-level jobs are queued.</p></div>
                </form>
            </section>

            <section class="mt-6 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"><div class="border-b border-gray-200 px-5 py-4"><h2 class="text-sm font-semibold text-gray-950">Recent context runs</h2></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-left text-xs"><thead class="bg-gray-50 text-[11px] font-semibold uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-3">Seasons</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Progress</th><th class="px-4 py-3">Excluded</th><th class="px-4 py-3">Current date</th><th class="px-4 py-3">Updated</th></tr></thead><tbody class="divide-y divide-gray-100">@forelse($runs as $run)<tr><td class="px-4 py-3 font-medium text-gray-900">{{ implode(', ', $run->season_ids) }}</td><td class="px-4 py-3 text-gray-700">{{ str($run->status)->replace('_', ' ')->title() }}</td><td class="px-4 py-3 text-gray-700">{{ number_format($run->completed_games) }} / {{ number_format($run->total_games) }}</td><td class="px-4 py-3 text-gray-700">{{ number_format($run->blocked_games) }}</td><td class="px-4 py-3 text-gray-700">{{ $run->current_game_date?->toDateString() ?? '—' }}</td><td class="px-4 py-3 text-gray-500">{{ $run->updated_at?->diffForHumans() }}</td></tr>@empty<tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">No context builds yet.</td></tr>@endforelse</tbody></table></div></section>
        </div>
    </div>
</x-app-layout>
