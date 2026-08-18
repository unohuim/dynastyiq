@php
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
@endphp

<div class="overflow-x-auto">
    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
        <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
            <tr>
                <th class="w-44 px-1.5 py-2">Name</th>
                <th class="px-1.5 py-2">Entity</th>
                <th class="px-1.5 py-2">Role</th>
                <th class="px-1.5 py-2">Context</th>
                <th class="px-1.5 py-2">Agg</th>
                <th class="w-48 px-1.5 py-2">Profile Bucket</th>
                <th class="px-1.5 py-2">Games</th>
                <th class="px-1.5 py-2">SAT</th>
                <th class="px-1.5 py-2">SAT/G</th>
                <th class="px-1.5 py-2">Exact Buckets</th>
                <th class="px-1.5 py-2">USAT</th>
                <th class="px-1.5 py-2">SOG</th>
                <th class="px-1.5 py-2">G</th>
                <th class="px-1.5 py-2">xG</th>
                <th class="px-1.5 py-2">Share</th>
                <th class="px-1.5 py-2">G Prob</th>
                <th class="px-1.5 py-2">SOG Prob</th>
                <th class="px-1.5 py-2">Conf</th>
                <th class="px-1.5 py-2">Borrowed</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
            @forelse($contextProfileAggregateRows as $row)
                @php
                    $includedBuckets = is_string($row->included_bucket_keys ?? null)
                        ? (json_decode($row->included_bucket_keys, true) ?: [])
                        : (array) ($row->included_bucket_keys ?? []);
                @endphp
                <tr>
                    <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->entity_name }}">
                        <span class="block truncate">{{ $row->entity_name }}</span>
                        <span class="block truncate text-[9px] font-normal text-gray-500">ID {{ $row->entity_id }}</span>
                    </td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->entity_type }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->role }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_context ?? 'game' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">A{{ str_pad((string) $row->aggregate_level, 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="whitespace-normal px-1.5 py-2 text-gray-700" title="{{ $row->aggregate_bucket_key }}">{{ $row->aggregate_label }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_sat_per_game) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ implode(', ', $includedBuckets) }}">{{ $formatNumber($row->included_bucket_count) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_unblocked_sat) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sog) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_goals) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xg) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal(((float) $row->source_profile_share) * 100) }}%</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal(((float) $row->goal_probability) * 100) }}%</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal(((float) $row->shot_on_goal_probability) * 100) }}%</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">
                        {{ $formatDecimal(((float) $row->confidence_score) * 100, 0) }}%
                        <div class="truncate text-[9px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                    </td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal(((float) $row->shrinkage_weight) * 100, 0) }}%</td>
                </tr>
            @empty
                <tr><td colspan="19" class="px-2 py-6 text-center text-xs text-gray-500">No aggregate profile rows match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
