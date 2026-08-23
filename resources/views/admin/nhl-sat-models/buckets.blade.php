<x-app-layout>
    @php
        $label = fn ($value) => str($value)->replace('_', ' ')->title();
        $trainingSeasons = collect($run->train_season_ids ?? [])->implode(', ');
        $sampleMode = data_get($model?->feature_config, 'sample_mode');
        $isSatDanger = $target === \App\Services\NhlExpectedGoalsBackfiller::TARGET_SHOT_ON_GOAL;
        $successLabel = $isSatDanger ? 'SOG' : 'Goals';
        $attemptLabel = $isSatDanger ? 'SAT' : ($sampleMode === 'sog' ? 'SOG' : 'Attempts');
        $excludedRate = data_get($trainingSummary, 'excluded_rate');
        $excludedRows = data_get($trainingSummary, 'excluded');
        $totalRows = data_get($trainingSummary, 'total');
        $winner = data_get($factorEvaluation, 'winner');
        $interpretationTitle = $isSatDanger ? 'SAT Interpretation' : 'SOG Interpretation';
        $baselineLabel = $isSatDanger ? 'Baseline SOG %' : 'Baseline Shooting';
        $baselineAttempts = $isSatDanger
            ? data_get($factorEvaluation, 'baseline_sat')
            : data_get($factorEvaluation, 'baseline_sog');
        $baselineSuccesses = $isSatDanger
            ? data_get($factorEvaluation, 'baseline_sog')
            : data_get($factorEvaluation, 'baseline_goals');
        $baselineAttemptsLabel = $isSatDanger ? 'Training SAT' : 'Training SOG';
        $baselineSuccessesLabel = $isSatDanger ? 'SOG' : 'goals';
        $modelStateLabel = $model?->trained_at ? 'Trained' : ($model?->status ? $label($model->status) : 'Unlabeled');
        $bucketCount = data_get($model?->metrics, 'bucket_count') ?? $buckets->total();
        $percent = static fn ($value, int $decimals = 2): string => $value === null
            ? '-'
            : number_format(((float) $value) * 100, $decimals) . '%';
        $number = static fn ($value): string => number_format((float) $value);
        $sortLink = function (string $key) use ($direction, $run, $sort, $target): string {
            $nextDirection = $sort === $key && $direction === 'desc' ? 'asc' : 'desc';

            return route('admin.nhl-sat-models.buckets', [
                'run' => $run,
                'target' => $target,
                'sort' => $key,
                'direction' => $nextDirection,
            ]);
        };
        $sortIcon = function (string $key) use ($direction, $sort): string {
            if ($sort !== $key) {
                return '<svg class="size-3.5 text-gray-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3.5 5.5 8h9L10 3.5Zm0 13 4.5-4.5h-9L10 16.5Z" /></svg>';
            }

            if ($direction === 'asc') {
                return '<svg class="size-3.5 text-gray-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 4 4.5 10h11L10 4Z" /></svg>';
            }

            return '<svg class="size-3.5 text-gray-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 16 4.5 10h11L10 16Z" /></svg>';
        };
        $header = function (string $key, string $text, string $align = 'left') use ($sortIcon, $sortLink): string {
            $alignment = $align === 'right' ? 'justify-end text-right' : 'justify-start text-left';

            return '<a href="' . e($sortLink($key)) . '" class="inline-flex w-full items-center gap-1.5 ' . $alignment . ' transition-colors hover:text-gray-950">'
                . '<span>' . e($text) . '</span>'
                . $sortIcon($key)
                . '</a>';
        };
    @endphp

    <div class="min-h-screen bg-gray-50 py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="mb-3">
                        <a href="{{ route('admin.nhl-sat-models.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 transition-colors hover:text-gray-950">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.56l4.22 4.22a.75.75 0 1 1-1.06 1.06l-5.5-5.5a.75.75 0 0 1 0-1.06l5.5-5.5a.75.75 0 0 1 1.06 1.06L5.56 9.25h10.69A.75.75 0 0 1 17 10Z" clip-rule="evenodd" />
                            </svg>
                            SAT Models
                        </a>
                    </div>
                    <h1 class="text-2xl font-semibold tracking-tight text-gray-950">{{ $run->name }}</h1>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ $isSatDanger ? 'SAT danger evaluation for this SAT model.' : 'SOG danger evaluation for this SAT model.' }}
                    </p>
                </div>
                <div class="flex flex-col items-start gap-3 sm:items-end">
                    <div class="rounded-md border border-gray-200 bg-white px-4 py-3 text-right shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Excluded</div>
                        @if($totalRows !== null && (int) $totalRows > 0)
                            <div class="mt-1 text-lg font-semibold text-gray-950">{{ number_format(((float) $excludedRate) * 100, 1) }}%</div>
                            <div class="mt-0.5 text-xs text-gray-500">{{ number_format((int) $excludedRows) }} of {{ number_format((int) $totalRows) }}</div>
                        @else
                            <div class="mt-1 text-lg font-semibold text-gray-400">-</div>
                        @endif
                    </div>
                </div>
            </div>

            <section class="mb-5 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="grid gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Version</div>
                        <div class="mt-1 text-sm font-medium text-gray-950">{{ $run->model_version }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Training Seasons</div>
                        <div class="mt-1 text-sm font-medium text-gray-950">{{ $trainingSeasons !== '' ? $trainingSeasons : 'None' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Test Season</div>
                        <div class="mt-1 text-sm font-medium text-gray-950">{{ $run->target_season_id ?? 'None' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status</div>
                        <div class="mt-1 text-sm font-medium text-gray-950">{{ $label($run->status) }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rows</div>
                        <div class="mt-1 text-sm font-medium text-gray-950">{{ number_format((int) $bucketCount) }}</div>
                    </div>
                </div>
            </section>

            @if($factorEvaluation)
                <section class="mb-5 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-5 py-4">
                        <h2 class="text-sm font-semibold text-gray-950">{{ $interpretationTitle }}</h2>
                    </div>
                    <div class="grid gap-4 px-5 py-4 lg:grid-cols-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Selected Factors</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950">{{ data_get($winner, 'label', '-') }}</div>
                            <div class="mt-1 text-xs text-gray-500">Score {{ number_format((float) data_get($winner, 'score', 0), 4) }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $baselineAttemptsLabel }}</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950">{{ number_format((int) $baselineAttempts) }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ number_format((int) $baselineSuccesses) }} {{ $baselineSuccessesLabel }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $baselineLabel }}</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950">{{ $percent(data_get($factorEvaluation, 'baseline_rate')) }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ str(data_get($factorEvaluation, 'candidate_method', ''))->replace('_', ' ') }}</div>
                        </div>
                    </div>
                    <div class="grid gap-0 border-t border-gray-200 lg:grid-cols-2">
                        @foreach(['singles' => 'Top Singles', 'doubles' => 'Top Doubles'] as $metricKey => $metricLabel)
                            <div class="border-gray-200 px-5 py-4 first:border-r">
                                <div class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $metricLabel }}</div>
                                <div class="space-y-2">
                                    @foreach(array_slice(data_get($factorEvaluation, $metricKey, []), 0, 5) as $candidate)
                                        <div class="flex items-center justify-between gap-3 rounded-md bg-gray-50 px-3 py-2 ring-1 ring-inset ring-gray-200">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-medium text-gray-950">{{ data_get($candidate, 'label') }}</div>
                                                <div class="mt-0.5 text-xs text-gray-500">{{ number_format((int) data_get($candidate, 'rows', 0)) }} rows · {{ number_format((int) data_get($candidate, $isSatDanger ? 'sat' : 'sog', 0)) }} {{ $isSatDanger ? 'SAT' : 'SOG' }}</div>
                                            </div>
                                            <div class="text-right text-sm font-semibold text-gray-950">{{ number_format((float) data_get($candidate, 'score', 0), 4) }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-4 border-b border-gray-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950">{{ $targets[$target] }}</h2>
                        <p class="mt-1 text-sm text-gray-500">
                            @if($model?->trained_at)
                                {{ $modelStateLabel }} {{ $isSatDanger ? 'SAT-to-SOG' : 'SOG-to-goal' }} model trained on {{ $model->training_season_id ?? 'unknown season' }}.
                            @else
                                {{ $isSatDanger ? 'Eval SAT before reviewing SAT danger.' : 'Eval SOG before reviewing SOG danger.' }}
                            @endif
                        </p>
                    </div>
                </div>

                @if($buckets->count() === 0)
                    <div class="mx-auto flex max-w-lg flex-col items-center px-6 py-16 text-center">
                        <div class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200">
                            <svg class="size-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M4.75 3A1.75 1.75 0 0 0 3 4.75v10.5c0 .966.784 1.75 1.75 1.75h10.5A1.75 1.75 0 0 0 17 15.25V4.75A1.75 1.75 0 0 0 15.25 3H4.75Zm.75 3.25a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75Zm.75 3a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Zm-.75 4.25a.75.75 0 0 1 .75-.75h6.5a.75.75 0 0 1 0 1.5h-6.5a.75.75 0 0 1-.75-.75Z" />
                            </svg>
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-950">
                            No {{ $isSatDanger ? 'SAT' : 'SOG' }} eval yet
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">
                            Eval {{ $isSatDanger ? 'SAT' : 'SOG' }} before reviewing danger probabilities.
                        </p>
                    </div>
                @else
                    <div class="hidden grid-cols-[minmax(0,1fr)_5rem_5rem_5.5rem_6.5rem_7rem] gap-4 border-b border-gray-200 bg-gray-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 lg:grid">
                        <div>{!! $header('bucket_key', 'Profile') !!}</div>
                        <div>{!! $header('attempts', $attemptLabel, 'right') !!}</div>
                        <div>{!! $header('successes', $successLabel, 'right') !!}</div>
                        <div>{!! $header('raw_rate', 'Raw %', 'right') !!}</div>
                        <div>{!! $header('smoothed_probability', 'Smoothed %', 'right') !!}</div>
                        <div>{!! $header('confidence_score', 'Confidence', 'right') !!}</div>
                    </div>

                    <div class="divide-y divide-gray-100">
                        @foreach($buckets as $bucket)
                            @php
                                $dimensions = is_array($bucket->bucket_dimensions) ? $bucket->bucket_dimensions : [];
                                $profileTitle = collect($dimensions)
                                    ->map(fn ($value, $dimension) => str($dimension)->replace('_', ' ')->title() . ': ' . $value)
                                    ->implode(' · ');
                            @endphp

                            <article class="px-5 py-4 transition-colors hover:bg-gray-50/70">
                                <div class="grid grid-cols-2 gap-3 lg:grid-cols-[minmax(0,1fr)_5rem_5rem_5.5rem_6.5rem_7rem] lg:items-start lg:gap-4">
                                    <div class="col-span-2 min-w-0 lg:col-span-1">
                                        <div class="text-sm font-semibold leading-5 text-gray-950">
                                            {{ $profileTitle !== '' ? $profileTitle : ($isSatDanger ? 'All selected SAT' : 'All selected SOG') }}
                                        </div>
                                        @if($dimensions !== [])
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                @foreach($dimensions as $dimension => $value)
                                                    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200">
                                                        {{ str($dimension)->replace('_', ' ')->title() }}: {{ $value }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div class="text-left lg:text-right">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 lg:hidden">{{ $attemptLabel }}</div>
                                        <div class="mt-0.5 text-sm font-semibold text-gray-950 lg:mt-0">{{ $number($bucket->attempts) }}</div>
                                    </div>
                                    <div class="text-left lg:text-right">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 lg:hidden">{{ $successLabel }}</div>
                                        <div class="mt-0.5 text-sm font-medium text-gray-800 lg:mt-0">{{ $number($bucket->goals) }}</div>
                                    </div>
                                    <div class="text-left lg:text-right">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 lg:hidden">Raw %</div>
                                        <div class="mt-0.5 text-sm font-medium text-gray-800 lg:mt-0">{{ $percent($bucket->raw_goal_rate) }}</div>
                                    </div>
                                    <div class="text-left lg:text-right">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 lg:hidden">Smoothed %</div>
                                        <div class="mt-0.5 text-sm font-semibold text-gray-950 lg:mt-0">{{ $percent($bucket->smoothed_goal_probability) }}</div>
                                    </div>
                                    <div class="text-left lg:text-right">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 lg:hidden">Confidence</div>
                                        <div class="mt-0.5 text-sm font-semibold text-gray-950 lg:mt-0">{{ $percent($bucket->confidence_score, 0) }}</div>
                                        @if($bucket->confidence_bucket)
                                            <div class="mt-0.5 text-xs text-gray-500">{{ $label($bucket->confidence_bucket) }}</div>
                                        @endif
                                    </div>
                                </div>

                                <details class="mt-3 group">
                                    <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-xs font-semibold text-gray-500 transition-colors hover:text-gray-800">
                                        <svg class="size-3.5 transition-transform group-open:rotate-90" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M7.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L10.94 10 7.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                        </svg>
                                        Details
                                    </summary>
                                    <div class="mt-2 grid gap-2 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600 ring-1 ring-inset ring-gray-200 sm:grid-cols-2 lg:grid-cols-4">
                                        <div><span class="font-semibold text-gray-700">Level:</span> L{{ str_pad((string) $bucket->fallback_level, 2, '0', STR_PAD_LEFT) }}</div>
                                        <div><span class="font-semibold text-gray-700">Shrinkage:</span> {{ $percent($bucket->shrinkage_weight, 0) }}</div>
                                        <div><span class="font-semibold text-gray-700">Updated:</span> {{ $bucket->updated_at?->format('Y-m-d H:i') }}</div>
                                        <div class="min-w-0 sm:col-span-2 lg:col-span-1">
                                            <span class="font-semibold text-gray-700">Key:</span>
                                            <code class="break-all text-[11px] text-gray-500">{{ $bucket->bucket_key }}</code>
                                        </div>
                                    </div>
                                </details>
                            </article>
                        @endforeach
                    </div>

                    <div class="border-t border-gray-200 px-5 py-3">
                        {{ $buckets->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
