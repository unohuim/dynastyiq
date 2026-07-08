{{-- resources/views/communities/leagues/show.blade.php --}}
<x-community-hub-layout
    :communities="$vm['sidebar']"
    :mobileBreakpoint="$vm['meta']['mobile_breakpoint']">

    <div
        x-data="{
            openFantrax: false,
            openDiscord: false,
            leagueSettingsOpen: false,
            connectionsOpen: true,
            activeTab: 'draft',
            leagueName: @js($vm['header']['title']),
            leagueNameSaving: false,
            leagueNameSaved: false,
            leagueNameError: '',
            leagueNameTimer: null,
            teamLogosSyncing: false,
            teamLogosMessage: '',
            teamLogosError: '',
            panelHeight: '42rem',
            updatePanelHeight() {
                const top = this.$refs.panelTop?.getBoundingClientRect().top || 0;
                const height = Math.max(560, window.innerHeight - top - 40);
                this.panelHeight = `${height}px`;
            },
            queueLeagueNameSave() {
                this.leagueNameSaved = false;
                this.leagueNameError = '';
                clearTimeout(this.leagueNameTimer);
                this.leagueNameTimer = setTimeout(() => this.saveLeagueName(), 200);
            },
            saveLeagueName() {
                const name = this.leagueName.trim();
                if (name.length < 2) {
                    this.leagueNameSaving = false;
                    this.leagueNameSaved = false;
                    this.leagueNameError = 'Use at least 2 characters.';
                    return;
                }

                this.leagueNameSaving = true;
                fetch(@js($vm['fantrax_modal']['action_url']), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                    },
                    body: JSON.stringify({ name })
                })
                    .then((response) => response.ok ? response.json() : Promise.reject(response))
                    .then((payload) => {
                        this.leagueName = payload.league?.name || name;
                        const pageTitle = document.getElementById('desktopCommunityTitle');
                        if (pageTitle) pageTitle.textContent = this.leagueName;
                        this.leagueNameSaved = true;
                        this.leagueNameError = '';
                    })
                    .catch(() => {
                        this.leagueNameSaved = false;
                        this.leagueNameError = 'Could not save league name.';
                    })
                    .finally(() => {
                        this.leagueNameSaving = false;
                    });
            },
            syncTeamLogos() {
                if (this.teamLogosSyncing) return;

                this.teamLogosSyncing = true;
                this.teamLogosMessage = '';
                this.teamLogosError = '';

                fetch(@js($vm['team_logo_sync']['action_url'] ?? ''), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                    }
                })
                    .then(async (response) => {
                        const payload = await response.json().catch(() => ({}));

                        if (! response.ok) {
                            throw payload;
                        }

                        window.dispatchEvent(new CustomEvent('league:logos-synced', {
                            detail: {
                                platform_league_id: payload.platform_league_id,
                                platform: payload.platform,
                                logo_url: payload.logo_url,
                                refresh_from_server: true,
                            },
                        }));
                        this.teamLogosMessage = payload.message || 'Team logos synced.';
                        if (window.toast?.success) window.toast.success(this.teamLogosMessage);
                    })
                    .catch((payload) => {
                        this.teamLogosError = payload?.message || 'Could not sync team logos.';
                        if (window.toast?.error) window.toast.error(this.teamLogosError);
                    })
                    .finally(() => {
                        this.teamLogosSyncing = false;
                    });
            }
        }"
        x-init="updatePanelHeight(); $nextTick(() => updatePanelHeight())"
        x-on:resize.window="updatePanelHeight()"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
            <a id="desktopCommunityTitle" href="{{ $vm['header']['url'] }}" class="text-2xl font-semibold text-slate-900 hover:text-indigo-700">
                {{ $vm['header']['title'] }}
            </a>

            <div class="flex items-center gap-2">
                @if (! empty($vm['header']['can_export_fantrax_aav']))
                    <a
                        href="{{ $vm['header']['fantrax_aav_export_url'] }}"
                        class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100 focus:outline-none focus:ring-2 focus:ring-emerald-200"
                    >
                        <svg class="h-4 w-4 text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M4.5 19.5h15"/>
                        </svg>
                        Fantrax Cap Hit
                    </a>
                @endif

                @if ($vm['header']['can_edit'])
                    <button
                        type="button"
                        id="btnEditName"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    >
                        <svg class="h-4 w-4 text-slate-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 3.487a2.25 2.25 0 013.182 3.182L7.5 19.313l-4.5 1.125L4.125 16.5 16.862 3.487z"/>
                        </svg>
                        Edit name
                    </button>
                @endif

                @if ($vm['header']['can_edit'])
                    <button
                        type="button"
                        x-on:click="leagueSettingsOpen = true"
                        aria-label="League options"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </button>
                @endif
            </div>
        </div>

        {{-- Body --}}
        <div x-ref="panelTop" x-bind:style="'--league-panel-height: ' + panelHeight" class="space-y-4 p-6">
            <div class="flex items-center gap-2 border-b border-slate-200">
                <button
                    type="button"
                    x-on:click="activeTab = 'draft'; $nextTick(() => updatePanelHeight())"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'draft' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                >
                    Draft
                </button>
                <button
                    type="button"
                    x-on:click="activeTab = 'teams'; $nextTick(() => updatePanelHeight())"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'teams' ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                >
                    Teams
                </button>
            </div>

            @php
                $teamGradients = [
                    'ANA' => 'linear-gradient(to bottom, #FF6F00, #000000)',
                    'ARI' => 'linear-gradient(to bottom, #8C2633, #000000)',
                    'BOS' => 'linear-gradient(to bottom, #FFB81C, #000000)',
                    'BUF' => 'linear-gradient(to bottom, #002654, #FDBB2F)',
                    'CGY' => 'linear-gradient(to bottom, #C8102E, #F1BE48)',
                    'CAR' => 'linear-gradient(to bottom, #CC0000, #000000)',
                    'CHI' => 'linear-gradient(to bottom, #CF0A2C, #000000)',
                    'COL' => 'linear-gradient(to bottom, #6F263D, #236192)',
                    'CBJ' => 'linear-gradient(to bottom, #002654, #A6A6A6)',
                    'DAL' => 'linear-gradient(to bottom, #006847, #000000)',
                    'DET' => 'linear-gradient(to bottom, #CE1126, #FFFFFF)',
                    'EDM' => 'linear-gradient(to bottom, #FF4C00, #041E42)',
                    'FLA' => 'linear-gradient(to bottom, #041E42, #C8102E)',
                    'LAK' => 'linear-gradient(to bottom, #A2AAAD, #000000)',
                    'MIN' => 'linear-gradient(to bottom, #154734, #A6192E)',
                    'MTL' => 'linear-gradient(to bottom, #AF1E2D, #192168)',
                    'NSH' => 'linear-gradient(to bottom, #FFB81C, #041E42)',
                    'NJD' => 'linear-gradient(to bottom, #CE1126, #000000)',
                    'NYI' => 'linear-gradient(to bottom, #00539B, #F47D30)',
                    'NYR' => 'linear-gradient(to bottom, #0038A8, #CE1126)',
                    'OTT' => 'linear-gradient(to bottom, #E31837, #000000)',
                    'PHI' => 'linear-gradient(to bottom, #FA4616, #000000)',
                    'PIT' => 'linear-gradient(to bottom, #FFB81C, #000000)',
                    'SEA' => 'linear-gradient(to bottom, #001628, #99D9D9)',
                    'SJS' => 'linear-gradient(to bottom, #006D75, #000000)',
                    'STL' => 'linear-gradient(to bottom, #002F87, #FDB827)',
                    'TBL' => 'linear-gradient(to bottom, #002868, #00529B)',
                    'TOR' => 'linear-gradient(to bottom, #00205B, #003E7E)',
                    'VAN' => 'linear-gradient(to bottom, #00205B, #00843D)',
                    'VGK' => 'linear-gradient(to bottom, #B4975A, #333F48)',
                    'WSH' => 'linear-gradient(to bottom, #C8102E, #041E42)',
                    'WPG' => 'linear-gradient(to bottom, #041E42, #7B303D)',
                ];
                $fallbackTeamGradient = 'linear-gradient(to bottom, #e5e7eb, #9ca3af)';
                $draftRounds = $vm['drafting']['rounds'] ?? [];
                $draftStatusTone = $vm['drafting']['status_tone'] ?? 'slate';
                $draftStatusDotClass = match ($draftStatusTone) {
                    'green' => 'bg-emerald-500 ring-emerald-100',
                    'blue' => 'bg-blue-500 ring-blue-100',
                    default => 'bg-slate-400 ring-slate-100',
                };
            @endphp

            <section
                x-cloak
                x-show="activeTab === 'draft'"
                x-transition.opacity.duration.150ms
                x-data="{
                    activeRound: @js($vm['drafting']['active_round_index'] ?? 0),
                    configOpen: false,
                    showAvatars: true,
                    showTeamBadges: true,
                    channelOpen: false,
                    channelQuery: @js(data_get($vm, 'drafting.config.selected_channel.name', '')),
                    channelId: @js(data_get($vm, 'drafting.config.selected_channel.id', '')),
                    channelOptions: @js(data_get($vm, 'drafting.config.channels', [])),
                    channelsStatus: @js(data_get($vm, 'drafting.config.channels_status', 'not_connected')),
                    channelsMessage: @js(data_get($vm, 'drafting.config.channels_message')),
                    channelSaving: false,
                    channelMessage: '',
                    get filteredChannels() {
                        const query = this.channelQuery.toLowerCase().replace(/^#/, '');
                        if (!query) return this.channelOptions;
                        return this.channelOptions.filter((channel) => String(channel.name || '').toLowerCase().includes(query));
                    },
                    selectChannel(channel) {
                        this.channelId = channel.id || '';
                        this.channelQuery = channel.name || '';
                        this.channelOpen = false;
                    },
                    init() {
                        this.$nextTick(() => this.scrollActiveRound());
                    },
                    setActiveRound(index) {
                        this.activeRound = index;
                        this.$nextTick(() => this.scrollActiveRound());
                    },
                    scrollActiveRound() {
                        const scroller = this.$refs[`roundScroller${this.activeRound}`];
                        if (!scroller) return;

                        const target = scroller.querySelector('[data-next-pick=true]') || scroller.lastElementChild;

                        if (target) {
                            target.scrollIntoView({ block: 'end' });
                            return;
                        }

                        scroller.scrollTop = scroller.scrollHeight;
                    },
                    saveChannel() {
                        this.channelSaving = true;
                        this.channelMessage = '';
                        fetch(@js(data_get($vm, 'drafting.config.action_url')), {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                            },
                            body: JSON.stringify({
                                draft_channel_id: this.channelId,
                                draft_channel_name: this.channelQuery
                            })
                        })
                            .then((response) => response.ok ? response.json() : Promise.reject(response))
                            .then((payload) => {
                                this.channelId = payload.channel?.id || '';
                                this.channelQuery = payload.channel?.name || '';
                                if (payload.channel && !this.channelOptions.find((channel) => channel.id === payload.channel.id)) {
                                    this.channelOptions.push(payload.channel);
                                }
                                this.channelMessage = 'Saved';
                            })
                            .catch(() => { this.channelMessage = 'Could not save channel'; })
                            .finally(() => { this.channelSaving = false; });
                    }
                }"
                class="relative flex min-h-[42rem] overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:h-[var(--league-panel-height)] lg:flex-col"
            >
                <div class="mb-2 flex select-none items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h3 class="truncate text-base font-semibold text-slate-900">
                            {{ $vm['drafting']['title'] ?? 'Draft' }}
                        </h3>

                        <div class="mt-1 flex items-center gap-2 text-xs font-medium text-slate-600">
                            <span class="h-2 w-2 rounded-full ring-4 {{ $draftStatusDotClass }}"></span>
                            <span>{{ $vm['drafting']['status_text'] ?? 'Draft' }}</span>
                        </div>
                    </div>

                    @if ($vm['header']['can_edit'])
                        <button type="button"
                                x-on:click="configOpen = !configOpen"
                                aria-label="Toggle draft settings"
                                class="rounded-md p-1 text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </button>
                    @endif
                </div>

                <div class="min-h-0 flex flex-1 flex-col">
                    @if (! empty($vm['drafting']['error_text']))
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            {{ $vm['drafting']['error_text'] }}
                        </div>
                    @elseif (empty($vm['drafting']['rows']))
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-600">
                            {{ $vm['drafting']['empty_text'] ?? 'No drafted players yet.' }}
                        </div>
                    @else
                        <div class="flex min-h-0 flex-1 flex-col space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($draftRounds as $roundIndex => $round)
                                    <button
                                        type="button"
                                        x-on:click="setActiveRound({{ $roundIndex }})"
                                        class="inline-flex h-8 items-center gap-2 rounded-lg border px-3 text-xs font-semibold transition-colors"
                                        :class="activeRound === {{ $roundIndex }} ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                                    >
                                        <span>{{ $round['label'] }}</span>
                                        <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ $round['count'] }}</span>
                                    </button>
                                @endforeach
                            </div>

                            @foreach ($draftRounds as $roundIndex => $round)
                                <div x-cloak x-show="activeRound === {{ $roundIndex }}" x-transition.opacity.duration.150ms class="min-h-0 flex flex-1 flex-col">
                                    <div class="mb-2 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                                        <div class="text-sm font-semibold text-slate-900">{{ $round['label'] }}</div>
                                        <div class="text-xs text-slate-500">{{ $round['count'] }} picks</div>
                                    </div>

                                    <ol x-ref="roundScroller{{ $roundIndex }}" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                                        @foreach ($round['rows'] as $row)
                                            @php
                                                $teamAbbrev = strtoupper((string) ($row['team_abbrev'] ?? ''));
                                                $teamBadgeBackground = $teamGradients[$teamAbbrev] ?? $fallbackTeamGradient;
                                                $pickInRound = $row['pick_in_round'] ?? $row['pick'] ?? null;
                                                $overallPick = $row['overall_pick'] ?? $row['pick'] ?? null;
                                                $hasDraftedPlayer = ! empty($row['fantrax_player_id']);
                                                $isNextPick = ! empty($row['is_next_pick']);
                                            @endphp

                                            <li @if ($isNextPick) data-next-pick="true" @endif class="grid grid-cols-[3.25rem_minmax(0,1.25fr)_4.5rem_minmax(8rem,0.75fr)_minmax(0,1.1fr)] items-center gap-2 bg-white px-4 py-3">
                                                <div class="flex flex-col items-center justify-center tabular-nums">
                                                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 bg-white text-xs font-semibold text-slate-700">
                                                        {{ $pickInRound ?? '-' }}
                                                    </div>
                                                    <div class="mt-1 text-[10px] font-medium text-slate-400">
                                                        {{ $overallPick ? '#' . $overallPick : '' }}
                                                    </div>
                                                </div>

                                                <div class="flex min-w-0 items-center gap-3">
                                                    @if ($hasDraftedPlayer)
                                                        <div x-show="showAvatars" class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                                            @if (! empty($row['avatar_url']))
                                                                <img src="{{ $row['avatar_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                                            @else
                                                                {{ collect(explode(' ', $row['player_name'] ?? ''))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: '?' }}
                                                            @endif
                                                        </div>
                                                    @endif

                                                    <div class="min-w-0">
                                                        @if ($hasDraftedPlayer)
                                                            <div class="truncate text-sm font-semibold text-slate-900">
                                                                {{ $row['player_name'] }}
                                                            </div>
                                                            <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                                                @if (! empty($row['position']))
                                                                    <span class="shrink-0">{{ $row['position'] }}</span>
                                                                @endif
                                                                @if (! empty($row['position']) && ! empty($row['league_abbrev']))
                                                                    <span class="shrink-0 text-slate-300">/</span>
                                                                @endif
                                                                @if (! empty($row['league_abbrev']))
                                                                    <span class="truncate">{{ $row['league_abbrev'] }}</span>
                                                                @endif
                                                            </div>
                                                        @elseif ($isNextPick)
                                                            <div class="space-y-2">
                                                                <div class="h-3 w-24 animate-pulse rounded-full bg-slate-200"></div>
                                                                <div class="h-2 w-20 animate-pulse rounded-full bg-slate-100"></div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="flex justify-center" x-show="showTeamBadges">
                                                    @if ($hasDraftedPlayer)
                                                        <span
                                                            class="inline-flex h-7 min-w-14 items-center justify-center rounded-md px-3 text-xs font-semibold tracking-wide text-white shadow-sm"
                                                            style="background: {{ $teamBadgeBackground }};"
                                                        >
                                                            {{ $teamAbbrev !== '' ? $teamAbbrev : '-' }}
                                                        </span>
                                                    @endif
                                                </div>

                                                <div class="grid grid-cols-4 gap-2 text-right tabular-nums">
                                                    @if ($hasDraftedPlayer)
                                                        @foreach (['gp' => 'GP', 'g' => 'G', 'a' => 'A', 'pts' => 'PTS'] as $statKey => $label)
                                                            <div>
                                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</div>
                                                                <div class="text-xs font-semibold text-slate-900">
                                                                    {{ data_get($row, 'stats.' . $statKey) ?? '-' }}
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    @endif
                                                </div>

                                                <div class="flex min-w-0 items-center justify-end gap-2">
                                                    @if ($isNextPick)
                                                        <span class="inline-flex h-5 shrink-0 items-center rounded-full bg-orange-100 px-2 text-[11px] font-semibold text-orange-700 ring-1 ring-orange-200">
                                                            OTC
                                                        </span>
                                                    @endif

                                                    <div class="min-w-0 text-right">
                                                        <div class="truncate text-xs font-semibold text-slate-700">
                                                            {{ $row['team_name'] }}
                                                        </div>
                                                        <div class="text-[10px] uppercase tracking-wide text-slate-400">Drafted by</div>
                                                    </div>

                                                    @if (! empty($row['team_avatar_url']))
                                                        <img src="{{ $row['team_avatar_url'] }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                                                    @else
                                                        <div class="h-8 w-8 shrink-0 rounded-full bg-slate-100 ring-1 ring-slate-200"></div>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($vm['header']['can_edit'])
                    <div
                        x-cloak
                        x-show="configOpen"
                        x-transition.opacity
                        x-on:click="configOpen = false"
                        class="absolute inset-0 z-10 bg-slate-900/10"
                        aria-hidden="true"
                    ></div>

                    <aside
                        x-cloak
                        x-show="configOpen"
                        x-transition:enter="transition duration-200 ease-out"
                        x-transition:enter-start="translate-x-full"
                        x-transition:enter-end="translate-x-0"
                        x-transition:leave="transition duration-150 ease-in"
                        x-transition:leave-start="translate-x-0"
                        x-transition:leave-end="translate-x-full"
                        class="absolute inset-y-0 right-0 z-20 w-80 border-l border-slate-200 bg-white p-5 shadow-xl"
                        aria-label="Draft settings"
                    >
                    <div class="mb-5 flex items-center justify-between gap-3">
                        <h4 class="text-sm font-semibold text-slate-900">Draft settings</h4>
                        <button type="button"
                                x-on:click="configOpen = false"
                                aria-label="Close draft settings"
                                class="rounded-md p-1 text-slate-500 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.22 5.22a.75.75 0 0 1 1.06 0L10 8.94l3.72-3.72a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 1 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div class="rounded-lg border border-slate-200 px-3 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <label class="text-sm font-medium text-slate-700" for="draft-channel-combobox">Draft pick channel</label>
                                <span class="text-[11px] text-slate-400" x-text="channelOptions.length + ' loaded'"></span>
                            </div>
                            <div class="relative mt-2" @click.stop>
                                <input
                                    id="draft-channel-combobox"
                                    type="text"
                                    x-model="channelQuery"
                                    x-on:focus="channelOpen = true"
                                    x-on:click="channelOpen = true"
                                    x-on:input="channelId = ''; channelOpen = true"
                                    x-on:keydown.escape.prevent.stop="channelOpen = false"
                                    placeholder="None"
                                    autocomplete="off"
                                    @disabled(empty(data_get($vm, 'drafting.config.discord_connected')))
                                    class="block w-full rounded-md border-slate-200 pr-9 text-sm text-slate-900 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-50 disabled:text-slate-400"
                                >
                                <button type="button"
                                        x-on:click="channelOpen = !channelOpen"
                                        @disabled(empty(data_get($vm, 'drafting.config.discord_connected')))
                                        class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 text-slate-400">
                                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                    </svg>
                                </button>

                                <div x-cloak x-show="channelOpen" x-transition @click.outside="channelOpen = false"
                                     class="absolute z-30 mt-1 max-h-48 w-full overflow-auto rounded-md bg-white p-1 text-sm shadow-lg ring-1 ring-black/5">
                                    <button type="button"
                                            class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-indigo-600 hover:text-white"
                                            x-on:click="selectChannel({ id: '', name: '' })">
                                        None
                                    </button>
                                    <template x-for="channel in filteredChannels" :key="channel.id">
                                        <button type="button"
                                                class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-indigo-600 hover:text-white"
                                                x-on:click="selectChannel(channel)">
                                            <span class="truncate">#<span x-text="channel.name"></span></span>
                                        </button>
                                    </template>
                                    <div x-show="!channelQuery && channelOptions.length === 0"
                                         class="px-3 py-2 text-xs text-slate-500"
                                         x-text="channelsMessage || 'No text channels returned for this Discord server.'"></div>
                                    <button type="button"
                                            x-show="channelQuery && filteredChannels.length === 0"
                                            class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-indigo-600 hover:text-white"
                                            x-on:click="channelOpen = false">
                                        Create #<span x-text="channelQuery.replace(/^#/, '')"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <p class="text-[11px] text-slate-500">
                                    @if (empty(data_get($vm, 'drafting.config.discord_connected')))
                                        Connect a Discord server first.
                                    @else
                                        New names create a text channel.
                                    @endif
                                </p>
                                <button type="button"
                                        x-on:click="saveChannel()"
                                        x-bind:disabled="channelSaving || {{ empty(data_get($vm, 'drafting.config.discord_connected')) ? 'true' : 'false' }}"
                                        class="rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
                                    Save
                                </button>
                            </div>
                            <p x-show="channelMessage" x-text="channelMessage" class="mt-2 text-[11px] text-slate-500"></p>
                        </div>

                        <label class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 px-3 py-2">
                            <span class="text-sm font-medium text-slate-700">Player avatars</span>
                            <input type="checkbox" x-model="showAvatars" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        </label>

                        <label class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 px-3 py-2">
                            <span class="text-sm font-medium text-slate-700">Team badges</span>
                            <input type="checkbox" x-model="showTeamBadges" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        </label>
                    </div>
                    </aside>
                @endif
            </section>

            <section
                x-cloak
                x-show="activeTab === 'teams'"
                x-transition.opacity.duration.150ms
                class="flex min-h-[42rem] max-w-xl flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:h-[var(--league-panel-height)]"
            >
                <div class="mb-4 flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Teams</h3>
                        <p class="mt-0.5 text-xs text-slate-500">Fantasy teams attached to this platform league.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">{{ count($vm['teams']) }}</span>
                </div>

                @if ($vm['platform']['connected'])
                    <ul class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                        @foreach ($vm['teams'] as $team)
                            <li class="rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-slate-200 bg-white" aria-hidden="true">
                                            @if (! empty($team['owner_avatar_url']))
                                                <img src="{{ $team['owner_avatar_url'] }}" alt="" class="h-full w-full rounded-full object-cover ring-1 ring-slate-200">
                                            @elseif (! empty($team['logo_url']))
                                                <img src="{{ $team['logo_url'] }}" alt="" class="h-full w-full object-cover">
                                            @else
                                                <span class="text-[10px] font-semibold text-slate-500">
                                                    {{ collect(explode(' ', $team['name'] ?? ''))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: '?' }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-slate-900">
                                                {{ $team['name'] }}
                                            </div>
                                            <div class="mt-0.5 truncate text-[11px] text-gray-400">
                                                {{ $team['id'] }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-600">
                        Connect a fantasy platform league to view teams.
                    </div>
                @endif
            </section>
        </div>

        @if ($vm['header']['can_edit'])
        <x-ui.slide-over show="leagueSettingsOpen" close-action="leagueSettingsOpen = false" title-id="league-options-title" max-width="max-w-xl">
            <div class="relative overflow-hidden bg-slate-950 px-6 pb-8 pt-7 text-white">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_75%_15%,rgba(59,130,246,0.36),transparent_30%),linear-gradient(135deg,rgba(15,23,42,0.96),rgba(15,23,42,0.74))]" aria-hidden="true"></div>
                <div class="absolute right-4 top-8 h-28 w-28 rounded-full border border-blue-300/20" aria-hidden="true"></div>
                <div class="absolute -right-8 bottom-0 h-24 w-40 rotate-[-18deg] rounded-full border border-white/20 bg-white/5" aria-hidden="true"></div>
                <div class="absolute bottom-0 left-0 right-0 h-px bg-blue-400/70 shadow-[0_0_24px_rgba(96,165,250,0.9)]" aria-hidden="true"></div>

                <div class="relative flex items-start justify-between gap-4">
                    <div class="min-w-0 pt-1">
                        <div class="inline-flex max-w-full items-center gap-2 rounded-full border border-blue-300/40 bg-blue-950/40 px-3 py-1 text-sm font-semibold text-blue-100 shadow-sm">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-blue-500/20 text-blue-200 ring-1 ring-blue-300/30" aria-hidden="true">
                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
                                    <path d="M10 2 4 4.5v4.1c0 3.7 2.4 7.2 6 8.7 3.6-1.5 6-5 6-8.7V4.5L10 2Z"/>
                                </svg>
                            </span>
                            <span class="truncate" x-text="leagueName"></span>
                        </div>
                        <h3 id="league-options-title" class="mt-7 text-3xl font-semibold tracking-tight text-white">League options</h3>
                        <p class="mt-3 max-w-sm text-sm leading-6 text-blue-50/90">Manage draft setup, teams, platform links, and Discord connections.</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="leagueSettingsOpen = false"
                        aria-label="Close league options"
                        class="rounded-xl border border-white/15 bg-white/5 p-2 text-white/80 hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-blue-200"
                    >
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.22 5.22a.75.75 0 0 1 1.06 0L10 8.94l3.72-3.72a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 1 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                <div class="mb-4 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <label for="league-options-name" class="text-xs font-semibold uppercase tracking-wide text-slate-500">League name</label>
                    <input
                        id="league-options-name"
                        type="text"
                        x-model="leagueName"
                        x-on:input="queueLeagueNameSave()"
                        class="mt-2 block w-full rounded-lg border-slate-200 text-sm font-semibold text-slate-900 focus:border-blue-500 focus:ring-blue-500"
                    >
                    <div class="mt-2 min-h-4 text-[11px]">
                        <span x-show="leagueNameSaving" class="text-slate-500">Saving...</span>
                        <span x-show="!leagueNameSaving && leagueNameSaved" class="text-emerald-600">Saved</span>
                        <span x-show="leagueNameError" x-text="leagueNameError" class="text-rose-600"></span>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white">
                    <button
                        type="button"
                        x-on:click="connectionsOpen = !connectionsOpen"
                        x-bind:aria-expanded="connectionsOpen.toString()"
                        aria-controls="league-connections-panel"
                        class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left"
                    >
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Connections</div>
                            <div class="mt-0.5 text-xs text-slate-500">Fantasy platform and Discord server.</div>
                        </div>
                        <svg
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            class="h-4 w-4 text-slate-400 transition-transform duration-300 ease-out motion-reduce:transition-none"
                            x-bind:class="connectionsOpen ? 'rotate-180' : ''"
                            aria-hidden="true"
                        >
                            <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                        </svg>
                    </button>

                    <div
                        id="league-connections-panel"
                        class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none"
                        x-bind:class="connectionsOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
                    >
                        <div class="min-h-0 overflow-hidden">
                            <ul class="space-y-3 border-t border-slate-200 p-4">
                                <li class="rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white" aria-hidden="true">
                                                @if ($vm['platform']['connected'])
                                                    <svg viewBox="0 0 24 24" class="h-5 w-5 text-emerald-600"><path fill="currentColor" d="M3 12 12 3l9 9-9 9-9-9Z"/></svg>
                                                @else
                                                    <svg viewBox="0 0 24 24" class="h-5 w-5 text-slate-500"><circle cx="12" cy="12" r="8" fill="currentColor"/></svg>
                                                @endif
                                            </div>
                                            <div class="min-w-0">
                                                <div class="truncate text-[15px] font-semibold text-slate-900">
                                                    {{ $vm['platform']['title'] }}
                                                </div>
                                                <div class="mt-0.5 text-xs">
                                                    <span class="{{ $vm['platform']['status_class'] }}">{{ $vm['platform']['status_text'] }}</span>
                                                    <span class="text-slate-400"> • {{ $vm['platform']['subtext'] }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        @if (! $vm['platform']['connected'])
                                            <button
                                                type="button"
                                                x-on:click="leagueSettingsOpen = false; openFantrax = true"
                                                class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                            >
                                                {{ $vm['platform']['action_label'] }}
                                            </button>
                                        @else
                                            <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">Connected</span>
                                        @endif
                                    </div>
                                </li>

                                <li class="rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                                    <div class="flex items-center justify-between gap-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white" aria-hidden="true">
                                                @if ($vm['discord']['avatar_url'])
                                                    <img src="{{ $vm['discord']['avatar_url'] }}" alt="" class="h-6 w-6 rounded-full object-cover ring-1 ring-slate-200">
                                                @else
                                                    <svg viewBox="0 0 24 24" class="h-5 w-5 text-indigo-600"><path fill="currentColor" d="M7 5h10a2 2 0 0 1 2 2v10l-3-2-2 2-3-2-2 2-2-2-3 2V7a2 2 0 0 1 2-2z"/></svg>
                                                @endif
                                            </div>
                                            <div class="min-w-0">
                                                <div class="truncate text-[15px] font-semibold text-slate-900">
                                                    {{ $vm['discord']['title'] }}
                                                </div>
                                                <div class="mt-0.5 text-xs">
                                                    <span class="{{ $vm['discord']['status_class'] }}">{{ $vm['discord']['status_text'] }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        @if (! $vm['discord']['connected'])
                                            <button type="button"
                                                    x-on:click="leagueSettingsOpen = false; openDiscord = true"
                                                    class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                                Connect
                                            </button>
                                        @elseif ($vm['discord']['can_change'])
                                            <button type="button"
                                                    x-on:click="leagueSettingsOpen = false; openDiscord = true"
                                                    class="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                                Change
                                            </button>
                                        @else
                                            <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">Connected</span>
                                        @endif
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.slide-over>
        @endif

        {{-- Fantrax modal (only when not connected) --}}
        @if (! $vm['platform']['connected'])
            <x-new-league-modal
                :model="'openFantrax'"
                title="Connect Fantrax"
                subtitle="Link this league to your Fantrax league."
                :actionUrl="$vm['fantrax_modal']['action_url']"
                :fantraxOptions="$vm['fantrax_modal']['options']"
                :fantraxConnected="$vm['fantrax_modal']['connected']"
                :showDiscord="false"
                :allowRename="false"
                :initialName="$vm['fantrax_modal']['initial_name']"
                submitLabel="Save"
                formId="connectFantraxForm"
            />
        @endif

        {{-- Discord chooser modal --}}
        <div x-cloak x-show="openDiscord"
             class="fixed inset-0 z-40 flex items-center justify-center bg-black/40"
             @keydown.escape.window="openDiscord=false"
             @click.self="openDiscord=false">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl" x-trap.noscroll="openDiscord" tabindex="-1">
                <h3 class="mb-2 text-base font-semibold text-slate-900">Select a Discord server</h3>

                <form id="changeDiscordForm" data-action="{{ $vm['discord']['action_url'] }}">
                    <div class="mb-4 max-h-64 space-y-2 overflow-auto">
                        @forelse ($vm['discord']['options'] as $opt)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-2">
                                <input type="radio"
                                       name="discord_server_id"
                                       value="{{ $opt['id'] }}"
                                       @checked($opt['selected'])
                                       required>
                                @if (! empty($opt['avatar_url']))
                                    <img src="{{ $opt['avatar_url'] }}" class="h-6 w-6 rounded-full ring-1 ring-slate-200" alt="">
                                @endif
                                <span class="text-sm text-slate-900">{{ $opt['name'] }}</span>
                            </label>
                        @empty
                            <div class="text-sm text-slate-600">No connected Discord servers.</div>
                        @endforelse
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button"
                                @click="openDiscord=false"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700">
                            Cancel
                        </button>
                        <button type="submit"
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</x-community-hub-layout>

{{-- Inline JS (form submits) --}}
<script>
const showToast = (type, message) => {
  if (window.toast?.[type]) {
    window.toast[type](message);
  } else if (window.toast?.show) {
    window.toast.show(message, { type });
  }
};

(() => {
  const form = document.getElementById('connectFantraxForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const url = form.dataset.action || form.getAttribute('data-action') || '{{ $vm['fantrax_modal']['action_url'] }}';
    if (!url) return;

    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const name  = form.querySelector('[name="name"]')?.value?.trim() || '';

    const platform         = form.querySelector('[name="platform"]')?.value || '';
    const platformLeagueId = form.querySelector('[name="platform_league_id"]')?.value || '';
    const discordId        = form.querySelector('[name="discord_server_id"]')?.value || '';

    if (!name) {
      showToast('error', 'Please enter a league name.');
      return;
    }
    if (platform && !platformLeagueId) {
      showToast('error', 'Please select or enter a Fantrax league ID.');
      return;
    }

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;

    const payload = {
      name,
      ...(discordId ? { discord_server_id: discordId } : {}),
      ...(platform ? { platform, platform_league_id: platformLeagueId } : {}),
    };

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error('Save failed');
      showToast('success', 'League linked to Fantrax.');
      window.setTimeout(() => window.location.reload(), 350);
    } catch (err) {
      showToast('error', 'Could not save changes.');
    } finally {
      btn.disabled = false;
    }
  });
})();

(() => {
  const form = document.getElementById('changeDiscordForm');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = form.dataset.action || form.getAttribute('data-action') || '{{ $vm['discord']['action_url'] }}';
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const discordId = form.querySelector('input[name="discord_server_id"]:checked')?.value;
    if (!discordId) return;

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token
        },
        body: JSON.stringify({ discord_server_id: discordId })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data?.ok !== true) throw new Error('Save failed');
      showToast('success', 'Discord server updated.');
      window.setTimeout(() => window.location.reload(), 350);
    } catch (err) {
      showToast('error', 'Could not update Discord server.');
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
