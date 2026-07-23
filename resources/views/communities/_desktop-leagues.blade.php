{{-- resources/views/communities/partials/_desktop-leagues.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection $leagues */
    /** @var \Illuminate\Support\Collection $guilds */

    $guilds = $guilds ?? collect();
    $allowUnlink = (bool) ($allowUnlink ?? false);

    // Build dropdown options: id, name, avatar
    $guildOptions = $guilds->map(function ($g) {
        $icon = data_get($g->meta, 'icon');
        $ext  = $icon && str_starts_with($icon, 'a_') ? 'gif' : 'png';
        $avatar = $icon ? "https://cdn.discordapp.com/icons/{$g->discord_guild_id}/{$icon}.{$ext}?size=64" : null;

        return [
            'id'     => $g->id,
            'name'   => $g->discord_guild_name ?? ('Server '.$g->discord_guild_id),
            'avatar' => $avatar,
            'guild_id' => $g->discord_guild_id,
        ];
    })->values();

    // Fast lookup for rendering the linked server in the list
    $guildIndex = $guilds->keyBy('id');
@endphp

<section
    x-data="{ open:false }"
    class="lg:col-span-full rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
>
    <div class="mb-3 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold tracking-wider text-slate-600 uppercase">Leagues</h3>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-700" data-community-league-count>{{ $leagues->count() }}</span>
        </div>

        @if($canEdit && $currentOrg)
            <button type="button" @click="open = true"
                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 5a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H7a1 1 0 110-2h4V6a1 1 0 011-1z"/></svg>
                Add a League
            </button>
        @else
            <button type="button" disabled
                class="inline-flex items-center gap-2 rounded-xl bg-slate-200 px-3 py-2 text-sm font-semibold text-slate-500 cursor-not-allowed">
                Add a League
            </button>
        @endif
    </div>

    {{-- List --}}
    @if ($leagues->isEmpty())
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600" data-community-leagues-empty>
            No leagues connected yet.
        </div>
    @else
        <ul class="space-y-2">
            @foreach ($leagues as $l)
                @php
                    $platform = strtolower((string) $l->platform);
                    $badge = [
                        'fantrax' => 'bg-emerald-100 text-emerald-800 ring-emerald-200',
                        'yahoo'   => 'bg-violet-100 text-violet-800 ring-violet-200',
                        'espn'    => 'bg-red-100 text-red-800 ring-red-200',
                    ][$platform] ?? 'bg-slate-100 text-slate-700 ring-slate-200';

                    // Linked Discord server (from pivot)
                    $serverId   = data_get($l, 'pivot.discord_server_id');
                    $server     = $serverId ? $guildIndex->get($serverId) : null;
                    $icon       = $server ? data_get($server->meta, 'icon') : null;
                    $ext        = $icon && str_starts_with($icon, 'a_') ? 'gif' : 'png';
                    $avatarUrl  = ($server && $icon)
                                    ? "https://cdn.discordapp.com/icons/{$server->discord_guild_id}/{$icon}.{$ext}?size=64"
                                    : null;
                    $serverName = $server
                                    ? ($server->discord_guild_name ?? ('Server '.$server->discord_guild_id))
                                    : null;
                    $scopeLabel = data_get($l->activePlatformScope(), 'scope_label');
                    $teamsUrl = route('community.leagues.teams', ['c_id' => $currentOrg->id, 'l_id' => $l->id], false);
                    $draftSummaryUrl = route('community.leagues.draft-summary', ['c_id' => $currentOrg->id, 'l_id' => $l->id], false);
                    $transactionsBrowserRpcUrl = $platform === 'fantrax'
                        ? route('community.leagues.transactions.browser-rpc', ['c_id' => $currentOrg->id, 'l_id' => $l->id], false)
                        : '';
                    $transactionsUrl = $platform === 'fantrax'
                        ? route('community.leagues.transactions.index', ['c_id' => $currentOrg->id, 'l_id' => $l->id], false)
                        : '';
                    $teamLogoSyncUrl = in_array($platform, ['fantrax', 'yahoo'], true)
                        ? route('community.leagues.team-logos.sync', ['c_id' => $currentOrg->id, 'l_id' => $l->id], false)
                        : '';
                    $leaguePayload = [
                        'id' => $l->id,
                        'name' => (string) $l->name,
                        'platform' => ucfirst($platform ?: 'League'),
                        'scope' => $scopeLabel,
                        'server' => $serverName,
                        'teamsUrl' => $teamsUrl,
                        'draftSummaryUrl' => $draftSummaryUrl,
                        'leagueOptionsUrl' => $canEdit ? route('community.leagues.options', ['c_id' => $currentOrg->id, 'l_id' => $l->id], false) : '',
                        'transactionsBrowserRpcUrl' => $transactionsBrowserRpcUrl,
                        'transactionsUrl' => $transactionsUrl,
                        'teamLogoSyncUrl' => $teamLogoSyncUrl,
                    ];
                @endphp

                <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3" data-community-league-row="{{ $l->id }}">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left focus:outline-none focus:ring-2 focus:ring-indigo-200"
                        @click="openCommunityLeague(@js($leaguePayload), 'teams')"
                    >
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1 {{ $badge }} text-[10px] font-semibold uppercase">
                            {{ $platform ? substr($platform, 0, 2) : '' }}
                        </span>

                        <div class="min-w-0">
                            <!-- Top row: League name + server (to the right) -->
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="text-sm font-medium text-slate-900 truncate">
                                    {{ $l->name }}
                                </div>

                                @if($serverName)
                                    <div class="flex items-center gap-2 text-xs text-slate-600 shrink-0">
                                        @if($avatarUrl)
                                            <img src="{{ $avatarUrl }}" alt="" class="h-4 w-4 rounded-full object-cover ring-1 ring-slate-200">
                                        @else
                                            <span class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-slate-200 ring-1 ring-slate-200"></span>
                                        @endif
                                        <span class="truncate max-w-[12rem] sm:max-w-[16rem] md:max-w-[20rem]">{{ $serverName }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Second line: ID / sport -->
                            <div class="text-xs text-slate-600">
                                ID: {{ $l->id }}@if($scopeLabel) / {{ $scopeLabel }}@endif
                            </div>
                        </div>
                    </button>

                    <div class="ml-auto flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                            @click.stop="openCommunityLeague(@js($leaguePayload), 'setup')"
                        >
                            Manage
                        </button>

                        @if ($allowUnlink && $canEdit && $currentOrg)
                            <button
                                type="button"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-200 disabled:cursor-not-allowed disabled:opacity-60"
                                data-community-league-detach
                                data-league-id="{{ $l->id }}"
                                data-url="{{ route('organizations.leagues.destroy', ['organization' => $currentOrg->id, 'league' => $l->id]) }}"
                                aria-label="Remove {{ $l->name }} from this community"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        @endif
                    </div>

                </li>

            @endforeach
        </ul>
        <div class="mt-2 hidden rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600" data-community-leagues-empty>
            No leagues connected yet.
        </div>
    @endif

    <x-new-league-modal
        model="open"
        :actionUrl="$currentOrg ? route('organizations.leagues.store', $currentOrg->id) : ''"
        :guildOptions="$guildOptions"
        :fantraxOptions="$fantraxOptions"
    />

</section>
