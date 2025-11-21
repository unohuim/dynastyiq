@php
    $patreonAccount = $currentOrg?->providerAccounts->firstWhere('provider', 'patreon');
    $patreonMemberships = $currentOrg?->memberships?->where('provider', 'patreon') ?? collect();
    $patreonIdentity = $patreonAccount?->patreonIdentity() ?? [];
    $patreonUser = $patreonIdentity['user'] ?? [];
    $patreonCampaign = $patreonIdentity['campaign'] ?? [];
    $patreonAvatar = $patreonIdentity['avatar'] ?? null;
    $status = $patreonAccount?->status ?? 'disconnected';
    $statusCopy = [
        'connected' => 'Online',
        'offline' => 'Offline',
        'disconnected' => 'Not connected',
    ][$status] ?? ucfirst($status);
    $badgeClass = match($status) {
        'connected' => 'bg-emerald-100 text-emerald-800',
        'offline' => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp

<section
    class="col-span-full lg:col-span-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
    x-data="{
        enabled: {{ $currentOrg?->creatorToolsEnabled() ? 'true' : 'false' }},
        orgId: {{ $currentOrg?->id ?? 'null' }},
    }"
    x-show="enabled"
    x-cloak
    x-on:org:settings-updated.window="
        if ($event.detail?.organization_id === orgId) {
            if ($event.detail?.enabled === false) {
                enabled = false;
                return;
            }

            const creator = $event.detail?.settings?.creator_tools;
            if (creator !== undefined) enabled = !!creator;
        }
    "
>
    <div class="mb-3 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Memberships</h3>
            <p class="text-xs text-slate-600">Connect Patreon to sync members and tiers.</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClass }}">{{ $statusCopy }}</span>
    </div>

    <div class="space-y-3 text-sm text-slate-700">
        @if($patreonAccount)
            <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="flex items-center gap-3">
                    @if($patreonAvatar)
                        <img src="{{ $patreonAvatar }}" alt="Patreon avatar" class="h-8 w-8 rounded-lg object-cover ring-1 ring-slate-200" loading="lazy">
                    @else
                        <div class="h-8 w-8 rounded-lg bg-slate-200"></div>
                    @endif
                    <div>
                        <div class="text-sm font-semibold text-slate-900">
                            {{ $patreonUser['full_name'] ?? $patreonAccount->display_name ?? 'Patreon account' }}
                        </div>
                        <div class="text-xs text-slate-600">
                            @if(!empty($patreonUser['email']))
                                {{ $patreonUser['email'] }}
                            @elseif(!empty($patreonCampaign['name']))
                                Campaign: {{ $patreonCampaign['name'] }}
                            @elseif($patreonAccount->external_id)
                                ID: {{ $patreonAccount->external_id }}
                            @else
                                Connected via Patreon
                            @endif
                        </div>
                    </div>
                </div>
                <a
                    href="{{ route('patreon.redirect', $currentOrg->id) }}"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                    Change
                </a>
            </div>
        @endif

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div class="flex items-center justify-between text-xs text-slate-600">
                <span>Last sync</span>
                <span>{{ $patreonAccount?->last_synced_at ? $patreonAccount->last_synced_at->diffForHumans() : 'Never' }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-slate-600">
                <span>Last webhook</span>
                <span>{{ $patreonAccount?->last_webhook_at ? $patreonAccount->last_webhook_at->diffForHumans() : 'None yet' }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @if($patreonAccount)
                <form method="POST" action="{{ route('patreon.sync', $currentOrg->id) }}">
                    @csrf
                    <button type="submit" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">Sync now</button>
                </form>
                <a
                    href="{{ route('patreon.redirect', $currentOrg->id) }}"
                    class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                    Change account
                </a>
                <form method="POST" action="{{ route('patreon.disconnect', $currentOrg->id) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200">Disconnect</button>
                </form>
            @else
                <a href="{{ route('patreon.redirect', $currentOrg->id) }}" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                    Connect Patreon
                </a>
            @endif
        </div>

        <div class="rounded-xl border border-dashed border-slate-300 p-3 text-xs text-slate-600">
            Auto-sync via webhooks with nightly fallback. When offline, we pause updates but keep access unchanged.
        </div>

        @if($patreonAccount)
            <div
                class="rounded-xl border border-slate-200 bg-slate-50"
                x-data="{ open: true }"
            >
                <button
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-semibold text-slate-700"
                    @click="open = !open"
                >
                    <span>Members</span>
                    <svg
                        :class="open ? 'rotate-180' : ''"
                        class="h-4 w-4 text-slate-500 transition-transform"
                        viewBox="0 0 20 20"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div x-show="open" x-cloak class="divide-y divide-slate-200 border-t border-slate-200">
                    @forelse($patreonMemberships as $membership)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800">
                                    {{ $membership->memberProfile?->display_name ?? 'Unknown member' }}
                                </p>
                                @if($membership->memberProfile?->email)
                                    <p class="truncate text-xs text-slate-500">{{ $membership->memberProfile->email }}</p>
                                @endif
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold text-indigo-700">
                                    {{ $membership->membershipTier?->name ?? 'No tier' }}
                                </p>
                                <p class="text-[11px] text-slate-500">
                                    {{ $membership->status ? ucfirst($membership->status) : 'Unknown status' }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="px-3 py-3 text-xs text-slate-600">No members synced yet.</div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</section>
