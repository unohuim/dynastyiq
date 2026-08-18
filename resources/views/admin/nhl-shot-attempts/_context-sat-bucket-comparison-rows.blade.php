@php
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
    $contextProfileBucketSort = $contextProfileBucketSort ?? 'source_sat_per_game';
    $contextProfileBucketDirection = $contextProfileBucketDirection ?? 'desc';
    $sortableColumns = [
        ['key' => 'entity_name', 'label' => 'Name', 'class' => 'w-44'],
        ['key' => 'entity_type', 'label' => 'Entity', 'class' => ''],
        ['key' => 'role', 'label' => 'Role', 'class' => ''],
        ['key' => 'team_context', 'label' => 'Context', 'class' => ''],
        ['key' => 'source_games', 'label' => 'Games', 'class' => ''],
        ['key' => 'source_sat', 'label' => 'SAT', 'class' => ''],
        ['key' => 'source_sat_per_game', 'label' => 'SAT/G', 'class' => ''],
        ['key' => 'source_profile_share', 'label' => 'Share', 'class' => ''],
        ['key' => 'goal_probability', 'label' => 'G Prob', 'class' => ''],
        ['key' => 'shot_on_goal_probability', 'label' => 'SOG Prob', 'class' => ''],
        ['key' => 'confidence_score', 'label' => 'Conf', 'class' => ''],
        ['key' => 'shrinkage_weight', 'label' => 'Borrowed', 'class' => ''],
    ];
@endphp

<div class="overflow-x-auto">
    <table class="w-full table-fixed divide-y divide-gray-200 text-[10px] leading-tight">
        <thead class="bg-white text-left text-[9px] font-semibold uppercase text-gray-500">
            <tr>
                @foreach($sortableColumns as $column)
                    @php
                        $isActive = $contextProfileBucketSort === $column['key'];
                        $nextDirection = $isActive && $contextProfileBucketDirection === 'desc' ? 'asc' : 'desc';
                        $sortUrl = request()->fullUrlWithQuery([
                            'context_profile_bucket_sort' => $column['key'],
                            'context_profile_bucket_direction' => $nextDirection,
                        ]);
                    @endphp
                    <th class="{{ trim($column['class'] . ' px-1.5 py-2') }}">
                        <a
                            href="{{ $sortUrl }}"
                            class="inline-flex items-center gap-1 text-gray-600 hover:text-gray-900"
                            data-context-sat-section-link
                        >
                            <span>{{ $column['label'] }}</span>
                            @if($isActive)
                                <span aria-hidden="true">{!! $contextProfileBucketDirection === 'desc' ? '&darr;' : '&uarr;' !!}</span>
                            @endif
                        </a>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
            @forelse($contextProfileBucketComparisonRows as $row)
                <tr>
                    <td class="truncate px-1.5 py-2 font-medium text-gray-900" title="{{ $row->entity_name }}">
                        <span class="block truncate">{{ $row->entity_name }}</span>
                        <span class="block truncate text-[9px] font-normal text-gray-500">ID {{ $row->entity_id }}</span>
                    </td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->entity_type }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->role }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $row->team_context ?? 'game' }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_games, 0) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatNumber($row->source_sat) }}</td>
                    <td class="truncate px-1.5 py-2 text-gray-700">{{ $formatDecimal($row->source_sat_per_game) }}</td>
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
                <tr>
                    <td colspan="12" class="px-3 py-4 text-center text-xs text-gray-500">
                        No coaches or refs match this bucket.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
