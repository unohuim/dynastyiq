<x-app-layout>
    @php
        $label = fn ($value) => str($value)->replace('_', ' ')->title();
        $hasModels = $runs->count() > 0;
    @endphp

    <div
        class="min-h-screen bg-gray-50 py-4 pb-24 sm:py-6"
        x-data="{ createOpen: {{ $errors->any() ? 'true' : 'false' }}, pregameScheduleOpen: false }"
        data-admin-sat-models
    >
        <div class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-950/35 px-4 backdrop-blur-sm" data-sat-model-loading>
            <div class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-5 text-center shadow-xl">
                <div class="mx-auto size-8 animate-spin rounded-full border-2 border-gray-200 border-t-gray-950"></div>
                <div class="mt-4 text-sm font-semibold text-gray-950" data-sat-model-loading-title>Working...</div>
                <div class="mt-1 text-xs text-gray-500">Keep this tab open until it finishes.</div>
            </div>
        </div>

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-gray-950">SAT Models</h1>
                    <p class="mt-1 text-sm text-gray-600">Create SAT models and evaluate SOG danger from training seasons.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.nhl-shot-attempts.index') }}" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50">
                        Shot Attempts
                    </a>
                    <button
                        type="button"
                        class="inline-flex size-10 items-center justify-center rounded-md bg-gray-950 text-white shadow-sm transition-colors hover:bg-gray-800"
                        @click="createOpen = true"
                        aria-label="Create model"
                        data-sat-model-create-button
                    >
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                        </svg>
                    </button>
                </div>
            </div>

            <div data-sat-model-alerts>
                @if(session('status'))
                    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        {{ session('status') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {{ $errors->first() }}
                    </div>
                @endif
            </div>

            <section class="overflow-visible rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950">Models</h2>
                        <p class="mt-1 text-sm text-gray-500">Each model stores its training seasons and optional test season.</p>
                    </div>
                    <form method="GET" action="{{ route('admin.nhl-sat-models.index') }}" class="flex items-end gap-2">
                        <label class="block text-sm">
                            <span class="font-medium text-gray-700">Status</span>
                            <select name="status" class="mt-1 block min-h-10 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All</option>
                                @foreach($statuses as $status)
                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label($status) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50">
                            Apply
                        </button>
                    </form>
                </div>

                <div data-sat-model-empty @class(['hidden' => $hasModels])>
                    <div class="mx-auto flex max-w-lg flex-col items-center px-6 py-16 text-center">
                        <div class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-700 ring-1 ring-gray-200">
                            <svg class="size-6" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M4 3.75A1.75 1.75 0 0 1 5.75 2h8.5A1.75 1.75 0 0 1 16 3.75v12.5A1.75 1.75 0 0 1 14.25 18h-8.5A1.75 1.75 0 0 1 4 16.25V3.75Zm2.25.75a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.5a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" />
                            </svg>
                        </div>
                        <h3 class="mt-4 text-base font-semibold text-gray-950">No SAT models yet</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600">Create a model with the seasons it should learn from and the season it should test against.</p>
                        <button
                            type="button"
                            class="mt-5 inline-flex min-h-10 items-center gap-2 rounded-md bg-gray-950 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800"
                            @click="createOpen = true"
                        >
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                            </svg>
                            Create Model
                        </button>
                    </div>
                </div>

                <div data-sat-model-table @class(['hidden' => ! $hasModels])>
                    <div class="overflow-visible">
                        <table class="block w-full divide-y divide-gray-200 text-left text-sm lg:table lg:text-xs">
                            <thead class="hidden bg-gray-50 text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:table-header-group">
                                <tr>
                                    <th class="px-4 py-2.5">Name</th>
                                    <th class="px-4 py-2.5">Version</th>
                                    <th class="px-4 py-2.5">Training Seasons</th>
                                    <th class="px-4 py-2.5">Test Season</th>
                                    <th class="px-4 py-2.5">Excluded</th>
                                    <th class="px-4 py-2.5">Status</th>
                                    <th class="px-4 py-2.5">Updated</th>
                                    <th class="px-4 py-2.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="block divide-y divide-gray-100 bg-white lg:table-row-group" data-sat-model-rows>
                                @foreach($runs as $run)
                                    @include('admin.nhl-sat-models._model-row', [
                                        'pregameBuild' => $pregameBuilds[$run->id] ?? null,
                                        'pregameSchedule' => $pregameSchedules[$run->id] ?? null,
                                        'comparisonState' => $comparisonStates[$run->id] ?? null,
                                        'genericBucketStabilityState' => $genericBucketStabilityStates[$run->id] ?? null,
                                        'run' => $run,
                                        'toiProjectionState' => $toiProjectionStates[$run->id] ?? null,
                                        'trainingDriftState' => $trainingDriftStates[$run->id] ?? null,
                                        'trainingSummary' => $trainingSummaries[$run->id] ?? null,
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-gray-200 px-5 py-3">
                        {{ $runs->links() }}
                    </div>
                </div>
            </section>
        </div>

        <x-ui.slide-over show="pregameScheduleOpen" close-action="pregameScheduleOpen = false" title-id="pregame-auto-sync-title" max-width="max-w-lg">
            <form class="flex h-full max-h-[100dvh] min-h-0 w-full flex-col" data-pregame-auto-sync-form>
                <div class="shrink-0 border-b border-gray-200 px-6 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="pregame-auto-sync-title" class="text-sm font-semibold text-gray-950">Automatic Pregame Updates</h2>
                            <p class="mt-1 text-xs text-gray-500" data-pregame-schedule-model></p>
                        </div>
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900" @click="pregameScheduleOpen = false" aria-label="Close pregame auto-sync settings">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-6 overflow-y-auto overscroll-contain px-6 py-5">
                    <div data-pregame-schedule-error class="hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"></div>
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-gray-900">Include in auto-sync</legend>
                        <label class="flex min-h-12 items-center justify-between gap-4 rounded-md border border-gray-200 px-3 py-2">
                            <span>
                                <span class="block text-sm font-medium text-gray-800">Update Pregame</span>
                                <span class="mt-0.5 block text-xs text-gray-500">Refresh this model’s Train and Test context data.</span>
                            </span>
                            <input type="checkbox" name="include_update_pregame" value="1" data-pregame-schedule-included class="size-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        </label>
                    </fieldset>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Frequency</span>
                        <select name="frequency_hours" data-pregame-schedule-frequency class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="1">Every hour</option>
                            <option value="3">Every 3 hours</option>
                            <option value="6">Every 6 hours</option>
                            <option value="12">Every 12 hours</option>
                            <option value="24">Daily</option>
                            <option value="48">Every 2 days</option>
                            <option value="168">Weekly</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Time of day</span>
                        <input type="time" name="start_time" data-pregame-schedule-time required class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <span class="mt-1 block text-xs text-gray-500">America/Toronto. Daily and longer intervals keep this local start time.</span>
                    </label>

                    <dl class="space-y-3 border-t border-gray-200 pt-4 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">Last automatic update</dt><dd class="text-right font-medium text-gray-800" data-pregame-schedule-last-run>Never</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">Next scheduled update</dt><dd class="text-right font-medium text-gray-800" data-pregame-schedule-next-run>Not scheduled</dd></div>
                        <div><dt class="text-gray-500">Latest build</dt><dd class="mt-1 font-medium text-gray-800" data-pregame-schedule-latest-build>Not built</dd></div>
                        <div data-pregame-schedule-last-error class="hidden text-red-700"></div>
                    </dl>
                </div>

                <div class="shrink-0 border-t border-gray-200 px-6 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                    <div class="flex justify-end gap-2">
                        <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50" @click="pregameScheduleOpen = false">Close</button>
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-md bg-gray-950 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60">Save settings</button>
                    </div>
                </div>
            </form>
        </x-ui.slide-over>

        <x-ui.slide-over show="createOpen" close-action="createOpen = false" title-id="sat-model-create-title" max-width="max-w-xl">
            <form method="POST" action="{{ route('admin.nhl-sat-models.store') }}" class="flex h-full max-h-[100dvh] min-h-0 w-full flex-col" data-sat-model-create-form>
                @csrf
                <div class="shrink-0 border-b border-gray-200 px-6 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 id="sat-model-create-title" class="text-sm font-semibold text-gray-950">Create Model</h2>
                            <p class="mt-1 text-xs text-gray-500">Choose exactly what this SAT model learns from.</p>
                        </div>
                        <button type="button" class="inline-flex size-8 items-center justify-center rounded-md text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900" @click="createOpen = false" aria-label="Close create model">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain px-6 py-5">
                    <div data-sat-model-form-errors class="hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"></div>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Model Name</span>
                        <input type="text" name="name" value="{{ old('name') }}" required class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Version</span>
                        <input type="text" name="model_version" value="{{ old('model_version', 'sat_v1') }}" required class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </label>

                    <div>
                        <div class="text-sm font-medium text-gray-700">Training Seasons</div>
                        @if($seasonOptions === [])
                            <div class="mt-2 rounded-md border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500">
                                No shot-attempt fact seasons are available.
                            </div>
                        @else
                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach($seasonOptions as $season)
                                    <label class="flex min-h-10 items-center gap-3 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-700 transition-colors hover:bg-gray-50">
                                        <input type="checkbox" name="train_season_ids[]" value="{{ $season }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked(in_array($season, old('train_season_ids', []), true))>
                                        <span class="font-medium">{{ $season }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Test Season</span>
                        <select name="test_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">None</option>
                            @foreach($seasonOptions as $season)
                                <option value="{{ $season }}" @selected(old('test_season_id') === $season)>{{ $season }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Projection Season</span>
                        <input type="text" name="projection_season_id" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" placeholder="20262027" value="{{ old('projection_season_id') }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <span class="mt-1 block text-xs text-gray-500">Optional. Defaults to the test season, or the season after the latest training season when Test Season is None.</span>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">Notes</span>
                        <textarea name="notes" rows="4" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                    </label>
                </div>

                <div class="shrink-0 border-t border-gray-200 px-6 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                    <div class="flex justify-end gap-2">
                        <button type="button" class="inline-flex min-h-10 items-center justify-center rounded-md border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50" @click="createOpen = false">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-md bg-gray-950 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60" @disabled($seasonOptions === [])>
                            Create Model
                        </button>
                    </div>
                </div>
            </form>
        </x-ui.slide-over>
    </div>
</x-app-layout>
