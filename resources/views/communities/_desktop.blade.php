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
    $discordMemberCount = $memberships->where('provider', 'discord')->count();
    $patreonMemberCount = $memberships->where('provider', 'patreon')->count();
    $manualMemberCount = $memberships->whereNotIn('provider', ['discord', 'patreon'])->count();
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
    <div class="grid gap-5 p-4 lg:grid-cols-[320px,1fr] lg:p-5">
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
                    @endphp
                    <a
                        href="{{ route('community.leagues.show', ['c_id' => $currentOrg->id, 'l_id' => $league->id]) }}"
                        class="flex items-center gap-3 rounded-lg border px-3 py-3 transition-colors"
                        data-community-sidebar-league-row="{{ $league->id }}"
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
                    </a>
                @empty
                    <div class="rounded-lg border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                        No leagues connected yet.
                    </div>
                @endforelse
            </div>
        </aside>

        <main class="min-w-0 space-y-5">
            <section
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

            <nav
                class="flex flex-wrap gap-2 rounded-lg border p-2"
                :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
                aria-label="Community sections"
            >
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'home' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="activeTab = 'home'"
                >
                    Home
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'members' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="activeTab = 'members'"
                >
                    Members
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'leagues' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="activeTab = 'leagues'"
                >
                    Leagues
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                    :class="activeTab === 'connections' ? 'bg-indigo-600 text-white' : (theme === 'dark' ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
                    @click="activeTab = 'connections'"
                >
                    Connections
                </button>
            </nav>

            <div x-show="activeTab === 'home'" x-cloak class="grid gap-5 xl:grid-cols-3">
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
                            <h3 class="text-base font-semibold">Tiers</h3>
                            <p class="text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">Access levels and perks</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'">{{ $tiers->count() }}</span>
                    </div>
                    <div class="mt-5 space-y-2">
                        @forelse ($tiers->take(4) as $tier)
                            <div class="flex items-center justify-between rounded-md border px-3 py-2" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                                <span class="truncate text-sm font-semibold">{{ $tier->name }}</span>
                                <span class="text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                    {{ $memberships->where('membership_tier_id', $tier->id)->count() }} members
                                </span>
                            </div>
                        @empty
                            <p class="rounded-md border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                                Tiers will appear after Patreon syncs or tier records are configured.
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

            <section x-show="activeTab === 'members'" x-cloak class="rounded-lg border p-5" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold">Members</h3>
                        <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                            Discord-connected members will appear here after the community has a connected Discord server and member sync is enabled.
                        </p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="theme === 'dark' ? 'bg-slate-800 text-slate-300' : 'bg-slate-100 text-slate-600'">
                        <span x-text="$store.communityMembers.memberMeta.total || {{ $totalMembers }}"></span> total
                    </span>
                </div>

                <div class="mt-5 overflow-hidden rounded-lg border" :class="theme === 'dark' ? 'border-slate-800' : 'border-slate-200'">
                    <template x-if="$store.communityMembers.members.length === 0">
                        <div class="p-8 text-center">
                            <h4 class="text-base font-semibold">No synced members yet</h4>
                            <p class="mx-auto mt-2 max-w-xl text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                                Connect Discord in the Connections tab. Once Discord member import is wired, server members will populate this roster automatically.
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
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold" x-text="member.display_name || 'Unknown member'"></p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                            <span x-text="member.email || 'No email on file'"></span>
                                            <template x-if="member.provider_label">
                                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="member.provider === 'discord' ? 'bg-indigo-50 text-indigo-700' : 'bg-rose-50 text-rose-700'" x-text="member.provider_label"></span>
                                            </template>
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

            <section x-show="activeTab === 'leagues'" x-cloak class="rounded-lg border p-5" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
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

            <div x-show="activeTab === 'connections'" x-cloak class="grid gap-5 xl:grid-cols-3">
                <div class="xl:col-span-2">
                    @include('communities._desktop-connected-servers')
                </div>

                <section class="rounded-lg border p-5" :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'">
                    <h3 class="text-base font-semibold">Fantrax</h3>
                    <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                        Connected Fantrax leagues can be attached to this community.
                    </p>
                    <div class="mt-4 rounded-md border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold">Account</span>
                            <span class="text-xs font-semibold {{ $fantraxConnected ? 'text-emerald-600' : 'text-slate-500' }}">
                                {{ $fantraxConnected ? 'Connected' : 'Not connected' }}
                            </span>
                        </div>
                        <div class="mt-2 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                            {{ $fantraxOptions->count() }} available leagues can be linked.
                        </div>
                    </div>
                </section>

                <div class="xl:col-span-3">
                    @include('communities._desktop-memberships')
                </div>
            </div>
        </main>
    </div>

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
