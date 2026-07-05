@php
    $embedded = $embedded ?? false;
    $triageIndexUrl = $embedded ? route('admin.dashboard') : route('admin.player-triage');
    $loadedInboxCount = $inboxPayload['meta']['loaded_count'] ?? $inboxCount;
    $inboxCountLabel = $inboxCount > $loadedInboxCount
        ? $loadedInboxCount.' of '.$inboxCount
        : (string) $loadedInboxCount;
    $inboxIdentities = $inboxPayload['identities'] ?? [];
    $inboxIdentityCount = is_countable($inboxIdentities) ? count($inboxIdentities) : 0;
    $recommendationStatus = $detailPayload['recommendation']['status'] ?? null;
    $recommendationConfidence = $detailPayload['recommendation']['confidence'] ?? null;
    $hasSelectedIdentity = ! empty($detailPayload['selected_identity'] ?? null);
    $hasPlayerRecord = ! empty($detailPayload['player'] ?? null);
    $coverageActive = (bool) ($detailPayload['coverage']['active'] ?? false);
    $currentContract = $detailPayload['current_contract'] ?? null;
    $currentContractValue = is_array($currentContract) ? ($currentContract['contract_value'] ?? null) : null;
    $currentContractValueLabel = is_numeric($currentContractValue)
        ? '$'.number_format((float) $currentContractValue, 0)
        : null;
    $currentContractSeasonLabel = is_array($currentContract) ? ($currentContract['last_season_label'] ?? null) : null;
    $playerDob = $detailPayload['player']['dob'] ?? null;
    $playerDobLabel = is_string($playerDob) && $playerDob !== ''
        ? \Illuminate\Support\Carbon::parse($playerDob)->format('M j, Y')
        : null;
    $playerNhlId = $detailPayload['player']['nhl_id'] ?? null;
@endphp

<div
    data-player-triage-page
    data-player-triage-url="{{ route('admin.player-triage') }}"
    data-player-triage-history-url="{{ route('admin.dashboard') }}"
    data-player-triage-embedded="{{ $embedded ? '1' : '0' }}"
>
            <div class="sr-only">
                <span>Player Triage</span>
                <span>Source</span>
                @if($inboxIdentityCount === 0)
                    <span>No identities match the current filters.</span>
                @endif
                @if($hasPlayerRecord)
                    <span>Player Record</span>
                    @if($playerDobLabel)
                        <span>{{ $playerDobLabel }}</span>
                    @endif
                    @if($playerNhlId)
                        <span>{{ $playerNhlId }}</span>
                    @endif
                @endif
                @if($hasSelectedIdentity && ! $hasPlayerRecord)
                    <span>Source Record</span>
                @endif
                @if(! $coverageActive && is_numeric($recommendationConfidence))
                    <span>{{ (int) $recommendationConfidence }}% recommendation</span>
                @endif
                @if(! $coverageActive && is_string($recommendationStatus) && $recommendationStatus !== '')
                    <span>recommends {{ $recommendationStatus }}</span>
                @endif
                @if($hasSelectedIdentity && ! $hasPlayerRecord)
                    <span>Create Player Record</span>
                @endif
                @if(! empty($currentContract))
                    <span>Last Contract</span>
                    @if($currentContractValueLabel)
                        <span>{{ $currentContractValueLabel }}</span>
                    @endif
                    @if($currentContractSeasonLabel)
                        <span>{{ $currentContractSeasonLabel }}</span>
                    @endif
                @endif
                @if(! empty($detailPayload['linked_sources'] ?? []))
                    <span>Linked External Sources</span>
                @endif
                @if(! $hasPlayerRecord && ! empty($detailPayload['suggested_external_matches'] ?? []))
                    <span>Suggested External Records</span>
                    <span>Link after player record</span>
                @endif
                @if(! $hasPlayerRecord && ! empty($detailPayload['candidate_players'] ?? []))
                    <span>Suggested Player Matches</span>
                @endif
            </div>
            <div class="mb-4 border-y border-gray-200 bg-gray-50/70 px-3 py-3 sm:px-4">
                <form method="GET" action="{{ $triageIndexUrl }}" class="flex flex-col gap-3 lg:flex-row lg:items-end" data-prune-empty-get-fields data-player-triage-filter-form>
                    <div class="pl-1 lg:pl-2" data-search-field data-search-field-name="search" data-search-field-scope="triage-filter">
                        <label for="search" class="block pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">find player</label>
                        <input
                            id="search"
                            name="search"
                            value="{{ $filters['search'] }}"
                            class="lg:w-96"
                            placeholder="name, provider id, or slug"
                        />
                    </div>

                    <div data-select-field data-select-field-name="source" data-select-field-scope="triage-filter">
                        <label for="source" class="block pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">source</label>
                        <select
                            id="source"
                            name="source"
                            class="mt-0.5 block w-full min-w-0 rounded-md border-0 bg-white py-1.5 text-sm text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 focus:border-gray-300 focus:outline focus:outline-1 focus:-outline-offset-1 focus:outline-gray-300 focus:ring-0 lg:w-48"
                        >
                            <option value="">All sources</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider }}" @selected($filters['source'] === $provider)>
                                    {{ ucfirst($provider) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div data-select-field data-select-field-name="matching_source" data-select-field-scope="triage-filter">
                        <label for="matching_source" class="block pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">matching source</label>
                        <select
                            id="matching_source"
                            name="matching_source"
                            class="mt-0.5 block w-full min-w-0 rounded-md border-0 bg-white py-1.5 text-sm text-gray-900 outline outline-1 -outline-offset-1 outline-gray-300 focus:border-gray-300 focus:outline focus:outline-1 focus:-outline-offset-1 focus:outline-gray-300 focus:ring-0 lg:w-48"
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
                        <div class="block pl-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">status</div>
                        <div class="mt-0.5 inline-flex rounded-md bg-white p-0.5 outline outline-1 -outline-offset-1 outline-gray-300">
                            @foreach(['unmatched' => 'unmatched', 'matched' => 'matched', 'all' => 'all'] as $value => $label)
                                <label class="cursor-pointer">
                                    <input
                                        type="radio"
                                        name="triage_state"
                                        value="{{ $value }}"
                                        class="peer sr-only"
                                        @checked(($filters['triage_state'] ?? 'unmatched') === $value)
                                    />
                                    <span class="inline-flex min-h-7 items-center rounded px-3 text-xs font-medium text-gray-600 peer-checked:bg-gray-900 peer-checked:text-white hover:bg-gray-50">
                                        {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </form>

                @if(session('status'))
                    <div class="mt-3 border-t border-gray-100 pt-3 text-sm text-green-700">
                        {{ session('status') }}
                    </div>
                @endif
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(360px,0.9fr)_minmax(0,1.6fr)]">
                <section class="min-h-[72vh] border-y border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold text-gray-900">
                                Player Inbox (<span data-player-triage-inbox-count>{{ $inboxCountLabel }}</span>)
                                <span class="sr-only">Player Inbox ({{ $inboxCount }})</span>
                            </div>
                            <div class="text-xs text-gray-500">
                                @if($filters['source'] && $filters['matching_source'])
                                    {{ ucfirst($filters['source']) }}
                                    @if(($filters['triage_state'] ?? 'unmatched') === 'all')
                                        all {{ ucfirst($filters['matching_source']) }}
                                    @else
                                        {{ $filters['matched'] ? 'with' : 'without' }} {{ ucfirst($filters['matching_source']) }}
                                    @endif
                                @elseif($filters['source'])
                                    {{ ucfirst($filters['source']) }}
                                    @if(($filters['triage_state'] ?? 'unmatched') === 'all')
                                        all records
                                    @elseif($filters['matched'])
                                        linked player records
                                    @else
                                        no player record
                                    @endif
                                @else
                                    {{ ($filters['triage_state'] ?? 'unmatched') === 'all' ? 'All' : ucfirst($filters['triage_state'] ?? 'unmatched') }}
                                @endif
                            </div>
                        </div>
                    </div>

                    <script type="application/json" data-player-triage-inbox-payload>
                        {!! json_encode($inboxPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
                    </script>
                    <div data-player-triage-inbox></div>
                </section>

                <section
                    class="min-h-[72vh] border-y border-gray-200 bg-white lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto"
                    data-player-triage
                >
                    <script type="application/json" data-player-triage-detail-payload>
                        {!! json_encode($detailPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
                    </script>
                    <div data-player-triage-detail></div>
                </section>
            </div>
</div>
