<x-app-layout>
    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8" data-lineups-page>
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-normal text-gray-950">NHL Lineups</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Current anticipated lineups for {{ \Illuminate\Support\Carbon::parse($meta['date'])->format('l, F j, Y') }}.
                </p>
            </div>
            <form method="GET" action="{{ route('lineups.index') }}" class="flex items-end gap-2" x-data="{ lineupDate: @js($meta['date']) }">
                <x-ui.date-field id="lineup-date" label="Game date" model="lineupDate" name="date" class="h-10" />
                <button class="h-10 rounded-md bg-gray-950 px-4 text-sm font-medium text-white" type="submit">View</button>
            </form>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
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
                            <span class="shrink-0 text-xs font-medium text-gray-400">#{{ $game['nhl_game_id'] }}</span>
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
                                            'border-emerald-200 bg-emerald-50 text-emerald-700' => $status === 'strongly_corroborated',
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
                            </section>
                        @endforeach
                    </div>

                    <footer class="border-t border-gray-100 bg-gray-50 px-5 py-3 text-right">
                        <a href="{{ route('lineups.show', ['nhlGameId' => $game['nhl_game_id']]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500">
                            View current lineups <span aria-hidden="true">&rarr;</span>
                        </a>
                    </footer>
                </article>
            @empty
                <div class="col-span-full rounded-lg border border-gray-200 bg-white px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-900">No NHL games are scheduled for this date.</p>
                    <a href="{{ route('lineups.index') }}" class="mt-2 inline-block text-sm font-semibold text-indigo-600 hover:text-indigo-500">Return to today</a>
                </div>
            @endforelse
        </div>
    </main>
</x-app-layout>
