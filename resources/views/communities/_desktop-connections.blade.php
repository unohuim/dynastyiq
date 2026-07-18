@php
    $diqInstallUrl = config('services.discord.diq_install_url');
    $discordBotToken = (string) config('apiurls.discord-bot.key');
    $botInstalledGuildIds = collect();
    $patreonAccount = $currentOrg?->providerAccounts->firstWhere('provider', 'patreon');
    $patreonIdentity = $patreonAccount?->patreonIdentity() ?? [];
    $patreonDisplay = $patreonIdentity['display'] ?? [];
    $patreonName = $patreonDisplay['name'] ?? $patreonAccount?->display_name ?? 'Patreon';

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
            <div
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-3"
                :class="theme === 'dark' ? 'border-slate-800 bg-slate-950' : 'border-slate-200 bg-slate-50'"
                data-discord-server-row="{{ $g->id }}"
                data-discord-bot-status-url="{{ route('organizations.discord-servers.bot.status', ['organization' => $currentOrg->id, 'discordServer' => $g->id]) }}"
            >
                <div class="flex min-w-0 items-center gap-3">
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
                    <span
                        class="{{ $botInstalled ? '' : 'hidden ' }}rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700"
                        data-discord-bot-installed-badge
                    >
                        Bot installed
                    </span>
                    @if($diqInstallUrl)
                        <a
                            href="{{ route('organizations.discord-servers.bot.install', ['organization' => $currentOrg->id, 'discordServer' => $g->id]) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="{{ $botInstalled ? 'hidden ' : '' }}rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100"
                            data-discord-bot-install
                            data-discord-server-id="{{ $g->id }}"
                        >
                            Install bot
                        </a>
                    @else
                        <span class="{{ $botInstalled ? 'hidden ' : '' }}rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500" data-discord-bot-needs-badge>Needs bot</span>
                    @endif
                    @if($canEdit && $currentOrg)
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-200 disabled:cursor-not-allowed disabled:opacity-60"
                            data-discord-server-detach
                            data-discord-server-id="{{ $g->id }}"
                            data-discord-server-name="{{ $g->discord_guild_name ?? 'Discord server' }}"
                            data-url="{{ route('organizations.discord-servers.destroy', ['organization' => $currentOrg->id, 'discordServer' => $g->id]) }}"
                            title="Remove Discord server"
                            aria-label="Remove {{ $g->discord_guild_name ?? 'Discord server' }} from this community"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'" data-discord-servers-empty>
                No Discord servers connected.
            </div>
        @endforelse
        @if($guilds->isNotEmpty())
            <div class="hidden rounded-lg border border-dashed px-3 py-4 text-sm" :class="theme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-300 text-slate-600'" data-discord-servers-empty>
                No Discord servers connected.
            </div>
        @endif

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
            <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500">{{ $fantraxConnected ? 'Connected' : 'Not connected' }}</span>
        </div>
    </div>

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/40 px-4 py-6"
        data-discord-server-detach-modal
        role="dialog"
        aria-modal="true"
        aria-labelledby="discord-server-detach-modal-title"
    >
        <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 id="discord-server-detach-modal-title" class="text-base font-semibold text-slate-900">Remove Discord server?</h3>
                    <p class="mt-2 text-sm text-slate-600">
                        Choose whether to also remove members imported from <span class="font-semibold" data-discord-server-detach-name>this Discord server</span>.
                    </p>
                </div>
                <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-700" data-discord-server-detach-cancel aria-label="Cancel Discord server removal">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-discord-server-detach-cancel>
                    Cancel
                </button>
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-discord-server-detach-confirm="without-members">
                    Remove Discord only
                </button>
                <button type="button" class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-200" data-discord-server-detach-confirm="with-members">
                    Remove Discord + members
                </button>
            </div>
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
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="connectionType === 'discord' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="connectionType = 'discord'">Discord</button>
                <button type="button" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="connectionType === 'patreon' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'" @click="connectionType = 'patreon'">Patreon</button>
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
        </div>
    </x-ui.slide-over>
</section>
