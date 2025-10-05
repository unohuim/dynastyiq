{{-- resources/views/communities/partials/_desktop-leagues.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection $leagues */
    /** @var \Illuminate\Support\Collection $guilds */

    $guilds = $guilds ?? collect();

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
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-700">{{ $leagues->count() }}</span>
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
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
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
                @endphp

                <li class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3">
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
                    @endphp

                    <div class="flex items-center gap-3 min-w-0">
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
                                ID: {{ $l->id }}
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        x-data
                        @click="window.location.href='{{ route('community.leagues.show', ['c_id' => $currentOrg->id, 'l_id' => $l->id]) }}'"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-700"
                    >
                        Manage
                    </button>

                </li>

            @endforeach
        </ul>
    @endif

    <x-new-league-modal
        model="open"
        :actionUrl="$currentOrg ? route('organizations.leagues.store', $currentOrg->id) : ''"
        :guildOptions="$guildOptions"
        :fantraxOptions="$fantraxOptions"
    />

</section>



<script>
/* -------- Dropdown helper (define once) -------- */
window.__dropdownSelectInit = window.__dropdownSelectInit || (function () {
  window.dropdownSelect = function ({ options = [] }) {
    return {
      openList: false,
      options,
      selected: null,
      select(opt) { this.selected = opt; this.openList = false; },
    };
  };
  return true;
})();

/* -------- Create League AJAX submit -------- */
(() => {
  const form = document.getElementById('createLeagueForm');
  if (!form) return;

  // Prefer data-action, fall back to action; otherwise bail
  const resolveAction = () => {
    const d = (form.dataset && form.dataset.action ? form.dataset.action.trim() : '');
    if (d) return d;
    const a = (form.getAttribute('action') || '').trim();
    return a;
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const url = resolveAction();

    // Hard guard so we never POST to the index page by accident
    if (!url || url === '#' || url === '/' || /\/communities(\?|$)/.test(url)) {
      console.warn('[createLeague] Missing/invalid action URL on form:', url);
      alert('Cannot submit: missing endpoint to create a league.');
      return;
    }

    const token        = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const name         = form.querySelector('[name="name"]')?.value.trim() || '';
    const discordId    = form.querySelector('[name="discord_server_id"]')?.value || '';
    const platform     = form.querySelector('[name="platform"]')?.value || '';
    const platformId   = form.querySelector('[name="platform_league_id"]')?.value || '';

    if (!name) {
      alert('Please enter a league name.');
      return;
    }
    if (platform && !platformId) {
      alert('Please select or enter a Fantrax league ID.');
      return;
    }

    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;

    const payload = {
      name,
      ...(discordId ? { discord_server_id: discordId } : {}),
      ...(platform ? { platform, platform_league_id: platformId } : {}),
    };

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || data?.ok !== true) {
        const msg = data?.message || `Create failed (${res.status})`;
        console.warn('[createLeague] Server responded with error:', res.status, data);
        alert(msg);
        return;
      }

      // Reload to reflect fresh leagues list/count
      window.location.reload();
    } catch (err) {
      console.error('[createLeague] Network/JS error:', err);
      alert('Could not create league.');
    } finally {
      if (btn) btn.disabled = false;
    }
  });
})();
</script>

