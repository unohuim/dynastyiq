@php
    $event = $review['event'] ?? null;
    $api = $event['api'] ?? [];
    $html = $event['html'] ?? null;
    $htmlPlayers = $event['html_players'] ?? [];
    $shiftPlayers = $event['shift_players'] ?? [];
    $missing = $event['missing_from_shiftcharts'] ?? [];
    $extra = $event['extra_in_shiftcharts'] ?? [];
@endphp

<div class="space-y-4 px-4 py-4" data-pbp-full-panel>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">Full PBP source review</h3>
            <div class="mt-1 space-y-0.5 text-xs text-gray-500">
                <div>API: <a class="text-indigo-600 hover:text-indigo-800" href="{{ $review['api_url'] }}" target="_blank" rel="noreferrer">{{ $review['api_url'] }}</a></div>
                <div>
                    HTML:
                    @if ($review['html_url'])
                        <a class="text-indigo-600 hover:text-indigo-800" href="{{ $review['html_url'] }}" target="_blank" rel="noreferrer">{{ $review['html_url'] }}</a>
                    @else
                        N/A
                    @endif
                </div>
                <div>Shiftcharts: <a class="text-indigo-600 hover:text-indigo-800" href="{{ $review['shift_url'] }}" target="_blank" rel="noreferrer">{{ $review['shift_url'] }}</a></div>
                <div>
                    TV TOI:
                    @if ($review['toi_away_url'] ?? null)
                        <a class="text-indigo-600 hover:text-indigo-800" href="{{ $review['toi_away_url'] }}" target="_blank" rel="noreferrer">{{ $review['toi_away_url'] }}</a>
                    @else
                        N/A
                    @endif
                </div>
                <div>
                    TH TOI:
                    @if ($review['toi_home_url'] ?? null)
                        <a class="text-indigo-600 hover:text-indigo-800" href="{{ $review['toi_home_url'] }}" target="_blank" rel="noreferrer">{{ $review['toi_home_url'] }}</a>
                    @else
                        N/A
                    @endif
                </div>
            </div>
        </div>
        <div class="text-right text-xs text-gray-500">
            <div>{{ number_format((int) $review['event_count']) }} events</div>
            <div>{{ number_format((int) $review['mismatch_count']) }} source mismatches</div>
        </div>
    </div>

    @if (! $event)
        <div class="rounded border border-gray-200 bg-white px-4 py-6 text-sm text-gray-600">
            No source events were available.
        </div>
    @else
        <div class="rounded border border-gray-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <div class="text-sm font-semibold text-gray-900">
                        Event {{ $api['event_id'] ?? 'N/A' }} · P{{ $api['period'] ?? 'N/A' }} {{ $api['time'] ?? 'N/A' }} · {{ $api['type'] ?? 'N/A' }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        Step {{ ((int) $review['current_index']) + 1 }} of {{ (int) $review['event_count'] }}
                    </div>
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ ($event['comparison_skipped'] ?? false) ? 'bg-gray-100 text-gray-700' : (($event['has_mismatch'] ?? false) ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700') }}">
                    {{ ($event['comparison_skipped'] ?? false) ? 'SKIPPED' : (($event['has_mismatch'] ?? false) ? 'MISMATCH' : 'MATCH') }}
                </span>
            </div>

            <div class="grid gap-4 p-4 lg:grid-cols-3">
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">API Event</h4>
                    <pre class="mt-2 max-h-56 overflow-auto rounded bg-gray-950 p-3 text-xs text-gray-100">{{ json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">HTML PBP On Ice</h4>
                    <div class="mt-2 rounded border border-gray-200 bg-gray-50 p-3 font-mono text-xs text-gray-700">
                        {{ $htmlPlayers === [] ? 'None resolved' : implode(', ', $htmlPlayers) }}
                    </div>
                    @if ($html)
                        <pre class="mt-2 max-h-40 overflow-auto rounded bg-white p-3 text-xs text-gray-600">{{ json_encode($html, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </div>
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Shiftchart On Ice</h4>
                    <div class="mt-2 rounded border border-gray-200 bg-gray-50 p-3 font-mono text-xs text-gray-700">
                        {{ $shiftPlayers === [] ? 'None resolved' : implode(', ', $shiftPlayers) }}
                    </div>
                    @if (($event['has_mismatch'] ?? false))
                        <div class="mt-3 space-y-2 text-xs text-red-700">
                            <div>Missing from shiftcharts: {{ $missing === [] ? 'None' : implode(', ', $missing) }}</div>
                            <div>Extra in shiftcharts: {{ $extra === [] ? 'None' : implode(', ', $extra) }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-xs text-gray-500">
                Source mismatches are written to <span class="font-mono">docs/troubleshooting/{{ $review['game_id'] }}/errors.txt</span>.
            </div>
            <div class="flex gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25"
                    data-pbp-full-advance
                    data-game-id="{{ $review['game_id'] }}"
                    data-full-url="{{ route('admin.nhl-pbp.full', $review['game_id']) }}"
                    data-next-index="{{ $review['next_mismatch_index'] }}"
                    {{ $review['next_mismatch_index'] === null ? 'disabled' : '' }}
                >
                    Next Mismatch
                </button>
            </div>
        </div>
    @endif
</div>
