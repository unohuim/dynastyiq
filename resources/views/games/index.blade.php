<x-app-layout>
    <main
        class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8"
        data-games-index-page
        data-payload-url="{{ route('games.payload') }}"
    >
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-normal text-gray-950">NHL Games</h1>
                <p class="mt-1 text-sm text-gray-600" data-games-description>
                    Current anticipated lineups for {{ \Illuminate\Support\Carbon::parse($meta['date'])->format('l, F j, Y') }}.
                </p>
            </div>
            <div class="flex items-end gap-2">
                <button type="button" data-games-previous aria-label="Previous game date" class="flex size-10 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 14.78a.75.75 0 0 1-1.06 0l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 1 1 1.06 1.06L8.06 10l3.72 3.72a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd" /></svg>
                </button>
                <div>
                    <label for="game-date" class="block text-sm font-medium text-gray-700">Game date</label>
                    <input id="game-date" data-games-date type="date" value="{{ $meta['date'] }}" class="mt-1 block h-10 cursor-pointer rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button type="button" data-games-next aria-label="Next game date" class="flex size-10 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 1 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </button>
            </div>
        </div>

        <p data-games-status class="sr-only" role="status" aria-live="polite"></p>

        <div class="grid gap-5 lg:grid-cols-2" data-games-list>
            @forelse ($games as $game)
                <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <header class="border-b border-gray-100 px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-950">
                                    {{ $game['away']['team_abbrev'] ?? 'TBD' }} at {{ $game['home']['team_abbrev'] ?? 'TBD' }}
                                </h2>
                                <p class="mt-1 text-sm text-gray-600">
                                    @if ($game['start_time_utc'])
                                        <time datetime="{{ $game['start_time_utc'] }}" data-local-datetime>
                                            {{ \Illuminate\Support\Carbon::parse($game['start_time_utc'])->utc()->format('D, F j, Y, g:i A \U\T\C') }}
                                        </time>
                                    @else
                                        Start time unavailable
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                @if ($game['game_state_label'])
                                    <span @class([
                                        'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold',
                                        'border-red-200 bg-red-50 text-red-700' => ! in_array($game['game_state'], ['FUT', 'PRE', 'FINAL'], true),
                                        'border-emerald-200 bg-emerald-50 text-emerald-700' => $game['game_state'] === 'FINAL',
                                        'border-indigo-200 bg-indigo-50 text-indigo-700' => in_array($game['game_state'], ['FUT', 'PRE'], true),
                                    ])>{{ $game['game_state_label'] }}</span>
                                @endif
                                <span class="text-xs font-medium text-gray-400">#{{ $game['nhl_game_id'] }}</span>
                            </div>
                        </div>
                    </header>

                    <div class="divide-y divide-gray-100">
                        @foreach (['away' => 'Away', 'home' => 'Home'] as $side => $sideLabel)
                            @php
                                $team = $game[$side];
                                $lineup = $team['lineup'];
                                $status = $lineup['evidence_status'] ?? 'not_reported';
                            @endphp
                            <section class="flex items-center gap-4 px-5 py-4">
                                <div class="flex size-12 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50">
                                    @if ($team['team_logo'])
                                        <img src="{{ $team['team_logo'] }}" alt="{{ $team['team_abbrev'] }} logo" class="size-9 object-contain">
                                    @else
                                        <span class="text-xs font-semibold text-gray-600">{{ $team['team_abbrev'] ?? 'TBD' }}</span>
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $sideLabel }} · {{ $team['team_abbrev'] ?? 'TBD' }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <span @class([
                                            'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold',
                                            'border-gray-200 bg-gray-50 text-gray-600' => $status === 'not_reported',
                                            'border-amber-200 bg-amber-50 text-amber-700' => $status === 'reported',
                                            'border-sky-200 bg-sky-50 text-sky-700' => $status === 'corroborated',
                                            'border-emerald-200 bg-emerald-50 text-emerald-700' => in_array($status, ['official', 'strongly_corroborated'], true),
                                        ])>{{ str($status)->replace('_', ' ')->headline() }}</span>
                                        @if ($lineup)
                                            <span class="text-xs text-gray-500">{{ $lineup['source_count'] }} {{ \Illuminate\Support\Str::plural('source', $lineup['source_count']) }}</span>
                                        @endif
                                    </div>
                                    @if ($lineup && $lineup['last_observed_at'])
                                        <p class="mt-2 text-xs text-gray-500">
                                            Updated <time datetime="{{ $lineup['last_observed_at'] }}" data-local-datetime>{{ \Illuminate\Support\Carbon::parse($lineup['last_observed_at'])->utc()->format('D, F j, Y, g:i A \U\T\C') }}</time>
                                        </p>
                                    @endif
                                </div>
                                @if ($team['starting_goalie'])
                                    <div class="ml-auto flex shrink-0 items-center gap-3">
                                        <div class="text-right">
                                            <p class="max-w-36 truncate text-sm font-semibold text-gray-950">{{ $team['starting_goalie']['name'] }}</p>
                                            <span @class([
                                                'mt-1 inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold',
                                                'border-emerald-200 bg-emerald-50 text-emerald-700' => $team['starting_goalie']['status'] === 'confirmed',
                                                'border-sky-200 bg-sky-50 text-sky-700' => $team['starting_goalie']['status'] === 'expected',
                                                'border-gray-200 bg-gray-50 text-gray-600' => $team['starting_goalie']['status'] === 'projected',
                                            ])>{{ str($team['starting_goalie']['status'])->headline() }}</span>
                                        </div>
                                        <div class="flex size-12 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-gray-100">
                                            @if ($team['starting_goalie']['avatar_url'])
                                                <img src="{{ $team['starting_goalie']['avatar_url'] }}" alt="{{ $team['starting_goalie']['name'] }}" class="size-full object-cover" loading="lazy">
                                            @else
                                                <span class="text-xs font-semibold text-gray-500">G</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                                @if ($team['score'] !== null)
                                    <span class="shrink-0 text-2xl font-semibold tabular-nums text-gray-950">{{ $team['score'] }}</span>
                                @endif
                            </section>
                        @endforeach
                    </div>

                    <footer class="border-t border-gray-100 bg-gray-50 px-5 py-3 text-right">
                        <a href="{{ route('games.show', ['nhlGameId' => $game['nhl_game_id']]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500">
                            View current lineups <span aria-hidden="true">&rarr;</span>
                        </a>
                    </footer>
                </article>
            @empty
                <div class="col-span-full rounded-lg border border-gray-200 bg-white px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-900">No NHL games are scheduled for this date.</p>
                    <a href="{{ route('games.index') }}" class="mt-2 inline-block text-sm font-semibold text-indigo-600 hover:text-indigo-500">Return to today</a>
                </div>
            @endforelse
        </div>
    </main>
</x-app-layout>
