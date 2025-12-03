{{-- resources/views/communities/partials/desktop.blade.php --}}
{{--
    Pre-Implementation Analysis (reuse inventory)
    Existing models reused: Membership, MembershipTier, MemberProfile
    Existing services reused: MembershipSyncService, TierMapper, PatreonSyncService (read-only)
    Existing UI components reused: dropdown/context menu primitives, modal patterns, badge styles
    Existing authorization patterns reused: organization membership guard
    Existing settings endpoint reused: OrganizationsController@updateSettings
--}}
@php
    /** @var \Illuminate\Support\Collection|\App\Models\Organization[] $communities */
    $currentOrg = ($activeCommunity ?? null)
        ? $communities->firstWhere('id', $activeCommunity->id)
        : $communities->first();
    $user = auth()->user();

    // Highest org-scoped role (by numeric level, higher = higher)
    $highestRole = null;
    if ($user && $currentOrg) {
        $highestRole = $user->roles()
            ->wherePivot('organization_id', $currentOrg->id)
            ->orderByDesc('level')
            ->first(); // expects roles table has `level` and `name`
    }

    // Admin permission = level >= 10
    $canEdit = $highestRole && (int)($highestRole->level ?? 0) >= 10;

    // Connected Discord servers (from DB relation; respects eager load)
    $guilds = $currentOrg
        ? ($currentOrg->relationLoaded('discordServers')
            ? $currentOrg->discordServers
            : $currentOrg->discordServers()->get())
        : collect();

    $memberships = $currentOrg?->memberships ?? collect();
    $patreonAccount = $currentOrg?->providerAccounts->firstWhere('provider', 'patreon');
    $commissionerEnabled = $currentOrg?->commissionerToolsEnabled();

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

<div class="grid grid-cols-[280px,1fr] gap-6">
    {{-- Sidebar: communities list --}}
    <aside class="rounded-2xl border border-slate-200 bg-white p-3">
        <div class="mb-2 px-2 text-xs font-semibold tracking-wider text-slate-600 uppercase">Communities</div>
        <ul id="communityList" class="space-y-1">
            @foreach ($communities as $i => $org)
                <li>
                    <button
                        type="button"
                        class="community-item group w-full rounded-xl px-3 py-2 text-left text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        data-slug="{{ $org->slug }}"
                        data-name="{{ $org->name }}"
                        data-org-id="{{ $org->id }}"
                        aria-current="{{ ($currentOrg && $currentOrg->id === $org->id) ? 'true' : 'false' }}"
                    >
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-700">
                                {{ strtoupper(mb_substr($org->short_name ?? $org->name, 0, 2)) }}
                            </span>
                            <span class="flex-1 truncate">{{ $org->name }}</span>
                            <span class="hidden rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-slate-600 group-aria-[current=true]:inline">
                                Active
                            </span>
                        </div>
                    </button>
                </li>
            @endforeach
        </ul>
    </aside>

    {{-- Main: Community Manager Hub --}}
    <main
        class="rounded-2xl border border-slate-200 bg-white p-0 overflow-hidden"
        x-data="communityMembersHub({{ \Illuminate\Support\Js::from($desktopConfig) }})"
    >
        {{-- Header --}}
        <div class="border-b border-slate-200 px-6 py-5">
            <div class="flex items-center justify-between gap-6">
                <div class="min-w-0">
                    <h2
                        id="desktopCommunityTitle"
                        class="text-2xl font-semibold text-slate-900"
                        x-text="$store.communityMembers.organizationName || '{{ $currentOrg?->name }}'"
                    >
                        {{ $currentOrg?->name }}
                    </h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if($highestRole)
                            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700">
                                {{ ucfirst($highestRole->name) }}
                            </span>
                        @else
                            <span class="text-xs text-slate-500">No role assigned</span>
                        @endif
                    </div>
                </div>

                {{-- Settings gear (admins only) --}}
                @if($canEdit && $currentOrg)
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-slate-50 p-2 text-slate-700 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        aria-label="Edit community settings"
                        @click="$store.communityMembers.openSettings()"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.607 2.296.07 2.573-1.065z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                @endif
            </div>

            {{-- Sub-nav --}}
            <div class="mt-4 flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-700">
                <button
                    type="button"
                    @click="activeTab = 'members'"
                    :class="activeTab === 'members' ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-50 text-slate-700 border border-slate-200'"
                    class="rounded-xl px-3 py-2 transition-colors"
                >
                    Members
                </button>

                @if($commissionerEnabled)
                    <button
                        type="button"
                        @click="activeTab = 'leagues'"
                        :class="activeTab === 'leagues' ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-50 text-slate-700 border border-slate-200'"
                        class="rounded-xl px-3 py-2 transition-colors"
                    >
                        Leagues
                    </button>
                @endif

                <button
                    type="button"
                    @click="activeTab = 'integrations'"
                    :class="activeTab === 'integrations' ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-50 text-slate-700 border border-slate-200'"
                    class="rounded-xl px-3 py-2 transition-colors"
                >
                    Integrations
                </button>

                <button
                    type="button"
                    @click="activeTab = 'dashboard'"
                    :class="activeTab === 'dashboard' ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-50 text-slate-700 border border-slate-200'"
                    class="rounded-xl px-3 py-2 transition-colors"
                >
                    Dashboard
                </button>
            </div>
        </div>

        {{-- Body --}}
        <div class="p-6 space-y-6">
            {{-- Members Tab --}}
            <div x-show="activeTab === 'members'" x-cloak class="space-y-6">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" x-data="{}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Community Members</h3>
                            <p class="text-xs text-slate-600">Manage members and tiers with API-driven updates.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <template x-if="$store.communityMembers.activeCollectionTab === 'members'">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="!$store.communityMembers.hasTiers"
                                    :title="$store.communityMembers.hasTiers ? '' : 'Add a tier before adding members'"
                                    @click="$store.communityMembers.openMemberModal()"
                                >
                                    Add Member
                                </button>
                            </template>
                            <template x-if="$store.communityMembers.activeCollectionTab === 'tiers'">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300"
                                    @click="$store.communityMembers.openTierModal()"
                                >
                                    Add Tier
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-700">
                        <button
                            type="button"
                            @click="$store.communityMembers.activeCollectionTab = 'members'"
                            :class="$store.communityMembers.activeCollectionTab === 'members' ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-50 text-slate-700 border border-slate-200'"
                            class="rounded-xl px-3 py-2 transition-colors"
                        >
                            Members
                        </button>
                        <button
                            type="button"
                            @click="$store.communityMembers.activeCollectionTab = 'tiers'"
                            :class="$store.communityMembers.activeCollectionTab === 'tiers' ? 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' : 'bg-slate-50 text-slate-700 border border-slate-200'"
                            class="rounded-xl px-3 py-2 transition-colors"
                        >
                            Tiers
                        </button>
                    </div>

                    <div class="mt-4" x-show="$store.communityMembers.activeCollectionTab === 'members'" x-cloak>
                        <div class="divide-y divide-slate-200 rounded-xl border border-slate-200">
                            <template x-if="$store.communityMembers.members.length === 0">
                                <div class="p-4 text-sm text-slate-600">No members yet.</div>
                            </template>
                            <template x-for="member in $store.communityMembers.members" :key="member.id">
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-800" x-text="member.display_name || 'Unknown member'"></p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                            <span class="truncate" x-text="member.email || ''"></span>
                                            <template x-if="member.provider_label">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700">
                                                    <svg class="h-3 w-3" viewBox="0 0 256 315" fill="currentColor" aria-hidden="true">
                                                        <path d="M34.86 0H0v315h34.86V0ZM178.18 67.21c-42.33 0-77 34.66-77 77s34.66 77 77 77c42.33 0 77-34.66 77-77s-34.66-77-77-77Z" />
                                                    </svg>
                                                    <span x-text="member.provider_label"></span>
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="text-right">
                                            <p class="text-xs font-semibold text-indigo-700" x-text="member.tier?.name || 'No tier'"></p>
                                            <p class="text-[11px] text-slate-500" x-text="$store.communityMembers.statusLabel(member.status)"></p>
                                        </div>
                                        <x-dropdown align="right" width="48">
                                            <x-slot name="trigger">
                                                <button type="button" class="rounded-full p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path d="M10 3a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 5.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM11.5 16a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z" />
                                                    </svg>
                                                </button>
                                            </x-slot>
                                            <x-slot name="content">
                                                <button
                                                    type="button"
                                                    class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                                                    :class="member.provider_managed ? 'cursor-not-allowed opacity-50' : ''"
                                                    :disabled="member.provider_managed"
                                                    @click.stop="$store.communityMembers.openMemberModal(member)"
                                                >
                                                    Edit
                                                </button>
                                                <div class="my-1 border-t border-slate-100"></div>
                                                <button
                                                    type="button"
                                                    class="block w-full px-4 py-2 text-left text-sm text-rose-700 hover:bg-rose-50"
                                                    :class="member.provider_managed ? 'cursor-not-allowed opacity-50' : ''"
                                                    :disabled="member.provider_managed"
                                                    @click.stop="$store.communityMembers.deleteMember(member.id)"
                                                >
                                                    Delete
                                                </button>
                                            </x-slot>
                                        </x-dropdown>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-600" x-show="$store.communityMembers.memberMeta.total">
                            <div>
                                Page <span x-text="$store.communityMembers.memberMeta.current_page"></span>
                                of <span x-text="$store.communityMembers.memberMeta.last_page"></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="$store.communityMembers.memberMeta.current_page <= 1"
                                    @click="$store.communityMembers.fetchMembers(($store.communityMembers.memberMeta.current_page || 1) - 1)"
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="$store.communityMembers.memberMeta.current_page >= $store.communityMembers.memberMeta.last_page"
                                    @click="$store.communityMembers.fetchMembers(($store.communityMembers.memberMeta.current_page || 1) + 1)"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4" x-show="$store.communityMembers.activeCollectionTab === 'tiers'" x-cloak>
                        <div class="divide-y divide-slate-200 rounded-xl border border-slate-200">
                            <template x-if="$store.communityMembers.tiers.length === 0">
                                <div class="p-4 text-sm text-slate-600">No tiers yet.</div>
                            </template>
                            <template x-for="tier in $store.communityMembers.tiers" :key="tier.id">
                                <div class="flex items-center justify-between gap-3 px-4 py-3">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-semibold text-slate-800" x-text="tier.name"></p>
                                            <template x-if="tier.provider_label">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700">
                                                    <svg class="h-3 w-3" viewBox="0 0 256 315" fill="currentColor" aria-hidden="true">
                                                        <path d="M34.86 0H0v315h34.86V0ZM178.18 67.21c-42.33 0-77 34.66-77 77s34.66 77 77 77c42.33 0 77-34.66 77-77s-34.66-77-77-77Z" />
                                                    </svg>
                                                    <span x-text="tier.provider_label"></span>
                                                </span>
                                            </template>
                                        </div>
                                        <p class="text-[11px] text-slate-500" x-text="tier.provider_managed ? 'Provider-managed' : 'Manual tier'"></p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="text-right">
                                            <p
                                                class="text-sm font-semibold text-slate-900"
                                                x-text="$store.communityMembers.formatMoney(tier.amount_cents, tier.currency)"
                                            ></p>
                                            <p
                                                class="text-[11px] text-slate-500"
                                                x-text="tier.currency || 'USD'"
                                                x-show="tier.amount_cents !== null && tier.amount_cents !== undefined"
                                            ></p>
                                        </div>
                                        <x-dropdown align="right" width="48">
                                            <x-slot name="trigger">
                                                <button type="button" class="rounded-full p-2 text-slate-500 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path d="M10 3a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 5.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM11.5 16a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z" />
                                                    </svg>
                                                </button>
                                            </x-slot>
                                            <x-slot name="content">
                                                <button
                                                    type="button"
                                                    class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                                                    :class="tier.provider_managed ? 'cursor-not-allowed opacity-50' : ''"
                                                    :disabled="tier.provider_managed"
                                                    @click.stop="$store.communityMembers.openTierModal(tier)"
                                                >
                                                    Edit
                                                </button>
                                                <div class="my-1 border-t border-slate-100"></div>
                                                <button
                                                    type="button"
                                                    class="block w-full px-4 py-2 text-left text-sm text-rose-700 hover:bg-rose-50"
                                                    :class="tier.provider_managed ? 'cursor-not-allowed opacity-50' : ''"
                                                    :disabled="tier.provider_managed"
                                                    @click.stop="$store.communityMembers.deleteTier(tier.id)"
                                                >
                                                    Delete
                                                </button>
                                            </x-slot>
                                        </x-dropdown>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Leagues Tab --}}
            <div x-show="activeTab === 'leagues'" x-cloak class="space-y-6">
                <div
                    class="col-span-full"
                    x-data="{
                        enabled: {{ $commissionerEnabled ? 'true' : 'false' }},
                        orgId: {{ $currentOrg?->id ?? 'null' }}
                    }"
                    x-show="enabled"
                    x-cloak
                    x-on:org:settings-updated.window="
                        if ($event.detail?.organization_id === orgId) {
                            const v = $event.detail?.settings?.commissioner_tools;
                            if (v !== undefined) enabled = !!v;
                        }
                    "
                >
                    @php
                        $leaguesOrg = $communities->first();
                        $leagues = $leaguesOrg ? ($leaguesOrg->relationLoaded('leagues')
                            ? $leaguesOrg->leagues
                            : $leaguesOrg->leagues()->get()) : collect();

                        $guilds = $leaguesOrg ? ($leaguesOrg->relationLoaded('discordServers')
                            ? $leaguesOrg->discordServers
                            : $leaguesOrg->discordServers()->get()) : collect();
                    @endphp

                    @include('communities._desktop-leagues', ['leagues' => $leagues, 'guilds' => $guilds, 'currentOrg' => $leaguesOrg, 'canEdit' => $canEdit])
                </div>
            </div>

            {{-- Integrations Tab --}}
            <div x-show="activeTab === 'integrations'" x-cloak class="space-y-6">
                <div class="grid gap-6 lg:grid-cols-3">
                    @include('communities._desktop-connected-servers')
                    @include('communities._desktop-memberships')
                </div>
            </div>

            {{-- Dashboard Tab --}}
            <div x-show="activeTab === 'dashboard'" x-cloak class="space-y-6">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Manager Hub</h3>
                        <div class="text-xs text-slate-600">Tools &amp; integrations for {{ $currentOrg?->short_name ?? $currentOrg?->name }}</div>
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-semibold text-slate-900">Role</div>
                            <p class="mt-1 text-xs text-slate-600">
                                {{ $highestRole ? ucfirst($highestRole->name) : '—' }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-semibold text-slate-900">Discord</div>
                            <p class="mt-1 text-xs text-slate-600">{{ $guilds->count() }} server(s) connected.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-semibold text-slate-900">Last Patreon sync</div>
                            <p class="mt-1 text-xs text-slate-600">
                                {{ $patreonAccount?->last_synced_at ? $patreonAccount->last_synced_at->diffForHumans() : 'Never' }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-semibold text-slate-900">Last webhook</div>
                            <p class="mt-1 text-xs text-slate-600">
                                {{ $patreonAccount?->last_webhook_at ? $patreonAccount->last_webhook_at->diffForHumans() : 'None yet' }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-semibold text-slate-900">Leagues</div>
                            <p class="mt-1 text-xs text-slate-600">{{ $commissionerEnabled ? ($currentOrg?->leagues->count() ?? 0) : 0 }} connected</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-sm font-semibold text-slate-900">Branding</div>
                            <p class="mt-1 text-xs text-slate-600">Name updates reflect across the app.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        {{-- Member modal --}}
        <div
            x-cloak
            x-show="$store.communityMembers.modals.member"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @keydown.escape.window="$store.communityMembers.modals.member = false"
            @click.self="$store.communityMembers.modals.member = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" @click.self="$store.communityMembers.modals.member = false">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900" x-text="$store.communityMembers.memberForm.id ? 'Edit Member' : 'Add Member'"></h3>
                    <button type="button" class="text-slate-500 hover:text-slate-700" @click="$store.communityMembers.modals.member = false">✕</button>
                </div>
                <form class="mt-4 space-y-4" @submit.prevent="$store.communityMembers.saveMember()">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Name</label>
                        <input type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model.trim="$store.communityMembers.memberForm.name" required />
                        <template x-if="$store.communityMembers.errors.member.name">
                            <p class="mt-1 text-xs text-rose-600" x-text="$store.communityMembers.errors.member.name[0]"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Email</label>
                        <input type="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model.trim="$store.communityMembers.memberForm.email" required />
                        <template x-if="$store.communityMembers.errors.member.email">
                            <p class="mt-1 text-xs text-rose-600" x-text="$store.communityMembers.errors.member.email[0]"></p>
                        </template>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Tier</label>
                        <select class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model="$store.communityMembers.memberForm.membership_tier_id">
                            <option value="">No tier</option>
                            <template x-for="tier in $store.communityMembers.tiers" :key="tier.id">
                                <option :value="tier.id" x-text="tier.name"></option>
                            </template>
                        </select>
                        <template x-if="$store.communityMembers.errors.member.membership_tier_id">
                            <p class="mt-1 text-xs text-rose-600" x-text="$store.communityMembers.errors.member.membership_tier_id[0]"></p>
                        </template>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="$store.communityMembers.modals.member = false">Cancel</button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300" :disabled="$store.communityMembers.loading.savingMember">
                            <span x-text="$store.communityMembers.loading.savingMember ? 'Saving…' : 'Save'"></span>
                        </button>
                    </div>
                    <template x-if="$store.communityMembers.errors.member.general">
                        <p class="text-xs text-rose-600" x-text="$store.communityMembers.errors.member.general[0]"></p>
                    </template>
                </form>
            </div>
        </div>

        {{-- Tier modal --}}
        <div
            x-cloak
            x-show="$store.communityMembers.modals.tier"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @keydown.escape.window="$store.communityMembers.modals.tier = false"
            @click.self="$store.communityMembers.modals.tier = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" @click.self="$store.communityMembers.modals.tier = false">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900" x-text="$store.communityMembers.tierForm.id ? 'Edit Tier' : 'Add Tier'"></h3>
                    <button type="button" class="text-slate-500 hover:text-slate-700" @click="$store.communityMembers.modals.tier = false">✕</button>
                </div>
                <form class="mt-4 space-y-4" @submit.prevent="$store.communityMembers.saveTier()">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Name</label>
                        <input type="text" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model.trim="$store.communityMembers.tierForm.name" required />
                        <template x-if="$store.communityMembers.errors.tier.name">
                            <p class="mt-1 text-xs text-rose-600" x-text="$store.communityMembers.errors.tier.name[0]"></p>
                        </template>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Amount (cents)</label>
                            <input type="number" min="0" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model.number="$store.communityMembers.tierForm.amount_cents" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Currency</label>
                            <input type="text" maxlength="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" x-model.trim="$store.communityMembers.tierForm.currency" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700">Description</label>
                        <textarea class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" rows="3" x-model.trim="$store.communityMembers.tierForm.description"></textarea>
                        <div class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" id="tier-active" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" x-model="$store.communityMembers.tierForm.is_active" />
                            <label for="tier-active">Active</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="$store.communityMembers.modals.tier = false">Cancel</button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300" :disabled="$store.communityMembers.loading.savingTier">
                            <span x-text="$store.communityMembers.loading.savingTier ? 'Saving…' : 'Save'"></span>
                        </button>
                    </div>
                    <template x-if="$store.communityMembers.errors.tier.general">
                        <p class="text-xs text-rose-600" x-text="$store.communityMembers.errors.tier.general[0]"></p>
                    </template>
                </form>
            </div>
        </div>

        {{-- Member delete confirm --}}
        <div
            x-cloak
            x-show="$store.communityMembers.modals.confirmMemberId"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @keydown.escape.window="$store.communityMembers.modals.confirmMemberId = null"
            @click.self="$store.communityMembers.modals.confirmMemberId = null"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" @click.self="$store.communityMembers.modals.confirmMemberId = null">
                <h3 class="text-lg font-semibold text-slate-900">Delete member?</h3>
                <p class="mt-2 text-sm text-slate-600">This removes the member from the community roster. Provider-managed members cannot be removed manually.</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="$store.communityMembers.modals.confirmMemberId = null">Cancel</button>
                    <button type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300" @click="$store.communityMembers.confirmDeleteMember()">Delete</button>
                </div>
            </div>
        </div>

        {{-- Tier delete confirm --}}
        <div
            x-cloak
            x-show="$store.communityMembers.modals.confirmTierId"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @keydown.escape.window="$store.communityMembers.modals.confirmTierId = null"
            @click.self="$store.communityMembers.modals.confirmTierId = null"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" @click.self="$store.communityMembers.modals.confirmTierId = null">
                <h3 class="text-lg font-semibold text-slate-900">Delete tier?</h3>
                <p class="mt-2 text-sm text-slate-600">Members will retain access but lose tier linkage. Provider-managed tiers cannot be removed manually.</p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="$store.communityMembers.modals.confirmTierId = null">Cancel</button>
                    <button type="button" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300" @click="$store.communityMembers.confirmDeleteTier()">Delete</button>
                </div>
            </div>
        </div>

        {{-- Settings modal --}}
        <div
            x-cloak
            x-show="$store.communityMembers.modals.settings"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @keydown.escape.window="$store.communityMembers.modals.settings = false"
            @click.self="$store.communityMembers.modals.settings = false"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" @click.self="$store.communityMembers.modals.settings = false">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900">Community Settings</h3>
                        <p class="text-xs text-slate-600">Update the community name. More settings coming soon.</p>
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-700" @click="$store.communityMembers.modals.settings = false">✕</button>
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
                            <span x-text="$store.communityMembers.loading.savingSettings ? 'Saving…' : 'Save'"></span>
                        </button>
                    </div>
                    <template x-if="$store.communityMembers.errors.settings.general">
                        <p class="text-xs text-rose-600" x-text="$store.communityMembers.errors.settings.general[0]"></p>
                    </template>
                </form>
            </div>
        </div>
    </main>
</div>
