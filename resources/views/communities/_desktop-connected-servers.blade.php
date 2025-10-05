{{-- Card: Connected Servers (with Add button in header) --}}
<section class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="mb-3 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Discord Servers</h3>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-700">{{ $guilds->count() }}</span>
        </div>

        @if($canEdit && $currentOrg)
            <a href="{{ route('discord-server.redirect', $currentOrg->id) }}"
               class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 5a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H7a1 1 0 110-2h4V6a1 1 0 011-1z"/></svg>
                Add a Discord Server
            </a>
        @else
            <button type="button" disabled
                class="inline-flex items-center gap-2 rounded-xl bg-slate-200 px-3 py-2 text-sm font-semibold text-slate-500 cursor-not-allowed">
                Add a Discord Server
            </button>
        @endif
    </div>

    @if ($guilds->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            No Discord servers connected yet.
        </div>
    @else
        <ul class="space-y-2">
            @foreach ($guilds as $g)
                @php
                    $icon   = data_get($g->meta, 'icon');
                    $ext    = $icon && str_starts_with($icon, 'a_') ? 'gif' : 'png';
                    $avatar = $icon ? "https://cdn.discordapp.com/icons/{$g->discord_guild_id}/{$icon}.{$ext}?size=64" : null;
                @endphp
                <li class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex items-center gap-3">
                        @if ($avatar)
                            <img src="{{ $avatar }}" alt="{{ $g->discord_guild_name ?? 'Server' }} icon"
                                 class="h-8 w-8 rounded-lg object-cover ring-1 ring-slate-200" loading="lazy">
                        @else
                            <div class="h-8 w-8 rounded-lg bg-slate-200"></div>
                        @endif
                        <div>
                            <div class="text-sm font-medium text-slate-900">
                                {{ $g->discord_guild_name ?? 'Unknown Server' }}
                            </div>
                            <div class="text-xs text-slate-600">ID: {{ $g->discord_guild_id }}</div>
                        </div>
                    </div>
                    <button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700 cursor-not-allowed">
                        Manage
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
