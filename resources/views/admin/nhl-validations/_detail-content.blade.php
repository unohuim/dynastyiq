<div class="{{ ($embedded ?? false) ? '' : 'overflow-hidden bg-white shadow-sm' }}">
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
</div>
