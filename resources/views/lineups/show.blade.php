<x-app-layout>
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8" data-lineups-page>
        <div class="mb-6">
            <a href="{{ route('lineups.index', ['date' => $game['game_date']]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500">&larr; All games</a>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-normal text-gray-950">
                        {{ $game['away']['team_abbrev'] ?? 'TBD' }} at {{ $game['home']['team_abbrev'] ?? 'TBD' }} lineups
                    </h1>
                    <p class="mt-1 text-sm text-gray-600">
                        @if ($game['start_time_utc'])
                            <time datetime="{{ $game['start_time_utc'] }}" data-local-datetime>{{ \Illuminate\Support\Carbon::parse($game['start_time_utc'])->utc()->format('D, F j, Y, g:i A \U\T\C') }}</time>
                        @else
                            {{ \Illuminate\Support\Carbon::parse($game['game_date'])->format('l, F j, Y') }} · Start time unavailable
                        @endif
                    </p>
                </div>
                <span class="text-xs font-medium text-gray-400">NHL game #{{ $game['nhl_game_id'] }}</span>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach (['away' => 'Away', 'home' => 'Home'] as $side => $sideLabel)
                @php
                    $team = $game[$side];
                    $lineup = $team['lineup'];
                    $status = $lineup['evidence_status'] ?? 'not_reported';
                    $players = collect($lineup['players'] ?? []);
                @endphp
                <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <header class="flex items-center gap-4 border-b border-gray-100 px-5 py-4">
                        <div class="flex size-14 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50">
                            @if ($team['team_logo'])
                                <img src="{{ $team['team_logo'] }}" alt="{{ $team['team_abbrev'] }} logo" class="size-11 object-contain">
                            @else
                                <span class="text-sm font-semibold text-gray-600">{{ $team['team_abbrev'] ?? 'TBD' }}</span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $sideLabel }}</p>
                            <h2 class="mt-1 text-xl font-semibold text-gray-950">{{ $team['team_abbrev'] ?? 'TBD' }}</h2>
                        </div>
                        <span @class([
                            'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold',
                            'border-gray-200 bg-gray-50 text-gray-600' => $status === 'not_reported',
                            'border-amber-200 bg-amber-50 text-amber-700' => $status === 'reported',
                            'border-sky-200 bg-sky-50 text-sky-700' => $status === 'corroborated',
                            'border-emerald-200 bg-emerald-50 text-emerald-700' => in_array($status, ['official', 'strongly_corroborated'], true),
                        ])>{{ str($status)->replace('_', ' ')->headline() }}</span>
                    </header>

                    @if ($lineup)
                        <div class="space-y-6 px-5 py-5">
                            <section>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Forwards</h3>
                                <div class="mt-3 space-y-2">
                                    @foreach (range(1, 4) as $lineNumber)
                                        <div class="grid grid-cols-[2rem_1fr] gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                                            <span class="text-xs font-semibold text-gray-500">F{{ $lineNumber }}</span>
                                            <div class="grid gap-1 sm:grid-cols-3">
                                                @forelse ($players->where('line_key', 'F' . $lineNumber)->sortBy('slot_index') as $player)
                                                    <span class="text-sm font-medium text-gray-900">
                                                        {{ $player['player_name'] }}
                                                        @if ($player['resolution_status'] === 'unresolved')<span class="text-xs font-normal text-amber-700">Unresolved</span>@endif
                                                    </span>
                                                @empty
                                                    <span class="text-sm text-gray-400">Not reported</span>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            <section>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Defence</h3>
                                <div class="mt-3 space-y-2">
                                    @foreach (range(1, 3) as $pairNumber)
                                        <div class="grid grid-cols-[2rem_1fr] gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
                                            <span class="text-xs font-semibold text-gray-500">D{{ $pairNumber }}</span>
                                            <div class="grid gap-1 sm:grid-cols-2">
                                                @forelse ($players->where('line_key', 'D' . $pairNumber)->sortBy('slot_index') as $player)
                                                    <span class="text-sm font-medium text-gray-900">
                                                        {{ $player['player_name'] }}
                                                        @if ($player['resolution_status'] === 'unresolved')<span class="text-xs font-normal text-amber-700">Unresolved</span>@endif
                                                    </span>
                                                @empty
                                                    <span class="text-sm text-gray-400">Not reported</span>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>

                            @foreach (['G' => 'Goalies', 'SCR' => 'Scratches'] as $lineKey => $label)
                                <section>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</h3>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @forelse ($players->where('line_key', $lineKey)->sortBy('slot_index') as $player)
                                            <span class="rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-sm font-medium text-gray-900">
                                                {{ $player['player_name'] }}
                                                @if ($player['resolution_status'] === 'unresolved')<span class="ml-1 text-xs font-normal text-amber-700">Unresolved</span>@endif
                                            </span>
                                        @empty
                                            <span class="text-sm text-gray-400">Not reported</span>
                                        @endforelse
                                    </div>
                                </section>
                            @endforeach
                        </div>

                        <footer class="border-t border-gray-100 bg-gray-50 px-5 py-4">
                            <p class="text-xs text-gray-600">
                                {{ $lineup['source_count'] }} {{ \Illuminate\Support\Str::plural('source', $lineup['source_count']) }} · updated
                                <time datetime="{{ $lineup['last_observed_at'] }}" data-local-datetime>{{ \Illuminate\Support\Carbon::parse($lineup['last_observed_at'])->utc()->format('D, F j, Y, g:i A \U\T\C') }}</time>
                            </p>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2">
                                @foreach ($lineup['sources'] as $source)
                                    <a href="{{ $source['post_url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500">
                                        {{ $source['name'] }}@if ($source['handle']) · {{ '@' . ltrim($source['handle'], '@') }}@endif
                                    </a>
                                @endforeach
                            </div>
                        </footer>
                    @else
                        <div class="px-5 py-12 text-center">
                            <p class="text-sm font-medium text-gray-900">No anticipated lineup has been reported for {{ $team['team_abbrev'] ?? 'this team' }}.</p>
                            <p class="mt-1 text-sm text-gray-500">The latest current projection will appear here after evidence is imported.</p>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </main>
</x-app-layout>
