{{-- resources/views/communities/_desktop.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection|\App\Models\Organization[] $communities */
    $currentOrg = ($activeCommunity ?? null)
        ? $communities->firstWhere('id', $activeCommunity->id)
        : $communities->first();
    $user = auth()->user();

    $highestRole = null;
    if ($user && $currentOrg) {
        $highestRole = $user->roles()
            ->wherePivot('organization_id', $currentOrg->id)
            ->orderByDesc('level')
            ->first();
    }

    $canEdit = $highestRole && (int) ($highestRole->level ?? 0) >= 10;
    $roleLabel = $highestRole ? ucfirst((string) $highestRole->name) : 'Member';

    $guilds = $currentOrg
        ? ($currentOrg->relationLoaded('discordServers')
            ? $currentOrg->discordServers
            : $currentOrg->discordServers()->get())
        : collect();

    $leagues = $currentOrg
        ? ($currentOrg->relationLoaded('leagues')
            ? $currentOrg->leagues
            : $currentOrg->leagues()->get())
        : collect();

    $memberships = $currentOrg
        ? ($currentOrg->relationLoaded('memberships')
            ? $currentOrg->memberships
            : $currentOrg->memberships()->with(['memberProfile', 'membershipTier', 'providerAccount'])->latest()->limit(10)->get())
        : collect();

    $tiers = $currentOrg
        ? ($currentOrg->relationLoaded('membershipTiers')
            ? $currentOrg->membershipTiers
            : $currentOrg->membershipTiers()->orderBy('name')->get())
        : collect();

    $patreonAccount = $currentOrg?->providerAccounts->firstWhere('provider', 'patreon');
    $commissionerEnabled = $currentOrg?->commissionerToolsEnabled();
    $shortName = $currentOrg?->short_name ?: $currentOrg?->name;
    $initials = strtoupper(mb_substr((string) $shortName, 0, 2));
    $providerCounts = $membershipProviderCounts ?? [];
    $discordMemberCount = (int) ($providerCounts['discord'] ?? $memberships->where('provider', 'discord')->count());
    $patreonMemberCount = (int) ($providerCounts['patreon'] ?? $memberships->where('provider', 'patreon')->count());
    $manualMemberCount = (int) ($providerCounts['other'] ?? $memberships->whereNotIn('provider', ['discord', 'patreon'])->count());
    $connectedCount = ($guilds->isNotEmpty() ? 1 : 0)
        + ($patreonAccount ? 1 : 0)
        + ($fantraxConnected ? 1 : 0);
    $totalMembers = data_get($initialMembers ?? [], 'meta.total', $memberships->count());

    $desktopConfig = [
        'organizationId' => $currentOrg?->id,
        'organizationName' => $currentOrg?->name,
        'endpoints' => [
            'members' => $currentOrg ? route('communities.members.index', $currentOrg, absolute: false) : '',
            'tiers' => $currentOrg ? route('communities.tiers.index', $currentOrg, absolute: false) : '',
            'settings' => $currentOrg ? route('organizations.settings.update', ['organization' => $currentOrg->id], absolute: false) : '',
        ],
        'initialMembers' => $initialMembers ?? [],
        'initialTiers' => $initialTiers ?? [],
    ];
@endphp

<div
    x-data="communityMembersHub({{ \Illuminate\Support\Js::from($desktopConfig) }})"
    class="min-h-[calc(100vh-8rem)] rounded-lg border border-slate-200 bg-slate-50 text-slate-900"
    :class="theme === 'dark' ? 'border-slate-800 bg-slate-950 text-slate-100' : 'border-slate-200 bg-slate-50 text-slate-900'"
>
    <div class="grid gap-3 p-3 lg:grid-cols-[300px,1fr] lg:p-4">
        <aside
            class="rounded-lg border bg-white p-4"
            :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
        >
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Communities</h2>
                    <div class="mt-2 h-px w-32 bg-indigo-500"></div>
                </div>
                <button
                    type="button"
                    class="inline-flex h-9 w-16 items-center rounded-full border p-1 transition-colors"
                    :class="theme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200 bg-slate-100'"
                    aria-label="Toggle light and dark community theme"
                    @click="theme = theme === 'dark' ? 'light' : 'dark'"
                >
                    <span
                        class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-[11px] font-semibold text-slate-700 shadow transition-transform"
                        :class="theme === 'dark' ? 'translate-x-7' : 'translate-x-0'"
                        x-text="theme === 'dark' ? 'D' : 'L'"
                    ></span>
                </button>
            </div>

            <nav class="mt-4 space-y-2">
                @foreach ($communities as $org)
                    @php
                        $isActive = $currentOrg && (int) $currentOrg->id === (int) $org->id;
                        $orgInitials = strtoupper(mb_substr((string) ($org->short_name ?: $org->name), 0, 2));
                    @endphp
                    <a
                        href="{{ route('communities.index', ['active' => $org->id]) }}"
                        class="group flex items-center gap-3 rounded-lg border px-3 py-3 text-sm font-semibold transition-colors"
                        :class="theme === 'dark'
                            ? '{{ $isActive ? 'border-indigo-500 bg-indigo-500/15 text-white' : 'border-slate-800 bg-slate-950 text-slate-300 hover:border-slate-700 hover:bg-slate-800' }}'
                            : '{{ $isActive ? 'border-indigo-500 bg-indigo-50 text-indigo-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}'"
                        aria-current="{{ $isActive ? 'page' : 'false' }}"
                    >
                        <span
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold"
                            :class="theme === 'dark' ? 'bg-indigo-500 text-white' : 'bg-indigo-600 text-white'"
                        >
                            {{ $orgInitials }}
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ $org->name }}</span>
                        @if ($isActive)
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="mt-7 flex items-center justify-between text-xs font-semibold uppercase tracking-wide">
                <span :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Leagues</span>
                <span
                    class="rounded-full px-2 py-0.5"
                    data-community-league-count
                    :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'"
                >
                    {{ $leagues->count() }}
                </span>
            </div>

            <div class="mt-3 space-y-2">
                @forelse ($leagues as $league)
                    @php
                        $platform = strtolower((string) $league->platform);
                        $statusText = data_get($league, 'settings.status') ?: 'Connected';
                        $scopeLabel = data_get($league->activePlatformScope(), 'scope_label');
                        $transactionsBrowserRpcUrl = $platform === 'fantrax'
                            ? route('community.leagues.transactions.browser-rpc', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false)
                            : '';
                        $transactionsUrl = $platform === 'fantrax'
                            ? route('community.leagues.transactions.index', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false)
                            : '';
                        $teamLogoSyncUrl = in_array($platform, ['fantrax', 'yahoo'], true)
                            ? route('community.leagues.team-logos.sync', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false)
                            : '';
                        $leaguePayload = [
                            'id' => $league->id,
                            'name' => (string) $league->name,
                            'platform' => ucfirst($platform ?: 'League'),
                            'scope' => $scopeLabel,
                            'server' => null,
                            'teamsUrl' => route('community.leagues.teams', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'draftSummaryUrl' => route('community.leagues.draft-summary', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'draftSettingsUrl' => route('community.leagues.draft-settings', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'leagueOptionsUrl' => $canEdit ? route('community.leagues.options', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false) : '',
                            'playersPayloadUrl' => route('community.leagues.players-payload', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'leagueStatsPayloadUrl' => route('community.leagues.stats-payload', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'draftTestingUrl' => route('community.leagues.draft-testing', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'draftTestingSimulateUrl' => route('community.leagues.draft-testing.simulate', ['c_id' => $currentOrg->id, 'l_id' => $league->id], false),
                            'transactionsBrowserRpcUrl' => $transactionsBrowserRpcUrl,
                            'transactionsUrl' => $transactionsUrl,
                            'teamLogoSyncUrl' => $teamLogoSyncUrl,
                        ];
                    @endphp
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg border px-3 py-3 text-left transition-colors"
                        data-community-sidebar-league-row="{{ $league->id }}"
                        @click="openCommunityLeague(@js($leaguePayload), 'draft')"
                        :class="theme === 'dark' ? 'border-slate-800 bg-slate-950 hover:bg-slate-800' : 'border-slate-200 bg-white hover:bg-slate-50'"
                    >
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-700 text-[10px] font-bold uppercase text-white">
                            {{ $platform ? mb_substr($platform, 0, 2) : 'L' }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold">{{ $league->name }}</span>
                            <span class="mt-0.5 block truncate text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                {{ ucfirst($platform ?: 'League') }} / {{ $scopeLabel ?: $statusText }}
                            </span>
                        </span>
                    </button>
                @empty
                    <div class="rounded-lg border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                        No leagues connected yet.
                    </div>
                @endforelse
            </div>
        </aside>

        <main class="min-w-0 space-y-5">
            <section
                x-show="!selectedLeague"
                x-cloak
                class="relative overflow-hidden rounded-lg border p-6"
                :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
            >
                <div class="absolute inset-y-0 right-0 hidden w-1/2 bg-gradient-to-l from-indigo-100 to-transparent opacity-70 lg:block" :class="theme === 'dark' ? 'from-indigo-950' : 'from-indigo-100'"></div>
                <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-3xl font-bold text-white">
                            {{ $initials }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="truncate text-3xl font-semibold">{{ $currentOrg?->name }}</h1>
                                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'border-slate-700 text-slate-300' : 'border-slate-200 text-slate-600'">
                                    {{ $roleLabel }}
                                </span>
                            </div>
                            <p class="mt-2 max-w-2xl text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                                Community operations, connected leagues, member access, and integrations.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-3 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                <span>{{ $leagues->count() }} leagues</span>
                                <span>{{ $totalMembers }} members</span>
                                <span>{{ $connectedCount }} connected services</span>
                            </div>
                        </div>
                    </div>

                    @if ($canEdit && $currentOrg)
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg border transition-colors"
                            :class="theme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                            aria-label="Edit community settings"
                            @click="$store.communityMembers.openSettings()"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.607 2.296.07 2.573-1.065z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </button>
                    @endif
                </div>
            </section>

            <section
                x-show="selectedLeague"
                x-cloak
                class="!mt-0 rounded-lg border p-4"
                :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
            >
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg bg-cyan-700 text-3xl font-bold text-white">
                            <span x-text="(selectedLeague?.name || 'L').slice(0, 2).toUpperCase()"></span>
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="truncate text-3xl font-semibold" x-text="selectedLeague?.name || 'League'"></h1>
                                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'border-slate-700 text-slate-300' : 'border-slate-200 text-slate-600'" x-text="selectedLeague?.platform || 'League'"></span>
                            </div>
                            <p class="mt-2 max-w-2xl text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                                League setup, teams, draft status, and community boundaries.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-3 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                <span x-show="selectedLeague?.scope" x-text="selectedLeague?.scope"></span>
                                <span x-show="selectedLeague?.server" x-text="selectedLeague?.server"></span>
                                <span>{{ $currentOrg?->name }}</span>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border px-3 py-2 text-sm font-semibold"
                        :class="theme === 'dark' ? 'border-slate-700 text-slate-300 hover:bg-slate-800' : 'border-slate-200 text-slate-700 hover:bg-slate-50'"
                        @click="selectCommunityTab('leagues')"
                    >
                        Back to Leagues
                    </button>
                </div>
            </section>

            <nav
                x-show="!selectedLeague"
                x-cloak
                class="flex flex-wrap gap-2 rounded-lg border p-2"
                :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
                aria-label="Community sections"
            >
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'home' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="selectCommunityTab('home')"
                >
                    Home
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'members' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="selectCommunityTab('members')"
                >
                    Members
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'leagues' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="selectCommunityTab('leagues')"
                >
                    Leagues
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'connections' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="selectCommunityTab('connections')"
                >
                    Connections
                </button>
            </nav>

            <nav
                x-show="selectedLeague"
                x-cloak
                class="!mt-0 flex flex-wrap gap-2 px-1 py-0"
                :class="theme === 'dark' ? 'text-slate-300' : 'text-slate-600'"
                aria-label="League sections"
            >
                <button
                    type="button"
                    class="border-b-2 px-1 py-2 text-sm font-semibold transition-colors"
                    :class="activeLeagueTab === 'home' ? 'border-cyan-600 text-cyan-700' : (theme === 'dark' ? 'border-transparent text-slate-300 hover:border-slate-700' : 'border-transparent text-slate-600 hover:border-slate-300')"
                    @click="openCommunityLeagueTab('home')"
                >
                    Home
                </button>
                <button
                    type="button"
                    class="border-b-2 px-1 py-2 text-sm font-semibold transition-colors"
                    :class="activeLeagueTab === 'draft' ? 'border-cyan-600 text-cyan-700' : (theme === 'dark' ? 'border-transparent text-slate-300 hover:border-slate-700' : 'border-transparent text-slate-600 hover:border-slate-300')"
                    @click="openCommunityLeagueTab('draft')"
                >
                    Draft
                </button>
                <button
                    type="button"
                    class="border-b-2 px-1 py-2 text-sm font-semibold transition-colors"
                    :class="activeLeagueTab === 'teams' ? 'border-cyan-600 text-cyan-700' : (theme === 'dark' ? 'border-transparent text-slate-300 hover:border-slate-700' : 'border-transparent text-slate-600 hover:border-slate-300')"
                    @click="openCommunityLeagueTab('teams')"
                >
                    Teams
                </button>
                <button
                    type="button"
                    class="border-b-2 px-1 py-2 text-sm font-semibold transition-colors"
                    :class="activeLeagueTab === 'transactions' ? 'border-cyan-600 text-cyan-700' : (theme === 'dark' ? 'border-transparent text-slate-300 hover:border-slate-700' : 'border-transparent text-slate-600 hover:border-slate-300')"
                    @click="openCommunityLeagueTab('transactions')"
                >
                    Transactions
                </button>
                <button
                    type="button"
                    class="border-b-2 px-1 py-2 text-sm font-semibold transition-colors"
                    :class="activeLeagueTab === 'setup' ? 'border-cyan-600 text-cyan-700' : (theme === 'dark' ? 'border-transparent text-slate-300 hover:border-slate-700' : 'border-transparent text-slate-600 hover:border-slate-300')"
                    @click="openCommunityLeagueTab('setup')"
                >
                    Setup
                </button>
            </nav>

            <div x-show="!selectedLeague && activeTab === 'home'" x-cloak class="grid gap-5 xl:grid-cols-3">
                <section class="rounded-lg border p-5 xl:col-span-1" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold">Members</h3>
                            <p class="text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">Total community members</p>
                        </div>
                        <button type="button" class="rounded-md border px-3 py-1.5 text-xs font-semibold" :class="theme === 'dark' ? 'border-slate-700 text-slate-300' : 'border-slate-200 text-slate-600'" @click="activeTab = 'members'">
                            View all
                        </button>
                    </div>
                    <div class="mt-5 text-4xl font-semibold">{{ $totalMembers }}</div>
                    <div class="mt-5 grid grid-cols-3 divide-x" :class="theme === 'dark' ? 'divide-slate-800' : 'divide-slate-200'">
                        <div class="pr-3">
                            <div class="text-2xl font-semibold">{{ $discordMemberCount }}</div>
                            <div class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Discord</div>
                        </div>
                        <div class="px-3">
                            <div class="text-2xl font-semibold">{{ $patreonMemberCount }}</div>
                            <div class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Patreon</div>
                        </div>
                        <div class="pl-3">
                            <div class="text-2xl font-semibold">{{ $manualMemberCount }}</div>
                            <div class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Other</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border p-5 xl:col-span-1" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold">Drafting</h3>
                            <p class="text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">League draft status</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ count($communityDraftingRows ?? []) }}</span>
                    </div>
                    <div class="mt-4 max-h-64 divide-y overflow-y-auto pr-1" :class="theme === 'dark' ? 'divide-slate-800' : 'divide-slate-200'">
                        @forelse (($communityDraftingRows ?? []) as $draftingRow)
                            @php
                                $draftingStatus = $draftingRow['status'] ?? '';
                                $draftingBadgeClass = match ($draftingStatus) {
                                    'Live' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                                    'Complete' => 'bg-blue-50 text-blue-700 ring-blue-100',
                                    default => 'bg-slate-100 text-slate-600 ring-slate-200',
                                };
                                $showDraftingBadge = in_array($draftingStatus, ['Live', 'Complete'], true);
                            @endphp
                            <div class="py-1.5">
                                <div class="flex min-w-0 items-center justify-between gap-3">
                                    <div class="min-w-0 truncate text-[11px] font-semibold">{{ $draftingRow['name'] }}</div>
                                    @if ($showDraftingBadge)
                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $draftingBadgeClass }}">
                                            {{ $draftingRow['status'] }}
                                        </span>
                                    @elseif (($draftingRow['detail'] ?? '') !== '')
                                        <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-100">
                                            {{ $draftingRow['detail'] }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="rounded-md border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                                Connected league drafts will appear here after draft state is available.
                            </p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border p-5 xl:col-span-1" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold">Syncs</h3>
                            <p class="text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">Connection status</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'bg-emerald-500/10 text-emerald-300' : 'bg-emerald-50 text-emerald-700'">{{ $connectedCount }} active</span>
                    </div>
                    <div class="mt-5 space-y-2">
                        <div class="flex items-center justify-between rounded-md border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                            <span class="text-sm font-semibold">Discord</span>
                            <span class="text-xs font-semibold {{ $guilds->isNotEmpty() ? 'text-emerald-600' : 'text-slate-500' }}">{{ $guilds->isNotEmpty() ? 'Connected' : 'Not connected' }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-md border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                            <span class="text-sm font-semibold">Patreon</span>
                            <span class="text-xs font-semibold {{ $patreonAccount ? 'text-emerald-600' : 'text-slate-500' }}">{{ $patreonAccount ? 'Connected' : 'Not connected' }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-md border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                            <span class="text-sm font-semibold">Fantrax</span>
                            <span class="text-xs font-semibold {{ $fantraxConnected ? 'text-emerald-600' : 'text-slate-500' }}">{{ $fantraxConnected ? 'Connected' : 'Not connected' }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border p-5 xl:col-span-2" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold">League Overview</h3>
                            <p class="text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">Connected leagues for this community</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1.5 text-xs font-semibold"
                            :class="theme === 'dark' ? 'border-slate-700 text-slate-300 hover:bg-slate-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                            @click="activeTab = 'leagues'"
                        >
                            Manage leagues
                        </button>
                    </div>
                    <div class="mt-5 grid grid-cols-3 divide-x rounded-lg border px-4 py-4" :class="theme === 'dark' ? 'divide-slate-800 border-slate-800 bg-slate-950' : 'divide-slate-200 border-slate-200 bg-slate-50'">
                        <div class="pr-4">
                            <div class="text-2xl font-semibold" data-community-league-count>{{ $leagues->count() }}</div>
                            <div class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Total</div>
                        </div>
                        <div class="px-4">
                            <div class="text-2xl font-semibold">{{ $leagues->where('platform', 'fantrax')->count() }}</div>
                            <div class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Fantrax</div>
                        </div>
                        <div class="pl-4">
                            <div class="text-2xl font-semibold">{{ $guilds->count() }}</div>
                            <div class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Discord servers</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border p-5 xl:col-span-1" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                    <div>
                        <h3 class="text-base font-semibold">Integrations</h3>
                        <p class="text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">Services powering this community</p>
                    </div>
                    <div class="mt-5 grid gap-3">
                        <button type="button" class="flex items-center justify-between rounded-md border px-3 py-3 text-left" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'" @click="activeTab = 'connections'">
                            <span class="text-sm font-semibold">Discord</span>
                            <span class="text-xs {{ $guilds->isNotEmpty() ? 'text-emerald-600' : 'text-slate-500' }}">{{ $guilds->count() }} servers</span>
                        </button>
                        <button type="button" class="flex items-center justify-between rounded-md border px-3 py-3 text-left" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'" @click="activeTab = 'connections'">
                            <span class="text-sm font-semibold">Patreon</span>
                            <span class="text-xs {{ $patreonAccount ? 'text-emerald-600' : 'text-slate-500' }}">{{ $patreonAccount ? 'Connected' : 'Not connected' }}</span>
                        </button>
                        <button type="button" class="flex items-center justify-between rounded-md border px-3 py-3 text-left" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'" @click="activeTab = 'connections'">
                            <span class="text-sm font-semibold">Fantrax</span>
                            <span class="text-xs {{ $fantraxConnected ? 'text-emerald-600' : 'text-slate-500' }}">{{ $fantraxConnected ? 'Connected' : 'Not connected' }}</span>
                        </button>
                    </div>
                </section>
            </div>

            <section x-show="!selectedLeague && activeTab === 'members'" x-cloak class="rounded-lg border p-5" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">Members</h3>
                        <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                            Provider-connected members will appear here after the community has a connected provider and member sync is enabled.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($canEdit && $currentOrg)
                            @php($canRefreshCommunityMembers = $guilds->isNotEmpty() || (bool) $patreonAccount)
                            <button
                                type="button"
                                @disabled(!$canRefreshCommunityMembers)
                                @if($canRefreshCommunityMembers)
                                    data-community-members-refresh
                                    data-url="{{ route('organizations.members.refresh', ['organization' => $currentOrg->id]) }}"
                                @endif
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border {{ $canRefreshCommunityMembers ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200' : 'cursor-not-allowed border-slate-200 bg-white text-slate-300' }}"
                                title="{{ $canRefreshCommunityMembers ? 'Refresh community members' : 'Connect Discord or Patreon before refreshing members' }}"
                                aria-label="{{ $canRefreshCommunityMembers ? 'Refresh community members from connected providers' : 'Community member refresh unavailable' }}"
                                data-idle-title="{{ $canRefreshCommunityMembers ? 'Refresh community members' : 'Connect Discord or Patreon before refreshing members' }}"
                                data-idle-label="{{ $canRefreshCommunityMembers ? 'Refresh community members from connected providers' : 'Community member refresh unavailable' }}"
                                data-loading-title="Refreshing community members"
                                data-loading-label="Refreshing community members from connected providers"
                            >
                                <svg data-community-members-refresh-icon class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 20v-6h-6" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 9a8 8 0 0 0-13.657-3.657L4 7.686" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 15a8 8 0 0 0 13.657 3.657L20 16.314" />
                                </svg>
                            </button>
                        @endif
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'">
                            <span x-text="$store.communityMembers.memberMeta.total || {{ $totalMembers }}"></span> total
                        </span>
                    </div>
                </div>

                <div class="mt-5 overflow-hidden rounded-lg border" :class="theme === 'dark' ? 'border-slate-800' : 'border-slate-200'">
                    <template x-if="$store.communityMembers.members.length === 0">
                        <div class="p-8 text-center">
                            <h4 class="text-base font-semibold">No synced members yet</h4>
                            <p class="mx-auto mt-2 max-w-xl text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                                Connect Discord or Patreon in the Connections tab, then refresh members to populate this roster.
                            </p>
                            <button type="button" class="mt-4 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white" @click="activeTab = 'connections'">
                                Open Connections
                            </button>
                        </div>
                    </template>

                    <template x-if="$store.communityMembers.members.length > 0">
                        <div class="divide-y" :class="theme === 'dark' ? 'divide-slate-800' : 'divide-slate-200'">
                            <template x-for="member in $store.communityMembers.members" :key="member.id">
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <template x-if="member.avatar_url">
                                            <img :src="member.avatar_url" alt="" class="h-9 w-9 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
                                        </template>
                                        <template x-if="!member.avatar_url">
                                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-xs font-semibold"
                                                  :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'"
                                                  x-text="(member.display_name || '?').slice(0, 1).toUpperCase()"></span>
                                        </template>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold" x-text="member.display_name || 'Unknown member'"></p>
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                                <span x-text="member.email || 'No email on file'"></span>
                                                <template x-if="member.provider_label">
                                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="member.provider === 'discord' ? 'bg-indigo-50 text-indigo-700' : 'bg-rose-50 text-rose-700'" x-text="member.provider_label"></span>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-semibold text-indigo-600" x-text="member.tier?.name || 'No tier'"></p>
                                        <p class="text-[11px]" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'" x-text="$store.communityMembers.statusLabel(member.status)"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'" x-show="$store.communityMembers.memberMeta.total">
                    <div>
                        Page <span x-text="$store.communityMembers.memberMeta.current_page"></span>
                        of <span x-text="$store.communityMembers.memberMeta.last_page"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1.5 font-semibold disabled:cursor-not-allowed disabled:opacity-50"
                            :class="theme === 'dark' ? 'border-slate-700 hover:bg-slate-800' : 'border-slate-200 hover:bg-slate-50'"
                            :disabled="$store.communityMembers.memberMeta.current_page <= 1"
                            @click="$store.communityMembers.fetchMembers(($store.communityMembers.memberMeta.current_page || 1) - 1)"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-1.5 font-semibold disabled:cursor-not-allowed disabled:opacity-50"
                            :class="theme === 'dark' ? 'border-slate-700 hover:bg-slate-800' : 'border-slate-200 hover:bg-slate-50'"
                            :disabled="$store.communityMembers.memberMeta.current_page >= $store.communityMembers.memberMeta.last_page"
                            @click="$store.communityMembers.fetchMembers(($store.communityMembers.memberMeta.current_page || 1) + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </section>

            <section x-show="!selectedLeague && activeTab === 'leagues'" x-cloak class="rounded-lg border p-5" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold">Leagues</h3>
                        <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                            Manage league associations for this community.
                        </p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" data-community-league-count :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'">
                        {{ $leagues->count() }}
                    </span>
                </div>

                @include('communities._desktop-leagues', [
                    'leagues' => $leagues,
                    'guilds' => $guilds,
                    'currentOrg' => $currentOrg,
                    'canEdit' => $canEdit,
                    'allowUnlink' => true,
                ])
            </section>

            <div x-show="!selectedLeague && activeTab === 'connections'" x-cloak>
                @include('communities._desktop-connections')
            </div>

            <section
                x-show="selectedLeague"
                x-cloak
                class="!mt-0 rounded-lg border p-4"
                :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
            >
                <div x-show="activeLeagueTab === 'home'" x-cloak>
                    <h3 class="text-lg font-semibold">League Home</h3>
                    <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                        Setup summary for this community league.
                    </p>
                    <div class="mt-5 grid gap-3 md:grid-cols-3">
                        <div class="rounded-lg border p-4" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                            <div class="text-xs font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Platform</div>
                            <div class="mt-2 text-sm font-semibold" x-text="selectedLeague?.platform || 'League'"></div>
                        </div>
                        <div class="rounded-lg border p-4" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                            <div class="text-xs font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Scope</div>
                            <div class="mt-2 text-sm font-semibold" x-text="selectedLeague?.scope || 'All teams'"></div>
                        </div>
                        <div class="rounded-lg border p-4" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                            <div class="text-xs font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Discord</div>
                            <div class="mt-2 text-sm font-semibold" x-text="selectedLeague?.server || 'Not bound'"></div>
                        </div>
                    </div>
                </div>

                <div x-show="activeLeagueTab === 'teams'" x-cloak>
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold">Teams</h3>
                            <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                                Fantasy teams attached to this league wrapper.
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'" x-text="leagueTeams.length"></span>
                    </div>

                    <div x-show="leagueTeamsLoading" class="rounded-lg border p-6 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-200 text-slate-600'">
                        Loading teams...
                    </div>

                    <div x-show="leagueTeamsError" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700" x-text="leagueTeamsError"></div>

                    <div x-show="!leagueTeamsLoading && !leagueTeamsError && leagueTeams.length === 0" class="rounded-lg border border-dashed p-6 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                        No teams are available for this league yet.
                    </div>

                    <div x-show="!leagueTeamsLoading && !leagueTeamsError && leagueTeams.length > 0" class="divide-y rounded-lg border" :class="theme === 'dark' ? 'divide-slate-800 border-slate-800' : 'divide-slate-200 border-slate-200'">
                        <template x-for="team in leagueTeams" :key="team.id">
                            <div class="flex items-center justify-between gap-4 px-4 py-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <template x-if="team.logo_url">
                                        <img :src="team.logo_url" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
                                    </template>
                                    <template x-if="!team.logo_url">
                                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-xs font-semibold" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'" x-text="(team.name || '?').slice(0, 2).toUpperCase()"></span>
                                    </template>
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold" x-text="team.name"></div>
                                        <div class="mt-0.5 flex flex-wrap gap-2 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                            <span x-show="team.fantrax_division" x-text="team.fantrax_division"></span>
                                            <span x-show="team.fantrax_pool" x-text="team.fantrax_pool"></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center justify-end gap-2">
                                    <template x-for="avatar in (team.owner_avatar_urls || []).slice(0, 3)" :key="avatar">
                                        <img :src="avatar" alt="" class="h-7 w-7 rounded-full object-cover ring-1 ring-slate-200">
                                    </template>
                                    <span class="max-w-48 truncate text-right text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'" x-text="(team.owner_names || []).join(', ')"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="activeLeagueTab === 'transactions'" x-cloak>
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold">Transactions</h3>
                            <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                                Refresh Fantrax trades, claims, drops, and lineup moves.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-lg border px-3 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                            :class="theme === 'dark' ? 'border-slate-700 bg-slate-950 text-slate-200 hover:bg-slate-800' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                            :disabled="leagueTransactionsLoading || !selectedLeague?.transactionsBrowserRpcUrl"
                            @click="refreshCommunityLeagueTransactions()"
                        >
                            <span x-show="!leagueTransactionsLoading">Refresh</span>
                            <span x-show="leagueTransactionsLoading">Refreshing...</span>
                        </button>
                    </div>

                    <div x-show="leagueTransactionsError" x-text="leagueTransactionsError" class="mb-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"></div>

                    <div x-show="!selectedLeague?.transactionsBrowserRpcUrl" class="rounded-lg border border-dashed p-6 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                        Connect a Fantrax platform league to refresh transactions.
                    </div>

                    <div
                        x-show="selectedLeague?.transactionsBrowserRpcUrl"
                        class="rounded-lg border"
                        :class="theme === 'dark' ? 'border-slate-800' : 'border-slate-200'"
                    >
                        <div x-show="leagueTransactionsMessage" x-text="leagueTransactionsMessage" class="border-b px-4 py-3 text-sm" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900 text-emerald-300' : 'border-slate-200 bg-emerald-50 text-emerald-700'"></div>
                        <div x-show="leagueTransactionsLoading" class="px-4 py-6 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                            Loading transactions...
                        </div>
                        <div x-show="!leagueTransactionsLoading && leagueTransactions.length === 0" class="px-4 py-6 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                            No persisted transactions yet. Refresh to import the latest Fantrax history.
                        </div>
                        <div x-show="!leagueTransactionsLoading && leagueTransactions.length > 0" class="divide-y" :class="theme === 'dark' ? 'divide-slate-800' : 'divide-slate-200'">
                            <template x-for="transaction in leagueTransactions" :key="transaction.id">
                                <article class="px-4 py-3">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-semibold" x-text="transactionTypeLabel(transaction)"></span>
                                                <span x-show="transaction.deleted" class="rounded border border-rose-200 px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-rose-700">Deleted</span>
                                                <span x-show="transaction.status" class="text-xs" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-500'" x-text="transaction.status"></span>
                                            </div>
                                            <p x-show="transaction.summary" x-text="transaction.summary" class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-300' : 'text-slate-700'"></p>
                                        </div>
                                        <div class="shrink-0 text-right text-xs" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-500'">
                                            <div x-text="transaction.occurred_at_label || 'Unknown date'"></div>
                                            <div x-show="transaction.period" x-text="transaction.period"></div>
                                        </div>
                                    </div>
                                    <ul class="mt-3 space-y-1.5">
                                        <template x-for="entry in transaction.entries" :key="entry.id">
                                            <li class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" :class="theme === 'dark' ? 'text-slate-300' : 'text-slate-700'">
                                                <span x-text="transactionEntryLabel(entry)"></span>
                                                <span x-show="transactionDraftLabel(entry)" x-text="transactionDraftLabel(entry)" class="text-xs" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-500'"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </article>
                            </template>
                        </div>
                    </div>
                </div>

                <div x-show="activeLeagueTab === 'draft'" x-cloak>
                    <div
                        class="min-w-0 rounded-xl border shadow-sm"
                        :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-white'"
                    >
                        <div class="flex items-start justify-between gap-3 border-b px-4 pt-3" :class="theme === 'dark' ? 'border-slate-800' : 'border-slate-200'">
                            <div class="flex gap-6 text-sm font-semibold">
                                <button
                                    type="button"
                                    class="border-b-2 pb-3 transition-colors"
                                    :class="activeCommunityDraftTab === 'live' ? 'border-blue-600 text-blue-700' : (theme === 'dark' ? 'border-transparent text-slate-400 hover:text-slate-200' : 'border-transparent text-slate-500 hover:text-slate-800')"
                                    @click="setCommunityDraftTab('live')"
                                >
                                    Live
                                </button>
                                <button
                                    type="button"
                                    class="border-b-2 pb-3 transition-colors"
                                    :class="activeCommunityDraftTab === 'players' ? 'border-blue-600 text-blue-700' : (theme === 'dark' ? 'border-transparent text-slate-400 hover:text-slate-200' : 'border-transparent text-slate-500 hover:text-slate-800')"
                                    @click="setCommunityDraftTab('players')"
                                >
                                    Players
                                </button>
                                <button
                                    type="button"
                                    class="border-b-2 pb-3 transition-colors"
                                    :class="activeCommunityDraftTab === 'testing' ? 'border-blue-600 text-blue-700' : (theme === 'dark' ? 'border-transparent text-slate-400 hover:text-slate-200' : 'border-transparent text-slate-500 hover:text-slate-800')"
                                    @click="setCommunityDraftTab('testing')"
                                >
                                    Testing
                                </button>
                            </div>

                            @if ($canEdit)
                                <button
                                    type="button"
                                    class="mb-2 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600"
                                    x-on:click="openDraftOptions()"
                                    aria-label="Toggle draft options"
                                >
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                </button>
                            @endif
                        </div>

                        <div class="min-h-[22rem]">
                            <div x-show="['live', 'testing'].includes(activeCommunityDraftTab)" x-cloak class="p-4">
                                <div x-show="leagueDraftSummaryLoading" class="rounded-lg border p-6 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-200 text-slate-600'">
                                    Loading draft summary...
                                </div>

                                <div x-show="leagueDraftSummaryError" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700" x-text="leagueDraftSummaryError"></div>

                                <div x-show="!leagueDraftSummaryLoading && !leagueDraftSummaryError && leagueDraftSummary" class="grid gap-3 lg:grid-cols-[minmax(0,1.15fr)_13rem_minmax(0,1fr)_minmax(0,1.45fr)]">
                                    <div class="rounded-xl border px-4 py-3 shadow-sm" :class="theme === 'dark' ? 'border-cyan-900 bg-slate-950' : 'border-blue-100 bg-white'">
                                        <div class="text-[11px] font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-cyan-300' : 'text-blue-600'">On The Clock</div>
                                        <div class="mt-2 flex min-w-0 items-center gap-3">
                                            <template x-if="leagueDraftSummary?.otc_team?.avatar_url">
                                                <img :src="leagueDraftSummary.otc_team.avatar_url" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200">
                                            </template>
                                            <template x-if="!leagueDraftSummary?.otc_team?.avatar_url">
                                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-1" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300 ring-slate-700' : 'bg-slate-100 text-slate-500 ring-slate-200'">OTC</span>
                                            </template>
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold" x-text="leagueDraftSummary?.otc_team?.name || 'Awaiting pick'"></div>
                                                <div class="mt-0.5 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                                    <span x-text="`Round ${leagueDraftSummary?.otc_team?.round || '-'}, Pick ${leagueDraftSummary?.otc_team?.pick || '-'}`"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border px-4 py-3 shadow-sm" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-white'">
                                        <div class="text-[11px] font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Time Remaining</div>
                                        <div class="mt-2 grid grid-cols-[minmax(0,1fr)_2.25rem] items-center gap-2">
                                            <div class="min-w-0 truncate text-2xl font-semibold tracking-tight tabular-nums" x-text="draftTimeRemainingLabel()"></div>
                                            <span class="flex h-9 w-9 justify-self-end rounded-full bg-slate-100 p-1" aria-hidden="true">
                                                <span class="h-full w-full rounded-full" :class="theme === 'dark' ? 'bg-slate-950' : 'bg-white'"></span>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border px-4 py-3 shadow-sm" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-white'">
                                        <div class="text-[11px] font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Up Next</div>
                                        <div class="mt-2 flex min-w-0 items-center gap-3">
                                            <template x-if="leagueDraftSummary?.up_next_team?.avatar_url">
                                                <img :src="leagueDraftSummary.up_next_team.avatar_url" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200">
                                            </template>
                                            <template x-if="!leagueDraftSummary?.up_next_team?.avatar_url">
                                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-1" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300 ring-slate-700' : 'bg-slate-100 text-slate-500 ring-slate-200'">UP</span>
                                            </template>
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold" x-text="leagueDraftSummary?.up_next_team?.name || 'No upcoming pick'"></div>
                                                <div class="mt-0.5 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                                    <span x-text="`Round ${leagueDraftSummary?.up_next_team?.round || '-'}, Pick ${leagueDraftSummary?.up_next_team?.pick || '-'}`"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-xl border px-4 py-3 shadow-sm" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-white'">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <div class="flex items-baseline gap-2 text-[11px] font-semibold uppercase tracking-wide" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                                    <span>Round Progress</span>
                                                    <span class="font-semibold tracking-normal" :class="theme === 'dark' ? 'text-slate-100' : 'text-slate-900'" x-text="`${leagueDraftSummary?.drafted_count || 0} / ${leagueDraftSummary?.total_picks || 0}`"></span>
                                                </div>
                                                <div class="mt-1 flex items-center gap-2 text-xs font-medium" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                                    <span class="h-2 w-2 rounded-full" :class="draftStatusDotClass()"></span>
                                                    <span x-text="leagueDraftSummary?.status_text || 'Draft'"></span>
                                                    <template x-if="leagueDraftSummary?.draft_at_label">
                                                        <span class="flex items-center gap-2">
                                                            <span :class="theme === 'dark' ? 'text-slate-700' : 'text-slate-300'">/</span>
                                                            <span x-text="leagueDraftSummary.draft_at_label"></span>
                                                        </span>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="relative mt-3 pb-3" data-draft-round-scroll-root>
                                            <div class="draft-round-scroll flex min-w-0 flex-nowrap items-center gap-1.5 overflow-x-auto pb-1" data-draft-round-scroll>
                                                <template x-for="round in (leagueDraftSummary?.rounds || [])" :key="round.label">
                                                    <span class="inline-flex h-6 min-w-6 shrink-0 items-center justify-center rounded-full px-2 text-[11px] font-semibold" :class="Number(round.round) === Number(leagueDraftSummary?.active_round) ? 'bg-cyan-700 text-white' : (theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600')" x-text="round.round || '?'"></span>
                                                </template>
                                                <span x-show="(leagueDraftSummary?.rounds || []).length === 0" class="shrink-0 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">No draft rounds loaded.</span>
                                            </div>
                                            <div class="draft-round-scrollbar" data-draft-round-scrollbar aria-hidden="true">
                                                <span class="draft-round-scrollbar__thumb" data-draft-round-scroll-thumb></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    x-show="!leagueDraftSummaryLoading && !leagueDraftSummaryError"
                                    class="mt-4 h-[38rem] min-h-0 overflow-hidden"
                                >
                                    <div
                                        x-ref="communityDraftLivePanel"
                                        x-show="leagueDraftLiveHtml"
                                        class="h-full min-h-0"
                                        x-html="leagueDraftLiveHtml"
                                    ></div>
                                    <div
                                        x-show="!leagueDraftLiveHtml"
                                        class="rounded-xl border px-4 py-6 text-sm"
                                        :class="theme === 'dark' ? 'border-slate-800 bg-slate-950 text-slate-400' : 'border-slate-200 bg-slate-50 text-slate-600'"
                                    >
                                        No draft picks loaded.
                                    </div>
                                </div>
                            </div>

                            <div x-show="activeCommunityDraftTab === 'players'" x-cloak class="flex min-h-[22rem] flex-col overflow-hidden">
                                <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50/70 px-3 py-2">
                                    <div class="w-48 shrink-0">
                                        <label class="sr-only" for="community-draft-player-search">Search players</label>
                                        <input
                                            id="community-draft-player-search"
                                            type="search"
                                            x-model.debounce.150ms="communityDraftPlayerSearch"
                                            class="h-9 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                                            placeholder="Search players"
                                        >
                                    </div>

                                    <label class="sr-only" for="community-draft-player-team">Team</label>
                                    <select
                                        id="community-draft-player-team"
                                        x-model="communityDraftPlayerTeam"
                                        class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm outline-none transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                                    >
                                        <option value="">All Teams</option>
                                        <template x-for="team in communityDraftPlayerTeams" :key="team">
                                            <option :value="team" x-text="team"></option>
                                        </template>
                                    </select>

                                    <label class="sr-only" for="community-draft-player-perspective">Perspective</label>
                                    <select
                                        id="community-draft-player-perspective"
                                        x-model="communityDraftSelectedPerspective"
                                        x-on:change="setCommunityDraftPlayerPerspective($event.target.value)"
                                        class="h-9 min-w-44 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm outline-none transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                                    >
                                        <template x-for="perspective in communityDraftPlayerPerspectives" :key="perspective.slug || perspective.id || perspective.name">
                                            <option :value="perspective.slug || perspective.id || perspective.name" x-text="perspective.name || perspective.slug"></option>
                                        </template>
                                    </select>

                                    <button
                                        type="button"
                                        x-show="communityDraftPlayerSearch || communityDraftPlayerTeam"
                                        x-on:click="communityDraftResetPlayerFilters()"
                                        class="h-9 rounded-lg px-2 text-xs font-semibold text-slate-500 transition hover:bg-white hover:text-slate-800"
                                    >
                                        Reset
                                    </button>
                                </div>

                                <div x-show="communityDraftPlayersError" class="border-b border-amber-100 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700" x-text="communityDraftPlayersError"></div>

                                <div class="min-h-0 flex-1 overflow-auto">
                                    <table class="min-w-full divide-y divide-slate-100 text-left">
                                        <thead class="sticky top-0 z-10 bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                            <tr>
                                                <th scope="col" class="w-14 px-4 py-2.5">Rk</th>
                                                <th scope="col" class="whitespace-nowrap px-2 py-2.5 text-right">
                                                    <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers('drafted_overall_pick')">
                                                        <span>Drafted</span>
                                                        <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator('drafted_overall_pick')"></span>
                                                    </button>
                                                </th>
                                                <th scope="col" class="min-w-56 px-3 py-2.5">
                                                    <button type="button" class="inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers('name')">
                                                        <span>Player</span>
                                                        <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator('name')"></span>
                                                    </button>
                                                </th>
                                                <th scope="col" class="whitespace-nowrap pl-1 pr-4 py-2.5 text-right">
                                                    <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers('age')">
                                                        <span>Age</span>
                                                        <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator('age')"></span>
                                                    </button>
                                                </th>
                                                <th scope="col" class="whitespace-nowrap px-2 py-2.5 text-center">
                                                    <button type="button" class="inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers('team')">
                                                        <span>Team</span>
                                                        <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator('team')"></span>
                                                    </button>
                                                </th>
                                                <th scope="col" class="min-w-24 pl-5 pr-2 py-2.5">
                                                    <button type="button" class="inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers('league')">
                                                        <span>League</span>
                                                        <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator('league')"></span>
                                                    </button>
                                                </th>
                                                <th scope="col" class="whitespace-nowrap px-3 py-2.5 text-right">
                                                    <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers('gp')">
                                                        <span>GP</span>
                                                        <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator('gp')"></span>
                                                    </button>
                                                </th>
                                                <template x-for="heading in communityDraftPlayerStatHeadings" :key="heading.key">
                                                    <th scope="col" class="whitespace-nowrap px-3 py-2.5 text-right">
                                                        <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortCommunityDraftPlayers(heading.key)">
                                                            <span x-text="heading.label || heading.key"></span>
                                                            <span class="text-[10px] text-blue-600" x-text="communityDraftPlayerSortIndicator(heading.key)"></span>
                                                        </button>
                                                    </th>
                                                </template>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 bg-white text-sm">
                                            <template x-for="(player, index) in filteredCommunityDraftPlayers" :key="player.id || player.player_id || `${player.name}-${index}`">
                                                <tr class="transition-colors hover:bg-slate-50/80">
                                                    <td class="px-4 py-2.5 text-xs font-semibold text-slate-500" x-text="index + 1"></td>
                                                    <td class="whitespace-nowrap px-2 py-2.5 text-right text-[10px] font-semibold tabular-nums text-slate-600" x-text="communityDraftPlayerDraftedLabel(player)"></td>
                                                    <td class="px-3 py-2.5">
                                                        <div class="flex min-w-0 items-center gap-3">
                                                            <template x-if="player.avatar_url || player.head_shot_url">
                                                                <img :src="player.avatar_url || player.head_shot_url" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                                                            </template>
                                                            <template x-if="!player.avatar_url && !player.head_shot_url">
                                                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 ring-1 ring-slate-200" x-text="communityDraftPlayerInitials(player)"></span>
                                                            </template>
                                                            <div class="min-w-0">
                                                                <div class="truncate text-sm font-semibold text-slate-900" x-text="player.name || player.full_name"></div>
                                                                <div class="mt-0.5 truncate text-[11px] text-slate-500">
                                                                    <span x-show="player.position || player.pos" x-text="player.position || player.pos"></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="whitespace-nowrap pl-1 pr-4 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="communityDraftPlayerAge(player)"></td>
                                                    <td class="whitespace-nowrap px-2 py-2.5 text-center">
                                                        <span
                                                            class="inline-flex h-6 min-w-12 items-center justify-center rounded-md px-2 text-[10px] font-semibold tracking-wide text-white shadow-sm"
                                                            :style="communityDraftTeamBadgeStyle(player.team_abbrev || player.team)"
                                                            x-text="player.team_abbrev || player.team || '-'"
                                                        ></span>
                                                    </td>
                                                    <td class="whitespace-nowrap pl-5 pr-2 py-2.5 text-xs font-semibold text-slate-600" x-text="communityDraftPlayerLeagueName(player)"></td>
                                                    <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="communityDraftPlayerGp(player)"></td>
                                                    <template x-for="heading in communityDraftPlayerStatHeadings" :key="`${player.id || player.player_id}-${heading.key}`">
                                                        <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="communityDraftFormatValue(communityDraftPlayerValue(player, heading.key))"></td>
                                                    </template>
                                                </tr>
                                            </template>
                                            <tr x-show="!communityDraftPlayersLoading && communityDraftPlayerLoaded && filteredCommunityDraftPlayers.length === 0">
                                                <td :colspan="Math.max(7 + communityDraftPlayerStatHeadings.length, 7)" class="px-4 py-8 text-center text-sm text-slate-500">No players match this draft view.</td>
                                            </tr>
                                            <template x-for="index in (communityDraftPlayersLoading ? 6 : 0)" :key="`community-draft-players-loading-${index}`">
                                                <tr class="animate-pulse">
                                                    <td class="px-4 py-2.5"><div class="h-3 w-5 rounded-full bg-slate-200"></div></td>
                                                    <td class="px-2 py-2.5"><div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div></td>
                                                    <td class="px-3 py-2.5">
                                                        <div class="flex min-w-0 items-center gap-3">
                                                            <div class="h-8 w-8 shrink-0 rounded-full bg-slate-200"></div>
                                                            <div class="min-w-0 flex-1 space-y-2">
                                                                <div class="h-3 w-40 max-w-full rounded-full bg-slate-200"></div>
                                                                <div class="h-2 w-24 rounded-full bg-slate-100"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-2.5"><div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div></td>
                                                    <td class="px-2 py-2.5"><div class="mx-auto h-5 w-12 rounded-md bg-slate-100"></div></td>
                                                    <td class="px-4 py-2.5"><div class="h-3 w-16 rounded-full bg-slate-100"></div></td>
                                                    <td class="px-3 py-2.5"><div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div></td>
                                                    <template x-for="heading in communityDraftPlayerStatHeadings" :key="`community-draft-players-loading-${index}-${heading.key}`">
                                                        <td class="px-3 py-2.5"><div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div></td>
                                                    </template>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="activeLeagueTab === 'setup'" x-cloak>
                    <h3 class="text-lg font-semibold">Setup</h3>
                    <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                        Community league configuration and Discord output options.
                    </p>

                    @if ($canEdit)
                        <div class="mt-5 max-w-xl rounded-lg border p-4" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-white'">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h4 class="text-sm font-semibold" :class="theme === 'dark' ? 'text-slate-200' : 'text-slate-800'">Team logos</h4>
                                    <p class="mt-1 text-xs" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-500'">Pull platform team logos into this league.</p>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="syncSelectedLeagueLogos()"
                                    :disabled="teamLogosSyncing || !selectedLeague?.teamLogoSyncUrl"
                                    class="inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    <span x-show="!teamLogosSyncing">Sync Logos</span>
                                    <span x-show="teamLogosSyncing">Syncing...</span>
                                </button>
                            </div>
                            <p x-show="teamLogosMessage" x-text="teamLogosMessage" class="mt-2 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'"></p>
                            <p x-show="teamLogosError" x-text="teamLogosError" class="mt-2 text-xs text-red-600"></p>
                            <p x-show="!selectedLeague?.teamLogoSyncUrl" class="mt-2 text-xs" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-500'">Logo sync is available for Fantrax and Yahoo leagues.</p>
                        </div>

                        <div class="mt-5 max-w-xl rounded-lg border p-4" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-white'">
                            <div class="flex items-center justify-between gap-3">
                                <label class="text-sm font-medium" :class="theme === 'dark' ? 'text-slate-200' : 'text-slate-700'" for="community-setup-transactions-channel-combobox">Transactions channel</label>
                                <span class="text-[11px]" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-400'" x-text="draftChannelOptions.length + ' loaded'"></span>
                            </div>
                            <div x-show="draftOptionsLoading" class="mt-2 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Loading league options...</div>
                            <div x-show="draftOptionsError" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700" x-text="draftOptionsError"></div>
                            <div class="relative mt-2" @click.stop>
                                <input
                                    id="community-setup-transactions-channel-combobox"
                                    type="text"
                                    x-model="transactionsChannelQuery"
                                    x-on:focus="transactionsChannelOpen = true"
                                    x-on:click="transactionsChannelOpen = true"
                                    x-on:input="transactionsChannelId = ''; transactionsChannelOpen = true"
                                    x-on:keydown.escape.prevent.stop="transactionsChannelOpen = false"
                                    placeholder="None"
                                    autocomplete="off"
                                    class="block w-full rounded-md border-slate-200 pr-9 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400"
                                    :disabled="!draftDiscordConnected"
                                >
                                <button
                                    type="button"
                                    x-on:click="transactionsChannelOpen = !transactionsChannelOpen"
                                    class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 text-slate-400"
                                    :disabled="!draftDiscordConnected"
                                >
                                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                    </svg>
                                </button>

                                <div x-cloak x-show="transactionsChannelOpen" x-transition @click.outside="transactionsChannelOpen = false" class="absolute z-30 mt-1 max-h-48 w-full overflow-auto rounded-md bg-white p-1 text-sm shadow-lg ring-1 ring-black/5">
                                    <button type="button" class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-blue-600 hover:text-white" x-on:click="selectTransactionsChannel({ id: '', name: '' })">
                                        None
                                    </button>
                                    <template x-for="channel in filteredTransactionsChannels" :key="channel.id">
                                        <button type="button" class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-blue-600 hover:text-white" x-on:click="selectTransactionsChannel(channel)">
                                            <span class="truncate">#<span x-text="channel.name"></span></span>
                                        </button>
                                    </template>
                                    <div x-show="!transactionsChannelQuery && draftChannelOptions.length === 0" class="px-3 py-2 text-xs text-slate-500" x-text="draftChannelsMessage || 'No text channels returned for this Discord server.'"></div>
                                    <button type="button" x-show="transactionsChannelQuery && filteredTransactionsChannels.length === 0" class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-blue-600 hover:text-white" x-on:click="transactionsChannelOpen = false">
                                        Create #<span x-text="transactionsChannelQuery.replace(/^#/, '')"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-3">
                                <p class="text-[11px]" :class="theme === 'dark' ? 'text-slate-500' : 'text-slate-500'" x-text="draftDiscordConnected ? 'New names create a text channel.' : 'Connect a Discord server first.'"></p>
                                <button type="button" x-on:click="saveTransactionsChannel()" :disabled="transactionsChannelSaving || !draftDiscordConnected" class="rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
                                    <span x-show="!transactionsChannelSaving">Save</span>
                                    <span x-show="transactionsChannelSaving">Saving...</span>
                                </button>
                            </div>
                            <p x-show="transactionsChannelMessage" x-text="transactionsChannelMessage" class="mt-2 text-[11px]" :class="transactionsChannelMessage.includes('Could not') ? 'text-red-600' : (theme === 'dark' ? 'text-slate-400' : 'text-slate-500')"></p>
                        </div>
                    @else
                        <div class="mt-5 max-w-xl rounded-lg border border-dashed p-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                            League setup options are available to league managers.
                        </div>
                    @endif
                </div>
            </section>
        </main>
    </div>

    @if ($canEdit)
        <x-ui.slide-over show="draftOptionsOpen" close-action="draftOptionsOpen = false" title-id="community-draft-options-title" max-width="max-w-md">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 id="community-draft-options-title" class="text-base font-semibold text-slate-950">Draft Options</h2>
                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600" x-on:click="draftOptionsOpen = false" aria-label="Close draft options">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                    </svg>
                </button>
            </div>

            <div class="flex-1 divide-y divide-slate-200 overflow-y-auto px-5">
                <section class="py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Clock</div>
                    <div class="mt-3 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div x-show="draftOptionsLoading" class="text-xs font-medium text-slate-500">Loading draft options...</div>
                        <div x-show="draftOptionsError" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700" x-text="draftOptionsError"></div>

                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-3">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-600">Hours</span>
                                    <input type="number" min="0" max="24" step="1" inputmode="numeric" x-model.number="draftPickClockHours" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-600">Minutes</span>
                                    <input type="number" min="0" max="59" step="1" inputmode="numeric" x-model.number="draftPickClockMinutes" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-600">Seconds</span>
                                    <input type="number" min="0" max="59" step="1" inputmode="numeric" x-model.number="draftPickClockSeconds" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                                </label>
                            </div>
                            <div class="mt-4">
                                <label class="block">
                                    <span class="text-xs font-semibold text-slate-600">Pause seconds</span>
                                    <input type="number" min="0" max="3600" step="1" inputmode="numeric" x-model.number="draftPauseSeconds" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                                </label>
                            </div>
                            <label class="mt-4 flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                <input type="checkbox" x-model="draftAutoPickEnabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                                <span class="text-sm font-medium text-slate-700">Enable auto-pick</span>
                            </label>
                            <div class="mt-3 min-h-5 text-xs">
                                <span x-show="draftTimerMessage" x-text="draftTimerMessage" :class="draftTimerMessage.includes('Could not') ? 'text-red-600' : 'text-emerald-700'"></span>
                                <span x-show="!draftTimerCanUpdate && !draftTimerMessage" class="text-slate-500">Connect a draft before saving timer settings.</span>
                            </div>
                            <button type="button" class="mt-2 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60" x-on:click="saveDraftTimerSettings()" :disabled="draftTimerSaving || !draftTimerCanUpdate">
                                <span x-show="!draftTimerSaving">Save timer settings</span>
                                <span x-show="draftTimerSaving">Saving...</span>
                            </button>
                        </div>
                    </div>
                </section>

                <section class="py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Discord</div>
                    <div class="mt-3 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <label class="text-sm font-medium text-slate-700" for="community-draft-channel-combobox">Draft pick channel</label>
                                <span class="text-[11px] text-slate-400" x-text="draftChannelOptions.length + ' loaded'"></span>
                            </div>
                            <div class="relative mt-2" @click.stop>
                                <input
                                    id="community-draft-channel-combobox"
                                    type="text"
                                    x-model="draftChannelQuery"
                                    x-on:focus="draftChannelOpen = true"
                                    x-on:click="draftChannelOpen = true"
                                    x-on:input="draftChannelId = ''; draftChannelOpen = true"
                                    x-on:keydown.escape.prevent.stop="draftChannelOpen = false"
                                    placeholder="None"
                                    autocomplete="off"
                                    class="block w-full rounded-md border-slate-200 pr-9 text-sm text-slate-900 focus:border-blue-500 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400"
                                    :disabled="!draftDiscordConnected"
                                >
                                <button
                                    type="button"
                                    x-on:click="draftChannelOpen = !draftChannelOpen"
                                    class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 text-slate-400"
                                    :disabled="!draftDiscordConnected"
                                >
                                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                    </svg>
                                </button>

                                <div x-cloak x-show="draftChannelOpen" x-transition @click.outside="draftChannelOpen = false" class="absolute z-30 mt-1 max-h-48 w-full overflow-auto rounded-md bg-white p-1 text-sm shadow-lg ring-1 ring-black/5">
                                    <button type="button" class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-blue-600 hover:text-white" x-on:click="selectDraftChannel({ id: '', name: '' })">
                                        None
                                    </button>
                                    <template x-for="channel in filteredDraftChannels" :key="channel.id">
                                        <button type="button" class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-blue-600 hover:text-white" x-on:click="selectDraftChannel(channel)">
                                            <span class="truncate">#<span x-text="channel.name"></span></span>
                                        </button>
                                    </template>
                                    <div x-show="!draftChannelQuery && draftChannelOptions.length === 0" class="px-3 py-2 text-xs text-slate-500" x-text="draftChannelsMessage || 'No text channels returned for this Discord server.'"></div>
                                    <button type="button" x-show="draftChannelQuery && filteredDraftChannels.length === 0" class="flex w-full items-center rounded-md px-3 py-2 text-left text-slate-700 hover:bg-blue-600 hover:text-white" x-on:click="draftChannelOpen = false">
                                        Create #<span x-text="draftChannelQuery.replace(/^#/, '')"></span>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-3 grid gap-2">
                                <label class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                    <input type="checkbox" x-model="draftAnnounceOtc" :disabled="!draftDiscordConnected" class="rounded border-slate-300 text-blue-600 focus:ring-blue-600 disabled:cursor-not-allowed disabled:opacity-60">
                                    <span class="text-sm font-medium text-slate-700">Announce OTC</span>
                                </label>
                                <label class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200">
                                    <input type="checkbox" x-model="draftAnnounceOnDeck" :disabled="!draftDiscordConnected" class="rounded border-slate-300 text-blue-600 focus:ring-blue-600 disabled:cursor-not-allowed disabled:opacity-60">
                                    <span class="text-sm font-medium text-slate-700">Announce On Deck</span>
                                </label>
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-3">
                                <p class="text-[11px] text-slate-500" x-text="draftDiscordConnected ? 'New names create a text channel.' : 'Connect a Discord server first.'"></p>
                                <button type="button" x-on:click="saveDraftChannel()" :disabled="draftChannelSaving || !draftDiscordConnected" class="rounded-md bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
                                    <span x-show="!draftChannelSaving">Save</span>
                                    <span x-show="draftChannelSaving">Saving...</span>
                                </button>
                            </div>
                            <p x-show="draftChannelMessage" x-text="draftChannelMessage" class="mt-2 text-[11px] text-slate-500"></p>
                        </div>
                    </div>
                </section>

                @foreach ([
                    'Auto-Pick' => ['Enable Auto-Pick', 'Auto-Pick Strategy'],
                    'Queue Settings' => ['Queue Prioritization', 'Max Queue Size', 'Duplicates'],
                    'Sound & Notifications' => ['Pick Time Alerts', 'Sound Notifications'],
                    'League Info' => ['League Home', 'View Managers', 'Draft Order'],
                ] as $section => $items)
                    <section class="py-4">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $section }}</div>
                        <div class="mt-2 space-y-1">
                            @foreach ($items as $item)
                                <button type="button" class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-slate-50">
                                    <span class="text-sm font-semibold text-slate-800">{{ $item }}</span>
                                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                    </svg>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </x-ui.slide-over>
    @endif

    <div
        x-cloak
        x-show="$store.communityMembers.modals.settings"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
        @keydown.escape.window="$store.communityMembers.modals.settings = false"
        @click.self="$store.communityMembers.modals.settings = false"
    >
        <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Community Settings</h3>
                    <p class="text-xs text-slate-600">Update the community name.</p>
                </div>
                <button type="button" class="text-slate-500 hover:text-slate-700" @click="$store.communityMembers.modals.settings = false" aria-label="Close settings">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form class="mt-4 space-y-4" @submit.prevent="$store.communityMembers.saveSettings()">
                <div>
                    <label class="block text-xs font-semibold text-slate-700">Name</label>
                    <input type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model.trim="$store.communityMembers.settingsForm.name" required />
                    <template x-if="$store.communityMembers.errors.settings.name">
                        <p class="mt-1 text-xs text-rose-600" x-text="$store.communityMembers.errors.settings.name[0]"></p>
                    </template>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="$store.communityMembers.modals.settings = false">Cancel</button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300" :disabled="$store.communityMembers.loading.savingSettings">
                        <span x-text="$store.communityMembers.loading.savingSettings ? 'Saving...' : 'Save'"></span>
                    </button>
                </div>
                <template x-if="$store.communityMembers.errors.settings.general">
                    <p class="text-xs text-rose-600" x-text="$store.communityMembers.errors.settings.general[0]"></p>
                </template>
            </form>
        </div>
    </div>
</div>
