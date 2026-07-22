<div class="{{ ($embedded ?? false) ? '' : 'overflow-hidden bg-white shadow-sm' }}">
    @if($validation->validation_type === \App\Models\NhlGameValidation::TYPE_PBP_HTML_REPORT)
        <div class="border-b border-gray-200 bg-white px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-gray-900">HTML/API PBP review</div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ $validation->mismatch_count }} mismatch{{ $validation->mismatch_count === 1 ? '' : 'es' }}
                    </div>
                    @php
                        $htmlPbpUrl = optional($validation->pbpSourceMismatches->first())->source_url;
                        $apiPbpUrl = "https://api-web.nhle.com/v1/gamecenter/{$validation->nhl_game_id}/play-by-play";
                    @endphp
                    <div class="mt-2 space-y-1 text-xs text-gray-500">
                        @if($htmlPbpUrl)
                            <div>
                                <span class="font-medium text-gray-600">HTML:</span>
                                <a href="{{ $htmlPbpUrl }}" class="break-all underline decoration-gray-300 underline-offset-4 hover:decoration-gray-900" target="_blank" rel="noreferrer">
                                    {{ $htmlPbpUrl }}
                                </a>
                            </div>
                        @endif
                        <div>
                            <span class="font-medium text-gray-600">API:</span>
                            <a href="{{ $apiPbpUrl }}" class="break-all underline decoration-gray-300 underline-offset-4 hover:decoration-gray-900" target="_blank" rel="noreferrer">
                                {{ $apiPbpUrl }}
                            </a>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form
                        method="POST"
                        action="{{ route('admin.nhl-validations.rerun-html-pbp', $validation) }}"
                        @if($embedded ?? false)
                            data-validation-action
                            data-validation-id="{{ $validation->id }}"
                            data-validation-url="{{ route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]) }}"
                        @endif
                    >
                        @csrf
                        <button type="submit" class="inline-flex min-h-8 items-center rounded border border-gray-300 bg-white px-2 text-xs font-semibold text-gray-700 transition-colors duration-150 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60">
                            <span data-validation-action-label>Re-run HTML verification</span>
                        </button>
                    </form>
                    <form
                        method="POST"
                        action="{{ route('admin.nhl-validations.accept-api-pbp', $validation) }}"
                        @if($embedded ?? false)
                            data-validation-action
                            data-validation-id="{{ $validation->id }}"
                            data-validation-url="{{ route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]) }}"
                        @endif
                    >
                        @csrf
                        <button type="submit" class="inline-flex min-h-8 items-center rounded bg-gray-900 px-2 text-xs font-semibold text-white transition-colors duration-150 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-60">
                            <span data-validation-action-label>Accept API</span>
                        </button>
                    </form>
                    <form
                        method="POST"
                        action="{{ route('admin.nhl-validations.accept-html-pbp-positions', $validation) }}"
                        @if($embedded ?? false)
                            data-validation-action
                            data-validation-id="{{ $validation->id }}"
                            data-validation-url="{{ route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]) }}"
                        @endif
                    >
                        @csrf
                        <button type="submit" class="inline-flex min-h-8 items-center rounded border border-gray-300 bg-white px-2 text-xs font-semibold text-gray-700 transition-colors duration-150 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60">
                            <span data-validation-action-label>Accept positions only</span>
                        </button>
                    </form>
                    <form
                        method="POST"
                        action="{{ route('admin.nhl-validations.acknowledge-pbp-mismatch', $validation) }}"
                        @if($embedded ?? false)
                            data-validation-action
                            data-validation-id="{{ $validation->id }}"
                            data-validation-url="{{ route('admin.nhl-validations.show', ['validation' => $validation->id, 'admin_panel' => 1]) }}"
                        @endif
                    >
                        @csrf
                        <button type="submit" class="inline-flex min-h-8 items-center rounded border border-gray-300 bg-white px-2 text-xs font-semibold text-gray-700 transition-colors duration-150 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60">
                            <span data-validation-action-label>Acknowledge</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Severity</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Type</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Event</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">API</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">HTML</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                @forelse($validation->pbpSourceMismatches as $mismatch)
                    <tr class="align-top">
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                {{ $mismatch->severity }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ str_replace('_', ' ', $mismatch->mismatch_type) }}</td>
                        <td class="px-4 py-3 text-gray-700">
                            <div>Event {{ $mismatch->nhl_event_id ?? 'N/A' }}</div>
                            <div class="text-xs text-gray-500">P{{ $mismatch->period ?? 'N/A' }} {{ $mismatch->time_in_period ?? 'N/A' }}</div>
                            @if($mismatch->source_url)
                                <a href="{{ $mismatch->source_url }}" class="text-xs text-gray-600 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-900" target="_blank" rel="noreferrer">
                                    Source
                                </a>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <pre class="max-w-md overflow-x-auto whitespace-pre-wrap text-xs text-gray-700">{{ json_encode($mismatch->api_event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'NULL' }}</pre>
                        </td>
                        <td class="px-4 py-3">
                            <pre class="max-w-md overflow-x-auto whitespace-pre-wrap text-xs text-gray-700">{{ json_encode($mismatch->html_event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'NULL' }}</pre>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No PBP source mismatches stored for this validation.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @else
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Player</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Field</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Boxscore</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Summary</th>
                <th class="px-4 py-3 text-left font-semibold text-gray-700">Delta</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
            @forelse($validation->deltas as $delta)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-semibold text-gray-900">{{ optional($delta->player)->full_name ?? 'NHL '.$delta->nhl_player_id }}</div>
                        <div class="text-xs text-gray-500">{{ optional($delta->player)->team_abbrev ?? 'N/A' }} · NHL ID {{ $delta->nhl_player_id ?? 'N/A' }}</div>
                    </td>
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $delta->field }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $delta->boxscore_value ?? 'NULL' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $delta->summary_value ?? 'NULL' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $delta->delta ?? 'N/A' }}</td>
                </tr>
                @if(!empty($delta->pbp_context))
                    <tr class="bg-gray-50">
                        <td colspan="5" class="px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">PBP context</div>
                            <div class="mt-2 grid gap-2 md:grid-cols-2">
                                @foreach($delta->pbp_context as $event)
                                    <div class="border border-gray-200 bg-white p-2 text-xs text-gray-600">
                                        <div class="font-medium text-gray-900">
                                            Event {{ $event['event_id'] ?? 'N/A' }} · P{{ $event['period'] ?? 'N/A' }} {{ $event['time'] ?? 'N/A' }} · {{ $event['type'] ?? 'N/A' }}
                                        </div>
                                        <div>{{ $event['period_type'] ?? 'N/A' }} · {{ $event['strength'] ?? 'N/A' }} · {{ $event['situation_code'] ?? 'N/A' }} · type {{ $event['raw_type_code'] ?? 'N/A' }}</div>
                                        <div>{{ $event['detail'] ?? 'No detail' }} · shot {{ $event['shot_type'] ?? 'N/A' }} · goalie {{ $event['goalie_in_net_player_id'] ?? 'N/A' }}</div>
                                        <div>Counts SOG {{ ($event['counts_as_sog'] ?? false) ? 'yes' : 'no' }}</div>
                                        <div>SOG away {{ $event['provider_sog']['away'] ?? 'N/A' }} · home {{ $event['provider_sog']['home'] ?? 'N/A' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No deltas stored for this validation.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @endif
</div>
