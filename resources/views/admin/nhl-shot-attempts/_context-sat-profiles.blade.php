<div class="space-y-4 p-4">
    @if(session('status'))
        <div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            {{ session('error') }}
        </div>
    @endif

    @unless($contextProfileTablesExist)
        <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            The refs and coaches SAT profile tables are not available in this environment yet. Run the profile migration first.
        </div>
    @else
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-4">
            <div>
                <h4 class="text-sm font-semibold text-gray-900">Refs &amp; Coaches SAT Profiles</h4>
                <p class="mt-1 text-sm text-gray-600">Review referee, linesman, and head-coach SAT profiles by shrunk chance bucket.</p>
            </div>
            <form method="POST" action="{{ route('admin.nhl-shot-attempts.game-context-sat-profiles.build') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Season</span>
                    <input type="text" name="source_season_id" value="{{ $contextProfileFilters['season_id'] ?: ($filters['season_id'] ?: '20252026') }}" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Game Type</span>
                    <input type="number" name="game_type" min="1" max="99" value="{{ $contextProfileFilters['game_type'] ?: 2 }}" class="mt-1 block min-h-9 w-24 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Build</span>
                    <select name="only" class="mt-1 block min-h-9 w-28 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="all">All</option>
                        <option value="officials">Officials</option>
                        <option value="staff">Staff</option>
                    </select>
                </label>
                <button type="submit" class="inline-flex min-h-9 items-center rounded-md bg-slate-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Build Profiles
                </button>
            </form>
        </div>

        <form method="GET" action="{{ route('admin.nhl-shot-attempts.index') }}" class="border-b border-gray-200 pb-4">
            @foreach(request()->except([
                'context_profile_season_id',
                'context_profile_game_type',
                'context_profile_entity_type',
                'context_profile_role',
                'context_profile_team_context',
                'context_profile_entity_search',
                'context_profile_shot_type_group',
                'context_profile_distance_group',
                'context_profile_angle_group',
                'context_profile_sequence_group',
                'context_profile_min_sat',
                'context_profile_useful_only',
                'page',
            ]) as $key => $value)
                @if(is_scalar($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <input type="hidden" name="tab" value="context-sat-profiles">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-11">
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Season</span>
                    <select name="context_profile_season_id" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['seasons'] as $season)
                            <option value="{{ $season }}" @selected((string) $contextProfileFilters['season_id'] === (string) $season)>{{ $season }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Game Type</span>
                    <select name="context_profile_game_type" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['gameTypes'] as $gameType)
                            <option value="{{ $gameType }}" @selected((string) $contextProfileFilters['game_type'] === (string) $gameType)>{{ $gameType }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Entity</span>
                    <select name="context_profile_entity_type" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        <option value="official" @selected((string) $contextProfileFilters['entity_type'] === 'official')>Official</option>
                        <option value="staff" @selected((string) $contextProfileFilters['entity_type'] === 'staff')>Staff</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Role</span>
                    <select name="context_profile_role" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['roles'] as $role)
                            <option value="{{ $role }}" @selected((string) $contextProfileFilters['role'] === (string) $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Context</span>
                    <select name="context_profile_team_context" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['teamContexts'] as $teamContext)
                            <option value="{{ $teamContext }}" @selected((string) $contextProfileFilters['team_context'] === (string) $teamContext)>{{ $teamContext }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Name</span>
                    <input type="search" name="context_profile_entity_search" value="{{ $contextProfileFilters['entity_search'] }}" placeholder="Name" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Shot Type</span>
                    <select name="context_profile_shot_type_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['shotTypes'] as $shotType)
                            <option value="{{ $shotType }}" @selected((string) $contextProfileFilters['shot_type_group'] === (string) $shotType)>{{ $shotType }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Distance</span>
                    <select name="context_profile_distance_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['distances'] as $distance)
                            <option value="{{ $distance }}" @selected((string) $contextProfileFilters['distance_group'] === (string) $distance)>{{ $distance }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Angle</span>
                    <select name="context_profile_angle_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['angles'] as $angle)
                            <option value="{{ $angle }}" @selected((string) $contextProfileFilters['angle_group'] === (string) $angle)>{{ $angle }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Sequence</span>
                    <select name="context_profile_sequence_group" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All</option>
                        @foreach($contextProfileOptions['sequences'] as $sequence)
                            <option value="{{ $sequence }}" @selected((string) $contextProfileFilters['sequence_group'] === (string) $sequence)>{{ $sequence }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-gray-600">Min SAT</span>
                    <input type="number" name="context_profile_min_sat" min="1" max="10000" value="{{ $contextProfileFilters['min_sat'] }}" class="mt-1 block min-h-10 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </label>
                <label class="flex min-h-10 items-center gap-2 self-end rounded-md border border-gray-200 px-3 text-sm text-gray-700">
                    <input type="checkbox" name="context_profile_useful_only" value="1" @checked($contextProfileFilters['useful_only']) class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <span>Useful only</span>
                    <span class="text-[10px] text-gray-500">25 SAT / 25% borrowed</span>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <a href="{{ route('admin.nhl-shot-attempts.index', ['tab' => 'context-sat-profiles']) }}" class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Clear</a>
                <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Apply</button>
            </div>
        </form>

        <div class="space-y-3" data-context-sat-accordions>
            <section class="border border-gray-200 bg-white" data-context-sat-section data-url="{{ route('admin.nhl-shot-attempts.context-sat-profiles.aggregate', request()->except('page')) }}">
                <button type="button" class="flex w-full items-center justify-between px-4 py-3 text-left" data-context-sat-section-toggle aria-expanded="false">
                    <span>
                        <span class="block text-xs font-semibold uppercase text-gray-600">Aggregate Profiles</span>
                        <span class="mt-1 block text-xs text-gray-500">Readable buckets merged from exact SAT buckets.</span>
                    </span>
                    <span class="text-lg text-gray-500" data-context-sat-section-icon>+</span>
                </button>
                <div class="hidden border-t border-gray-200 p-4" data-context-sat-section-panel>
                    <div class="text-xs text-gray-500" data-context-sat-section-status>Open this section to load aggregate profiles.</div>
                    <div data-context-sat-section-content></div>
                </div>
            </section>

            <section class="border border-gray-200 bg-white" data-context-sat-section data-url="{{ route('admin.nhl-shot-attempts.context-sat-profiles.bucket-comparisons', request()->except('page')) }}">
                <button type="button" class="flex w-full items-center justify-between px-4 py-3 text-left" data-context-sat-section-toggle aria-expanded="false">
                    <span>
                        <span class="block text-xs font-semibold uppercase text-gray-600">Bucket Comparisons</span>
                        <span class="mt-1 block text-xs text-gray-500">Group each aggregate bucket and compare all matching coaches and refs.</span>
                    </span>
                    <span class="text-lg text-gray-500" data-context-sat-section-icon>+</span>
                </button>
                <div class="hidden border-t border-gray-200 p-4" data-context-sat-section-panel>
                    <div class="text-xs text-gray-500" data-context-sat-section-status>Open this section to load bucket comparisons.</div>
                    <div data-context-sat-section-content></div>
                </div>
            </section>

            <section class="border border-gray-200 bg-white" data-context-sat-section data-url="{{ route('admin.nhl-shot-attempts.context-sat-profiles.exact', request()->except('page')) }}">
                <button type="button" class="flex w-full items-center justify-between px-4 py-3 text-left" data-context-sat-section-toggle aria-expanded="false">
                    <span>
                        <span class="block text-xs font-semibold uppercase text-gray-600">Exact Bucket Detail</span>
                        <span class="mt-1 block text-xs text-gray-500">Auditable granular rows with shrinkage provenance.</span>
                    </span>
                    <span class="text-lg text-gray-500" data-context-sat-section-icon>+</span>
                </button>
                <div class="hidden border-t border-gray-200 p-4" data-context-sat-section-panel>
                    <div class="text-xs text-gray-500" data-context-sat-section-status>Open this section to load exact bucket detail.</div>
                    <div data-context-sat-section-content></div>
                </div>
            </section>
        </div>
    @endunless
</div>
