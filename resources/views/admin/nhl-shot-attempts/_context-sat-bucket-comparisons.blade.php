@php
    $formatNumber = fn ($value) => number_format((int) $value);
    $formatDecimal = fn ($value, $places = 2) => $value === null ? 'N/A' : number_format((float) $value, $places);
@endphp

<div class="space-y-4">
    @forelse($contextProfileBucketComparisonGroups as $group)
        <section
            class="border border-gray-200 bg-white"
            data-context-sat-section
            data-url="{{ route('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparison-rows', array_merge(request()->except('page', 'aggregate_bucket_key', 'context_profile_bucket_sort', 'context_profile_bucket_direction'), ['aggregate_bucket_key' => $group['bucket_key']])) }}"
        >
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 bg-gray-50 px-3 py-2 text-left"
                aria-expanded="false"
                data-context-sat-section-toggle
            >
                <div class="min-w-0">
                    <h5 class="truncate text-xs font-semibold text-gray-900">{{ $group['bucket_label'] }}</h5>
                    <div class="mt-1 text-[10px] text-gray-500">
                        A{{ str_pad((string) $group['aggregate_level'], 2, '0', STR_PAD_LEFT) }}
                        · {{ $formatNumber($group['entity_count']) }} entities
                        · {{ $formatNumber($group['total_sat']) }} SAT
                        · avg share {{ $formatDecimal(((float) $group['avg_share']) * 100) }}%
                        · avg dist {{ $formatDecimal($group['avg_distance']) }} ft
                        · avg angle {{ $formatDecimal($group['avg_angle']) }} deg
                    </div>
                    <div class="mt-1 truncate text-[10px] text-gray-500" title="{{ $group['bucket_key'] }}">{{ $group['bucket_key'] }}</div>
                </div>
                <span class="shrink-0 text-sm font-semibold text-gray-500" data-context-sat-section-icon>+</span>
            </button>
            <div class="hidden border-t border-gray-200" data-context-sat-section-panel>
                <div class="px-3 py-3 text-xs text-gray-500" data-context-sat-section-status>
                    Open this bucket to load matching coaches and refs.
                </div>
                <div data-context-sat-section-content></div>
            </div>
        </section>
    @empty
        <div class="border border-gray-200 bg-white px-4 py-6 text-center text-xs text-gray-500">No bucket comparison rows match these filters.</div>
    @endforelse
</div>
