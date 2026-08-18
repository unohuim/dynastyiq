@php
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
    $contextProfileSortUrl = function ($key) use ($contextProfileSort, $contextProfileDirection) {
        return route('admin.nhl-shot-attempts.context-sat-profiles.exact', array_merge(
            request()->except(['page', 'context_profile_sort', 'context_profile_direction']),
            [
                'tab' => 'context-sat-profiles',
                'context_profile_sort' => $key,
                'context_profile_direction' => $contextProfileSort === $key && $contextProfileDirection === 'desc' ? 'asc' : 'desc',
            ]
        ));
    };
    $contextProfileSortArrow = function ($key) use ($contextProfileSort, $contextProfileDirection) {
        if ($contextProfileSort !== $key) {
            return '';
        }

        return $contextProfileDirection === 'desc' ? '↓' : '↑';
    };
@endphp

<div class="overflow-x-auto">
    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
        <thead class="bg-gray-50 text-left text-[9px] font-semibold uppercase text-gray-500">
            <tr>
                @foreach([
                    'entity_name' => 'Name',
                    'entity_type' => 'Entity',
                    'role' => 'Role',
                    'team_context' => 'Context',
                    'fallback_level' => 'Lvl',
                    'shot_type_group' => 'Type',
                    'distance_group' => 'Dist',
                    'angle_group' => 'Angle',
                    'sequence_group' => 'Seq',
                    'source_games' => 'Games',
                    'source_sat' => 'Direct SAT',
                    'source_sat_per_game' => 'SAT/G',
                    'prior_bucket_key' => 'Prior Bucket',
                    'prior_sat' => 'Prior SAT',
                    'shrinkage_weight' => 'Borrowed',
                    'source_unblocked_sat' => 'USAT',
                    'source_sog' => 'SOG',
                    'source_goals' => 'G',
                    'source_xg' => 'xG',
                    'source_xsog' => 'xSOG',
                    'source_profile_share' => 'Share',
                    'league_avg_profile_share' => 'Lg Share',
                    'profile_share_delta' => '+/- Lg',
                    'goal_probability' => 'G Prob',
                    'shot_on_goal_probability' => 'SOG Prob',
                    'confidence_score' => 'Conf',
                ] as $sortKey => $label)
                    <th class="px-1.5 py-2 first:w-44">
                        <a href="{{ $contextProfileSortUrl($sortKey) }}" class="inline-flex items-center gap-1 hover:text-gray-900" data-context-sat-section-link>
                            <span>{{ $label }}</span>
                            @if($contextProfileSortArrow($sortKey) !== '')
                                <span class="text-[10px] text-indigo-600" aria-hidden="true">{{ $contextProfileSortArrow($sortKey) }}</span>
                            @endif
                        </a>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
            @forelse($contextProfileRows as $row)
                <tr>
                    <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->entity_name }}">
                        <span class="block truncate">{{ $row->entity_name }}</span>
                        <span class="block truncate text-[9px] font-normal text-gray-500">ID {{ $row->entity_id }}</span>
                    </td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->entity_type }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->role }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_context ?? 'game' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">L{{ str_pad((string) $row->fallback_level, 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->shot_type_group ?? 'Any' }}">{{ $row->shot_type_group ?? 'Any' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->distance_group ?? 'Any' }}">{{ $row->distance_group ?? 'Any' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->angle_group ?? 'Any' }}">{{ $row->angle_group ?? 'Any' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->sequence_group ?? 'Any' }}">{{ $row->sequence_group ?? 'Any' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_sat_per_game) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700" title="{{ $row->prior_bucket_key ?? 'Direct' }}">
                        @if($row->prior_bucket_key)
                            <span class="block truncate">{{ $row->prior_bucket_key }}</span>
                            <span class="block truncate text-[9px] text-gray-500">L{{ str_pad((string) $row->prior_fallback_level, 2, '0', STR_PAD_LEFT) }}</span>
                        @else
                            Direct
                        @endif
                    </td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->prior_sat) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal(((float) $row->shrinkage_weight) * 100, 0) }}%</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_unblocked_sat) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sog) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_goals) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xg) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_xsog) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->source_profile_share === null ? 'N/A' : $formatDecimal(((float) $row->source_profile_share) * 100) . '%' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->league_avg_profile_share === null ? 'N/A' : $formatDecimal(((float) $row->league_avg_profile_share) * 100) . '%' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->profile_share_delta === null ? 'N/A' : (($row->profile_share_delta >= 0 ? '+' : '') . $formatDecimal(((float) $row->profile_share_delta) * 100) . '%') }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->goal_probability === null ? 'N/A' : $formatDecimal(((float) $row->goal_probability) * 100) . '%' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->shot_on_goal_probability === null ? 'N/A' : $formatDecimal(((float) $row->shot_on_goal_probability) * 100) . '%' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">
                        {{ $row->confidence_score === null ? 'N/A' : $formatDecimal(((float) $row->confidence_score) * 100, 0) . '%' }}
                        <div class="truncate text-[9px] text-gray-500">{{ $row->confidence_bucket ?? 'unscored' }}</div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="28" class="px-2 py-6 text-center text-xs text-gray-500">No refs or coaches SAT profile rows match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
