<x-app-layout>
    <main class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8" data-starting-goalies-page>
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-normal text-gray-950">Starting Goalies</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Latest projected and confirmed NHL starters for
                    {{ \Illuminate\Support\Carbon::parse($meta['date'])->format('l, F j, Y') }}.
                </p>
            </div>
            <form
                method="GET"
                action="{{ route('starting-goalies.index') }}"
                class="flex items-end gap-2"
                x-data="{ goalieDate: @js($meta['date']) }"
            >
                <x-ui.date-field
                    id="goalie-date"
                    label="Game date"
                    model="goalieDate"
                    name="date"
                    class="h-10"
                />
                <button class="h-10 rounded-md bg-gray-950 px-4 text-sm font-medium text-white" type="submit">View</button>
            </form>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            @forelse ($games as $game)
                <article class="overflow-hidden rounded-xl border border-sky-800 bg-slate-950 text-slate-100 shadow-sm">
                    <header class="flex flex-col gap-2 border-b border-sky-900 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-xl font-semibold tracking-tight text-white">
                            {{ $game['away_team_abbrev'] ?? 'TBD' }} at {{ $game['home_team_abbrev'] ?? 'TBD' }}
                        </h2>
                        <p class="text-sm text-sky-100">
                            @if ($game['start_time_utc'])
                                <time datetime="{{ $game['start_time_utc'] }}" data-local-datetime>
                                    {{ \Illuminate\Support\Carbon::parse($game['start_time_utc'])->utc()->format('D, F j, Y, g:i A \\U\\T\\C') }}
                                </time>
                            @else
                                {{ \Illuminate\Support\Carbon::parse($game['game_date'])->format('l, F j, Y') }} · Time unavailable
                            @endif
                        </p>
                    </header>

                    <div class="grid divide-y divide-sky-900 sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                        @foreach (['away' => 'Away', 'home' => 'Home'] as $side => $label)
                            @php
                                $goalie = $game[$side . '_goalie'];
                                $team = $game[$side . '_team_abbrev'] ?? 'TBD';
                                $logo = $game[$side . '_team_logo'] ?? null;
                                $stats = $goalie['season_stats'] ?? null;
                                $seasonLabel = $stats
                                    ? substr($stats['season_key'], 0, 4) . '–' . substr($stats['season_key'], 6, 2)
                                    : null;
                            @endphp
                            <section class="px-5 py-5">
                                <div class="flex items-start gap-4">
                                    <div class="flex size-14 shrink-0 items-center justify-center rounded-full border border-sky-900 bg-slate-900">
                                        @if ($logo)
                                            <img src="{{ $logo }}" alt="{{ $team }} logo" class="size-11 object-contain">
                                        @else
                                            <span class="text-sm font-semibold text-sky-200">{{ $team }}</span>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-semibold uppercase tracking-wider text-sky-300">
                                            {{ $label }} · {{ $team }}
                                        </p>
                                        <p class="mt-1 truncate text-lg font-semibold text-white">{{ $goalie['player_name'] ?? 'Starter not reported' }}</p>
                                        @if ($goalie)
                                            <span @class([
                                                'mt-2 inline-flex rounded-full border px-3 py-1 text-xs font-semibold',
                                                'border-emerald-400 bg-emerald-950 text-emerald-200' => $goalie['status'] === 'confirmed',
                                                'border-slate-600 bg-slate-800 text-slate-200' => $goalie['status'] === 'expected',
                                                'border-amber-600 bg-amber-950 text-amber-200' => ! in_array($goalie['status'], ['confirmed', 'expected'], true),
                                            ])>
                                                {{ str($goalie['status'])->headline() }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                @if ($stats)
                                    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-200">
                                        <span class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1">
                                            {{ $stats['goals_against_average'] !== null ? number_format($stats['goals_against_average'], 2) : '—' }} GAA
                                        </span>
                                        <span class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1">
                                            {{ $stats['save_percentage'] !== null ? ltrim(number_format($stats['save_percentage'], 3), '0') : '—' }} SV%
                                        </span>
                                        <span class="rounded-md border border-slate-700 bg-slate-900 px-2 py-1">
                                            {{ $stats['games_played'] }} GP
                                        </span>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">{{ $seasonLabel }} regular season</p>
                                @else
                                    <p class="mt-4 text-xs text-slate-500">Season statistics unavailable</p>
                                @endif

                                @if ($goalie)
                                    <p class="mt-4 text-xs leading-5 text-slate-400">
                                        {{ str($goalie['provider'])->headline() }} · observed
                                        <time datetime="{{ $goalie['observed_at'] }}" data-local-datetime>
                                            {{ \Illuminate\Support\Carbon::parse($goalie['observed_at'])->utc()->format('D, F j, Y, g:i A \\U\\T\\C') }}
                                        </time>
                                    </p>
                                @endif
                            </section>
                        @endforeach
                    </div>
                </article>
            @empty
                <p class="col-span-full rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-600">
                    No goalie observations have been imported for this date.
                </p>
            @endforelse
        </div>
    </main>
</x-app-layout>
