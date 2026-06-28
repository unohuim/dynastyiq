@php
    $embedded = $embedded ?? false;
    $triageIndexUrl = $embedded ? route('admin.dashboard') : route('admin.player-triage');
    $statusTone = [
        'conflict' => 'bg-red-50 text-red-700 ring-red-200',
        'candidate' => 'bg-yellow-50 text-yellow-800 ring-yellow-200',
        'unmatched' => 'bg-gray-100 text-gray-700 ring-gray-200',
        'matched' => 'bg-green-50 text-green-700 ring-green-200',
        'ignored' => 'bg-gray-50 text-gray-500 ring-gray-200',
    ];
@endphp

            <div class="mb-3 border border-gray-200 bg-white px-4 py-3">
                <form method="GET" action="{{ $triageIndexUrl }}" class="flex flex-col gap-3 lg:flex-row lg:items-end" data-prune-empty-get-fields>
                    <div>
                        <label for="source" class="block text-xs font-medium uppercase tracking-wide text-gray-500">Source</label>
                        <select
                            id="source"
                            name="source"
                            class="mt-1 block w-full min-w-0 border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 lg:w-48"
                        >
                            <option value="">All sources</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider }}" @selected($filters['source'] === $provider)>
                                    {{ ucfirst($provider) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="matching_source" class="block text-xs font-medium uppercase tracking-wide text-gray-500">Matching Source</label>
                        <select
                            id="matching_source"
                            name="matching_source"
                            class="mt-1 block w-full min-w-0 border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 lg:w-48"
                        >
                            <option value="">No source match</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider }}" @selected($filters['matching_source'] === $provider)>
                                    {{ ucfirst($provider) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="search" class="block text-xs font-medium uppercase tracking-wide text-gray-500">Find player</label>
                        <input
                            id="search"
                            name="search"
                            value="{{ $filters['search'] }}"
                            class="mt-1 block w-full min-w-0 border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 lg:w-96"
                            placeholder="Name, provider ID, or slug"
                        />
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex min-h-10 items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                name="include_resolved"
                                value="1"
                                class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                @checked($filters['include_resolved'])
                            />
                            <span>All</span>
                        </label>
                        <label class="inline-flex min-h-10 items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                name="matched"
                                value="1"
                                class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                @checked($filters['matched'])
                            />
                            <span>Matched</span>
                        </label>
                        <x-primary-button type="submit">Apply</x-primary-button>
                        <a href="{{ $triageIndexUrl }}" class="inline-flex min-h-10 items-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Reset</a>
                    </div>
                </form>

                @if(session('status'))
                    <div class="mt-3 border-t border-gray-100 pt-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif
            </div>

            <div class="grid gap-3 lg:grid-cols-[minmax(360px,0.9fr)_minmax(0,1.6fr)]">
                <section class="min-h-[72vh] border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold text-gray-900">
                                Player Inbox (<span data-player-triage-inbox-count>{{ $inboxCount }}</span>)
                                <span class="sr-only">Player Inbox ({{ $inboxCount }})</span>
                            </div>
                            <div class="text-xs text-gray-500">
                                @if($filters['source'] && $filters['matching_source'])
                                    {{ ucfirst($filters['source']) }} {{ $filters['matched'] ? 'with' : 'without' }} {{ ucfirst($filters['matching_source']) }}
                                @elseif($filters['source'])
                                    {{ ucfirst($filters['source']) }} no player record
                                @else
                                    {{ $filters['low_confidence_only'] ? '<75%' : 'All' }}
                                @endif
                            </div>
                        </div>
                    </div>

                    @if($identities->isEmpty())
                        <div class="px-4 py-10 text-sm text-gray-600">
                            No identities match the current filters.
                        </div>
                    @else
                        <div class="divide-y divide-gray-100">
                            @foreach($identities as $identity)
                                @php
                                    $selected = $selectedIdentity?->id === $identity->id;
                                    $query = array_merge(request()->query(), ['identity' => $identity->id]);
                                    $recommendation = $recommendations[$identity->id] ?? null;
                                    $usesSourceCoverage = $filters['source'] && $filters['matching_source'];
                                    $coverageLabel = $usesSourceCoverage
                                        ? ($filters['matched'] ? 'has '.$filters['matching_source'] : 'missing '.$filters['matching_source'])
                                        : null;
                                @endphp
                                <a
                                    href="{{ $triageIndexUrl }}?{{ http_build_query($query) }}"
                                    class="block px-4 py-3 {{ $selected ? 'bg-indigo-50' : 'bg-white hover:bg-gray-50' }}"
                                    @if($selected) data-player-triage-selected-row @endif
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-gray-900">
                                                {{ $identity->display_name ?: 'Unnamed identity' }}
                                            </div>
                                            <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                                <span>{{ ucfirst($identity->provider) }}</span>
                                                <span>{{ $identity->position ?: 'No position' }}</span>
                                                <span>{{ $identity->team ?: 'No team' }}</span>
                                            </div>
                                        </div>
                                        <span
                                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $usesSourceCoverage ? ($filters['matched'] ? 'bg-green-50 text-green-700 ring-green-200' : 'bg-red-50 text-red-700 ring-red-200') : ($statusTone[$identity->match_status] ?? 'bg-gray-50 text-gray-600 ring-gray-200') }}"
                                            @if($selected) data-player-triage-selected-row-badge @endif
                                        >
                                            {{ $usesSourceCoverage ? $coverageLabel : $identity->match_status }}
                                        </span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                        <span>ID {{ $identity->provider_player_id }}</span>
                                        @if($usesSourceCoverage)
                                            <span>{{ ucfirst($identity->match_status) }} {{ $identity->match_confidence !== null ? 'at '.$identity->match_confidence.'%' : 'source identity' }}</span>
                                        @else
                                            @if($recommendation?->confidence !== null)
                                                <span>{{ $recommendation->confidence }}% recommendation</span>
                                            @endif
                                            @if($recommendation && $recommendation->status !== $identity->match_status)
                                                <span>recommends {{ $recommendation->status }}</span>
                                            @endif
                                        @endif
                                        @if($identity->unmatched_reason)
                                            <span>{{ str_replace('_', ' ', $identity->unmatched_reason) }}</span>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section
                    class="min-h-[72vh] border border-gray-200 bg-white lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto"
                    data-player-triage
                >
                    @if(! $selectedIdentity)
                        <div class="px-6 py-12 text-sm text-gray-600">
                            Select an identity from the inbox to review match details.
                        </div>
                    @else
                        @php
                            $selectedRecommendation = $recommendations[$selectedIdentity->id] ?? null;
                            $usesCoverageDetail = $filters['source'] && $filters['matching_source'];
                            $hasMatchingSourceIdentity = $selectedMatchingSourceIdentity !== null;
                            $detailCoverageLabel = $usesCoverageDetail
                                ? ($hasMatchingSourceIdentity ? 'has '.$filters['matching_source'] : 'missing '.$filters['matching_source'])
                                : null;
                            $hasPlayerRecord = $selectedIdentity->player !== null;
                            $detailBadgeTone = $hasPlayerRecord
                                ? 'bg-green-50 text-green-700 ring-green-200'
                                : ($usesCoverageDetail
                                    ? ($hasMatchingSourceIdentity ? 'bg-green-50 text-green-700 ring-green-200' : 'bg-red-50 text-red-700 ring-red-200')
                                    : ($statusTone[$selectedIdentity->match_status] ?? 'bg-gray-50 text-gray-600 ring-gray-200'));
                        @endphp
                        <div class="border-b border-gray-200 px-6 py-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    @if($hasPlayerRecord)
                                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Player Record</div>
                                        <h3 class="mt-1 text-2xl font-semibold text-gray-900">{{ $selectedIdentity->player->full_name }}</h3>
                                        @if($currentContract)
                                            @php
                                                $lastContractSeason = $currentContract->seasons
                                                    ->sortByDesc('season_key')
                                                    ->first();
                                            @endphp
                                            <div class="mt-3 text-sm text-gray-700">
                                                <span class="font-semibold text-gray-900">Last Contract:</span>
                                                {{ $currentContract->contract_type }}
                                                @if($currentContract->contract_length)
                                                    &middot; {{ $currentContract->contract_length }}
                                                @endif
                                                @if($currentContract->contract_value !== null)
                                                    &middot; ${{ number_format($currentContract->contract_value) }}
                                                @endif
                                                @if($lastContractSeason?->label)
                                                    &middot; {{ $lastContractSeason->label }}
                                                @endif
                                            </div>
                                        @endif
                                    @else
                                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ ucfirst($selectedIdentity->provider) }} identity</div>
                                        <h3 class="mt-1 text-2xl font-semibold text-gray-900">{{ $selectedIdentity->display_name ?: 'Unnamed identity' }}</h3>
                                        <div class="mt-2 flex flex-wrap gap-2 text-sm text-gray-600">
                                            <span>{{ $selectedIdentity->provider_player_id }}</span>
                                            @if($selectedIdentity->provider_slug)
                                                <span>{{ $selectedIdentity->provider_slug }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <span class="rounded-full px-3 py-1 text-sm font-medium ring-1 {{ $detailBadgeTone }}" data-player-triage-detail-badge>
                                    {{ $hasPlayerRecord ? 'linked' : ($usesCoverageDetail ? $detailCoverageLabel : $selectedIdentity->match_status) }}
                                </span>
                            </div>
                        </div>

                        @if($hasPlayerRecord)
                            <div class="border-t border-gray-200 px-6 py-5">
                                <h4 class="text-sm font-semibold text-gray-900">Player Record</h4>
                                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-3">
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">DOB</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player->dob ? \Illuminate\Support\Carbon::parse($selectedIdentity->player->dob)->format('M j, Y') : 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Position</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player->position ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Team</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player->team_abbrev ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">NHL ID</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player->nhl_id ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Status</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player->status ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Prospect</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player->is_prospect ? 'Yes' : 'No' }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="border-t border-gray-200 px-6 py-5 {{ $linkedSourceIdentities->isEmpty() ? 'hidden' : '' }}" data-player-triage-linked-sources-section>
                                <h4 class="text-sm font-semibold text-gray-900">Linked External Sources</h4>
                                <div class="mt-4 divide-y divide-gray-100 border border-gray-200" data-player-triage-linked-sources-list>
                                    @foreach($linkedSourceIdentities as $linkedIdentity)
                                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-gray-900">{{ ucfirst($linkedIdentity->provider) }}</div>
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $linkedIdentity->display_name ?: 'Unnamed identity' }} &middot; {{ $linkedIdentity->provider_player_id }} &middot; {{ $linkedIdentity->team ?: 'No team' }} &middot; {{ $linkedIdentity->position ?: 'No position' }}
                                                </div>
                                            </div>
                                            <span class="shrink-0 rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 ring-1 ring-green-200">Linked</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            @if($suggestedExternalMatches->isNotEmpty())
                                <div class="border-t border-gray-200 px-6 py-5">
                                    <h4 class="text-sm font-semibold text-gray-900">Suggested External Records</h4>
                                    <p class="mt-1 text-sm text-gray-600">These records look like the same player and can be linked to {{ $selectedIdentity->player->full_name }}.</p>
                                    <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                        @foreach($suggestedExternalMatches as $externalMatch)
                                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                                <div class="min-w-0">
                                                    <div class="text-sm font-semibold text-gray-900">{{ ucfirst($externalMatch->provider) }}</div>
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        {{ $externalMatch->display_name ?: 'Unnamed identity' }} &middot; {{ $externalMatch->provider_player_id }} &middot; {{ $externalMatch->team ?: 'No team' }} &middot; {{ $externalMatch->position ?: 'No position' }}
                                                    </div>
                                                </div>
                                                <form method="POST" action="{{ route('admin.player-triage.link-external-source', $selectedIdentity) }}" class="shrink-0">
                                                    @csrf
                                                    @if($embedded)
                                                        <input type="hidden" name="admin_panel" value="1">
                                                    @endif
                                                    <input type="hidden" name="external_identity_id" value="{{ $externalMatch->id }}">
                                                    <x-primary-button type="submit">Link to this player</x-primary-button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if($usesCoverageDetail && ! $hasMatchingSourceIdentity)
                                <div class="border-t border-gray-200 px-6 py-5">
                                    <h4 class="text-sm font-semibold text-gray-900">Matching Source Search</h4>
                                    <form method="GET" action="{{ $triageIndexUrl }}" class="mt-3 flex flex-col gap-3 sm:flex-row">
                                        @foreach(request()->except(['matching_identity_search']) as $key => $value)
                                            @if(is_array($value))
                                                @foreach($value as $item)
                                                    <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                                                @endforeach
                                            @else
                                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                            @endif
                                        @endforeach
                                        <input type="hidden" name="identity" value="{{ $selectedIdentity->id }}">
                                        <input
                                            name="matching_identity_search"
                                            value="{{ request('matching_identity_search') }}"
                                            class="block min-h-10 flex-1 border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="Search {{ ucfirst($filters['matching_source']) }} identities"
                                        />
                                        <x-secondary-button type="submit">Search</x-secondary-button>
                                    </form>
                                </div>
                            @endif

                            @if($usesCoverageDetail && ! $hasMatchingSourceIdentity && $matchingSourceSearchResults->isNotEmpty())
                                <div class="border-t border-gray-200 px-6 py-5">
                                    <h4 class="text-sm font-semibold text-gray-900">Search Results</h4>
                                    <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                        @foreach($matchingSourceSearchResults as $matchingIdentity)
                                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900">{{ $matchingIdentity->display_name ?: 'Unnamed identity' }}</div>
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        {{ $matchingIdentity->provider_player_id }} &middot; {{ $matchingIdentity->team ?: 'No team' }} &middot; {{ $matchingIdentity->position ?: 'No position' }} &middot; {{ $matchingIdentity->match_status }}
                                                    </div>
                                                </div>
                                                <form method="POST" action="{{ route('admin.player-triage.link-matching-source', $selectedIdentity) }}" data-player-triage-link-form>
                                                    @csrf
                                                    @if($embedded)
                                                        <input type="hidden" name="admin_panel" value="1">
                                                    @endif
                                                    <input type="hidden" name="matching_identity_id" value="{{ $matchingIdentity->id }}">
                                                    <x-primary-button type="submit">Link source</x-primary-button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="border-t border-gray-200 px-6 py-5">
                                <details>
                                    <summary class="cursor-pointer text-sm font-semibold text-gray-900">Raw Provider Payload</summary>
                                    <pre class="mt-3 max-h-80 overflow-auto bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($selectedIdentity->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </div>
                        @elseif($usesCoverageDetail)
                            <div class="grid gap-6 px-6 py-5 xl:grid-cols-[1fr_1fr]">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">Source Coverage</h4>
                                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-gray-500">Player Record</dt>
                                            <dd class="mt-1 text-gray-900">{{ $selectedIdentity->player?->full_name ?? 'N/A' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-gray-500">Coverage</dt>
                                            <dd class="mt-1 text-gray-900" data-player-triage-coverage-label>{{ ucfirst($detailCoverageLabel) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-gray-500">Source Position</dt>
                                            <dd class="mt-1 text-gray-900">{{ $selectedIdentity->position ?: 'N/A' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-gray-500">Source Team</dt>
                                            <dd class="mt-1 text-gray-900">{{ $selectedIdentity->team ?: 'N/A' }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <div data-player-triage-matched-section @class(['hidden' => ! $hasMatchingSourceIdentity])>
                                    <h4 class="text-sm font-semibold text-gray-900">Matched {{ ucfirst($filters['matching_source']) }} Identity</h4>
                                    <div class="mt-4 border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-950">
                                        <div class="font-semibold" data-player-triage-matched-name>
                                            {{ $selectedMatchingSourceIdentity?->display_name ?: 'Matched identity' }}
                                        </div>
                                        <div class="mt-1 text-xs text-green-800" data-player-triage-matched-meta>
                                            @if($selectedMatchingSourceIdentity)
                                                {{ $selectedMatchingSourceIdentity->provider_player_id }} &middot; {{ $selectedMatchingSourceIdentity->team ?: 'No team' }} &middot; {{ $selectedMatchingSourceIdentity->position ?: 'No position' }} &middot; {{ $selectedMatchingSourceIdentity->match_status }}
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                @if(! $hasMatchingSourceIdentity)
                                    <div data-player-triage-unmatched-section>
                                        <h4 class="text-sm font-semibold text-gray-900">Matching Source Search</h4>
                                        <form method="GET" action="{{ $triageIndexUrl }}" class="mt-3 flex flex-col gap-3 sm:flex-row">
                                            @foreach(request()->except(['matching_identity_search']) as $key => $value)
                                                @if(is_array($value))
                                                    @foreach($value as $item)
                                                        <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                                                    @endforeach
                                                @else
                                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                                @endif
                                            @endforeach
                                            <input type="hidden" name="identity" value="{{ $selectedIdentity->id }}">
                                            <input
                                                name="matching_identity_search"
                                                value="{{ request('matching_identity_search') }}"
                                                class="block min-h-10 flex-1 border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                placeholder="Search {{ ucfirst($filters['matching_source']) }} identities"
                                            />
                                            <x-secondary-button type="submit">Search</x-secondary-button>
                                        </form>
                                    </div>
                                @endif
                            </div>

                            @if(! $hasMatchingSourceIdentity)
                                <div class="border-t border-gray-200 px-6 py-5" data-player-triage-unmatched-section>
                                    <h4 class="text-sm font-semibold text-gray-900">Suggested {{ ucfirst($filters['matching_source']) }} Identities</h4>

                                    @if($matchingSourceCandidates->isEmpty())
                                        <div class="mt-4 border border-gray-200 bg-gray-50 px-4 py-5 text-sm text-gray-600">
                                            No suggested matching-source identities.
                                        </div>
                                    @else
                                        <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                            @foreach($matchingSourceCandidates as $matchingIdentity)
                                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                                    <div>
                                                        <div class="text-sm font-semibold text-gray-900">{{ $matchingIdentity->display_name ?: 'Unnamed identity' }}</div>
                                                        <div class="mt-1 text-xs text-gray-500">
                                                            {{ $matchingIdentity->provider_player_id }} &middot; {{ $matchingIdentity->team ?: 'No team' }} &middot; {{ $matchingIdentity->position ?: 'No position' }} &middot; {{ $matchingIdentity->match_status }}
                                                        </div>
                                                    </div>
                                                    <form method="POST" action="{{ route('admin.player-triage.link-matching-source', $selectedIdentity) }}" data-player-triage-link-form>
                                                        @csrf
                                                        @if($embedded)
                                                            <input type="hidden" name="admin_panel" value="1">
                                                        @endif
                                                        <input type="hidden" name="matching_identity_id" value="{{ $matchingIdentity->id }}">
                                                        <x-primary-button type="submit">Link source</x-primary-button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @if(! $hasMatchingSourceIdentity && $matchingSourceSearchResults->isNotEmpty())
                                <div class="border-t border-gray-200 px-6 py-5" data-player-triage-unmatched-section>
                                    <h4 class="text-sm font-semibold text-gray-900">Search Results</h4>
                                    <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                        @foreach($matchingSourceSearchResults as $matchingIdentity)
                                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900">{{ $matchingIdentity->display_name ?: 'Unnamed identity' }}</div>
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        {{ $matchingIdentity->provider_player_id }} &middot; {{ $matchingIdentity->team ?: 'No team' }} &middot; {{ $matchingIdentity->position ?: 'No position' }} &middot; {{ $matchingIdentity->match_status }}
                                                    </div>
                                                </div>
                                                <form method="POST" action="{{ route('admin.player-triage.link-matching-source', $selectedIdentity) }}" data-player-triage-link-form>
                                                    @csrf
                                                    @if($embedded)
                                                        <input type="hidden" name="admin_panel" value="1">
                                                    @endif
                                                    <input type="hidden" name="matching_identity_id" value="{{ $matchingIdentity->id }}">
                                                    <x-primary-button type="submit">Link source</x-primary-button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="border-t border-gray-200 px-6 py-5 {{ $linkedSourceIdentities->isEmpty() ? 'hidden' : '' }}" data-player-triage-linked-sources-section>
                                <h4 class="text-sm font-semibold text-gray-900">Other Linked Source Identities</h4>
                                <div class="mt-4 divide-y divide-gray-100 border border-gray-200" data-player-triage-linked-sources-list>
                                    @foreach($linkedSourceIdentities as $linkedIdentity)
                                        <div class="px-4 py-3">
                                            <div class="text-sm font-semibold text-gray-900">{{ $linkedIdentity->display_name ?: 'Unnamed identity' }}</div>
                                            <div class="mt-1 text-xs text-gray-500">
                                                {{ ucfirst($linkedIdentity->provider) }} &middot; {{ $linkedIdentity->provider_player_id }} &middot; {{ $linkedIdentity->team ?: 'No team' }} &middot; {{ $linkedIdentity->position ?: 'No position' }} &middot; {{ $linkedIdentity->match_status }}
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                        <div class="grid gap-6 px-6 py-5 xl:grid-cols-[1fr_1fr]">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Source Record</h4>
                                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Normalized</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->normalized_name ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Birthdate</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->birthdate?->toDateString() ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Position</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->position ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Team</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->team ?: 'N/A' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Recommendation</dt>
                                        <dd class="mt-1 text-gray-900">
                                            @if($selectedRecommendation?->confidence !== null)
                                                {{ $selectedRecommendation->confidence }}% {{ $selectedRecommendation->status }}
                                            @else
                                                {{ $selectedRecommendation?->status ?? 'N/A' }}
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-wide text-gray-500">Reason</dt>
                                        <dd class="mt-1 text-gray-900">{{ $selectedIdentity->unmatched_reason ? str_replace('_', ' ', $selectedIdentity->unmatched_reason) : 'N/A' }}</dd>
                                    </div>
                                </dl>

                            </div>
                        </div>

                        @if(! $selectedIdentity->player)
                            <div class="border-t border-gray-200 px-6 py-5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Suggested Player Matches</h4>
                                        <p class="mt-1 text-sm text-gray-600">Suggestions use normalized names and birthdate context where available.</p>
                                    </div>
                                </div>

                                @if($candidatePlayers->isEmpty())
                                    <div class="mt-4 border border-gray-200 bg-gray-50 px-4 py-5 text-sm text-gray-600">
                                        No suggested player matches.
                                    </div>
                                @else
                                    <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                        @foreach($candidatePlayers as $player)
                                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                                <div>
                                                    <div class="text-sm font-semibold text-gray-900">{{ $player->full_name }}</div>
                                                    <div class="mt-1 text-xs text-gray-500">
                                                        {{ $player->team_abbrev ?: 'No team' }} &middot; {{ $player->position ?: 'No position' }} &middot; {{ $player->dob ? \Illuminate\Support\Carbon::parse($player->dob)->toDateString() : 'No birthdate' }} &middot; NHL {{ $player->nhl_id ?: 'N/A' }}
                                                    </div>
                                                </div>
                                                <form method="POST" action="{{ route('admin.player-triage.link', $selectedIdentity) }}">
                                                    @csrf
                                                    @if($embedded)
                                                        <input type="hidden" name="admin_panel" value="1">
                                                    @endif
                                                    <input type="hidden" name="player_id" value="{{ $player->id }}">
                                                    <x-primary-button type="submit">Link</x-primary-button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($suggestedExternalMatches->isNotEmpty())
                            <div class="border-t border-gray-200 px-6 py-5">
                                <h4 class="text-sm font-semibold text-gray-900">Suggested External Records</h4>
                                <p class="mt-1 text-sm text-gray-600">
                                    @if($selectedIdentity->player)
                                        These records look like the same player and can be linked to {{ $selectedIdentity->player->full_name }}.
                                    @else
                                        These records look like the same player. Link the selected identity to a player record before attaching them.
                                    @endif
                                </p>
                                <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                    @foreach($suggestedExternalMatches as $externalMatch)
                                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-gray-900">{{ ucfirst($externalMatch->provider) }}</div>
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $externalMatch->display_name ?: 'Unnamed identity' }} &middot; {{ $externalMatch->provider_player_id }} &middot; {{ $externalMatch->team ?: 'No team' }} &middot; {{ $externalMatch->position ?: 'No position' }}
                                                </div>
                                            </div>
                                            @if($selectedIdentity->player)
                                                <form method="POST" action="{{ route('admin.player-triage.link-external-source', $selectedIdentity) }}" class="shrink-0">
                                                    @csrf
                                                    @if($embedded)
                                                        <input type="hidden" name="admin_panel" value="1">
                                                    @endif
                                                    <input type="hidden" name="external_identity_id" value="{{ $externalMatch->id }}">
                                                    <x-primary-button type="submit">Link to this player</x-primary-button>
                                                </form>
                                            @else
                                                <button type="button" disabled class="inline-flex min-h-10 shrink-0 items-center border border-gray-200 px-4 text-sm font-medium text-gray-400">
                                                    Link after player record
                                                </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if(! $selectedIdentity->player && $candidatePlayers->isEmpty())
                            <div class="border-t border-gray-200 px-6 py-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold text-gray-900">Create Player Record</h4>
                                        <p class="mt-1 text-sm text-gray-600">Use this when the player is real but absent from NHL API records.</p>
                                    </div>
                                </div>

                                <form method="POST" action="{{ route('admin.player-triage.create-canonical', $selectedIdentity) }}" class="mt-4 border border-gray-200 px-4 py-4">
                                    @csrf
                                    @if($embedded)
                                        <input type="hidden" name="admin_panel" value="1">
                                    @endif

                                    @if($suggestedExternalMatches->isNotEmpty())
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">Suggested External Matches</div>
                                            <div class="mt-3 divide-y divide-gray-100 border border-gray-200">
                                                @foreach($suggestedExternalMatches as $externalMatch)
                                                    <label class="flex cursor-pointer items-start gap-3 px-4 py-3">
                                                        <input
                                                            type="checkbox"
                                                            name="external_identity_ids[]"
                                                            value="{{ $externalMatch->id }}"
                                                            class="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                        />
                                                        <span>
                                                            <span class="block text-sm font-semibold text-gray-900">{{ $externalMatch->display_name ?: 'Unnamed identity' }}</span>
                                                            <span class="mt-1 block text-xs text-gray-500">
                                                                {{ ucfirst($externalMatch->provider) }} &middot; {{ $externalMatch->provider_player_id }} &middot; {{ $externalMatch->team ?: 'No team' }} &middot; {{ $externalMatch->position ?: 'No position' }} &middot; {{ $externalMatch->match_status }}
                                                            </span>
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                        <div class="text-sm text-gray-600">No suggested external matches found.</div>
                                    @endif

                                    <div class="mt-4 flex flex-wrap items-center gap-3">
                                        <x-primary-button type="submit">Create player record</x-primary-button>
                                        <span class="text-xs text-gray-500">Creates a prospect player with no NHL ID and links selected external identities.</span>
                                    </div>
                                </form>
                            </div>
                        @endif

                        <div class="border-t border-gray-200 px-6 py-5">
                            <h4 class="text-sm font-semibold text-gray-900">Manual Player Search</h4>
                            <form method="GET" action="{{ $triageIndexUrl }}" class="mt-3 flex flex-col gap-3 sm:flex-row">
                                @foreach(request()->except(['player_search']) as $key => $value)
                                    @if(is_array($value))
                                        @foreach($value as $item)
                                            <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                                        @endforeach
                                    @else
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endforeach
                                <input type="hidden" name="identity" value="{{ $selectedIdentity->id }}">
                                <input
                                    name="player_search"
                                    value="{{ request('player_search') }}"
                                    class="block min-h-10 flex-1 border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Search by player name or NHL ID"
                                />
                                <x-secondary-button type="submit">Search</x-secondary-button>
                            </form>

                            @if($playerSearchResults->isNotEmpty())
                                <div class="mt-4 divide-y divide-gray-100 border border-gray-200">
                                    @foreach($playerSearchResults as $player)
                                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">{{ $player->full_name }}</div>
                                                <div class="mt-1 text-xs text-gray-500">
                                                    {{ $player->team_abbrev ?: 'No team' }} &middot; {{ $player->position ?: 'No position' }} &middot; {{ $player->dob ? \Illuminate\Support\Carbon::parse($player->dob)->toDateString() : 'No birthdate' }} &middot; NHL {{ $player->nhl_id ?: 'N/A' }}
                                                </div>
                                            </div>
                                            <form method="POST" action="{{ route('admin.player-triage.link', $selectedIdentity) }}">
                                                @csrf
                                                @if($embedded)
                                                    <input type="hidden" name="admin_panel" value="1">
                                                @endif
                                                <input type="hidden" name="player_id" value="{{ $player->id }}">
                                                <x-primary-button type="submit">Link</x-primary-button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="border-t border-gray-200 px-6 py-5">
                            <details>
                                <summary class="cursor-pointer text-sm font-semibold text-gray-900">Raw Provider Payload</summary>
                                <pre class="mt-3 max-h-80 overflow-auto bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($selectedIdentity->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        </div>
                        @endif
                    @endif
                </section>
            </div>
