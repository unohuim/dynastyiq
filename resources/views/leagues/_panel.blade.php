{{-- resources/views/leagues/_panel.blade.php --}}
@php
  /** @var \App\Models\PlatformLeague|null $league */
  $displayId = $league?->id;
  $authId = auth()->id();
  $leagueStatsFallbackSlug = $league?->platform === 'fantrax'
      ? 'fantrax-league-' . $league->id
      : 'yahoo-league-' . $league?->id;
  $leagueStatsFallbackName = $league?->platform === 'fantrax'
      ? $league->name . ' Fantrax'
      : $league?->name . ' Scoring';
@endphp

@if (! $league)
  <div class="p-6 text-sm text-slate-500">No active league selected.</div>
@else
  <div
    class="p-6"
    x-data="{
      teams: @js($teams ?? []),
      scoringCategories: @js($scoringCategories ?? []),
      scoringAlignmentCategories: @js($scoringAlignmentCategories ?? []),
      manualMappings: @js($manualScoringMappings ?? []),
      availableStatFields: @js($availableStatFields ?? []),
      searchPlayers: @js($searchPlayers ?? []),
      scoringSettingsUpdateUrl: @js($scoringSettingsUpdateUrl ?? ''),
      leagueStatsPayloadUrl: @js($leagueStatsPayloadUrl ?? ''),
      isScoringFullyMapped: @js((bool) ($isScoringFullyMapped ?? false)),
      canShowLeagueStats: @js((bool) ($canShowLeagueStats ?? false)),
      playerSearch: '',
      settingsOpen: false,
      scoringAlignmentOpen: true,
      savingScoringAlignment: false,
      scoringAlignmentMessage: '',
      scoringAlignmentError: '',
      leagueStatsLoading: false,
      leagueStatsError: '',
      leagueStatsShell: null,

      init(){
        this.$nextTick(() => this.loadLeagueStats());
        window.addEventListener('diq:stats-page-ready', () => this.loadLeagueStats(), { once: true });
      },

      // pick my team as default when possible
      i: (() => {
        const teams = @js($teams ?? []);
        const me = @js($authId);
        let idx = teams.findIndex(t => t?.owned_by_me === true);
        if (idx !== -1) return idx;
        idx = teams.findIndex(t => (t?.owner_user_ids || []).includes(me));
        return idx !== -1 ? idx : 0;
      })(),

      teamQuery: '',
      teamListOpen: false,
      get current(){ return this.teams[this.i] ?? null },
      get filteredPlayers(){
        const q = (this.playerSearch || '').toLowerCase().trim();
        const players = q === '' ? (this.current?.players ?? []) : (this.searchPlayers ?? []);

        if (q === '') return players;

        return players.filter(player => {
          const values = [
            player?.name,
            player?.first_name,
            player?.last_name,
            player?.position,
            player?.team_abbrev,
            this.eligibilityLabel(player),
          ];

          return values
            .filter(Boolean)
            .some(value => String(value).toLowerCase().includes(q));
        });
      },
      get filtered(){
        const q = (this.teamQuery || '').toLowerCase().trim();
        return (this.teams || [])
          .map((t, idx) => ({ t, idx }))
          .filter(o => q === '' || (o.t.name || '').toLowerCase().includes(q));
      },
      shouldShowRosterSections(){
        return !this.playerSearch
          && this.current?.id !== '__all_players__'
          && this.current?.id !== '__free_agents__';
      },
      select(idx){
        this.i = idx;
        this.teamQuery = this.teams[idx]?.name ?? '';
        this.playerSearch = '';
        this.teamListOpen = false;
      },
      eligibilityLabel(player){
        const raw = player?.eligibility;
        const values = Array.isArray(raw)
          ? raw
          : (typeof raw === 'object' && raw !== null ? Object.values(raw).flat() : [raw]);
        const hidden = new Set(['F', 'UTIL', 'UTILS', 'UTILITY', 'UTL', 'W/R/T']);
        const positions = values
          .filter(Boolean)
          .map(value => String(value).trim())
          .filter(value => value !== '')
          .filter(value => !hidden.has(value.toUpperCase()));

        return positions.length ? positions.join('/') : (player?.position || '');
      },
      playerInitials(player){
        const name = player?.name || [player?.first_name, player?.last_name].filter(Boolean).join(' ') || '';
        return name
          .trim()
          .split(/\s+/)
          .filter(Boolean)
          .slice(0, 2)
          .map(part => part.slice(0, 1).toUpperCase())
          .join('') || 'DI';
      },
      async saveScoringMappings(){
        if (!this.scoringSettingsUpdateUrl) return;

        this.savingScoringAlignment = true;
        this.scoringAlignmentMessage = '';
        this.scoringAlignmentError = '';

        try {
          const response = await fetch(this.scoringSettingsUpdateUrl, {
            method: 'PUT',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({ mappings: this.manualMappings }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not save scoring alignment.');
          }

          this.scoringCategories = payload.scoringCategories ?? this.scoringCategories;
          this.scoringAlignmentCategories = payload.scoringAlignmentCategories ?? this.scoringAlignmentCategories;
          this.manualMappings = payload.manualScoringMappings ?? this.manualMappings;
          this.isScoringFullyMapped = Boolean(payload.isScoringFullyMapped ?? this.isScoringFullyMapped);
          this.canShowLeagueStats = Boolean(payload.canShowLeagueStats ?? this.canShowLeagueStats);
          this.leagueStatsPayloadUrl = payload.leagueStatsPayloadUrl ?? this.leagueStatsPayloadUrl;
          this.scoringAlignmentMessage = payload.message || 'Scoring category alignment saved.';
          this.$nextTick(() => this.loadLeagueStats(true));
        } catch (error) {
          this.scoringAlignmentError = error?.message || 'Could not save scoring alignment.';
        } finally {
          this.savingScoringAlignment = false;
        }
      },
      async loadLeagueStats(force = false){
        if (!this.canShowLeagueStats || !this.leagueStatsPayloadUrl || this.leagueStatsLoading) return;
        if (this.leagueStatsShell && !force) return;

        const mount = window.DIQ?.mountStatsPage;
        if (typeof mount !== 'function') return;

        this.leagueStatsLoading = true;
        this.leagueStatsError = '';

        try {
          const response = await fetch(this.leagueStatsPayloadUrl, {
            headers: { Accept: 'application/json' },
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not load league stats.');
          }

          const container = this.$refs.leagueStats;
          if (!container) return;

          delete container.dataset.statsMounted;
          this.leagueStatsShell = mount(container, {
            initialPayload: payload,
            apiUrl: this.leagueStatsPayloadUrl,
            connectedLeagues: payload.connectedLeagues ?? [],
            perspectives: payload.perspectives ?? [
              {
                slug: @js($leagueStatsFallbackSlug),
                name: @js($leagueStatsFallbackName),
              },
            ],
            selectedPerspective: payload.selectedPerspective ?? @js($leagueStatsFallbackSlug),
            mobileBreakpoint: @js(config('viewports.mobile', 640)),
            syncUrl: false,
          });
        } catch (error) {
          this.leagueStatsError = error?.message || 'Could not load league stats.';
        } finally {
          this.leagueStatsLoading = false;
        }
      }
    }"
    x-cloak
  >
    <div class="mb-4 flex items-start justify-between gap-4 px-2">
      <div class="min-w-0">
        <div class="truncate text-sm font-semibold text-slate-900">{{ $league->name }}</div>
        <div class="mt-1 text-xs text-slate-500">ID: <span class="font-mono">{{ $displayId }}</span></div>
      </div>
      <button
        type="button"
        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2"
        @click="settingsOpen = true"
        aria-label="League options"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 4.3c.4-1.7 2.9-1.7 3.4 0a1.8 1.8 0 0 0 2.7 1.1c1.5-.9 3.2.9 2.4 2.4a1.8 1.8 0 0 0 1.1 2.7c1.7.4 1.7 2.9 0 3.4a1.8 1.8 0 0 0-1.1 2.7c.9 1.5-.9 3.2-2.4 2.4a1.8 1.8 0 0 0-2.7 1.1c-.4 1.7-2.9 1.7-3.4 0a1.8 1.8 0 0 0-2.7-1.1c-1.5.9-3.2-.9-2.4-2.4A1.8 1.8 0 0 0 4.1 14c-1.7-.4-1.7-2.9 0-3.4a1.8 1.8 0 0 0 1.1-2.7c-.9-1.5.9-3.2 2.4-2.4a1.8 1.8 0 0 0 2.7-1.1Z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" />
        </svg>
      </button>
    </div>

    <div x-show="!canShowLeagueStats">
      <x-card-section title="Players" class="border-0">
        <div class="space-y-5">
          <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:items-center">
            <div>
              <label for="league-player-search" class="sr-only">Search players</label>
              <input
                id="league-player-search"
                type="search"
                x-model.debounce.100ms="playerSearch"
                class="block w-full rounded-md bg-white py-2 pl-3 pr-3 text-sm text-slate-900 outline outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
                placeholder="Search all players..."
                autocomplete="off"
              />
            </div>

            {{-- Combobox --}}
            <div class="relative" @click.stop>
              <label for="team-combobox" class="block text-sm font-medium text-slate-900 sr-only">Team</label>
              <div class="flex items-center gap-2">
                <img x-show="current?.owner_avatar_url" :src="current?.owner_avatar_url" alt=""
                     class="h-8 w-8 rounded-full object-cover ring-1 ring-slate-200">
                <span
                  x-show="!current?.owner_avatar_url"
                  class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200"
                  x-text="current?.id === '__free_agents__' ? 'FA' : (current?.id === '__all_players__' ? 'ALL' : 'TM')"
                ></span>
                <div class="relative w-full">
                  <input
                    id="team-combobox"
                    type="text"
                    x-model="teamQuery"
                    @focus="teamListOpen = true; teamQuery = ''"
                    @keydown.escape.prevent.stop="teamListOpen = false"
                    class="block w-full rounded-md bg-white py-2 pl-3 pr-10 text-sm text-slate-900 outline outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
                    :placeholder="current?.name ?? 'Select team...'"
                    autocomplete="off"
                  />
                  <button type="button"
                          class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-2 focus:outline-none"
                          @click.stop="teamListOpen = !teamListOpen" aria-label="Toggle team list">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="size-5 text-slate-400">
                      <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"/>
                    </svg>
                  </button>

                  {{-- Options --}}
                  <div x-show="teamListOpen" @click.outside="teamListOpen = false" x-transition
                       class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md bg-white p-1 text-sm shadow-lg outline outline-1 outline-black/5">
                    <template x-for="o in filtered" :key="o.idx">
                      <button type="button"
                              class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-slate-900 hover:bg-indigo-600 hover:text-white"
                              @click="select(o.idx)">
                        <template x-if="o.t.owner_avatar_url">
                          <img :src="o.t.owner_avatar_url" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-black/5" alt="">
                        </template>
                        <template x-if="!o.t.owner_avatar_url">
                          <span
                            class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-600 ring-1 ring-black/5"
                            x-text="o.t.id === '__free_agents__' ? 'FA' : (o.t.id === '__all_players__' ? 'ALL' : 'TM')"
                          ></span>
                        </template>
                        <span class="truncate" x-text="o.t.name ?? 'Team'"></span>
                      </button>
                    </template>
                    <div x-show="filtered.length === 0" class="px-3 py-2 text-slate-500">No matches.</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
              <div class="text-xs font-semibold text-slate-700" x-text="playerSearch ? 'Player search' : (current?.name ?? 'Roster')"></div>
              <div class="text-[11px] text-slate-500">
                <span x-text="filteredPlayers.length"></span>
                <span x-text="filteredPlayers.length === 1 ? 'player' : 'players'"></span>
              </div>
            </div>

            <div class="h-[calc(100vh-19rem)] min-h-[18rem] overflow-y-auto divide-y divide-slate-200">
	              <template x-for="(p, playerIndex) in filteredPlayers" :key="p.id">
	                <div>
	                  <div
	                    x-show="shouldShowRosterSections() && p.roster_group === 'minor' && (filteredPlayers?.[playerIndex - 1]?.roster_group !== 'minor')"
	                    class="bg-slate-50 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500"
	                  >
	                    Minor League
	                  </div>
	                  <div class="flex items-center gap-2 px-3 py-1.5">
                    <span
                      class="inline-flex h-6 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600"
                      x-text="p.roster_slot || '-'"
                    ></span>
                    <template x-if="p.avatar_url">
                      <img
                        :src="p.avatar_url"
                        alt=""
                        class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-slate-200"
                        loading="lazy"
                      >
                    </template>
                    <template x-if="!p.avatar_url">
                      <span
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 ring-1 ring-slate-200"
                        x-text="playerInitials(p)"
                      ></span>
                    </template>
	                    <div class="min-w-0">
	                      <div class="truncate text-sm font-medium text-slate-900"
	                           x-text="p.name || [p.first_name, p.last_name].filter(Boolean).join(' ')"></div>
	                      <div class="text-[11px] text-slate-500">
                        <span x-text="eligibilityLabel(p)"></span>
                        <span x-show="p.team_abbrev"> &bull; <span x-text="p.team_abbrev"></span></span>
	                        <span x-show="p.age !== undefined && p.age !== null"> &bull; Age <span x-text="p.age"></span></span>
	                      </div>
	                    </div>
	                    <div
	                      x-show="current?.id === '__all_players__' && p.fantasy_team_name"
	                      class="ml-auto flex min-w-0 max-w-[42%] shrink-0 items-center justify-end gap-2"
	                    >
	                      <span class="truncate text-right text-[11px] font-medium text-slate-600" x-text="p.fantasy_team_name"></span>
	                      <template x-if="p.fantasy_team_avatar_url">
	                        <img
	                          :src="p.fantasy_team_avatar_url"
	                          alt=""
	                          class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-slate-200"
	                          loading="lazy"
	                        >
	                      </template>
	                      <template x-if="!p.fantasy_team_avatar_url">
	                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[9px] font-semibold text-slate-500 ring-1 ring-slate-200">TM</span>
	                      </template>
	                    </div>
	                  </div>
	                </div>
	              </template>
              <template x-if="!playerSearch && (current?.players ?? []).length === 0">
                <div class="px-3 py-4 text-sm text-slate-500">No players.</div>
              </template>
              <template x-if="filteredPlayers.length === 0">
                <div class="px-3 py-4 text-sm text-slate-500">No players match your search.</div>
              </template>
            </div>
          </div>
        </div>
      </x-card-section>
    </div>

    <div x-show="canShowLeagueStats" class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <div>
          <div class="text-sm font-semibold text-slate-950">League Stats</div>
          <div class="mt-1 text-xs text-slate-500">League context with fantasy ownership</div>
        </div>
        <div x-show="leagueStatsLoading" class="text-xs text-slate-500">Loading...</div>
      </div>
      <div x-show="leagueStatsError" class="border-b border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="leagueStatsError"></div>
      <div x-ref="leagueStats" class="min-h-[24rem] py-3"></div>
    </div>

    <x-ui.slide-over show="settingsOpen" close-action="settingsOpen = false" title-id="league-options-title" max-width="max-w-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <h2 id="league-options-title" class="text-base font-semibold text-slate-950">League options</h2>
          <p class="mt-1 text-xs text-slate-500">{{ $league->name }} &bull; {{ ucfirst((string) $league->platform) }}</p>
        </div>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-600"
          @click="settingsOpen = false"
          aria-label="Close league options"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z" />
          </svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto p-6">
        @if (in_array($league->platform, ['yahoo', 'fantrax'], true))
        <section class="rounded-lg border border-slate-200 bg-white">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left"
            @click="scoringAlignmentOpen = !scoringAlignmentOpen"
            :aria-expanded="scoringAlignmentOpen.toString()"
          >
            <div>
              <div class="text-sm font-semibold text-slate-950">Scoring category alignment</div>
              <div class="mt-1 text-xs text-slate-500">Map {{ ucfirst((string) $league->platform) }} categories to DynastyIQ stat fields.</div>
            </div>
            <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 shrink-0 text-slate-400 transition" :class="scoringAlignmentOpen ? 'rotate-180' : ''" aria-hidden="true">
              <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
          </button>

          <div x-show="scoringAlignmentOpen">
            <form class="border-t border-slate-200 p-4" @submit.prevent="saveScoringMappings">
              <div class="space-y-3">
                <template x-for="category in scoringAlignmentCategories" :key="category.id">
                  <div class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(13rem,16rem)] sm:items-center">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <div class="truncate text-sm font-semibold text-slate-900" x-text="category.label"></div>
                        <span
                          x-show="category.mapping_source"
                          class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                          :class="category.mapping_source === 'manual' ? 'bg-indigo-50 text-indigo-700' : 'bg-emerald-50 text-emerald-700'"
                          x-text="category.mapping_source"
                        ></span>
                      </div>
                      <div class="mt-1 text-xs text-slate-500">
                        <span x-show="category.value !== null && category.value !== undefined">Value <span x-text="category.value"></span></span>
                        <span x-show="category.auto_stat_key">Auto <span class="font-mono" x-text="category.auto_stat_key"></span></span>
                      </div>
                    </div>
                    <label class="block">
                      <span class="sr-only">DynastyIQ stat field</span>
                      <select
                        x-model="manualMappings[String(category.id)]"
                        class="block w-full rounded-md border-0 bg-white py-2 pl-3 pr-9 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                      >
                        <option value="">Use auto / none</option>
                        <template x-for="field in availableStatFields" :key="field.key">
                          <option :value="field.key" x-text="`${field.label} (${field.key})`"></option>
                        </template>
                      </select>
                    </label>
                  </div>
                </template>
                <div x-show="scoringAlignmentCategories.length === 0" class="rounded-md bg-slate-50 px-3 py-4 text-sm text-slate-500">
                  No {{ ucfirst((string) $league->platform) }} scoring categories are available for this league yet.
                </div>
              </div>

              <div class="mt-4 flex items-center justify-between gap-3">
                <div class="min-h-5 text-xs">
                  <span x-show="scoringAlignmentMessage" class="text-emerald-700" x-text="scoringAlignmentMessage"></span>
                  <span x-show="scoringAlignmentError" class="text-red-600" x-text="scoringAlignmentError"></span>
                </div>
                <button
                  type="submit"
                  class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="savingScoringAlignment"
                >
                  <span x-show="!savingScoringAlignment">Save alignment</span>
                  <span x-show="savingScoringAlignment">Saving...</span>
                </button>
              </div>
            </form>
          </div>
        </section>
        @if ($league->platform === 'fantrax')
        <section class="mt-4 rounded-lg border border-slate-200 bg-white">
          <div class="border-b border-slate-200 px-4 py-3">
            <div class="text-sm font-semibold text-slate-950">Fantrax league context</div>
            <div class="mt-1 text-xs text-slate-500">Stats use your saved DynastyIQ perspectives with Fantrax roster ownership layered on.</div>
          </div>
          <div class="space-y-3 p-4">
            <div class="rounded-md bg-slate-50 px-3 py-3 text-sm text-slate-600">
              Fantasy managers, team avatars, roster order, and selected-team filtering come from the synced Fantrax league roster.
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-md border border-slate-200 p-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Platform</div>
                <div class="mt-1 text-sm font-medium text-slate-900">Fantrax</div>
              </div>
              <div class="rounded-md border border-slate-200 p-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">League ID</div>
                <div class="mt-1 truncate font-mono text-sm text-slate-900">{{ $league->platform_league_id }}</div>
              </div>
            </div>
          </div>
        </section>
        @endif
        @else
        <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
          No league options are available for this platform yet.
        </section>
        @endif
      </div>
    </x-ui.slide-over>
  </div>
@endif
