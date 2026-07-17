@php
    $diqInstallUrl = config('services.discord.diq_install_url');
    $discordBotToken = (string) config('apiurls.discord-bot.key');
    $botInstalledGuildIds = collect();
    $patreonAccount = $currentOrg?->providerAccounts->firstWhere('provider', 'patreon');
    $patreonIdentity = $patreonAccount?->patreonIdentity() ?? [];
    $patreonDisplay = $patreonIdentity['display'] ?? [];
    $patreonName = $patreonDisplay['name'] ?? $patreonAccount?->display_name ?? 'Patreon';
    $fantraxSecret = auth()->user()?->fantraxSecret?->secret;

    if ($discordBotToken !== '' && $guilds->isNotEmpty()) {
        $botInstalledGuildIds = $guilds
            ->filter(function ($guild) use ($discordBotToken): bool {
                try {
                    return \Illuminate\Support\Facades\Http::withHeaders([
                        'Authorization' => 'Bot ' . $discordBotToken,
                    ])
                        ->acceptJson()
                        ->get('https://discord.com/api/v10/guilds/' . $guild->discord_guild_id)
                        ->successful();
                } catch (\Throwable) {
                    return false;
                }
            })
            ->pluck('discord_guild_id');
    }
@endphp

<section
    x-data="{ connectionDrawer: false, connectionType: 'discord' }"
    class="rounded-lg border p-5"
    :class="theme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200 bg-white'"
>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold">Connections</h3>
            <p class="mt-1 text-sm" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-600'">
                Connected services for this community.
            </p>
        </div>

        @if($canEdit && $currentOrg)
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300"
                @click="connectionDrawer = true; connectionType = 'discord'"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 5a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H7a1 1 0 110-2h4V6a1 1 0 011-1z"/>
                </svg>
                Add
            </button>
        @endif
    </div>

    <div class="space-y-3">
        @forelse($guilds as $g)
            @php
                $icon = data_get($g->meta, 'icon');
                $ext = $icon && str_starts_with($icon, 'a_') ? 'gif' : 'png';
                $avatar = $icon ? "https://cdn.discordapp.com/icons/{$g->discord_guild_id}/{$icon}.{$ext}?size=64" : null;
                $botInstalled = $botInstalledGuildIds->contains((string) $g->discord_guild_id);
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
                <div class="flex min-w-0 items-center gap-3">
                    <button
                        type="button"
                        @disabled(! $botInstalled)
                        @if($botInstalled)
                            data-discord-members-refresh
                            data-url="{{ route('organizations.discord-servers.members.refresh', ['organization' => $currentOrg->id, 'discordServer' => $g->id]) }}"
                        @endif
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border {{ $botInstalled ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-200' : 'cursor-not-allowed border-slate-200 bg-white text-slate-300' }}"
                        title="{{ $botInstalled ? 'Refresh Discord members' : 'Install the bot before refreshing members' }}"
                        aria-label="{{ $botInstalled ? 'Refresh Discord members for ' . ($g->discord_guild_name ?? 'server') : 'Discord member refresh unavailable' }}"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 20v-6h-6" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 9a8 8 0 0 0-13.657-3.657L4 7.686" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 15a8 8 0 0 0 13.657 3.657L20 16.314" />
                        </svg>
                    </button>
                    @if($avatar)
                        <img src="{{ $avatar }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-slate-200" loading="lazy">
                    @else
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-200 text-xs font-semibold text-slate-600">DS</span>
                    @endif
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-semibold">{{ $g->discord_guild_name ?? 'Unknown Server' }}</p>
                            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">Discord Server</span>
                        </div>
                        <p class="mt-0.5 truncate text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                            ID: {{ $g->discord_guild_id }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if($botInstalled)
                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">Bot installed</span>
                    @elseif($diqInstallUrl)
                        <a href="{{ $diqInstallUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">Install bot</a>
                    @else
                        <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">Needs bot</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'">
                No Discord servers connected.
            </div>
        @endforelse

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-semibold">{{ $patreonAccount ? $patreonName : 'Patreon' }}</p>
                    <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700">Patreon</span>
                </div>
                <p class="mt-0.5 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                    {{ $patreonAccount ? 'Members and tiers sync enabled' : 'Not connected' }}
                </p>
            </div>
            @if($canEdit)
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="connectionDrawer = true; connectionType = 'patreon'">
                    {{ $patreonAccount ? 'Manage' : 'Connect' }}
                </button>
            @else
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">{{ $patreonAccount ? 'Connected' : 'Not connected' }}</span>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-3" :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-semibold">Fantrax</p>
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Fantasy Platform</span>
                </div>
                <p class="mt-0.5 text-xs" :class="theme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                    {{ $fantraxConnected ? $fantraxOptions->count() . ' available league(s)' : 'Not connected' }}
                </p>
            </div>
            @if($canEdit)
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="connectionDrawer = true; connectionType = 'fantrax'">
                    {{ $fantraxConnected ? 'Manage' : 'Connect' }}
                </button>
            @else
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">{{ $fantraxConnected ? 'Connected' : 'Not connected' }}</span>
            @endif
        </div>
    </div>

    <x-ui.slide-over show="connectionDrawer" close-action="connectionDrawer = false" title-id="community-connection-drawer-title">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
                <h3 id="community-connection-drawer-title" class="text-base font-semibold text-slate-900">Add connection</h3>
                <p class="mt-0.5 text-xs text-slate-500">Choose the service this community should use.</p>
            </div>
            <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" @click="connectionDrawer = false" aria-label="Close connection drawer">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="border-b border-slate-200 px-5 py-3">
            <div class="grid grid-cols-3 gap-2">
                <button type="button" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="connectionType === 'discord' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="connectionType = 'discord'">Discord</button>
                <button type="button" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="connectionType === 'patreon' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="connectionType = 'patreon'">Patreon</button>
                <button type="button" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="connectionType === 'fantrax' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="connectionType = 'fantrax'">Fantrax</button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-5">
            <div x-show="connectionType === 'discord'" x-cloak class="space-y-4">
                <div>
                    <h4 class="text-sm font-semibold text-slate-900">Discord Server</h4>
                    <p class="mt-1 text-sm text-slate-600">Connect a server, install the DIQ bot, and refresh members into the community roster.</p>
                </div>
                @if($canEdit && $currentOrg)
                    <a href="{{ route('discord-server.redirect', $currentOrg->id) }}" class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Connect Discord server
                    </a>
                @endif
                @if($diqInstallUrl)
                    <a href="{{ $diqInstallUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Install DIQ bot
                    </a>
                @endif
            </div>

            <div x-show="connectionType === 'patreon'" x-cloak class="space-y-4">
                <div>
                    <h4 class="text-sm font-semibold text-slate-900">Patreon</h4>
                    <p class="mt-1 text-sm text-slate-600">Connect Patreon to sync paid members and tiers into this community.</p>
                </div>
                @if($canEdit && $currentOrg)
                    <a href="{{ route('patreon.redirect', $currentOrg->id) }}" class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ $patreonAccount ? 'Change Patreon account' : 'Connect Patreon' }}
                    </a>
                    @if($patreonAccount)
                        <form method="POST" action="{{ route('patreon.sync', $currentOrg->id) }}">
                            @csrf
                            <button type="submit" class="mt-2 inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sync now</button>
                        </form>
                        <form method="POST" action="{{ route('patreon.disconnect', $currentOrg->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="mt-2 inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Disconnect</button>
                        </form>
                    @endif
                @else
                    <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">You need community edit access to manage Patreon.</p>
                @endif
            </div>

            <div x-show="connectionType === 'fantrax'" x-cloak class="space-y-4">
                <div>
                    <h4 class="text-sm font-semibold text-slate-900">Fantrax</h4>
                    <p class="mt-1 text-sm text-slate-600">Connect a Fantrax Secret Key to discover your platform leagues.</p>
                </div>
                @if($canEdit)
                    <form method="POST" action="{{ route('integrations.fantrax.save') }}" class="space-y-3">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Secret Key ID</label>
                            <input name="fantrax_secret_key" type="password" value="{{ $fantraxSecret }}" placeholder="Enter your Fantrax Secret Key" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200">
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            Save Fantrax connection
                        </button>
                    </form>
                    @if($fantraxConnected)
                        <form method="POST" action="{{ route('integrations.fantrax.disconnect') }}">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Disconnect Fantrax</button>
                        </form>
                    @endif
                @else
                    <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-600">You need community edit access to manage Fantrax.</p>
                @endif
            </div>
        </div>
    </x-ui.slide-over>
</section>
