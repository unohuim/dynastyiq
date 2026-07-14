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
  $teamRowsForHeader = collect($teams ?? [])->reject(static fn (array $team): bool => in_array($team['id'] ?? null, ['__all_players__', '__free_agents__'], true));
  $ownedTeamForHeader = $teamRowsForHeader->first(static fn (array $team): bool => (bool) ($team['owned_by_me'] ?? false));
  $teamCountForHeader = $teamRowsForHeader->count();
  $teamCountLabel = $teamCountForHeader === 1 ? 'Team' : 'Teams';
  $canManageLeague = (bool) ($canManageLeague ?? false);
  $canEditLeagueSettings = (bool) ($canEditLeagueSettings ?? $canManageLeague);
  $leagueSettingsSource = (string) ($leagueSettingsSource ?? 'league_default');
  $canSyncTeamLogos = $canManageLeague && in_array($league?->platform, ['fantrax', 'yahoo'], true) && filled($teamLogoSyncUrl ?? null);
@endphp

@if (! $league)
  <div class="p-6 text-sm text-slate-500">No active league selected.</div>
@else
  <div
    class="flex h-full min-h-0 flex-col overflow-hidden"
    x-data="{
      teams: @js($teams ?? []),
      currentUserId: @js($authId),
      scoringCategories: @js($scoringCategories ?? []),
      scoringAlignmentCategories: @js($scoringAlignmentCategories ?? []),
      manualMappings: @js($manualScoringMappings ?? []),
      availableStatFields: @js($availableStatFields ?? []),
      scoringMappingOptions: @js($scoringMappingOptions ?? []),
      scoringMappingQueries: {},
      scoringMappingOpen: {},
      searchPlayers: @js($searchPlayers ?? []),
      scoringSettingsUpdateUrl: @js($scoringSettingsUpdateUrl ?? ''),
      capSettingsUpdateUrl: @js($capSettingsUpdateUrl ?? ''),
      teamLogoSyncUrl: @js($teamLogoSyncUrl ?? ''),
      leagueStatsPayloadUrl: @js($leagueStatsPayloadUrl ?? ''),
      leagueStatsPerspectives: @js($leagueStatsPerspectives ?? []),
      selectedLeagueStatsPerspective: @js($selectedLeagueStatsPerspective ?? $leagueStatsFallbackSlug),
      playersPayloadUrl: @js($playersPayloadUrl ?? ''),
      playersFreeAgentsPayloadUrl: @js($playersFreeAgentsPayloadUrl ?? ''),
      capProjectionsUpdateUrl: @js($capProjectionsUpdateUrl ?? ''),
      isScoringFullyMapped: @js((bool) ($isScoringFullyMapped ?? false)),
      canShowLeagueStats: @js((bool) ($canShowLeagueStats ?? false)),
      customCap: @js((bool) ($customCap ?? false)),
      salaryCap: @js($salaryCap ?? null),
      salaryCapInput: @js(($salaryCap ?? null) !== null ? (string) $salaryCap : ''),
      capLimitsBySeason: @js((object) ($capLimitsBySeason ?? [])),
      capLimitDrafts: @js((object) ($capLimitsBySeason ?? [])),
      capAdjustmentsByTeam: @js((object) ($capAdjustmentsByTeam ?? [])),
      maxActiveBuyouts: @js($maxActiveBuyouts ?? null),
      maxActiveRetentions: @js($maxActiveRetentions ?? null),
      maxActiveBuyoutsInput: @js(($maxActiveBuyouts ?? null) !== null ? (string) $maxActiveBuyouts : ''),
      maxActiveRetentionsInput: @js(($maxActiveRetentions ?? null) !== null ? (string) $maxActiveRetentions : ''),
      leagueSettingsSource: @js($leagueSettingsSource),
      canEditLeagueSettings: @js($canEditLeagueSettings),
      fantraxContractCodes: @js($fantraxContractCodes ?? []),
      fantraxContractCodeDefinitions: @js((object) ($fantraxContractCodeDefinitions ?? [])),
      activeLeagueTab: 'draft',
      playerSearch: '',
      capView: 'sheet',
      capSettingsDrawerOpen: false,
      capAdjustmentDrawerOpen: false,
      capAdjustmentMode: 'create',
      capAdjustmentEditingId: '',
      capAdjustmentPlayerQuery: '',
      capAdjustmentPlayerOpen: false,
      capAdjustmentForm: {
        type: 'buyout',
        player_id: '',
        player_name: '',
        player_position: '',
        avatar_url: '',
        team_abbrev: '',
        percent: 50,
        start_season: '',
        end_season: '',
        values_by_season: {},
      },
      capAdjustmentOverrides: {},
      capShowLimitRows: false,
      capShowSummaryRows: false,
      savingCapProjection: false,
      capSortKey: '',
      capSortDirection: 'desc',
      playersPayloadLoaded: false,
      playersPayloadLoading: false,
      playersPayloadError: '',
      freeAgents: [],
      freeAgentsPayloadLoaded: false,
      freeAgentsPayloadLoading: false,
      freeAgentsPayloadError: '',
      capTeamId: '',
      capTeamLoading: false,
      capTeamError: '',
      settingsOpen: false,
      scoringAlignmentOpen: false,
      savingScoringAlignment: false,
      scoringAlignmentMessage: '',
      scoringAlignmentError: '',
      savingCapSettings: false,
      capSettingsMessage: '',
      capSettingsError: '',
      teamLogosSyncing: false,
      teamLogosMessage: '',
      teamLogosError: '',
      leagueStatsLoading: false,
      leagueStatsError: '',
      leagueStatsShell: null,

      scoringAlignmentStatusLabel(category){
        const status = String(category?.alignment_status ?? '').replaceAll('_', ' ');

        return status ? status : '';
      },
      scoringAlignmentStatusClass(category){
        const status = category?.alignment_status;

        if (status === 'unsupported') return 'bg-red-50 text-red-700';
        if (status === 'planned_derivation') return 'bg-amber-50 text-amber-700';
        if (status === 'ignored_deprecated') return 'bg-slate-100 text-slate-600';

        return 'bg-emerald-50 text-emerald-700';
      },
      normalizeScoringMappingKey(value){
        const key = String(value ?? '').trim();

        if (!key || key.includes(':')) return key;

        return `stat:${key}`;
      },
      categoryAutoMappingKey(category){
        if (category?.auto_mapping_key) return category.auto_mapping_key;
        if (category?.dictionary_provider_label) return `dictionary:fantrax:${category.dictionary_provider_label}`;
        if (category?.auto_stat_key) return `stat:${category.auto_stat_key}`;

        return '';
      },
      categorySelectedMappingKey(category){
        const id = String(category?.id ?? '');
        const manual = this.normalizeScoringMappingKey(this.manualMappings[id] ?? '');

        return manual || this.categoryAutoMappingKey(category);
      },
      scoringMappingOptionByKey(key){
        const normalized = this.normalizeScoringMappingKey(key);

        return (this.scoringMappingOptions || []).find((option) => option.key === normalized) ?? null;
      },
      scoringMappingDisplay(category){
        const option = this.scoringMappingOptionByKey(this.categorySelectedMappingKey(category));

        if (!option) return 'No mapped option';

        return option.type === 'dictionary' ? option.label : `${option.label} (${option.stat_key})`;
      },
      scoringMappingQuery(category){
        const id = String(category?.id ?? '');

        return this.scoringMappingQueries[id] ?? this.scoringMappingDisplay(category);
      },
      openScoringMappingCombobox(category){
        const id = String(category?.id ?? '');
        this.scoringMappingQueries[id] = this.scoringMappingDisplay(category);
        this.scoringMappingOpen[id] = true;
      },
      closeScoringMappingCombobox(category){
        const id = String(category?.id ?? '');
        this.scoringMappingOpen[id] = false;
        this.scoringMappingQueries[id] = this.scoringMappingDisplay(category);
      },
      scoringMappingFilteredOptions(category){
        const id = String(category?.id ?? '');
        const query = String(this.scoringMappingQueries[id] ?? '').toLowerCase().trim();
        const options = this.scoringMappingOptions || [];

        if (!query) return options.slice(0, 80);

        return options.filter((option) => {
          return [
            option.label,
            option.description,
            option.formula,
            option.stat_key,
            option.alignment_status,
            option.type,
          ].filter(Boolean).some((value) => String(value).toLowerCase().includes(query));
        }).slice(0, 80);
      },
      selectScoringMappingOption(category, option){
        const id = String(category?.id ?? '');
        const autoKey = this.categoryAutoMappingKey(category);

        if (option.key === autoKey) {
          delete this.manualMappings[id];
        } else {
          this.manualMappings[id] = option.key;
        }

        this.scoringMappingQueries[id] = option.type === 'dictionary' ? option.label : `${option.label} (${option.stat_key})`;
        this.scoringMappingOpen[id] = false;
      },
      clearScoringMappingOverride(category){
        const id = String(category?.id ?? '');
        delete this.manualMappings[id];
        this.scoringMappingQueries[id] = this.scoringMappingDisplay(category);
        this.scoringMappingOpen[id] = false;
      },
      scoringMappingOptionClass(option){
        const status = option?.alignment_status;

        if (status === 'unsupported') return 'text-red-700';
        if (status === 'planned_derivation') return 'text-amber-700';

        return 'text-slate-900';
      },
      scoringMappingOptionTitle(option){
        return [
          option?.label,
          option?.description,
          option?.formula,
          option?.stat_key,
          option?.alignment_status,
        ].filter(Boolean).join(' | ');
      },

      init(){
        const state = this.readLeagueUiState();
        this.activeLeagueTab = this.validLeagueTab(state.activeTab);
        this.capView = this.validCapView(state.capView);
        this.capShowLimitRows = state.capShowLimitRows === undefined ? false : Boolean(state.capShowLimitRows);
        this.capShowSummaryRows = Number(state.capSummaryRowsVersion || 0) >= 2
          ? Boolean(state.capShowSummaryRows)
          : false;
        if (Number(state.capSummaryRowsVersion || 0) < 2) {
          this.writeLeagueUiState({
            capShowSummaryRows: false,
            capSummaryRowsVersion: 2,
          });
        }

        window.addEventListener('diq:stats-page-ready', () => {
          if (this.activeLeagueTab === 'players') this.loadLeagueStats();
        }, { once: true });

        if (['players', 'cap'].includes(this.activeLeagueTab)) {
          this.$nextTick(() => this.openLeagueTab(this.activeLeagueTab, false));
        }
      },
      leagueUiStateKey(){
        return `diq:league-ui:${@js((string) $league->id)}`;
      },
      readLeagueUiState(){
        try {
          return JSON.parse(window.localStorage?.getItem(this.leagueUiStateKey()) || '{}') || {};
        } catch (error) {
          return {};
        }
      },
      writeLeagueUiState(patch){
        try {
          const current = this.readLeagueUiState();
          window.localStorage?.setItem(this.leagueUiStateKey(), JSON.stringify({
            ...current,
            ...patch,
            updatedAt: new Date().toISOString(),
          }));
        } catch (error) {
          // localStorage can be unavailable in private browsing or strict contexts.
        }
      },
      validLeagueTab(tab){
        return ['overview', 'draft', 'players', 'cap'].includes(String(tab || '')) ? String(tab) : 'draft';
      },
      validCapView(view){
        return ['sheet', 'adjustments', 'limits'].includes(String(view || '')) ? String(view) : 'sheet';
      },
      setCapView(view){
        this.capView = this.validCapView(view);
        this.writeLeagueUiState({ capView: this.capView });
      },
      toggleCapLimitRows(){
        this.capShowLimitRows = !this.capShowLimitRows;
        this.writeLeagueUiState({ capShowLimitRows: this.capShowLimitRows });
      },
      toggleCapSummaryRows(){
        this.capShowSummaryRows = !this.capShowSummaryRows;
        this.writeLeagueUiState({
          capShowSummaryRows: this.capShowSummaryRows,
          capSummaryRowsVersion: 2,
        });
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
      rosterRowClass(player){
        if (!this.shouldShowRosterSections()) return '';

        const slot = String(player?.roster_slot || '').trim().toUpperCase();
        const status = String(player?.roster_status || '').trim().toLowerCase();

        if (player?.roster_group === 'minor') return 'bg-blue-50';
        if (status === 'ir' || ['IR', 'IR+'].includes(slot)) return 'bg-red-100';
        if (['bench', 'reserve'].includes(status) || ['BN', 'BEN', 'BENCH', 'RES', 'RESERVE'].includes(slot)) return 'bg-yellow-100';

        return '';
      },
      select(idx){
        this.i = idx;
        this.teamQuery = this.teams[idx]?.name ?? '';
        this.playerSearch = '';
        this.teamListOpen = false;
      },
      async openLeagueTab(tab, persist = true){
        this.activeLeagueTab = this.validLeagueTab(tab);

        if (persist) {
          this.writeLeagueUiState({ activeTab: this.activeLeagueTab });
        }

        if (this.activeLeagueTab === 'players') {
          if (this.canShowLeagueStats) {
            this.$nextTick(() => this.loadLeagueStats());
            return;
          }

          await this.loadPlayersPayload();
          return;
        }

        if (this.activeLeagueTab === 'cap') {
          await this.loadPlayersPayload(false, false);
        }
      },
      resetSelectedTeamIndex(){
        const me = @js($authId);
        let idx = (this.teams || []).findIndex(t => t?.owned_by_me === true);
        if (idx !== -1) {
          this.i = idx;
          return;
        }

        idx = (this.teams || []).findIndex(t => (t?.owner_user_ids || []).includes(me));
        this.i = idx !== -1 ? idx : 0;
      },
      defaultCapTeamId(){
        const team = (this.teams || []).find(team => team?.owned_by_me === true)
          || (this.teams || []).find(team => (team?.owner_user_ids || []).includes(this.currentUserId))
          || null;

        return String(team?.id || '');
      },
      ensureCapTeamSelection(preferredId = ''){
        const selectableIds = (this.teams || [])
          .filter(team => !['__all_players__', '__free_agents__'].includes(String(team?.id || '')))
          .map(team => String(team.id));
        const preferred = String(preferredId || '');

        if (preferred && selectableIds.includes(preferred)) {
          this.capTeamId = preferred;
          return;
        }

        if (this.capTeamId && selectableIds.includes(String(this.capTeamId))) return;

        this.capTeamId = this.defaultCapTeamId() || selectableIds[0] || '';
      },
      async loadPlayersPayload(force = false, hydrateFreeAgents = true){
        if (!force && this.playersPayloadLoaded) return true;
        if (this.playersPayloadLoading || !this.playersPayloadUrl) return false;

        this.playersPayloadLoading = true;
        this.playersPayloadError = '';

        try {
          const response = await fetch(this.playersPayloadUrl, {
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not load players.');
          }

          this.teams = payload.teams ?? [];
          this.searchPlayers = [];
          this.canShowLeagueStats = Boolean(payload.canShowLeagueStats ?? this.canShowLeagueStats);
          this.isScoringFullyMapped = Boolean(payload.isScoringFullyMapped ?? this.isScoringFullyMapped);
          this.customCap = Boolean(payload.customCap ?? this.customCap);
          this.salaryCap = payload.salaryCap ?? this.salaryCap;
          this.salaryCapInput = this.salaryCap ? String(this.salaryCap) : '';
          this.capLimitsBySeason = { ...(payload.capLimitsBySeason ?? this.capLimitsBySeason) };
          this.capLimitDrafts = { ...this.capLimitsBySeason };
          this.capAdjustmentsByTeam = { ...(payload.capAdjustmentsByTeam ?? this.capAdjustmentsByTeam) };
          this.maxActiveBuyouts = payload.maxActiveBuyouts ?? this.maxActiveBuyouts;
          this.maxActiveRetentions = payload.maxActiveRetentions ?? this.maxActiveRetentions;
          this.maxActiveBuyoutsInput = this.maxActiveBuyouts !== null && this.maxActiveBuyouts !== undefined ? String(this.maxActiveBuyouts) : '';
          this.maxActiveRetentionsInput = this.maxActiveRetentions !== null && this.maxActiveRetentions !== undefined ? String(this.maxActiveRetentions) : '';
          this.leagueSettingsSource = payload.leagueSettingsSource ?? this.leagueSettingsSource;
          this.canEditLeagueSettings = Boolean(payload.canEditLeagueSettings ?? this.canEditLeagueSettings);
          this.fantraxContractCodes = payload.fantraxContractCodes ?? this.fantraxContractCodes;
          this.fantraxContractCodeDefinitions = { ...(payload.fantraxContractCodeDefinitions ?? this.fantraxContractCodeDefinitions) };
          this.leagueStatsPayloadUrl = payload.leagueStatsPayloadUrl ?? this.leagueStatsPayloadUrl;
          this.leagueStatsPerspectives = Array.isArray(payload.leagueStatsPerspectives)
            ? payload.leagueStatsPerspectives
            : this.leagueStatsPerspectives;
          this.selectedLeagueStatsPerspective = payload.selectedLeagueStatsPerspective
            ?? this.selectedLeagueStatsPerspective;
          this.playersFreeAgentsPayloadUrl = payload.playersFreeAgentsPayloadUrl ?? this.playersFreeAgentsPayloadUrl;
          this.applyDeferredTeams();
          this.resetSelectedTeamIndex();
          this.ensureCapTeamSelection();
          this.playersPayloadLoaded = true;
          if (hydrateFreeAgents) {
            this.$nextTick(() => this.loadFreeAgentsPayload());
          }

          return true;
        } catch (error) {
          this.playersPayloadError = error?.message || 'Could not load players.';

          return false;
        } finally {
          this.playersPayloadLoading = false;
        }
      },
      realTeams(){
        return (this.teams || []).filter(team => !['__all_players__', '__free_agents__'].includes(String(team?.id || '')));
      },
      rosteredPlayers(){
        return this.realTeams()
          .flatMap(team => Array.isArray(team?.players) ? team.players : []);
      },
      combinedPlayers(){
        const players = [...this.rosteredPlayers(), ...(this.freeAgents || [])];
        const byId = new Map();

        players.forEach(player => {
          if (!player?.id || byId.has(player.id)) return;
          byId.set(player.id, player);
        });

        return Array.from(byId.values())
          .sort((a, b) => String(a?.name || '').localeCompare(String(b?.name || '')));
      },
      applyDeferredTeams(){
        const selectedId = String(this.current?.id || '');
        const allPlayers = this.combinedPlayers();
        const freeAgents = this.freeAgents || [];
        const realTeams = this.realTeams();

        this.teams = [
          {
            id: '__all_players__',
            name: 'All Players',
            owner_avatar_url: null,
            owner_avatar_urls: [],
            owned_by_me: false,
            owner_user_ids: [],
            players: allPlayers,
          },
          ...realTeams,
          {
            id: '__free_agents__',
            name: 'Free Agents',
            owner_avatar_url: null,
            owner_avatar_urls: [],
            owned_by_me: false,
            owner_user_ids: [],
            players: freeAgents,
          },
        ];

        if (selectedId) {
          const nextIndex = this.teams.findIndex(team => String(team?.id || '') === selectedId);
          if (nextIndex !== -1) this.i = nextIndex;
        }

        if (!this.freeAgentsPayloadLoaded) {
          this.searchPlayers = allPlayers;
        }
      },
      async loadFreeAgentsPayload(force = false){
        if (!force && this.freeAgentsPayloadLoaded) return true;
        if (this.freeAgentsPayloadLoading || !this.playersFreeAgentsPayloadUrl) return false;

        this.freeAgentsPayloadLoading = true;
        this.freeAgentsPayloadError = '';

        try {
          const response = await fetch(this.playersFreeAgentsPayloadUrl, {
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not load free agents.');
          }

          this.freeAgents = Array.isArray(payload.freeAgents) ? payload.freeAgents : [];
          this.searchPlayers = Array.isArray(payload.searchPlayers) ? payload.searchPlayers : this.combinedPlayers();
          this.freeAgentsPayloadLoaded = true;
          this.applyDeferredTeams();

          return true;
        } catch (error) {
          this.freeAgentsPayloadError = error?.message || 'Could not load free agents.';

          return false;
        } finally {
          this.freeAgentsPayloadLoading = false;
        }
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
      contractTypeCode(player){
        const contract = player?.fantasy_contract_code || null;

        return String(contract?.code || contract?.prefix || '').trim().toUpperCase();
      },
      contractTypeTitle(player){
        const contract = player?.fantasy_contract_code || null;
        const code = String(contract?.code || '').trim().toUpperCase();
        const label = String(contract?.label || '').trim();

        return [code, label].filter(Boolean).join(' - ');
      },
      rosterPlaceholderLabel(player){
        const slot = String(player?.roster_slot || '').trim();

        return slot ? `Open ${slot}` : 'Open slot';
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
      get capTeam(){
        const selected = String(this.capTeamId || '');

        return (this.teams || []).find(team => String(team?.id || '') === selected)
          || (this.teams || []).find(team => team?.owned_by_me === true)
          || (this.teams || []).find(team => (team?.owner_user_ids || []).includes(this.currentUserId))
          || null;
      },
      get capTeamOptions(){
        return (this.teams || []).filter(team => !['__all_players__', '__free_agents__'].includes(String(team?.id || '')));
      },
      async changeCapTeam(event){
        const nextTeamId = String(event?.target?.value || '');
        const previousTeamId = String(this.capTeamId || '');

        if (!nextTeamId || nextTeamId === previousTeamId || this.capTeamLoading) {
          event.target.value = previousTeamId;
          return;
        }

        this.capTeamLoading = true;
        this.capTeamError = '';

        const loaded = await this.loadPlayersPayload(true, false);

        if (!loaded) {
          event.target.value = previousTeamId;
          this.capTeamError = this.playersPayloadError || 'Could not load team cap data.';
          this.capTeamLoading = false;
          return;
        }

        this.ensureCapTeamSelection(nextTeamId);
        event.target.value = this.capTeamId;
        this.capTeamLoading = false;
      },
      teamOwnerAvatarUrls(team){
        if (Array.isArray(team?.owner_avatar_urls) && team.owner_avatar_urls.length > 0) {
          return team.owner_avatar_urls.filter(Boolean).slice(0, 3);
        }

        return team?.owner_avatar_url ? [team.owner_avatar_url] : [];
      },
      hasTeamOwnerAvatars(team){
        return this.teamOwnerAvatarUrls(team).length > 0;
      },
      get capSeasonColumns(){
        const seasons = new Map();
        const currentSeasonKey = this.currentCapSeasonKey();

        if (this.customCap) {
          const maxYearsRemaining = Math.max(1, ...(this.capTeam?.players || [])
            .map(player => Number(player?.fantasy_contract_code?.years_remaining || 0))
            .filter(value => Number.isFinite(value) && value > 0));

          return Array.from({ length: maxYearsRemaining }, (_, index) => {
            const startYear = Math.floor(currentSeasonKey / 10000) + index;
            const seasonKey = (startYear * 10000) + startYear + 1;

            return {
              key: String(seasonKey),
              label: this.capSeasonLabel(seasonKey),
              sort: seasonKey,
            };
          });
        }

        (this.capTeam?.players || []).forEach(player => {
          (player?.contract?.seasons || []).forEach(season => {
            const key = season?.season_key ?? season?.label;
            const sort = Number(season?.season_key || key || 0);

            if (key === null || key === undefined || key === '') return;
            if (sort > 0 && sort < currentSeasonKey) return;

            seasons.set(String(key), {
              key: String(key),
              label: season?.label || String(key),
              sort,
            });
          });
        });

        Object.keys(this.capLimitsBySeason || {}).forEach(key => {
          const sort = Number(key || 0);

          if (sort > 0 && sort >= currentSeasonKey) {
            seasons.set(String(key), {
              key: String(key),
              label: this.capSeasonLabel(key),
              sort,
            });
          }
        });

        Object.keys(this.capAdjustmentTotals()).forEach(key => {
          const sort = Number(key || 0);

          if (sort > 0 && sort >= currentSeasonKey) {
            seasons.set(String(key), {
              key: String(key),
              label: this.capSeasonLabel(key),
              sort,
            });
          }
        });

        Object.values(this.capTeam?.cap_contract_projections || {}).forEach(playerProjection => {
          Object.keys(playerProjection?.seasons || {}).forEach(key => {
            const sort = Number(key || 0);

            if (sort > 0 && sort >= currentSeasonKey) {
              seasons.set(String(key), {
                key: String(key),
                label: this.capSeasonLabel(key),
                sort,
              });
            }
          });
        });

        return Array.from(seasons.values()).sort((a, b) => a.sort - b.sort);
      },
      get capPlayers(){
        const positionOrder = { C: 10, LW: 20, RW: 30, D: 40, G: 50 };

        return [...(this.capTeam?.players || [])]
          .map(player => ({
            ...player,
            cap_position_key: this.capPositionKey(player),
            cap_group_order: player?.roster_group === 'minor' ? 1 : 0,
          }))
          .sort((a, b) => {
            if (a.cap_group_order !== b.cap_group_order) return a.cap_group_order - b.cap_group_order;

            if (this.capSortKey) {
              const sortResult = this.capSortValue(a, this.capSortKey, positionOrder) > this.capSortValue(b, this.capSortKey, positionOrder)
                ? 1
                : (this.capSortValue(a, this.capSortKey, positionOrder) < this.capSortValue(b, this.capSortKey, positionOrder) ? -1 : 0);

              if (sortResult !== 0) {
                return this.capSortDirection === 'asc' ? sortResult : -sortResult;
              }
            }

            const posDiff = (positionOrder[a.cap_position_key] || 90) - (positionOrder[b.cap_position_key] || 90);
            if (posDiff !== 0) return posDiff;

            return String(a.name || '').localeCompare(String(b.name || ''));
          });
      },
      get capDisplayRows(){
        const rows = [];

        this.capPlayers.forEach((player, index) => {
          if (player.cap_group_order === 1 && this.capPlayers?.[index - 1]?.cap_group_order !== 1) {
            rows.push({
              type: 'group',
              id: 'group-minors',
              label: 'Minors',
              player: {},
            });
          }

          rows.push({
            type: 'player',
            id: `player-${player.id}`,
            player,
          });
        });

        return rows;
      },
      get hasCustomCapSalaryData(){
        return (this.capTeam?.players || []).some(player => Number(player?.fantasy_salary?.cap_hit || 0) > 0);
      },
      get capTotals(){
        const totals = {};

        this.capSeasonColumns.forEach(column => {
          totals[column.key] = this.capPlayers.reduce((sum, player) => {
            if (player?.roster_group === 'minor') return sum;

            const season = this.capSeasonForPlayer(player, column.key);
            const value = Number(season?.cap_hit || 0);
            const projection = this.capProjectionForPlayer(player, column.key);
            const projectedValue = season ? 0 : Number(projection?.projected_aav || 0);

            return sum
              + (Number.isFinite(value) ? value : 0)
              + (Number.isFinite(projectedValue) ? projectedValue : 0);
          }, 0);
        });

        return totals;
      },
      get capProjectionTotals(){
        const totals = {};

        this.capSeasonColumns.forEach(column => {
          totals[column.key] = this.capPlayers.reduce((sum, player) => {
            if (player?.roster_group === 'minor') return sum;
            if (this.capSeasonForPlayer(player, column.key)) return sum;

            const projection = this.capProjectionForPlayer(player, column.key);
            const value = Number(projection?.projected_aav || 0);

            return sum + (Number.isFinite(value) ? value : 0);
          }, 0);
        });

        return totals;
      },
      get capSpaceTotals(){
        const totals = {};
        const adjustments = this.capAdjustmentTotals();

        this.capSeasonColumns.forEach(column => {
          const ceiling = this.capCeilingForSeason(column.key);
          const capHit = Number(this.capTotals[column.key] || 0);
          const adjustmentHit = Number(adjustments[column.key] || 0);

          totals[column.key] = Number.isFinite(ceiling) && ceiling > 0 ? ceiling - capHit - adjustmentHit : null;
        });

        return totals;
      },
      capPositionKey(player){
        const eligibility = this.capEligibilityValues(player);
        const raw = String(player?.position || '').toUpperCase();

        if (player?.is_goalie || raw === 'G') return 'G';
        if (eligibility.includes('C')) return 'C';
        if (eligibility.includes('LW') || eligibility.includes('L')) return 'LW';
        if (eligibility.includes('RW') || eligibility.includes('R')) return 'RW';
        if (eligibility.includes('D')) return 'D';
        if (eligibility.includes('G')) return 'G';
        if (raw === 'C') return 'C';
        if (raw === 'LW' || raw === 'L') return 'LW';
        if (raw === 'RW' || raw === 'R') return 'RW';
        if (raw.includes('D')) return 'D';

        return raw || '-';
      },
      capPositionLabel(player){
        const values = this.capEligibilityValues(player);
        const visible = values.filter(value => !['F', 'UTIL', 'UTILS', 'UTILITY', 'UTL', 'W/R/T'].includes(value));

        return visible.length ? visible.join('/') : this.capPositionKey(player);
      },
      capEligibilityValues(player){
        const raw = player?.eligibility;
        const values = Array.isArray(raw)
          ? raw
          : (typeof raw === 'object' && raw !== null ? Object.values(raw).flat() : [raw]);

        return values
          .filter(Boolean)
          .map(value => String(value).trim().toUpperCase())
          .filter(value => value !== '');
      },
      capSeasonForPlayer(player, key){
        if (this.customCap) {
          const currentSeasonKey = String(this.currentCapSeasonKey());
          const salary = player?.fantasy_salary || null;
          const yearsRemaining = Number(player?.fantasy_contract_code?.years_remaining || 0);
          const seasonOffset = this.capCustomSeasonOffset(key);
          const coveredYears = Number.isFinite(yearsRemaining) && yearsRemaining > 0 ? yearsRemaining : 1;

          // Fantrax custom salaries repeat through the parsed remaining contract term.
          if (seasonOffset < 0 || seasonOffset >= coveredYears || !salary?.cap_hit) return null;

          return {
            season_key: Number(key || currentSeasonKey),
            label: this.capSeasonLabel(key || currentSeasonKey),
            cap_hit: Number(salary.cap_hit || 0),
            cap_hit_label: salary.label || this.capMoney(salary.cap_hit),
          };
        }

        return (player?.contract?.seasons || []).find(season => String(season?.season_key ?? season?.label ?? '') === String(key)) || null;
      },
      capProjectionForPlayer(player, key){
        if (this.customCap || !player?.id || this.capSeasonForPlayer(player, key)) return null;

        return this.capTeam?.cap_contract_projections?.[String(player.id)]?.seasons?.[String(key)] || null;
      },
      capProjectionEditValue(player, key){
        const projection = this.capProjectionForPlayer(player, key);
        const value = Number(projection?.projected_aav || 0);

        if (!Number.isFinite(value) || value <= 0) return '';

        return String(value / 1000000).replace(/\.0+$/, '');
      },
      isFirstCapProjectionSeason(player, key){
        const seasons = this.capTeam?.cap_contract_projections?.[String(player?.id || '')]?.seasons || {};
        const keys = Object.keys(seasons)
          .map(value => Number(value || 0))
          .filter(value => Number.isFinite(value) && value > 0)
          .sort((a, b) => a - b);

        return keys.length > 0 && keys[0] === Number(key || 0);
      },
      normalizedCapExpiryStatus(player){
        const status = String(player?.contract?.expiry_status || '').trim().toUpperCase();

        if (status.includes('RFA')) return 'RFA';
        if (status.includes('UFA')) return 'UFA';

        return '';
      },
      isExpiredContractProjectionCandidate(player){
        const status = this.normalizedCapExpiryStatus(player);
        const lastSeasonKey = Number(player?.contract?.last_season_key || 0);
        const currentSeasonKey = Number(this.currentCapSeasonKey());

        if (!['UFA', 'RFA'].includes(status) || !lastSeasonKey || lastSeasonKey >= currentSeasonKey) return false;

        return !(player?.contract?.seasons || []).some(season => Number(season?.season_key || 0) >= currentSeasonKey);
      },
      capExpiryStatusBadge(player){
        const status = this.normalizedCapExpiryStatus(player);
        if (!['UFA', 'RFA'].includes(status)) return null;
        if (!this.capTeam?.cap_contract_projections?.[String(player?.id || '')] && !this.isExpiredContractProjectionCandidate(player)) return null;

        return {
          label: status,
          className: status === 'RFA'
            ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
            : 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        };
      },
      capExpiryBadge(player, key){
        if (this.customCap) return null;

        const status = this.normalizedCapExpiryStatus(player);

        if (!['RFA', 'UFA'].includes(status)) return null;

        const columnKey = Number(key || 0);
        const lastSeasonKey = Number(player?.contract?.last_season_key || 0);

        if (!columnKey || !lastSeasonKey || columnKey <= lastSeasonKey) return null;

        const futureColumns = this.capSeasonColumns
          .map(column => Number(column.key || 0))
          .filter(seasonKey => seasonKey > lastSeasonKey)
          .sort((a, b) => a - b);

        if (futureColumns[0] !== columnKey) return null;

        return {
          label: status,
          className: status === 'RFA'
            ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
            : 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        };
      },
      async saveCapProjection(player, key, value){
        if (!this.capProjectionsUpdateUrl || this.savingCapProjection || !this.capTeam?.platform_team_record_id || !player?.id) return;

        this.savingCapProjection = true;
        this.capSettingsMessage = '';
        this.capSettingsError = '';

        try {
          const response = await fetch(this.capProjectionsUpdateUrl, {
            method: 'PUT',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
              platform_team_id: this.capTeam.platform_team_record_id,
              projections: [{
                player_id: player.id,
                season_key: Number(key),
                projected_aav: value,
              }],
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not save cap projection.');
          }

          this.capTeam.cap_contract_projections = { ...(payload.projections || {}) };
          this.capSettingsMessage = payload.message || 'Cap projections saved.';
        } catch (error) {
          this.capSettingsError = error?.message || 'Could not save cap projection.';
        } finally {
          this.savingCapProjection = false;
        }
      },
      capCustomSeasonOffset(key){
        const currentSeasonKey = Number(this.currentCapSeasonKey());
        const seasonKey = Number(key || 0);

        if (!Number.isFinite(currentSeasonKey) || !Number.isFinite(seasonKey) || seasonKey <= 0) return -1;

        return Math.floor(seasonKey / 10000) - Math.floor(currentSeasonKey / 10000);
      },
      capSortValue(player, key, positionOrder){
        if (key === 'player') return String(player?.name || '').toLowerCase();
        if (key === 'position') return Number(positionOrder[player?.cap_position_key] || 90);
        if (key === 'age') return Number(player?.age || 0);
        if (key.startsWith('season:')) {
          const seasonKey = key.slice(7);
          const season = this.capSeasonForPlayer(player, seasonKey);
          const projection = this.capProjectionForPlayer(player, seasonKey);

          return Number(season?.cap_hit || projection?.projected_aav || 0);
        }

        return '';
      },
      setCapSort(key){
        if (this.capSortKey === key) {
          this.capSortDirection = this.capSortDirection === 'desc' ? 'asc' : 'desc';
          return;
        }

        this.capSortKey = key;
        this.capSortDirection = 'desc';
      },
      capSortIndicator(key){
        if (this.capSortKey !== key) return '';

        return this.capSortDirection === 'desc' ? '↓' : '↑';
      },
      currentCapSeasonKey(){
        const now = new Date();
        const year = now.getFullYear();
        // Fantrax custom salary seasons turn over on July 1.
        const startsThisYear = now.getMonth() >= 6;
        const startYear = startsThisYear ? year : year - 1;

        return (startYear * 10000) + startYear + 1;
      },
      capSeasonLabel(seasonKey){
        const value = String(seasonKey || '');

        if (value.length >= 8) {
          const startYear = Number(value.slice(0, 4));
          const endYear = Number(value.slice(4, 8));

          if (Number.isFinite(startYear) && Number.isFinite(endYear)) {
            return `${startYear}-${String(endYear).slice(-2)}`;
          }
        }

        return value;
      },
      capMoney(value){
        const number = Number(value || 0);

        if (!Number.isFinite(number) || number <= 0) return '-';

        return `$${(number / 1000000).toFixed(2)}M`;
      },
      capSpaceMoney(value){
        if (value === null || value === undefined) return '-';

        const number = Number(value);

        if (!Number.isFinite(number)) return '-';

        const sign = number < 0 ? '-' : '';

        return `${sign}$${(Math.abs(number) / 1000000).toFixed(2)}M`;
      },
      applyCapSettingsPayload(payload){
        this.customCap = Boolean(payload.customCap ?? this.customCap);
        this.salaryCap = payload.salaryCap ?? this.salaryCap;
        this.salaryCapInput = this.salaryCap ? String(this.salaryCap) : '';
        this.capLimitsBySeason = { ...(payload.capLimitsBySeason ?? this.capLimitsBySeason) };
        this.capLimitDrafts = { ...this.capLimitsBySeason };
        this.capAdjustmentsByTeam = { ...(payload.capAdjustmentsByTeam ?? this.capAdjustmentsByTeam) };
        this.maxActiveBuyouts = payload.maxActiveBuyouts ?? this.maxActiveBuyouts;
        this.maxActiveRetentions = payload.maxActiveRetentions ?? this.maxActiveRetentions;
        this.maxActiveBuyoutsInput = this.maxActiveBuyouts !== null && this.maxActiveBuyouts !== undefined ? String(this.maxActiveBuyouts) : '';
        this.maxActiveRetentionsInput = this.maxActiveRetentions !== null && this.maxActiveRetentions !== undefined ? String(this.maxActiveRetentions) : '';
        this.leagueSettingsSource = payload.leagueSettingsSource ?? this.leagueSettingsSource;
        this.canEditLeagueSettings = Boolean(payload.canEditLeagueSettings ?? this.canEditLeagueSettings);
        this.fantraxContractCodes = payload.fantraxContractCodes ?? this.fantraxContractCodes;
        this.fantraxContractCodeDefinitions = { ...(payload.fantraxContractCodeDefinitions ?? this.fantraxContractCodeDefinitions) };
      },
      async saveCapSettingsPayload(extraPayload, successMessage = 'Cap settings saved.'){
        if (!this.capSettingsUpdateUrl || this.savingCapSettings) return false;

        this.savingCapSettings = true;
        this.capSettingsMessage = '';
        this.capSettingsError = '';

        try {
          const response = await fetch(this.capSettingsUpdateUrl, {
            method: 'PUT',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
              custom_cap: this.customCap,
              ...extraPayload,
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not save cap settings.');
          }

          this.applyCapSettingsPayload(payload);
          this.capSettingsMessage = successMessage;

          return true;
        } catch (error) {
          this.capSettingsError = error?.message || 'Could not save cap settings.';

          return false;
        } finally {
          this.savingCapSettings = false;
        }
      },
      capSeasonKeys(count = 10){
        const current = Number(this.currentCapSeasonKey());
        const startYear = Math.floor(current / 10000);

        return Array.from({ length: count }, (_, index) => {
          const year = startYear + index;

          return String((year * 10000) + year + 1);
        });
      },
      get capLimitRows(){
        return this.capSeasonKeys(10).map(key => ({
          key,
          label: this.capSeasonLabel(key),
          floor: this.capLimitDrafts?.[key]?.floor ?? null,
          ceiling: this.capLimitDrafts?.[key]?.ceiling ?? null,
        }));
      },
      setCapLimitValue(seasonKey, field, value){
        const key = String(seasonKey || '');
        const existing = this.capLimitDrafts?.[key] ?? {};

        this.capLimitDrafts = {
          ...this.capLimitDrafts,
          [key]: {
            ...existing,
            [field]: value,
          },
        };
      },
      syncCapLimitInputs(){
        const next = { ...(this.capLimitDrafts || {}) };

        this.$root?.querySelectorAll('[data-cap-limit-season][data-cap-limit-field]')?.forEach(input => {
          const key = String(input.dataset.capLimitSeason || '');
          const field = String(input.dataset.capLimitField || '');

          if (!key || !['floor', 'ceiling'].includes(field)) return;

          next[key] = {
            ...(next[key] || {}),
            [field]: input.value,
          };
        });

        this.capLimitDrafts = next;

        return next;
      },
      async saveCapLimits(){
        const syncedLimits = this.syncCapLimitInputs();

        const saved = await this.saveCapSettingsPayload({
          salary_cap: this.salaryCapInput,
          cap_limits_by_season: syncedLimits,
        }, 'Cap limits saved.');

        if (saved) {
          this.capLimitDrafts = { ...this.capLimitsBySeason };
        }
      },
      async saveCapRuleLimits(){
        const buyouts = String(this.maxActiveBuyoutsInput || '').trim();
        const retentions = String(this.maxActiveRetentionsInput || '').trim();

        await this.saveCapSettingsPayload({
          max_active_buyouts: buyouts === '' ? null : Number(buyouts),
          max_active_retentions: retentions === '' ? null : Number(retentions),
        }, 'Cap options saved.');
      },
      capLimitForSeason(seasonKey, field){
        const value = this.capLimitsBySeason?.[String(seasonKey)]?.[field] ?? null;

        return Number(value || 0) > 0 ? Number(value) : null;
      },
      capCeilingForSeason(seasonKey){
        return this.capLimitForSeason(seasonKey, 'ceiling') ?? (Number(this.salaryCap || 0) > 0 ? Number(this.salaryCap) : null);
      },
      capFloorForSeason(seasonKey){
        return this.capLimitForSeason(seasonKey, 'floor');
      },
      get capTeamAdjustments(){
        const teamId = String(this.capTeamId || '');
        const rows = this.capAdjustmentsByTeam?.[teamId] ?? [];

        return Array.isArray(rows) ? rows : [];
      },
      capAdjustmentCount(type){
        return this.capTeamAdjustments.filter(row => String(row?.type || '') === type).length;
      },
      capAdjustmentLimit(type){
        return type === 'buyout' ? this.maxActiveBuyouts : this.maxActiveRetentions;
      },
      capAdjustmentUsageLabel(type){
        const limit = this.capAdjustmentLimit(type);
        const count = this.capAdjustmentCount(type);
        const label = type === 'buyout' ? 'Buyouts' : 'Retentions';

        return `${label}: ${count}${limit !== null && limit !== undefined && limit !== '' ? ` / ${limit}` : ''}`;
      },
      capAdjustmentUsageClass(type){
        const limit = Number(this.capAdjustmentLimit(type));

        if (!Number.isFinite(limit) || limit <= 0) return 'text-slate-600 ring-slate-200';

        return this.capAdjustmentCount(type) > limit
          ? 'text-red-700 ring-red-200'
          : 'text-slate-600 ring-slate-200';
      },
      capAdjustmentTotals(){
        const totals = {};

        this.capTeamAdjustments.forEach(adjustment => {
          Object.entries(adjustment?.values_by_season || {}).forEach(([seasonKey, value]) => {
            totals[seasonKey] = Number(totals[seasonKey] || 0) + Number(value || 0);
          });
        });

        return totals;
      },
      capAdjustmentValue(adjustment, seasonKey){
        return Number(adjustment?.values_by_season?.[String(seasonKey)] || 0);
      },
      capAdjustmentTypeLabel(type){
        return String(type || '') === 'retention' ? 'Retention' : 'Buyout';
      },
      capAdjustmentPlayerLabel(player){
        const name = String(player?.name || player?.player_name || '').trim();
        const position = String(player?.position || player?.player_position || '').trim();

        return position ? `${name}, ${position}` : name;
      },
      capAdjustmentInitials(player){
        return this.playerInitials({
          name: player?.name || player?.player_name || '',
          first_name: player?.first_name || '',
          last_name: player?.last_name || '',
        });
      },
      playerHasCurrentOrFutureContract(player){
        const currentSeason = Number(this.currentCapSeasonKey());

        return (player?.contract?.seasons || []).some(season => {
          const seasonKey = Number(season?.season_key || 0);
          const capHit = Number(season?.cap_hit || season?.aav || 0);

          return seasonKey >= currentSeason && capHit > 0;
        });
      },
      capAdjustmentPlayerCandidates(){
        const selectedTeamId = String(this.capTeamId || '');
        const query = String(this.capAdjustmentPlayerQuery || '').toLowerCase().trim();
        const selectedRosterIds = new Set((this.capTeam?.players || [])
          .map(player => String(player?.id || ''))
          .filter(Boolean));
        const otherTeamPlayers = this.realTeams()
          .filter(team => String(team?.id || '') !== selectedTeamId)
          .flatMap(team => Array.isArray(team?.players) ? team.players : []);
        const playersById = new Map();

        [...otherTeamPlayers, ...(this.freeAgents || [])]
          .filter(player => player?.type !== 'empty')
          .filter(player => !selectedRosterIds.has(String(player?.id || '')))
          .filter(player => this.playerHasCurrentOrFutureContract(player))
          .forEach(player => {
            const id = String(player?.id || '');
            if (id) playersById.set(id, player);
          });

        const players = Array.from(playersById.values());

        if (!query) return players.slice(0, 40);

        return players.filter(player => [
          player?.name,
          player?.team_abbrev,
          player?.position,
        ].filter(Boolean).some(value => String(value).toLowerCase().includes(query))).slice(0, 40);
      },
      capPlayerById(playerId){
        const id = Number(playerId || 0);

        return this.realTeams()
          .flatMap(team => Array.isArray(team?.players) ? team.players : [])
          .concat(this.freeAgents || [])
          .find(player => Number(player?.id || 0) === id) || null;
      },
      openCapAdjustmentDrawer(adjustment = null){
        if (adjustment) {
          this.capAdjustmentMode = 'edit';
          this.capAdjustmentEditingId = String(adjustment.id || '');
          this.capAdjustmentForm = {
            type: String(adjustment.type || 'buyout'),
            player_id: String(adjustment.player_id || ''),
            player_name: String(adjustment.player_name || ''),
            player_position: String(adjustment.player_position || ''),
            avatar_url: String(adjustment.avatar_url || ''),
            team_abbrev: String(adjustment.team_abbrev || ''),
            percent: Number(adjustment.percent || 50),
            start_season: String(adjustment.start_season || ''),
            end_season: String(adjustment.end_season || ''),
            values_by_season: { ...(adjustment.values_by_season || {}) },
          };
          this.capAdjustmentOverrides = Object.fromEntries(Object.keys(this.capAdjustmentForm.values_by_season || {}).map(key => [key, true]));
          this.capAdjustmentPlayerQuery = this.capAdjustmentForm.player_name;
        } else {
          const firstSeason = this.capSeasonKeys(1)[0] || '';
          this.capAdjustmentMode = 'create';
          this.capAdjustmentEditingId = '';
          this.capAdjustmentForm = {
            type: 'buyout',
            player_id: '',
            player_name: '',
            player_position: '',
            avatar_url: '',
            team_abbrev: '',
            percent: 50,
            start_season: firstSeason,
            end_season: '',
            values_by_season: {},
          };
          this.capAdjustmentOverrides = {};
          this.capAdjustmentPlayerQuery = '';
        }

        this.capAdjustmentPlayerOpen = false;
        this.capAdjustmentDrawerOpen = true;
        this.loadFreeAgentsPayload();
        this.recalculateCapAdjustmentValues(false);
      },
      selectCapAdjustmentPlayer(player){
        this.capAdjustmentForm.player_id = String(player?.id || '');
        this.capAdjustmentForm.player_name = String(player?.name || '');
        this.capAdjustmentForm.player_position = String(player?.position || '');
        this.capAdjustmentForm.avatar_url = String(player?.avatar_url || '');
        this.capAdjustmentForm.team_abbrev = String(player?.team_abbrev || '');
        this.capAdjustmentPlayerQuery = this.capAdjustmentForm.player_name;
        this.capAdjustmentPlayerOpen = false;
        this.recalculateCapAdjustmentValues(false);
      },
      capAdjustmentSeasonOptions(){
        const keys = new Set(this.capSeasonKeys(10));
        const player = this.capPlayerById(this.capAdjustmentForm.player_id);

        (player?.contract?.seasons || []).forEach(season => {
          const key = String(season?.season_key || '');
          if (key) keys.add(key);
        });

        return Array.from(keys).sort().map(key => ({
          key,
          label: this.capSeasonLabel(key),
        }));
      },
      capAdjustmentCalculatedRows(){
        return this.capAdjustmentSeasonOptions()
          .filter(option => Number(this.capAdjustmentForm.values_by_season?.[option.key] || 0) > 0)
          .map(option => ({
            ...option,
            value: this.capAdjustmentForm.values_by_season?.[option.key] ?? '',
          }));
      },
      setCapAdjustmentManualValue(seasonKey, value){
        this.capAdjustmentOverrides = {
          ...this.capAdjustmentOverrides,
          [seasonKey]: true,
        };
        this.capAdjustmentForm.values_by_season = {
          ...this.capAdjustmentForm.values_by_season,
          [seasonKey]: value,
        };
      },
      recalculateCapAdjustmentValues(clearOverrides = true){
        const player = this.capPlayerById(this.capAdjustmentForm.player_id);
        const percent = Number(this.capAdjustmentForm.percent || 0) / 100;
        const start = Number(this.capAdjustmentForm.start_season || 0);
        const end = Number(this.capAdjustmentForm.end_season || 0);
        const values = clearOverrides ? {} : { ...(this.capAdjustmentForm.values_by_season || {}) };
        const overrides = clearOverrides ? {} : this.capAdjustmentOverrides;

        if (!player || !Number.isFinite(percent) || percent <= 0) {
          this.capAdjustmentForm.values_by_season = values;
          this.capAdjustmentOverrides = overrides;
          return;
        }

        (player?.contract?.seasons || []).forEach(season => {
          const key = String(season?.season_key || '');
          const numericKey = Number(key || 0);
          const capHit = Number(season?.cap_hit || season?.aav || 0);

          if (!key || capHit <= 0) return;
          if (start && numericKey < start) return;
          if (end && numericKey > end) return;
          if (overrides[key]) return;

          values[key] = Math.round(capHit * percent);
        });

        this.capAdjustmentForm.values_by_season = values;
        this.capAdjustmentOverrides = overrides;
      },
      resetCapAdjustmentValues(){
        this.recalculateCapAdjustmentValues(true);
      },
      async saveCapAdjustment(){
        const teamId = String(this.capTeamId || '');
        if (!teamId) return;

        this.recalculateCapAdjustmentValues(false);

        const existing = [...this.capTeamAdjustments];
        const row = {
          id: this.capAdjustmentEditingId || `${Date.now()}-${Math.random().toString(16).slice(2)}`,
          type: this.capAdjustmentForm.type,
          player_id: this.capAdjustmentForm.player_id ? Number(this.capAdjustmentForm.player_id) : null,
          player_name: this.capAdjustmentForm.player_name,
          player_position: this.capAdjustmentForm.player_position,
          avatar_url: this.capAdjustmentForm.avatar_url,
          team_abbrev: this.capAdjustmentForm.team_abbrev,
          percent: Number(this.capAdjustmentForm.percent || 0),
          start_season: this.capAdjustmentForm.start_season || null,
          end_season: this.capAdjustmentForm.end_season || null,
          values_by_season: { ...(this.capAdjustmentForm.values_by_season || {}) },
        };
        const nextRows = this.capAdjustmentMode === 'edit'
          ? existing.map(adjustment => String(adjustment.id) === String(row.id) ? row : adjustment)
          : [...existing, row];
        const nextAdjustments = {
          ...this.capAdjustmentsByTeam,
          [teamId]: nextRows,
        };
        const saved = await this.saveCapSettingsPayload({
          cap_adjustments_by_team: nextAdjustments,
        }, 'Buyouts and retentions saved.');

        if (saved) {
          this.capAdjustmentDrawerOpen = false;
        }
      },
      async deleteCapAdjustment(adjustment){
        const teamId = String(this.capTeamId || '');
        if (!teamId) return;

        const nextAdjustments = {
          ...this.capAdjustmentsByTeam,
          [teamId]: this.capTeamAdjustments.filter(row => String(row.id) !== String(adjustment?.id || '')),
        };

        await this.saveCapSettingsPayload({
          cap_adjustments_by_team: nextAdjustments,
        }, 'Buyout or retention removed.');
      },
      async toggleCustomCap(){
        if (!this.capSettingsUpdateUrl || this.savingCapSettings) return;

        const previous = this.customCap;
        const next = !previous;
        this.savingCapSettings = true;
        this.customCap = next;
        this.capSettingsMessage = '';
        this.capSettingsError = '';

        try {
          const response = await fetch(this.capSettingsUpdateUrl, {
            method: 'PUT',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({ custom_cap: next }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not save cap settings.');
          }

          this.applyCapSettingsPayload(payload);
          this.capSettingsMessage = payload.message || 'Cap settings saved.';
          this.playersPayloadLoaded = false;
          await this.loadPlayersPayload(true, false);
          this.$nextTick(() => this.loadLeagueStats(true));
        } catch (error) {
          this.customCap = previous;
          this.capSettingsError = error?.message || 'Could not save cap settings.';
        } finally {
          this.savingCapSettings = false;
        }
      },
      contractCodeDefinition(prefix){
        const key = String(prefix || '').toUpperCase();

        if (!this.fantraxContractCodeDefinitions[key]) {
          this.fantraxContractCodeDefinitions[key] = {
            label: '',
            type: '',
            suffix_years_remaining: true,
          };
        }

        return this.fantraxContractCodeDefinitions[key];
      },
      contractCodeTypeValue(prefix){
        return this.contractCodeDefinition(prefix).type || '';
      },
      contractCodeLabelValue(prefix){
        return this.contractCodeDefinition(prefix).label || '';
      },
      contractCodeSuffixValue(prefix){
        return Boolean(this.contractCodeDefinition(prefix).suffix_years_remaining ?? true);
      },
      setContractCodeDefinition(prefix, field, value){
        const key = String(prefix || '').toUpperCase();
        const definition = this.contractCodeDefinition(key);
        definition[field] = value;
        this.fantraxContractCodeDefinitions = {
          ...this.fantraxContractCodeDefinitions,
          [key]: definition,
        };
      },
      async saveContractCodeDefinitions(){
        if (!this.capSettingsUpdateUrl || this.savingCapSettings) return;

        this.savingCapSettings = true;
        this.capSettingsMessage = '';
        this.capSettingsError = '';

        try {
          const response = await fetch(this.capSettingsUpdateUrl, {
            method: 'PUT',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
              custom_cap: this.customCap,
              contract_code_definitions: this.fantraxContractCodeDefinitions,
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not save contract codes.');
          }

          this.applyCapSettingsPayload(payload);
          this.capSettingsMessage = 'Contract codes saved.';
          this.playersPayloadLoaded = false;
          await this.loadPlayersPayload(true, false);
          this.$nextTick(() => this.loadLeagueStats(true));
        } catch (error) {
          this.capSettingsError = error?.message || 'Could not save contract codes.';
        } finally {
          this.savingCapSettings = false;
        }
      },
      async saveSalaryCap(){
        if (!this.capSettingsUpdateUrl || this.savingCapSettings) return;

        this.savingCapSettings = true;
        this.capSettingsMessage = '';
        this.capSettingsError = '';

        try {
          const response = await fetch(this.capSettingsUpdateUrl, {
            method: 'PUT',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
            body: JSON.stringify({
              custom_cap: this.customCap,
              salary_cap: this.salaryCapInput,
            }),
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not save salary cap.');
          }

          this.applyCapSettingsPayload(payload);
          this.capSettingsMessage = 'Salary cap saved.';
        } catch (error) {
          this.capSettingsError = error?.message || 'Could not save salary cap.';
        } finally {
          this.savingCapSettings = false;
        }
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
          this.scoringMappingOptions = payload.scoringMappingOptions ?? this.scoringMappingOptions;
          this.scoringMappingQueries = {};
          this.scoringMappingOpen = {};
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
      async syncTeamLogos(){
        if (!this.teamLogoSyncUrl || this.teamLogosSyncing) return;

        this.teamLogosSyncing = true;
        this.teamLogosMessage = '';
        this.teamLogosError = '';

        try {
          const response = await fetch(this.teamLogoSyncUrl, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            },
          });
          const payload = await response.json().catch(() => ({}));

          if (!response.ok) {
            throw new Error(payload.message || 'Could not sync team logos.');
          }

          window.dispatchEvent(new CustomEvent('league:logos-synced', {
            detail: {
              platform_league_id: payload.platform_league_id,
              platform: payload.platform,
              logo_url: payload.logo_url,
              refresh_from_server: true,
            },
          }));
          this.teamLogosMessage = payload.message || 'Team logos synced.';
          if (window.toast?.success) window.toast.success(this.teamLogosMessage);
        } catch (error) {
          this.teamLogosError = error?.message || 'Could not sync team logos.';
          if (window.toast?.error) window.toast.error(this.teamLogosError);
        } finally {
          this.teamLogosSyncing = false;
        }
      },
      async loadLeagueStats(force = false){
        if (!this.canShowLeagueStats) {
          console.warn('[DIQ] League stats skipped: canShowLeagueStats=false');
          return;
        }
        if (!this.leagueStatsPayloadUrl) {
          console.warn('[DIQ] League stats skipped: leagueStatsPayloadUrl missing');
          return;
        }
        if (this.leagueStatsLoading) {
          console.warn('[DIQ] League stats skipped: already loading');
          return;
        }
        if (this.leagueStatsShell && !force) {
          console.warn('[DIQ] League stats skipped: shell already mounted');
          return;
        }

        const mount = window.DIQ?.mountStatsPage;
        if (typeof mount !== 'function') {
          console.warn('[DIQ] League stats skipped: window.DIQ.mountStatsPage missing');
          return;
        }

        this.leagueStatsLoading = true;
        this.leagueStatsError = '';

        const container = this.$refs.leagueStats;
        if (!container) {
          console.warn('[DIQ] League stats skipped: leagueStats ref missing');
          this.leagueStatsLoading = false;
          return;
        }

        try {
          delete container.dataset.statsMounted;
          this.leagueStatsShell = mount(container, {
            initialPayload: {},
            initialLoading: true,
            apiUrl: this.leagueStatsPayloadUrl,
            perspectives: this.leagueStatsPerspectives?.length
              ? this.leagueStatsPerspectives
              : [
                {
                  slug: @js($leagueStatsFallbackSlug),
                  name: @js($leagueStatsFallbackName),
                },
              ],
            selectedPerspective: this.selectedLeagueStatsPerspective || @js($leagueStatsFallbackSlug),
            mobileBreakpoint: @js(config('viewports.mobile', 640)),
            syncUrl: false,
          });

          await this.leagueStatsShell?.fetchPayload?.({ force: true });
        } catch (error) {
          this.leagueStatsError = error?.message || 'Could not load league stats.';
        } finally {
          this.leagueStatsLoading = false;
        }
      }
    }"
    x-cloak
  >
    <div class="shrink-0 overflow-hidden bg-white">
      <div class="relative min-h-44 overflow-hidden bg-slate-950 text-white">
        <div class="absolute inset-0 bg-[linear-gradient(110deg,rgba(2,6,23,0.98),rgba(15,23,42,0.86)_46%,rgba(29,78,216,0.72)),radial-gradient(circle_at_78%_16%,rgba(96,165,250,0.62),transparent_25%)]" aria-hidden="true"></div>
        <div class="absolute inset-x-0 bottom-0 h-px bg-blue-300/70 shadow-[0_0_28px_rgba(96,165,250,0.92)]" aria-hidden="true"></div>
        @if ($canEditLeagueSettings)
        <button
          type="button"
          class="absolute right-4 top-4 z-10 inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/15 bg-white/10 text-white/90 shadow-sm transition-colors hover:bg-white/15 hover:text-white focus:outline-none focus:ring-2 focus:ring-blue-200/70"
          @click="settingsOpen = true"
          aria-label="League settings"
          title="League settings"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
          </svg>
        </button>
        @endif

        <div class="relative flex min-h-44 items-end justify-between gap-6 px-7 pb-6 pt-8">
          <div class="flex min-w-0 items-end gap-5">
            <div class="flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl border border-white/15 bg-white/10 shadow-2xl ring-1 ring-blue-200/20">
              <svg viewBox="0 0 64 64" fill="none" class="h-16 w-16 text-blue-100" aria-hidden="true">
                <path d="M12 45 32 54l20-9V18L32 9l-20 9v27Z" fill="currentColor" opacity=".16"/>
                <path d="M17 41 32 48l15-7V22L32 16l-15 6v19Z" stroke="currentColor" stroke-width="3"/>
                <path d="m21 42 25-22M43 44 18 22" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
              </svg>
            </div>
            <div class="min-w-0 pb-1">
              <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-blue-100/80">
                <span>{{ ucfirst((string) $league->platform) }}</span>
                <span class="text-blue-200/40">/</span>
                <span>{{ $teamCountForHeader }} {{ $teamCountLabel }}</span>
                <span class="text-blue-200/40">/</span>
                <span>Head-to-Head</span>
              </div>
              <h2 class="mt-2 truncate text-3xl font-semibold tracking-tight text-white">{{ $league->name }}</h2>
              <div class="mt-4 inline-flex max-w-full items-center gap-2 rounded-full bg-black/35 px-3 py-1.5 text-xs font-semibold text-blue-50 ring-1 ring-white/10">
                @if (! empty($ownedTeamForHeader['owner_avatar_url']))
                  <img src="{{ $ownedTeamForHeader['owner_avatar_url'] }}" alt="" class="h-5 w-5 rounded-full object-cover ring-1 ring-white/20">
                @else
                  <span class="h-2 w-2 rounded-full bg-blue-300"></span>
                @endif
                <span class="shrink-0 text-blue-100/80">Your Team:</span>
                <span class="truncate">{{ $ownedTeamForHeader['name'] ?? 'Not linked' }}</span>
              </div>
            </div>
          </div>

          <div class="hidden shrink-0 lg:block" aria-hidden="true"></div>
        </div>
      </div>
    </div>

    <div class="mb-4 flex shrink-0 items-center gap-7 border-b border-slate-200 px-7">
      <button type="button" class="border-b-2 border-transparent py-3 text-sm font-semibold text-slate-500 transition hover:text-slate-800">
        Overview
      </button>
      <button
        type="button"
        class="border-b-2 py-3 text-sm font-semibold transition"
        :class="activeLeagueTab === 'draft' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="openLeagueTab('draft')"
      >
        Draft
      </button>
      <button
        type="button"
        class="border-b-2 py-3 text-sm font-semibold transition"
        :class="activeLeagueTab === 'players' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="openLeagueTab('players')"
      >
        Players
      </button>
      <button
        type="button"
        class="border-b-2 py-3 text-sm font-semibold transition"
        :class="activeLeagueTab === 'cap' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="openLeagueTab('cap')"
      >
        Cap
      </button>
      <button type="button" class="border-b-2 border-transparent py-3 text-sm font-semibold text-slate-500 transition hover:text-slate-800">
        Standings
      </button>
      <button type="button" class="border-b-2 border-transparent py-3 text-sm font-semibold text-slate-500 transition hover:text-slate-800">
        Activity
      </button>
    </div>

    <div class="min-h-0 flex-1 overflow-hidden">
    <div x-show="activeLeagueTab === 'players'" class="h-full overflow-y-auto px-6 pb-6">
    <div x-show="!canShowLeagueStats && playersPayloadLoading" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="animate-pulse space-y-4">
        <div class="flex items-center justify-between gap-4">
          <div class="h-9 w-64 rounded-md bg-slate-200"></div>
          <div class="h-9 w-56 rounded-md bg-slate-200"></div>
        </div>
        <div class="space-y-2">
          <div class="h-10 rounded-lg bg-slate-100"></div>
          <div class="h-10 rounded-lg bg-slate-100"></div>
          <div class="h-10 rounded-lg bg-slate-100"></div>
          <div class="h-10 rounded-lg bg-slate-100"></div>
          <div class="h-10 rounded-lg bg-slate-100"></div>
        </div>
      </div>
    </div>
    <div x-show="!canShowLeagueStats && playersPayloadError" class="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="playersPayloadError"></div>
    <div x-show="!canShowLeagueStats && playersPayloadLoaded">
      <x-card-section title="Players" class="border-0">
        <div class="space-y-5">
          <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:items-center">
            <div>
              <label for="league-player-search" class="sr-only">Search players</label>
              <input
                id="league-player-search"
                type="search"
                x-model.debounce.100ms="playerSearch"
                @focus="loadFreeAgentsPayload()"
                @input.debounce.200ms="loadFreeAgentsPayload()"
                class="block w-full rounded-md bg-white py-2 pl-3 pr-3 text-sm text-slate-900 outline outline-1 -outline-offset-1 outline-slate-300 placeholder:text-slate-400 focus:outline focus:outline-2 focus:-outline-offset-2 focus:outline-indigo-600"
                placeholder="Search all players..."
                autocomplete="off"
              />
              <div x-show="freeAgentsPayloadLoading" class="mt-1 text-xs text-slate-500">Loading free agents...</div>
              <div x-show="freeAgentsPayloadError" class="mt-1 flex items-center gap-2 text-xs text-red-600">
                <span x-text="freeAgentsPayloadError"></span>
                <button
                  type="button"
                  class="font-semibold text-red-700 underline-offset-2 hover:underline"
                  @click="loadFreeAgentsPayload(true)"
                >
                  Retry
                </button>
              </div>
            </div>

            {{-- Combobox --}}
            <div class="relative z-50" @click.stop>
              <label for="team-combobox" class="block text-sm font-medium text-slate-900 sr-only">Team</label>
              <div class="flex items-center gap-2">
                <template x-if="hasTeamOwnerAvatars(current)">
                  <div class="isolate flex -space-x-2 overflow-hidden">
                    <template x-for="(avatar, index) in teamOwnerAvatarUrls(current)" :key="`selected-team-owner-${index}-${avatar}`">
                      <img
                        :src="avatar"
                        alt=""
                        class="relative h-8 w-8 rounded-full object-cover ring-2 ring-white"
                        :class="index === 0 ? 'z-30' : (index === 1 ? 'z-20' : 'z-10')"
                        loading="lazy"
                      >
                    </template>
                  </div>
                </template>
                <span
                  x-show="!hasTeamOwnerAvatars(current)"
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
                       class="absolute z-50 mt-1 max-h-56 w-full overflow-auto rounded-md bg-white p-1 text-sm shadow-lg outline outline-1 outline-black/5">
                    <template x-for="o in filtered" :key="o.idx">
                      <button type="button"
                              class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-slate-900 hover:bg-indigo-600 hover:text-white"
                              @click="select(o.idx)">
                        <template x-if="hasTeamOwnerAvatars(o.t)">
                          <div class="isolate flex -space-x-1.5 overflow-hidden">
                            <template x-for="(avatar, index) in teamOwnerAvatarUrls(o.t)" :key="`team-owner-${o.t.id}-${index}-${avatar}`">
                              <img
                                :src="avatar"
                                class="relative h-6 w-6 shrink-0 rounded-full object-cover ring-2 ring-white"
                                :class="index === 0 ? 'z-30' : (index === 1 ? 'z-20' : 'z-10')"
                                alt=""
                                loading="lazy"
                              >
                            </template>
                          </div>
                        </template>
                        <template x-if="!hasTeamOwnerAvatars(o.t)">
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

            <div class="divide-y divide-slate-200">
	              <template x-for="(p, playerIndex) in filteredPlayers" :key="p.id">
	                <div>
	                  <div
	                    x-show="shouldShowRosterSections() && p.roster_group === 'minor' && (filteredPlayers?.[playerIndex - 1]?.roster_group !== 'minor')"
	                    class="bg-blue-100 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-blue-700"
	                  >
	                    Minor League
	                  </div>
	                  <div class="flex items-center gap-2 px-3 py-1.5" :class="rosterRowClass(p)">
                    <span
                      class="inline-flex h-6 w-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600"
                      x-text="p.roster_slot || '-'"
                    ></span>
                    <span
                      x-show="contractTypeCode(p)"
                      class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200"
                      :title="contractTypeTitle(p)"
                      x-text="contractTypeCode(p)"
                    ></span>
                    <template x-if="p.league_roster_placeholder">
                      <span
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-50 text-[10px] font-semibold text-slate-400 ring-1 ring-slate-200"
                        aria-hidden="true"
                      >-</span>
                    </template>
                    <template x-if="p.avatar_url && !p.league_roster_placeholder">
                      <img
                        :src="p.avatar_url"
                        alt=""
                        class="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-slate-200"
                        loading="lazy"
                      >
                    </template>
                    <template x-if="!p.avatar_url && !p.league_roster_placeholder">
                      <span
                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 ring-1 ring-slate-200"
                        x-text="playerInitials(p)"
                      ></span>
                    </template>
	                    <div class="min-w-0">
	                      <div
                          class="truncate text-sm font-medium"
                          :class="p.league_roster_placeholder ? 'text-slate-400' : 'text-slate-900'"
	                           x-text="p.league_roster_placeholder ? rosterPlaceholderLabel(p) : (p.name || [p.first_name, p.last_name].filter(Boolean).join(' '))"></div>
	                      <div x-show="!p.league_roster_placeholder" class="text-[11px] text-slate-500">
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

    <div x-show="canShowLeagueStats" class="mt-6">
      <div x-show="leagueStatsError" class="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="leagueStatsError"></div>
      <div x-ref="leagueStats" class="min-h-[24rem]"></div>
    </div>
    </div>

    <div x-show="activeLeagueTab === 'cap'" class="px-6 pb-12">
      <div x-show="playersPayloadLoading" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="animate-pulse space-y-4">
          <div class="h-8 w-56 rounded-md bg-slate-200"></div>
          <div class="space-y-2">
            <div class="h-11 rounded-lg bg-slate-100"></div>
            <div class="h-11 rounded-lg bg-slate-100"></div>
            <div class="h-11 rounded-lg bg-slate-100"></div>
          </div>
        </div>
      </div>
      <div x-show="playersPayloadError" class="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700" x-text="playersPayloadError"></div>
      <div x-show="playersPayloadLoaded">
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div class="min-w-0">
              <h3 class="text-sm font-semibold text-slate-950">Cap</h3>
              <p class="mt-1 truncate text-xs text-slate-500" x-text="capTeam ? `${capTeam.name} ${customCap ? 'Fantrax salary outlook' : 'contract outlook'}` : 'No fantasy team is linked for this league.'"></p>
            </div>
            <div x-show="capTeam" class="flex flex-wrap items-center justify-end gap-3">
              <div class="inline-flex rounded-md bg-slate-100 p-0.5">
                <button type="button" class="rounded px-3 py-1.5 text-xs font-semibold transition-colors" :class="capView === 'sheet' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-900'" @click="setCapView('sheet')">Sheet</button>
                <button type="button" class="rounded px-3 py-1.5 text-xs font-semibold transition-colors" :class="capView === 'adjustments' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-900'" @click="setCapView('adjustments')">Buyouts &amp; Retentions</button>
                <button type="button" class="rounded px-3 py-1.5 text-xs font-semibold transition-colors" :class="capView === 'limits' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-600 hover:text-slate-900'" @click="setCapView('limits')">Limits</button>
              </div>
              <label class="block">
                <span class="sr-only">Cap team</span>
                <select
                  class="block w-56 rounded-md border-0 bg-white py-1.5 pl-3 pr-9 text-sm font-medium text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 transition focus:ring-2 focus:ring-inset focus:ring-indigo-600 disabled:cursor-wait disabled:bg-slate-50 disabled:text-slate-500"
                  :value="capTeamId"
                  :disabled="capTeamLoading"
                  @change="changeCapTeam"
                >
                  <template x-for="team in capTeamOptions" :key="team.id">
                    <option :value="team.id" x-text="team.name"></option>
                  </template>
                </select>
              </label>
              <div class="min-w-16 text-right text-xs text-slate-500">
                <div>
                  <span class="font-semibold text-slate-700" x-text="capPlayers.length"></span>
                  <span x-text="capPlayers.length === 1 ? 'player' : 'players'"></span>
                </div>
                <div x-show="capTeamLoading" class="mt-1 text-[11px] text-slate-500">Loading...</div>
              </div>
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-600"
                @click="capSettingsDrawerOpen = true"
                aria-label="Cap options"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                  <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.53 1.53 0 0 1-2.29.95c-1.37-.84-2.94.73-2.1 2.1.54.89.06 2.05-.95 2.29-1.56.38-1.56 2.6 0 2.98 1.01.24 1.49 1.4.95 2.29-.84 1.37.73 2.94 2.1 2.1.89-.54 2.05-.06 2.29.95.38 1.56 2.6 1.56 2.98 0 .24-1.01 1.4-1.49 2.29-.95 1.37.84 2.94-.73 2.1-2.1-.54-.89-.06-2.05.95-2.29 1.56-.38 1.56-2.6 0-2.98a1.53 1.53 0 0 1-.95-2.29c.84-1.37-.73-2.94-2.1-2.1-.89.54-2.05.06-2.29-.95ZM10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd" />
                </svg>
              </button>
            </div>
          </div>
          <div x-show="capTeamError" class="border-b border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700" x-text="capTeamError"></div>

          <template x-if="!capTeam">
            <div class="px-4 py-8 text-sm text-slate-500">No fantasy team is linked to your account for this league.</div>
          </template>

          <template x-if="capTeam && capPlayers.length === 0">
            <div class="px-4 py-8 text-sm text-slate-500">No roster players are available for your fantasy team.</div>
          </template>

          <template x-if="capTeam && capPlayers.length > 0 && (capSeasonColumns.length === 0 || (customCap && !hasCustomCapSalaryData))">
            <div class="px-4 py-8 text-sm text-slate-500" x-text="customCap ? 'No Fantrax salary data is available for your roster yet.' : 'No contract data is available for your roster yet.'"></div>
          </template>

          <div x-show="capView === 'sheet' && capTeam && capPlayers.length > 0 && capSeasonColumns.length > 0 && (!customCap || hasCustomCapSalaryData)" class="max-h-[calc(100vh-20rem)] min-h-[20rem] overflow-auto pb-12 pr-2">
            <table class="min-w-max divide-y divide-slate-200 text-sm">
              <thead class="sticky top-0 z-20 bg-slate-50">
                <tr
                  id="cap-summary-total-row"
                  x-show="capShowSummaryRows"
                  x-transition:enter="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:enter-start="opacity-0"
                  x-transition:enter-end="opacity-100"
                  x-transition:leave="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:leave-start="opacity-100"
                  x-transition:leave-end="opacity-0"
                  class="bg-sky-50"
                >
                  <th
                    scope="row"
                    :colspan="4 + (customCap ? 1 : 0)"
                    class="sticky left-0 z-10 bg-sky-50 px-2 py-1 text-left text-[10px] font-semibold uppercase tracking-wide text-sky-700"
                  >
                    <button type="button" class="inline-flex items-center gap-1 hover:text-sky-900" :aria-expanded="capShowLimitRows.toString()" aria-controls="cap-summary-projections-row cap-summary-ceiling-row cap-summary-floor-row" @click="toggleCapLimitRows()">
                      <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="capShowLimitRows ? 'rotate-90' : ''" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 1 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" />
                      </svg>
                      <span x-text="customCap ? 'Projected custom cap' : 'Projected cap hit'"></span>
                    </button>
                  </th>
                  <template x-for="(column, index) in capSeasonColumns" :key="`header-total-${column.key}`">
                    <td class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <span
                        class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 ring-1 ring-sky-200"
                        x-text="capMoney(capTotals[column.key])"
                      ></span>
                    </td>
                  </template>
                  <td class="w-3 px-0 py-1" aria-hidden="true"></td>
                </tr>
                <tr
                  id="cap-summary-projections-row"
                  x-show="capShowSummaryRows && capShowLimitRows && !customCap"
                  x-transition:enter="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:enter-start="opacity-0"
                  x-transition:enter-end="opacity-100"
                  x-transition:leave="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:leave-start="opacity-100"
                  x-transition:leave-end="opacity-0"
                  class="bg-violet-50"
                >
                  <th
                    scope="row"
                    :colspan="4 + (customCap ? 1 : 0)"
                    class="sticky left-0 z-10 bg-violet-50 px-2 py-1 text-left text-[10px] font-semibold uppercase tracking-wide text-violet-700"
                  >
                    Projections
                  </th>
                  <template x-for="(column, index) in capSeasonColumns" :key="`header-projections-${column.key}`">
                    <td class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <span
                        class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-slate-200"
                        x-text="capMoney(capProjectionTotals[column.key])"
                      ></span>
                    </td>
                  </template>
                  <td class="w-3 px-0 py-1" aria-hidden="true"></td>
                </tr>
                <tr
                  id="cap-summary-ceiling-row"
                  x-show="capShowSummaryRows && capShowLimitRows"
                  x-transition:enter="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:enter-start="opacity-0"
                  x-transition:enter-end="opacity-100"
                  x-transition:leave="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:leave-start="opacity-100"
                  x-transition:leave-end="opacity-0"
                  class="bg-slate-50"
                >
                  <th
                    scope="row"
                    :colspan="4 + (customCap ? 1 : 0)"
                    class="sticky left-0 z-10 bg-slate-50 px-2 py-1 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-600"
                  >
                    Cap ceiling
                  </th>
                  <template x-for="(column, index) in capSeasonColumns" :key="`header-ceiling-${column.key}`">
                    <td class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <span
                        class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-slate-200"
                        x-text="capMoney(capCeilingForSeason(column.key))"
                      ></span>
                    </td>
                  </template>
                  <td class="w-3 px-0 py-1" aria-hidden="true"></td>
                </tr>
                <tr
                  id="cap-summary-floor-row"
                  x-show="capShowSummaryRows && capShowLimitRows"
                  x-transition:enter="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:enter-start="opacity-0"
                  x-transition:enter-end="opacity-100"
                  x-transition:leave="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:leave-start="opacity-100"
                  x-transition:leave-end="opacity-0"
                  class="bg-slate-50"
                >
                  <th
                    scope="row"
                    :colspan="4 + (customCap ? 1 : 0)"
                    class="sticky left-0 z-10 bg-slate-50 px-2 py-1 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-600"
                  >
                    Cap floor
                  </th>
                  <template x-for="(column, index) in capSeasonColumns" :key="`header-floor-${column.key}`">
                    <td class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <span
                        class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-slate-200"
                        x-text="capMoney(capFloorForSeason(column.key))"
                      ></span>
                    </td>
                  </template>
                  <td class="w-3 px-0 py-1" aria-hidden="true"></td>
                </tr>
                <tr
                  id="cap-summary-adjustments-row"
                  x-show="capShowSummaryRows"
                  x-transition:enter="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:enter-start="opacity-0"
                  x-transition:enter-end="opacity-100"
                  x-transition:leave="transition-opacity duration-300 ease-out motion-reduce:transition-none"
                  x-transition:leave-start="opacity-100"
                  x-transition:leave-end="opacity-0"
                  class="bg-amber-50"
                >
                  <th
                    scope="row"
                    :colspan="4 + (customCap ? 1 : 0)"
                    class="sticky left-0 z-10 bg-amber-50 px-2 py-1 text-left text-[10px] font-semibold uppercase tracking-wide text-amber-700"
                  >
                    Buyouts &amp; retentions
                  </th>
                  <template x-for="(column, index) in capSeasonColumns" :key="`header-adjustments-${column.key}`">
                    <td class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <span
                        class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-amber-200"
                        x-text="capMoney(capAdjustmentTotals()[column.key])"
                      ></span>
                    </td>
                  </template>
                  <td class="w-3 px-0 py-1" aria-hidden="true"></td>
                </tr>
                <tr class="bg-emerald-50">
                  <th
                    scope="row"
                    :colspan="4 + (customCap ? 1 : 0)"
                    class="sticky left-0 z-10 bg-emerald-50 px-2 py-1 text-left text-[10px] font-semibold uppercase tracking-wide text-emerald-700"
                  >
                    <button type="button" class="inline-flex items-center gap-1 hover:text-emerald-900" :aria-expanded="capShowSummaryRows.toString()" aria-controls="cap-summary-total-row cap-summary-projections-row cap-summary-ceiling-row cap-summary-floor-row cap-summary-adjustments-row" @click="toggleCapSummaryRows()">
                      <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="capShowSummaryRows ? 'rotate-90' : ''" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.22 14.78a.75.75 0 0 1 0-1.06L10.94 10 7.22 6.28a.75.75 0 1 1 1.06-1.06l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd" />
                      </svg>
                      <span>Cap space</span>
                    </button>
                  </th>
                  <template x-for="(column, index) in capSeasonColumns" :key="`header-space-${column.key}`">
                    <td class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <span
                        class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                        :class="Number(capSpaceTotals[column.key] || 0) < 0 ? 'text-red-700 ring-red-200' : 'text-emerald-700 ring-emerald-200'"
                        x-text="capSpaceMoney(capSpaceTotals[column.key])"
                      ></span>
                    </td>
                  </template>
                  <td class="w-3 px-0 py-1" aria-hidden="true"></td>
                </tr>
                <tr>
                  <th scope="col" class="sticky left-0 z-10 w-56 bg-slate-50 px-2 py-1.5 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                    <button type="button" class="inline-flex items-center gap-1 hover:text-slate-800" @click="setCapSort('player')">
                      Player <span class="text-slate-400" x-text="capSortIndicator('player')"></span>
                    </button>
                  </th>
                  <th scope="col" class="w-7 px-0 py-1.5 text-center text-[10px] font-semibold uppercase tracking-wide text-slate-500" aria-label="Free agent status"></th>
                  <th scope="col" class="w-12 px-1.5 py-1.5 pl-4 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                    <button type="button" class="inline-flex items-center gap-1 hover:text-slate-800" @click="setCapSort('position')">
                      Pos <span class="text-slate-400" x-text="capSortIndicator('position')"></span>
                    </button>
                  </th>
                  <th scope="col" class="w-10 px-1 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                    <button type="button" class="inline-flex items-center justify-end gap-1 hover:text-slate-800" @click="setCapSort('age')">
                      Age <span class="text-slate-400" x-text="capSortIndicator('age')"></span>
                    </button>
                  </th>
                  <th x-show="customCap" scope="col" class="w-10 py-1.5 pl-3 pr-1 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">Type</th>
                  <template x-for="(column, index) in capSeasonColumns" :key="column.key">
                    <th scope="col" class="px-1 py-1.5 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                      <button type="button" class="inline-flex items-center justify-end gap-1 hover:text-slate-800" @click="setCapSort(`season:${column.key}`)">
                        <span x-text="column.label"></span>
                        <span class="text-slate-400" x-text="capSortIndicator(`season:${column.key}`)"></span>
                      </button>
                    </th>
                  </template>
                  <th scope="col" class="w-3 px-0 py-1.5" aria-hidden="true"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 bg-white">
                <template x-for="row in capDisplayRows" :key="row.id">
                  <tr>
                    <td
                      x-show="row.type === 'group'"
                      :colspan="5 + capSeasonColumns.length + (customCap ? 1 : 0)"
                      class="bg-slate-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500"
                      x-text="row.label"
                    ></td>
                    <td x-show="row.type === 'player'" class="sticky left-0 z-10 bg-white px-2 py-1">
                      <div class="flex min-w-56 items-center gap-2">
                        <template x-if="row.player.avatar_url">
                          <img :src="row.player.avatar_url" alt="" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                        </template>
                        <template x-if="!row.player.avatar_url">
                          <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[9px] font-semibold text-slate-500 ring-1 ring-slate-200" x-text="playerInitials(row.player)"></span>
                        </template>
                        <div class="min-w-0">
                          <div class="flex min-w-0 items-center gap-1.5">
                            <span class="truncate text-xs font-medium text-slate-900" x-text="row.player.name"></span>
                          </div>
                          <div class="text-[11px] text-slate-500">
                            <span x-text="row.player.team_abbrev || '-'"></span>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td x-show="row.type === 'player'" class="px-0 py-1 text-center">
                      <template x-if="capExpiryStatusBadge(row.player)">
                        <span
                          class="inline-flex h-4 shrink-0 items-center rounded px-1 text-[9px] font-bold ring-1"
                          :class="capExpiryStatusBadge(row.player).className"
                          x-text="capExpiryStatusBadge(row.player).label"
                        ></span>
                      </template>
                    </td>
                    <td x-show="row.type === 'player'" class="px-1.5 py-1 pl-4 text-xs font-medium text-slate-700" x-text="capPositionLabel(row.player)"></td>
                    <td x-show="row.type === 'player'" class="px-1 py-1 text-right text-xs font-medium text-slate-700" x-text="row.player.age ?? '-'"></td>
                    <td x-show="customCap && row.type === 'player'" class="py-1 pl-3 pr-1 text-left">
                      <span
                        x-show="contractTypeCode(row.player)"
                        class="inline-flex h-5 min-w-5 items-center justify-center rounded bg-slate-100 px-1 text-[10px] font-semibold text-slate-600 ring-1 ring-slate-200"
                        :title="contractTypeTitle(row.player)"
                        x-text="contractTypeCode(row.player)"
                      ></span>
                    </td>
                    <template x-for="(column, index) in capSeasonColumns" :key="`${row.id}-${column.key}`">
                      <td x-show="row.type === 'player'" class="px-1 py-1 text-right" :class="index === 0 ? 'w-24 pl-5' : 'w-16'">
                        <template x-if="capSeasonForPlayer(row.player, column.key)?.cap_hit_label && capSeasonForPlayer(row.player, column.key)?.cap_hit_label !== '-'">
                          <span
                            class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-slate-200"
                            x-text="capSeasonForPlayer(row.player, column.key)?.cap_hit_label"
                          ></span>
                        </template>
                        <template x-if="!(capSeasonForPlayer(row.player, column.key)?.cap_hit_label && capSeasonForPlayer(row.player, column.key)?.cap_hit_label !== '-') && capProjectionForPlayer(row.player, column.key) && isFirstCapProjectionSeason(row.player, column.key)">
                          <div class="inline-flex items-center gap-1">
                            <span class="text-[10px] font-semibold text-slate-500">$</span>
                            <input
                              type="text"
                              inputmode="decimal"
                              class="h-6 w-14 rounded border-0 bg-slate-50 px-1 text-right text-[10px] font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-400 transition-colors focus:bg-white focus:ring-2 focus:ring-inset focus:ring-indigo-600 disabled:cursor-wait disabled:opacity-60"
                              :value="capProjectionEditValue(row.player, column.key)"
                              :disabled="savingCapProjection"
                              :aria-label="`Projected AAV for ${row.player.name} in ${column.label}`"
                              @change="saveCapProjection(row.player, column.key, $event.target.value)"
                            >
                          </div>
                        </template>
                        <template x-if="!(capSeasonForPlayer(row.player, column.key)?.cap_hit_label && capSeasonForPlayer(row.player, column.key)?.cap_hit_label !== '-') && capProjectionForPlayer(row.player, column.key) && !isFirstCapProjectionSeason(row.player, column.key)">
                          <span
                            class="inline-flex h-5 min-w-14 items-center justify-center rounded bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 ring-1 ring-slate-200"
                            x-text="capProjectionForPlayer(row.player, column.key)?.projected_aav_label"
                          ></span>
                        </template>
                        <template x-if="!(capSeasonForPlayer(row.player, column.key)?.cap_hit_label && capSeasonForPlayer(row.player, column.key)?.cap_hit_label !== '-') && !capProjectionForPlayer(row.player, column.key) && capExpiryBadge(row.player, column.key)">
                          <span
                            class="inline-flex h-5 min-w-14 items-center justify-center rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                            :class="capExpiryBadge(row.player, column.key).className"
                            x-text="capExpiryBadge(row.player, column.key).label"
                          ></span>
                        </template>
                        <template x-if="!(capSeasonForPlayer(row.player, column.key)?.cap_hit_label && capSeasonForPlayer(row.player, column.key)?.cap_hit_label !== '-') && !capProjectionForPlayer(row.player, column.key) && !capExpiryBadge(row.player, column.key)">
                          <div class="text-xs font-semibold text-slate-400">-</div>
                        </template>
                      </td>
                    </template>
                    <td x-show="row.type === 'player'" class="px-0 py-1" aria-hidden="true"></td>
                  </tr>
                </template>
                <tr aria-hidden="true">
                  <td :colspan="5 + capSeasonColumns.length + (customCap ? 1 : 0)" class="h-12 border-0 p-0"></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div x-show="capView === 'adjustments' && capTeam" class="p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
              <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-semibold ring-1" :class="capAdjustmentUsageClass('buyout')" x-text="capAdjustmentUsageLabel('buyout')"></span>
                <span class="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-semibold ring-1" :class="capAdjustmentUsageClass('retention')" x-text="capAdjustmentUsageLabel('retention')"></span>
              </div>
              <button
                type="button"
                class="inline-flex h-8 items-center justify-center rounded-md bg-slate-900 px-3 text-xs font-semibold text-white transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
                @click="openCapAdjustmentDrawer()"
              >
                + Add
              </button>
            </div>
            <div class="overflow-auto rounded-md border border-slate-200">
              <table class="min-w-max divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                  <tr>
                    <th scope="col" class="w-56 px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">Player</th>
                    <th scope="col" class="w-24 px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">Type</th>
                    <th scope="col" class="w-16 px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500">%</th>
                    <template x-for="column in capSeasonColumns" :key="`adjustment-head-${column.key}`">
                      <th scope="col" class="w-20 px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500" x-text="column.label"></th>
                    </template>
                    <th scope="col" class="w-24 px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                  <template x-for="adjustment in capTeamAdjustments" :key="adjustment.id">
                    <tr>
                      <td class="px-3 py-2">
                        <div class="flex min-w-56 items-center gap-2">
                          <template x-if="adjustment.avatar_url">
                            <img :src="adjustment.avatar_url" alt="" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                          </template>
                          <template x-if="!adjustment.avatar_url">
                            <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[9px] font-semibold text-slate-500 ring-1 ring-slate-200" x-text="capAdjustmentInitials(adjustment)"></span>
                          </template>
                          <div class="min-w-0">
                            <div class="truncate text-xs font-medium text-slate-900" x-text="capAdjustmentPlayerLabel(adjustment) || 'Manual adjustment'"></div>
                            <div class="text-[11px] text-slate-500" x-text="adjustment.team_abbrev || '-'"></div>
                          </div>
                        </div>
                      </td>
                      <td class="px-3 py-2 text-xs text-slate-600" x-text="capAdjustmentTypeLabel(adjustment.type)"></td>
                      <td class="px-3 py-2 text-right text-xs text-slate-600" x-text="`${Number(adjustment.percent || 0)}%`"></td>
                      <template x-for="column in capSeasonColumns" :key="`${adjustment.id}-${column.key}`">
                        <td class="px-3 py-2 text-right text-xs font-semibold text-slate-700" x-text="capMoney(capAdjustmentValue(adjustment, column.key))"></td>
                      </template>
                      <td class="px-3 py-2 text-right">
                        <button type="button" class="text-xs font-semibold text-slate-600 hover:text-slate-950" @click="openCapAdjustmentDrawer(adjustment)">Edit</button>
                        <button type="button" class="ml-3 text-xs font-semibold text-red-600 hover:text-red-700" @click="deleteCapAdjustment(adjustment)">Delete</button>
                      </td>
                    </tr>
                  </template>
                  <tr x-show="capTeamAdjustments.length === 0">
                    <td :colspan="4 + capSeasonColumns.length" class="px-3 py-8 text-center text-sm text-slate-500">No buyouts or retentions added.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="mt-3 min-h-5 text-xs">
              <span x-show="capSettingsMessage" class="text-emerald-700" x-text="capSettingsMessage"></span>
              <span x-show="capSettingsError" class="text-red-600" x-text="capSettingsError"></span>
            </div>
          </div>
          <div x-show="capView === 'limits' && capTeam" class="flex max-h-[calc(100vh-22rem)] min-h-[22rem] flex-col">
            <div class="min-h-0 flex-1 overflow-auto p-4 pb-6">
              <div class="rounded-md border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                  <thead class="bg-slate-50">
                    <tr>
                      <th scope="col" class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">Season</th>
                      <th scope="col" class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500">Floor</th>
                      <th scope="col" class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500">Ceiling</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <template x-for="row in capLimitRows" :key="row.key">
                      <tr>
                        <td class="px-3 py-2 text-xs font-semibold text-slate-900" x-text="row.label"></td>
                        <td class="px-3 py-2">
                          <input type="text" class="ml-auto block w-32 rounded-md border-0 bg-white py-1.5 px-2 text-right text-xs text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600" :value="row.floor ?? ''" :data-cap-limit-season="row.key" data-cap-limit-field="floor" @input="setCapLimitValue(row.key, 'floor', $event.target.value)" placeholder="Floor">
                        </td>
                        <td class="px-3 py-2">
                          <input type="text" class="ml-auto block w-32 rounded-md border-0 bg-white py-1.5 px-2 text-right text-xs text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600" :value="row.ceiling ?? ''" :data-cap-limit-season="row.key" data-cap-limit-field="ceiling" @input="setCapLimitValue(row.key, 'ceiling', $event.target.value)" placeholder="Ceiling">
                        </td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="sticky bottom-0 z-10 flex items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3 shadow-sm">
              <div class="min-h-5 text-xs">
                <span x-show="capSettingsMessage" class="text-emerald-700" x-text="capSettingsMessage"></span>
                <span x-show="capSettingsError" class="text-red-600" x-text="capSettingsError"></span>
              </div>
              <button
                type="button"
                class="inline-flex h-8 items-center justify-center rounded-md bg-slate-900 px-3 text-xs font-semibold text-white transition-colors hover:bg-slate-700 disabled:cursor-wait disabled:opacity-60"
                :disabled="savingCapSettings"
                @click="saveCapLimits()"
              >
                Save limits
              </button>
            </div>
          </div>
        </section>
      </div>
    </div>

    <div x-show="activeLeagueTab === 'draft'" class="px-6 pb-6">
      @include('leagues._draft-panel', [
        'drafting' => $drafting ?? [],
        'league' => $league,
        'teams' => $teams ?? [],
        'canManageLeague' => $canManageLeague,
        'canShowLeagueStats' => $canShowLeagueStats ?? false,
        'leagueStatsPayloadUrl' => $leagueStatsPayloadUrl ?? '',
        'playersPayloadUrl' => $playersPayloadUrl ?? '',
        'leagueStatsFallbackSlug' => $leagueStatsFallbackSlug,
        'leagueStatsFallbackName' => $leagueStatsFallbackName,
      ])
    </div>
    </div>

    <x-ui.slide-over show="capSettingsDrawerOpen" close-action="capSettingsDrawerOpen = false" title-id="cap-options-title" max-width="max-w-lg">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <h2 id="cap-options-title" class="text-sm font-semibold text-slate-950">Cap options</h2>
          <p class="mt-1 text-xs text-slate-500">League buyout and retention counters.</p>
        </div>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-600"
          @click="capSettingsDrawerOpen = false"
          aria-label="Close cap options"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z" />
          </svg>
        </button>
      </div>
      <div class="flex-1 overflow-y-auto p-6">
        <div class="space-y-4">
          <label class="block">
            <span class="text-sm font-semibold text-slate-950">Buyouts allowed</span>
            <input
              type="number"
              min="0"
              class="mt-2 block w-full rounded-md border-0 bg-white py-2 px-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 disabled:bg-slate-50 disabled:text-slate-500"
              x-model="maxActiveBuyoutsInput"
              :disabled="savingCapSettings || !canEditLeagueSettings"
            >
          </label>
          <label class="block">
            <span class="text-sm font-semibold text-slate-950">Retentions allowed</span>
            <input
              type="number"
              min="0"
              class="mt-2 block w-full rounded-md border-0 bg-white py-2 px-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 disabled:bg-slate-50 disabled:text-slate-500"
              x-model="maxActiveRetentionsInput"
              :disabled="savingCapSettings || !canEditLeagueSettings"
            >
          </label>
          <div class="min-h-5 text-xs">
            <span x-show="capSettingsMessage" class="text-emerald-700" x-text="capSettingsMessage"></span>
            <span x-show="capSettingsError" class="text-red-600" x-text="capSettingsError"></span>
          </div>
        </div>
      </div>
      <div class="border-t border-slate-200 px-6 py-4">
        <button
          type="button"
          class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="savingCapSettings || !canEditLeagueSettings"
          @click="saveCapRuleLimits()"
        >
          <span x-show="!savingCapSettings">Save options</span>
          <span x-show="savingCapSettings">Saving...</span>
        </button>
      </div>
    </x-ui.slide-over>

    <x-ui.slide-over show="capAdjustmentDrawerOpen" close-action="capAdjustmentDrawerOpen = false" title-id="cap-adjustment-title" max-width="max-w-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <h2 id="cap-adjustment-title" class="text-sm font-semibold text-slate-950" x-text="capAdjustmentMode === 'edit' ? 'Edit buyout or retention' : 'Add buyout or retention'"></h2>
          <p class="mt-1 text-xs text-slate-500" x-text="capTeam ? capTeam.name : ''"></p>
        </div>
        <button
          type="button"
          class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-600"
          @click="capAdjustmentDrawerOpen = false"
          aria-label="Close buyout or retention"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z" />
          </svg>
        </button>
      </div>
      <div class="flex-1 overflow-y-auto p-6">
        <div class="space-y-5">
          <div class="relative" @click.outside="capAdjustmentPlayerOpen = false">
            <label class="block">
              <span class="text-sm font-semibold text-slate-950">Player</span>
              <input
                type="text"
                class="mt-2 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-9 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                x-model="capAdjustmentPlayerQuery"
                @focus="capAdjustmentPlayerOpen = true"
                @input="capAdjustmentPlayerOpen = true"
                placeholder="Search roster players"
                autocomplete="off"
              >
            </label>
            <div
              x-show="capAdjustmentPlayerOpen"
              x-cloak
              class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-md bg-white py-1 text-sm shadow-lg ring-1 ring-black/5"
            >
              <template x-for="player in capAdjustmentPlayerCandidates()" :key="player.id">
                <button
                  type="button"
                  class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-slate-50"
                  @click="selectCapAdjustmentPlayer(player)"
                >
                  <span class="flex min-w-0 items-center gap-2">
                    <template x-if="player.avatar_url">
                      <img :src="player.avatar_url" alt="" class="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                    </template>
                    <template x-if="!player.avatar_url">
                      <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[9px] font-semibold text-slate-500 ring-1 ring-slate-200" x-text="capAdjustmentInitials(player)"></span>
                    </template>
                    <span class="truncate font-medium text-slate-900" x-text="capAdjustmentPlayerLabel(player)"></span>
                  </span>
                  <span class="shrink-0 text-xs text-slate-500" x-text="`${player.team_abbrev || '-'} ${player.contract?.current_aav_label || ''}`"></span>
                </button>
              </template>
              <div x-show="capAdjustmentPlayerCandidates().length === 0" class="px-3 py-3 text-sm text-slate-500">No contract-backed roster players found.</div>
            </div>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="text-sm font-semibold text-slate-950">Type</span>
              <select
                class="mt-2 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-9 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                x-model="capAdjustmentForm.type"
              >
                <option value="buyout">Buyout</option>
                <option value="retention">Retention</option>
              </select>
            </label>
            <label class="block">
              <span class="text-sm font-semibold text-slate-950">% of cap hit</span>
              <input
                type="number"
                min="0"
                max="100"
                step="0.01"
                class="mt-2 block w-full rounded-md border-0 bg-white py-2 px-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                x-model.number="capAdjustmentForm.percent"
                @input="recalculateCapAdjustmentValues(false)"
              >
            </label>
            <label class="block">
              <span class="text-sm font-semibold text-slate-950">Start season</span>
              <select
                class="mt-2 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-9 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                x-model="capAdjustmentForm.start_season"
                @change="recalculateCapAdjustmentValues(false)"
              >
                <template x-for="season in capAdjustmentSeasonOptions()" :key="`start-${season.key}`">
                  <option :value="season.key" x-text="season.label"></option>
                </template>
              </select>
            </label>
            <label class="block">
              <span class="text-sm font-semibold text-slate-950">End season</span>
              <select
                class="mt-2 block w-full rounded-md border-0 bg-white py-2 pl-3 pr-9 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                x-model="capAdjustmentForm.end_season"
                @change="recalculateCapAdjustmentValues(false)"
              >
                <option value="">Contract end</option>
                <template x-for="season in capAdjustmentSeasonOptions()" :key="`end-${season.key}`">
                  <option :value="season.key" x-text="season.label"></option>
                </template>
              </select>
            </label>
          </div>
          <div class="rounded-md border border-slate-200">
            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2">
              <div class="text-sm font-semibold text-slate-950">Calculated impact</div>
              <button type="button" class="text-xs font-semibold text-slate-600 hover:text-slate-950" @click="resetCapAdjustmentValues()">Reset</button>
            </div>
            <div class="divide-y divide-slate-100">
              <template x-for="row in capAdjustmentCalculatedRows()" :key="row.key">
                <label class="grid grid-cols-[minmax(0,1fr)_8rem] items-center gap-3 px-3 py-2">
                  <span class="text-xs font-semibold text-slate-700" x-text="row.label"></span>
                  <input
                    type="text"
                    class="block w-full rounded-md border-0 bg-white py-1.5 px-2 text-right text-xs text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                    :value="row.value"
                    @input="setCapAdjustmentManualValue(row.key, $event.target.value)"
                  >
                </label>
              </template>
              <div x-show="capAdjustmentCalculatedRows().length === 0" class="px-3 py-4 text-sm text-slate-500">Select a player with contract seasons to calculate cap impact.</div>
            </div>
          </div>
          <div class="min-h-5 text-xs">
            <span x-show="capSettingsMessage" class="text-emerald-700" x-text="capSettingsMessage"></span>
            <span x-show="capSettingsError" class="text-red-600" x-text="capSettingsError"></span>
          </div>
        </div>
      </div>
      <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
        <button type="button" class="inline-flex items-center justify-center rounded-md px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-950" @click="capAdjustmentDrawerOpen = false">Cancel</button>
        <button
          type="button"
          class="inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="savingCapSettings || !capAdjustmentForm.player_id"
          @click="saveCapAdjustment()"
        >
          <span x-show="!savingCapSettings">Save</span>
          <span x-show="savingCapSettings">Saving...</span>
        </button>
      </div>
    </x-ui.slide-over>

    @if ($canEditLeagueSettings)
    <x-ui.slide-over show="settingsOpen" close-action="settingsOpen = false" title-id="league-options-title" max-width="max-w-2xl">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
        <div>
          <h2 id="league-options-title" class="text-sm font-semibold text-slate-950">League settings</h2>
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
        @if ($canManageLeague && in_array($league->platform, ['yahoo', 'fantrax'], true))
        <section class="rounded-lg border border-slate-200 bg-white">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left"
            @click="scoringAlignmentOpen = !scoringAlignmentOpen"
            :aria-expanded="scoringAlignmentOpen.toString()"
          >
            <div>
              <div class="text-sm font-semibold text-slate-950">Scoring category alignment</div>
              <div class="mt-1 text-xs text-slate-500">Map {{ ucfirst((string) $league->platform) }} categories to DynastyIQ scoring options.</div>
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
                          :class="category.mapping_source === 'manual' ? 'bg-indigo-50 text-indigo-700' : scoringAlignmentStatusClass(category)"
                          x-text="category.mapping_source"
                        ></span>
                        <span
                          x-show="category.alignment_status"
                          class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                          :class="scoringAlignmentStatusClass(category)"
                          x-text="scoringAlignmentStatusLabel(category)"
                        ></span>
                      </div>
                      <div class="mt-1 space-y-1 text-xs text-slate-500">
                        <div>
                          <span x-show="category.value !== null && category.value !== undefined">Value <span x-text="category.value"></span></span>
                          <span x-show="category.auto_stat_key">Auto <span class="font-mono" x-text="category.auto_stat_key"></span></span>
                        </div>
                        <div x-show="category.dictionary_provider_label">
                          DynastyIQ <span class="font-medium text-slate-700" x-text="category.dictionary_provider_label"></span>
                        </div>
                        <div x-show="category.formula">
                          Formula <span class="font-mono text-slate-700" x-text="category.formula"></span>
                        </div>
                        <div x-show="category.support_message" class="text-amber-700" x-text="category.support_message"></div>
                      </div>
                    </div>
                    <div class="relative" @click.outside="closeScoringMappingCombobox(category)">
                      <label class="block">
                        <span class="sr-only">DynastyIQ scoring option</span>
                        <input
                          type="text"
                          class="block w-full rounded-md border-0 bg-white py-2 pl-3 pr-9 text-xs text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                          :value="scoringMappingQuery(category)"
                          :title="scoringMappingQuery(category)"
                          @focus="openScoringMappingCombobox(category)"
                          @input="
                            scoringMappingQueries[String(category.id)] = $event.target.value;
                            scoringMappingOpen[String(category.id)] = true;
                          "
                          @keydown.escape.prevent="closeScoringMappingCombobox(category)"
                          @keydown.enter.prevent="
                            const first = scoringMappingFilteredOptions(category)[0];
                            if (first) selectScoringMappingOption(category, first);
                          "
                          placeholder="Search DynastyIQ scoring options"
                          role="combobox"
                          :aria-expanded="Boolean(scoringMappingOpen[String(category.id)]).toString()"
                        >
                      </label>
                      <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center px-2 text-slate-400 hover:text-slate-600"
                        @click="scoringMappingOpen[String(category.id)] ? closeScoringMappingCombobox(category) : openScoringMappingCombobox(category)"
                        aria-label="Toggle scoring options"
                      >
                        <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                          <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                      </button>

                      <div
                        x-show="scoringMappingOpen[String(category.id)]"
                        x-cloak
                        class="absolute right-0 z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-md bg-white py-1 text-xs shadow-lg ring-1 ring-black/5"
                      >
                        <button
                          type="button"
                          class="block w-full px-3 py-2 text-left text-slate-600 hover:bg-slate-50"
                          @click="clearScoringMappingOverride(category)"
                        >
                          <span class="block font-medium" :title="scoringMappingDisplay(category)" x-text="`Use recognized: ${scoringMappingDisplay(category)}`"></span>
                          <span class="block truncate text-xs text-slate-500">Clear manual override</span>
                        </button>
                        <template x-for="option in scoringMappingFilteredOptions(category)" :key="option.key">
                          <button
                            type="button"
                            class="block w-full px-3 py-2 text-left hover:bg-slate-50"
                            :class="option.key === categorySelectedMappingKey(category) ? 'bg-indigo-50' : ''"
                            @click="selectScoringMappingOption(category, option)"
                          >
                            <span class="flex items-center justify-between gap-3">
                              <span class="truncate font-medium" :class="scoringMappingOptionClass(option)" :title="scoringMappingOptionTitle(option)" x-text="option.label"></span>
                              <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-600" x-text="option.type"></span>
                            </span>
                            <span class="mt-0.5 block truncate text-[11px] text-slate-500" :title="scoringMappingOptionTitle(option)" x-text="option.description || option.formula || option.stat_key || option.alignment_status"></span>
                          </button>
                        </template>
                        <div
                          x-show="scoringMappingFilteredOptions(category).length === 0"
                          class="px-3 py-3 text-sm text-slate-500"
                        >
                          No scoring options found.
                        </div>
                      </div>
                    </div>
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
            <div class="flex items-center justify-between gap-4 rounded-md border border-slate-200 p-3">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-950">Custom cap</div>
                <div class="mt-1 text-xs text-slate-500">Use Fantrax roster salaries in the Cap tab.</div>
                <div class="mt-2 min-h-4 text-xs">
                  <span x-show="capSettingsMessage" class="text-emerald-700" x-text="capSettingsMessage"></span>
                  <span x-show="capSettingsError" class="text-red-600" x-text="capSettingsError"></span>
                </div>
              </div>
              <button
                type="button"
                class="group relative inline-flex shrink-0 items-center rounded-full transition-colors duration-200 ease-out focus:outline-none focus:ring-2 focus:ring-indigo-200 disabled:cursor-wait disabled:opacity-60"
                :class="customCap ? 'bg-indigo-600' : 'bg-slate-200'"
                style="height: 14px; width: 28px;"
                :aria-pressed="customCap ? 'true' : 'false'"
                aria-label="Toggle custom cap"
                :disabled="savingCapSettings"
                @click.stop.prevent="toggleCustomCap()"
              >
                <span
                  class="inline-block rounded-full bg-white shadow-sm transition-transform duration-200 ease-out motion-reduce:transition-none"
                  style="height: 10px; width: 10px;"
                  :style="`height: 10px; width: 10px; transform: translateX(${customCap ? '16px' : '2px'});`"
                  aria-hidden="true"
                ></span>
              </button>
            </div>
            <div x-show="fantraxContractCodes.length > 0" class="rounded-md border border-slate-200">
              <div class="border-b border-slate-200 px-3 py-3">
                <div class="text-sm font-semibold text-slate-950">Custom contract codes</div>
                <div class="mt-1 text-xs text-slate-500">Define detected Fantrax contract prefixes for custom salary leagues.</div>
              </div>
              <div class="divide-y divide-slate-200">
                <template x-for="codeGroup in fantraxContractCodes" :key="codeGroup.prefix">
                  <div class="grid gap-3 px-3 py-3 sm:grid-cols-[minmax(0,0.9fr)_minmax(0,1.5fr)] sm:items-center">
                    <div class="min-w-0">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-slate-100 px-2 py-1 font-mono text-xs font-semibold text-slate-800" x-text="codeGroup.prefix"></span>
                        <span class="text-xs text-slate-500" x-text="`${codeGroup.count} players`"></span>
                      </div>
                      <div class="mt-1 truncate text-xs text-slate-500" x-text="`Detected ${codeGroup.examples}`"></div>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-center">
                      <label class="block">
                        <span class="sr-only">Contract label</span>
                        <input
                          type="text"
                          class="block w-full rounded-md border-0 bg-white py-2 px-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                          :value="contractCodeLabelValue(codeGroup.prefix)"
                          @input="setContractCodeDefinition(codeGroup.prefix, 'label', $event.target.value)"
                          placeholder="Entry Level"
                        >
                      </label>
                      <label class="block">
                        <span class="sr-only">Contract type key</span>
                        <input
                          type="text"
                          class="block w-full rounded-md border-0 bg-white py-2 px-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                          :value="contractCodeTypeValue(codeGroup.prefix)"
                          @input="setContractCodeDefinition(codeGroup.prefix, 'type', $event.target.value)"
                          placeholder="entry_level"
                        >
                      </label>
                      <label class="inline-flex items-center justify-end gap-2 text-xs font-medium text-slate-600">
                        <input
                          type="checkbox"
                          class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                          :checked="contractCodeSuffixValue(codeGroup.prefix)"
                          @change="setContractCodeDefinition(codeGroup.prefix, 'suffix_years_remaining', $event.target.checked)"
                        >
                        <span>Years</span>
                      </label>
                    </div>
                  </div>
                </template>
              </div>
              <div class="flex items-center justify-end border-t border-slate-200 px-3 py-3">
                <button
                  type="button"
                  class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="savingCapSettings"
                  @click="saveContractCodeDefinitions()"
                >
                  <span x-show="!savingCapSettings">Save contract codes</span>
                  <span x-show="savingCapSettings">Saving...</span>
                </button>
              </div>
            </div>
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
    @endif
  </div>
@endif
