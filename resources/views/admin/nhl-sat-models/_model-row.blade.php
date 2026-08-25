@php
    $label = fn ($value) => str($value)->replace('_', ' ')->title();
    $statusClasses = [
        'draft' => 'bg-gray-100 text-gray-700 ring-gray-200',
        'running' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'complete' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'failed' => 'bg-red-50 text-red-700 ring-red-200',
        'archived' => 'bg-gray-50 text-gray-500 ring-gray-200',
    ];
    $trainingSeasons = collect($run->train_season_ids ?? [])->implode(', ');
    $excludedRate = data_get($trainingSummary ?? [], 'excluded_rate');
    $excludedSog = data_get($trainingSummary ?? [], 'excluded');
    $totalSog = data_get($trainingSummary ?? [], 'total');
    $canBuildRateComparison = (bool) data_get($comparisonState ?? [], 'can_build_rate_comparison', false);
    $canViewRateComparison = (bool) data_get($comparisonState ?? [], 'can_view_rate_comparison', false);
    $canViewTrainingDrift = (bool) data_get($trainingDriftState ?? [], 'can_view_training_drift', false);
    $canViewGenericBucketStability = (bool) data_get($genericBucketStabilityState ?? [], 'can_view_bucket_stability', false);
    $canBuildToiProjection = (bool) data_get($toiProjectionState ?? [], 'can_build_toi_projection', false);
    $canViewToiProjection = (bool) data_get($toiProjectionState ?? [], 'can_view_toi_projection', false);
@endphp

<tr data-sat-model-row="{{ $run->id }}" class="transition-colors hover:bg-gray-50/70">
    <td class="min-w-56 px-4 py-3">
        <div class="font-medium text-gray-950">{{ $run->name }}</div>
        @if($run->notes)
            <div class="mt-1 max-w-xl truncate text-xs text-gray-500">{{ $run->notes }}</div>
        @endif
    </td>
    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-700">{{ $run->model_version }}</td>
    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $trainingSeasons !== '' ? $trainingSeasons : 'None' }}</td>
    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $run->target_season_id ?? 'None' }}</td>
    <td class="whitespace-nowrap px-4 py-3">
        @if($totalSog !== null && (int) $totalSog > 0)
            <div class="font-medium text-gray-950">{{ number_format(((float) $excludedRate) * 100, 1) }}%</div>
            <div class="mt-1 text-xs text-gray-500">{{ number_format((int) $excludedSog) }} of {{ number_format((int) $totalSog) }}</div>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </td>
    <td class="whitespace-nowrap px-4 py-3">
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $statusClasses[$run->status] ?? 'bg-gray-100 text-gray-700 ring-gray-200' }}">
            {{ $label($run->status) }}
        </span>
    </td>
    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $run->updated_at?->format('Y-m-d H:i') }}</td>
    <td class="whitespace-nowrap px-4 py-3 text-right">
        <div class="relative inline-flex" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 shadow-sm transition-colors hover:bg-gray-50 hover:text-gray-950"
                @click="open = !open"
                aria-haspopup="menu"
                :aria-expanded="open.toString()"
                aria-label="Model actions"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 6.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM10 11.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM11.5 15a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z" />
                </svg>
            </button>

            <div
                x-cloak
                x-show="open"
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="translate-y-1 opacity-0"
                x-transition:enter-end="translate-y-0 opacity-100"
                x-transition:leave="transition duration-100 ease-in"
                x-transition:leave-start="translate-y-0 opacity-100"
                x-transition:leave-end="translate-y-1 opacity-0"
                class="absolute right-0 top-9 z-30 w-40 overflow-hidden rounded-md border border-gray-200 bg-white py-1 text-left shadow-lg"
                role="menu"
            >
                <a href="{{ route('admin.nhl-sat-models.buckets', ['run' => $run, 'target' => \App\Services\NhlExpectedGoalsBackfiller::TARGET_GOAL]) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                    View SOG
                </a>
                <a href="{{ route('admin.nhl-sat-models.buckets', ['run' => $run, 'target' => \App\Services\NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL]) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                    View SAT
                </a>
                <form method="POST" action="{{ route('admin.nhl-sat-models.train', $run) }}" data-sat-model-train-form>
                    @csrf
                    <input type="hidden" name="evaluation" value="sog">
                    <input type="hidden" name="smoothing_prior_attempts" value="100">
                    <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                        Eval SOG
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.nhl-sat-models.train', $run) }}" data-sat-model-train-form>
                    @csrf
                    <input type="hidden" name="evaluation" value="sat">
                    <input type="hidden" name="smoothing_prior_attempts" value="100">
                    <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                        Eval SAT
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.nhl-sat-models.profiles.build', $run) }}" data-sat-model-profile-build-form>
                    @csrf
                    <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                        Build Profiles
                    </button>
                </form>
                <a href="{{ route('admin.nhl-sat-models.profiles', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                    View Profiles
                </a>
                @if($canViewTrainingDrift)
                    <a href="{{ route('admin.nhl-sat-models.profiles.training-drift', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                        Training Drift
                    </a>
                @else
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        Training Drift
                    </span>
                @endif
                @if($canViewGenericBucketStability)
                    <a href="{{ route('admin.nhl-sat-models.profiles.bucket-stability', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                        Bucket Stability
                    </a>
                @else
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        Bucket Stability
                    </span>
                @endif
                <form method="POST" action="{{ route('admin.nhl-sat-models.rate-projections.build', $run) }}" data-sat-model-rate-build-form>
                    @csrf
                    <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                        Build /60
                    </button>
                </form>
                <a href="{{ route('admin.nhl-sat-models.rate-projections', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                    View /60
                </a>
                @if($canBuildToiProjection)
                    <form method="POST" action="{{ route('admin.nhl-sat-models.toi-projections.build', $run) }}" data-sat-model-toi-build-form>
                        @csrf
                        <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                            Build TOI
                        </button>
                    </form>
                @else
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        Build TOI
                    </span>
                @endif
                @if($canViewToiProjection)
                    <a href="{{ route('admin.nhl-sat-models.toi-projections', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                        View TOI
                    </a>
                @else
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        View TOI
                    </span>
                @endif
                @if($canBuildRateComparison)
                    <form method="POST" action="{{ route('admin.nhl-sat-models.rate-projections.compare.build', $run) }}" data-sat-model-rate-compare-build-form>
                        @csrf
                        <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                            Compare /60
                        </button>
                    </form>
                @else
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        Compare /60
                    </span>
                @endif
                @if($canViewRateComparison)
                    <a href="{{ route('admin.nhl-sat-models.rate-projections.compare.raw', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                        Raw Compare /60
                    </a>
                    <a href="{{ route('admin.nhl-sat-models.rate-projections.compare.aggregates', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">
                        Aggregate Compare /60
                    </a>
                @else
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        Raw Compare /60
                    </span>
                    <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">
                        Aggregate Compare /60
                    </span>
                @endif
            </div>
        </div>
    </td>
</tr>
