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
    $predictionBuild = data_get($run->metrics, 'prediction_build', []);
    $profileBuild = data_get($run->metrics, 'profile_build', []);
    $predictionStage = $predictionBuild['stage'] ?? null;
    $predictionStageLabel = ['profiles' => 'Profiles', 'rates' => '/60', 'toi' => 'TOI/GP'][$predictionStage] ?? '';
    $predictionPrefix = ['profiles' => 'profile', 'rates' => 'rate_projection', 'toi' => 'toi_projection'][$predictionStage] ?? 'profile';
    $predictionDone = (int) data_get($run->metrics, $predictionPrefix . '_entities_completed', 0);
    $predictionQueued = (int) data_get($run->metrics, $predictionPrefix . '_entities_queued', 0);
    if ($predictionStage === 'profiles') {
        $predictionDone += (int) data_get($run->metrics, 'season_snapshot_entities_completed', 0);
        $predictionQueued += (int) data_get($run->metrics, 'season_snapshot_entities_queued', 0);
    }
    $canBuildRateComparison = (bool) data_get($comparisonState ?? [], 'can_build_rate_comparison', false);
    $canViewRateComparison = (bool) data_get($comparisonState ?? [], 'can_view_rate_comparison', false);
    $canViewTrainingDrift = (bool) data_get($trainingDriftState ?? [], 'can_view_training_drift', false);
    $canViewGenericBucketStability = (bool) data_get($genericBucketStabilityState ?? [], 'can_view_bucket_stability', false);
    $canBuildToiProjection = (bool) data_get($toiProjectionState ?? [], 'can_build_toi_projection', false);
    $canViewToiProjection = (bool) data_get($toiProjectionState ?? [], 'can_view_toi_projection', false);
    $pregameBuild = $pregameBuild ?? null;
    $pregameActive = in_array($pregameBuild?->status, ['queued', 'running'], true);
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
    <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ $run->target_season_id ?? 'None' }}<span class="mt-1 block text-xs text-gray-500">Projection: {{ $run->projectionSeasonId() ?? 'None' }}</span></td>
    <td class="whitespace-nowrap px-4 py-3">
        @if($totalSog !== null && (int) $totalSog > 0)
            <div class="font-medium text-gray-950">{{ number_format(((float) $excludedRate) * 100, 1) }}%</div>
            <div class="mt-1 text-xs text-gray-500">{{ number_format((int) $excludedSog) }} of {{ number_format((int) $totalSog) }}</div>
        @else
            <span class="text-gray-400">-</span>
        @endif
    </td>
    <td class="whitespace-nowrap px-4 py-3">
        <div data-pregame-progress data-progress-url="{{ route('admin.nhl-sat-models.context.progress', $run) }}">
            @include('admin.nhl-sat-models._pregame-progress', ['pregameBuild' => $pregameBuild])
        </div>
        <span data-model-training-status @class(['hidden' => $pregameActive, 'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset', $statusClasses[$run->status] ?? 'bg-gray-100 text-gray-700 ring-gray-200'])>
            {{ $label($run->status) }}
        </span>
        @if(($predictionBuild['status'] ?? null) === 'running' && $run->status === 'running')
            <div class="mt-1 text-xs text-gray-500" role="status" aria-live="polite">
                Build Predictions · {{ $predictionStageLabel }} · {{ $predictionDone }}/{{ $predictionQueued }}
                @if($predictionStage === 'profiles' && ! ($profileBuild['loading_complete'] ?? false)) · Discovering entities @endif
            </div>
        @elseif(($predictionBuild['status'] ?? null) === 'failed' && $run->status === 'failed')
            <div class="mt-1 text-xs text-red-700">Build Predictions failed · {{ $predictionStageLabel }}</div>
            <div class="mt-1 max-w-xs whitespace-normal text-xs text-red-700">{{ $predictionBuild['error'] ?? '' }}</div>
        @elseif(($predictionBuild['status'] ?? null) === 'complete' && $run->status === 'complete')
            <div class="mt-1 text-xs text-gray-500">Build Predictions complete</div>
        @elseif(($profileBuild['status'] ?? null) === 'running' && $run->status === 'running')
            <div class="mt-1 text-xs text-gray-500" role="status" aria-live="polite">
                Profiles · {{ (int) data_get($run->metrics, 'profile_entities_completed', 0) + (int) data_get($run->metrics, 'season_snapshot_entities_completed', 0) }}/{{ (int) data_get($run->metrics, 'profile_entities_queued', 0) + (int) data_get($run->metrics, 'season_snapshot_entities_queued', 0) }}
                @if(! ($profileBuild['loading_complete'] ?? false)) · Discovering entities @endif
            </div>
        @elseif(($profileBuild['status'] ?? null) === 'failed' && $run->status === 'failed')
            <div class="mt-1 max-w-xs whitespace-normal text-xs text-red-700">Profiles failed · {{ $profileBuild['error'] ?? '' }}</div>
        @endif
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
                class="absolute right-0 top-9 z-30 w-40 rounded-md border border-gray-200 bg-white py-1 text-left shadow-lg"
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
                <form method="POST" action="{{ route('admin.nhl-sat-models.predictions.build', $run) }}" data-sat-model-profile-build-form>
                    @csrf
                    <button type="submit" @disabled($run->status === 'running') class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                        Build Predictions
                    </button>
                </form>
                <div class="relative" x-data="{ profilesOpen: false }" @mouseenter="profilesOpen = true" @mouseleave="profilesOpen = false">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem" :aria-expanded="profilesOpen.toString()" @click.stop="profilesOpen = !profilesOpen">
                        <span>Profiles</span>
                        <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06L8.28 15.78a.75.75 0 0 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>
                    <div x-cloak x-show="profilesOpen" x-transition.opacity.duration.150ms class="absolute right-full top-0 z-40 mr-1 w-48 rounded-md border border-gray-200 bg-white py-1 shadow-lg" role="menu">
                        <a href="{{ route('admin.nhl-sat-models.profiles', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">View Profiles</a>
                        <form method="POST" action="{{ route('admin.nhl-sat-models.profiles.build', $run) }}" data-sat-model-profile-build-form>
                            @csrf
                            <input type="hidden" name="profile_type" value="skater_offense">
                            <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">Build Offensive Skaters</button>
                        </form>
                        <form method="POST" action="{{ route('admin.nhl-sat-models.profiles.build', $run) }}" data-sat-model-profile-build-form>
                            @csrf
                            <input type="hidden" name="profile_type" value="goalie_faced">
                            <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">Build Goalies</button>
                        </form>
                    </div>
                </div>
                <div class="relative" x-data="{ predictOpen: false }" @mouseenter="predictOpen = true" @mouseleave="predictOpen = false">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem" :aria-expanded="predictOpen.toString()" @click.stop="predictOpen = !predictOpen">
                        <span>Predict</span>
                        <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06L8.28 15.78a.75.75 0 0 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>
                    <div x-cloak x-show="predictOpen" x-transition.opacity.duration.150ms class="absolute right-full top-0 z-40 mr-1 w-52 rounded-md border border-gray-200 bg-white py-1 shadow-lg" role="menu">
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
                    <input type="hidden" name="profile_type" value="skater_offense">
                    <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">
                        Build /60 — Offense
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
                    </div>
                </div>
                <div class="relative" x-data="{ analysisOpen: false }" @mouseenter="analysisOpen = true" @mouseleave="analysisOpen = false" @keydown.escape.stop="analysisOpen = false">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem" aria-haspopup="menu" aria-controls="analysis-menu-{{ $run->id }}" :aria-expanded="analysisOpen.toString()" @click.stop="analysisOpen = !analysisOpen">
                        <span>Analysis</span>
                        <svg class="size-3 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="{ 'rotate-90': analysisOpen }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="m7 4 6 6-6 6V4Z" /></svg>
                    </button>
                    <div id="analysis-menu-{{ $run->id }}" x-cloak x-show="analysisOpen" x-transition.opacity.duration.150ms class="absolute right-full top-0 z-40 mr-1 w-52 rounded-md border border-gray-200 bg-white py-1 shadow-lg motion-reduce:transition-none" role="menu">
                        <form method="POST" action="{{ route('admin.nhl-sat-models.context.build', $run) }}" data-sat-model-pregame-build-form>
                            @csrf
                            <button type="submit" @disabled($pregameActive) class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">Build Pregame</button>
                        </form>
                        <a href="{{ route('admin.nhl-sat-models.context.effects') }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">View Pregame Impacts</a>
                    </div>
                </div>
                <div class="relative" x-data="{ compareOpen: false }" @mouseenter="compareOpen = true" @mouseleave="compareOpen = false">
                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem" :aria-expanded="compareOpen.toString()" @click.stop="compareOpen = !compareOpen">
                        <span>Compare</span>
                        <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06L8.28 15.78a.75.75 0 0 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>
                    <div x-cloak x-show="compareOpen" x-transition.opacity.duration.150ms class="absolute right-full top-0 z-40 mr-1 w-52 rounded-md border border-gray-200 bg-white py-1 shadow-lg" role="menu">
                        @if($canBuildRateComparison)
                            <form method="POST" action="{{ route('admin.nhl-sat-models.rate-projections.compare.build', $run) }}" data-sat-model-rate-compare-build-form>
                                @csrf
                                <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-60" role="menuitem">Build Comparisons</button>
                            </form>
                        @else
                            <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">Build Comparisons</span>
                        @endif
                        @if($canViewRateComparison)
                            <a href="{{ route('admin.nhl-sat-models.rate-projections.compare.raw', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">Bucket Comparisons</a>
                            <a href="{{ route('admin.nhl-sat-models.rate-projections.compare.aggregates', $run) }}" class="block px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 hover:text-gray-950" role="menuitem">Player Comparisons</a>
                        @else
                            <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">Bucket Comparisons</span>
                            <span class="block cursor-not-allowed px-3 py-2 text-xs font-medium text-gray-300" role="menuitem" aria-disabled="true">Player Comparisons</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </td>
</tr>
