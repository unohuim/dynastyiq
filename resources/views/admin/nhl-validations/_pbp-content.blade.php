<div class="border-y border-gray-200 bg-white">
    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
        <div>
            <h2 class="text-sm font-semibold text-gray-900">PBP</h2>
            <p class="mt-1 text-sm text-gray-600">Most recent games with imported play-by-play.</p>
        </div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">
            Max 10
        </div>
    </div>

    @if ($games->isEmpty())
        <div class="px-4 py-10 text-sm text-gray-600">
            No imported PBP games found.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Game</th>
                        <th class="px-4 py-3">Matchup</th>
                        <th class="px-4 py-3">PBP Events</th>
                        <th class="px-4 py-3">HTML PBP</th>
                        <th class="px-4 py-3">Last Checked</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($games as $game)
                        <tr data-pbp-game-row="{{ $game->nhl_game_id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-700">
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded border border-gray-300 text-gray-500 transition hover:border-gray-400 hover:text-gray-700"
                                        data-pbp-toggle
                                        data-game-id="{{ $game->nhl_game_id }}"
                                        data-validation-id="{{ $game->validation_id }}"
                                        data-validation-url="{{ $game->validation_id ? route('admin.nhl-validations.show', ['validation' => $game->validation_id, 'admin_panel' => 1]) : '' }}"
                                        aria-expanded="false"
                                    >
                                        <span class="sr-only">View PBP review</span>
                                        <svg data-pbp-caret class="h-4 w-4 transition-transform duration-200" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                    <span>{{ $game->nhl_game_id }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                <div class="font-semibold">{{ $game->away_team_abbrev }} @ {{ $game->home_team_abbrev }}</div>
                                <div class="text-xs text-gray-500">{{ $game->game_date }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">
                                {{ number_format((int) $game->events_count) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @php
                                    $status = $game->validation_status ?: 'not checked';
                                    $statusClass = match ($game->validation_status) {
                                        \App\Models\NhlGameValidation::STATUS_APPROVED => 'bg-green-100 text-green-700',
                                        \App\Models\NhlGameValidation::STATUS_FAILED => 'bg-red-100 text-red-700',
                                        \App\Models\NhlGameValidation::STATUS_INCOMPLETE => 'bg-amber-100 text-amber-700',
                                        \App\Models\NhlGameValidation::STATUS_ACCEPTED_EXCEPTION => 'bg-blue-100 text-blue-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                @endphp
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $statusClass }}"
                                        data-pbp-status="{{ $game->nhl_game_id }}"
                                        data-status-class="{{ $statusClass }}"
                                    >
                                        {{ strtoupper(str_replace('_', ' ', $status)) }}
                                    </span>
                                    <span class="text-xs text-gray-500" data-pbp-mismatch-count="{{ $game->nhl_game_id }}">
                                        {{ (int) ($game->mismatch_count ?? 0) }} mismatches
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500" data-pbp-checked-at="{{ $game->nhl_game_id }}">
                                {{ $game->checked_at ? \Illuminate\Support\Carbon::parse($game->checked_at)->diffForHumans() : 'Never' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <x-secondary-button
                                        type="button"
                                        data-pbp-full
                                        data-game-id="{{ $game->nhl_game_id }}"
                                        data-full-url="{{ route('admin.nhl-pbp.full', $game->nhl_game_id) }}"
                                    >
                                        <span data-pbp-full-label>Full PBP</span>
                                    </x-secondary-button>
                                    <x-secondary-button
                                        type="button"
                                        data-pbp-event-shifts
                                        data-game-id="{{ $game->nhl_game_id }}"
                                        data-event-shifts-url="{{ route('admin.nhl-pbp.event-shifts', $game->nhl_game_id) }}"
                                    >
                                        <span data-pbp-event-shifts-label>Event Shifts</span>
                                    </x-secondary-button>
                                    <x-primary-button
                                        type="button"
                                        data-pbp-enrich
                                        data-game-id="{{ $game->nhl_game_id }}"
                                        data-enrich-url="{{ route('admin.nhl-pbp.enrich', $game->nhl_game_id) }}"
                                    >
                                        <span data-pbp-enrich-label>Enrich</span>
                                    </x-primary-button>
                                </div>
                            </td>
                        </tr>
                        <tr class="hidden" data-pbp-detail-row="{{ $game->nhl_game_id }}" data-pbp-detail-validation-id="{{ $game->validation_id }}">
                            <td colspan="6" class="bg-gray-50 p-0">
                                <div
                                    class="grid grid-rows-[0fr] opacity-0 transition-[grid-template-rows,opacity] duration-300 ease-out"
                                    data-pbp-detail-shell="{{ $game->nhl_game_id }}"
                                >
                                    <div class="min-h-0 overflow-hidden">
                                        <div data-pbp-detail-content="{{ $game->nhl_game_id }}"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
