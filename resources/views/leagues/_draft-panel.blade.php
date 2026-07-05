@php
    /** @var \App\Models\PlatformLeague|null $league */
    $drafting = $drafting ?? [];
    $teamRows = collect($teams ?? []);
    $managerTeams = $teamRows->reject(static fn (array $team): bool => in_array($team['id'] ?? null, ['__all_players__', '__free_agents__'], true));
    $allPlayersTeam = $teamRows->first(static fn (array $team): bool => ($team['id'] ?? null) === '__all_players__');
    $freeAgentsTeam = $teamRows->first(static fn (array $team): bool => ($team['id'] ?? null) === '__free_agents__');
    $ownedTeam = $managerTeams->first(static fn (array $team): bool => (bool) ($team['owned_by_me'] ?? false));
    $allPlayers = collect(data_get($allPlayersTeam, 'players', []))->values();
    $availablePlayers = collect(data_get($freeAgentsTeam, 'players', []))->values();
    $availablePlayerTeams = $availablePlayers
        ->pluck('team_abbrev')
        ->map(static fn (mixed $team): string => strtoupper(trim((string) $team)))
        ->filter()
        ->unique()
        ->sort()
        ->values();
    $myPlayers = collect(data_get($ownedTeam, 'players', []))->values();
    $ownedTeamId = (string) data_get($ownedTeam, 'id', '');
    $leagueStatsPayloadUrl = (string) ($leagueStatsPayloadUrl ?? '');
    $playersPayloadUrl = (string) ($playersPayloadUrl ?? '');
    $leagueStatsFallbackSlug = (string) ($leagueStatsFallbackSlug ?? '');
    $leagueStatsFallbackName = (string) ($leagueStatsFallbackName ?? 'League Scoring');
    $draftPerspectiveOptions = [
        [
            'slug' => 'prospects',
            'name' => 'Prospects',
        ],
        [
            'slug' => 'prospects-goalies',
            'name' => 'Prospects - Goalies',
        ],
    ];
    $draftCommitSeasonLabel = (string) ($drafting['draft_commit_season_label'] ?? '');
    $draftRows = collect($drafting['rows'] ?? []);
    $draftRounds = collect($drafting['rounds'] ?? []);
    $completedRows = $draftRows->filter(static fn (array $row): bool => ! empty($row['fantrax_player_id']))->values();
    $myDraftPickRows = $completedRows
        ->filter(static fn (array $row): bool => $ownedTeamId !== '' && (string) ($row['team_id'] ?? '') === $ownedTeamId)
        ->values();
    $myDraftPicks = $completedRows
        ->filter(static fn (array $row): bool => $ownedTeamId !== '' && (string) ($row['team_id'] ?? '') === $ownedTeamId)
        ->map(static fn (array $row): array => [
            'id' => $row['player_id'] ?? ('fantrax:' . ($row['fantrax_player_id'] ?? '') . ':pick:' . ($row['overall_pick'] ?? '')),
            'name' => $row['player_name'] ?? 'Unknown player',
            'position' => $row['position'] ?? null,
            'league_abbrev' => $row['league_abbrev'] ?? null,
            'team_abbrev' => $row['team_abbrev'] ?? null,
            'avatar_url' => $row['avatar_url'] ?? null,
            'age' => $row['age'] ?? null,
            'stats' => $row['stats'] ?? [],
            'fantasy_team_name' => $row['team_name'] ?? null,
            'overall_pick' => $row['overall_pick'] ?? null,
            'round' => $row['round'] ?? null,
            'pick_in_round' => $row['pick_in_round'] ?? null,
            'draft_label' => 'R' . ($row['round'] ?? '-') . ' P' . ($row['pick_in_round'] ?? ($row['pick'] ?? '-')),
            'overall_pick_label' => isset($row['overall_pick']) ? '#' . $row['overall_pick'] : '-',
            'status_detail' => data_get($row, 'next_season.team_name'),
        ])
        ->values();
    $nextPick = $draftRows->first(static fn (array $row): bool => ! empty($row['is_next_pick']))
        ?? $draftRows->first(static fn (array $row): bool => empty($row['fantrax_player_id']));
    $upNextPick = $draftRows
        ->filter(static fn (array $row): bool => empty($row['fantrax_player_id']) && ! empty($nextPick) && ((int) ($row['overall_pick'] ?? 0)) > ((int) ($nextPick['overall_pick'] ?? 0)))
        ->first();
    $pendingDraftRows = $draftRows
        ->filter(static fn (array $row): bool => empty($row['fantrax_player_id']))
        ->values();
    $picksUntilOtc = $ownedTeamId !== ''
        ? $pendingDraftRows->search(static fn (array $row): bool => (string) ($row['team_id'] ?? '') === $ownedTeamId)
        : false;
    $recentPicks = $completedRows->reverse()->take(5)->values();
    $draftedCount = $completedRows->count();
    $totalPicks = $draftRows->count();
    $activeRoundIndex = (int) ($drafting['active_round_index'] ?? 0);
    $activeRound = $draftRounds->get($activeRoundIndex, []);
    $statusTone = $drafting['status_tone'] ?? 'slate';
    $statusDotClass = match ($statusTone) {
        'green' => 'bg-emerald-500',
        'blue' => 'bg-blue-500',
        default => 'bg-slate-400',
    };
    $hasCanonicalDraft = (bool) ($drafting['has_canonical_draft'] ?? false);
    $canManageDraft = (bool) ($canManageLeague ?? false);
    $createDraftUrl = (string) ($drafting['create_url'] ?? '');
    $draftSettingsUrl = (string) ($drafting['settings_url'] ?? '');
    $isFantraxLeague = (string) ($league?->platform ?? '') === 'fantrax';
    $draftPickClockSeconds = (int) ($drafting['pick_clock_seconds'] ?? 0);
    $draftPickClockMinutes = (int) ($drafting['pick_clock_minutes'] ?? 5);
    $draftPauseSeconds = (int) ($drafting['pause_between_picks_seconds'] ?? 0);
    $draftAutoPickEnabled = (bool) ($drafting['auto_pick_enabled'] ?? false);
    $draftCountdownExpiresAt = (string) ($drafting['countdown_expires_at'] ?? '');
    $draftCanCountdown = (bool) ($drafting['is_live'] ?? false) && $draftCountdownExpiresAt !== '';
    $draftQueueItems = collect($drafting['queue_items'] ?? [])->values();
    $draftQueuedPlayerIds = $draftQueueItems
        ->pluck('player_id')
        ->mapWithKeys(static fn (mixed $playerId): array => [(string) $playerId => true])
        ->all();
    $draftQueueStoreUrl = (string) ($drafting['queue_store_url'] ?? '');
    $draftQueuePayloadUrl = (string) ($drafting['queue_payload_url'] ?? '');
    $teamGradients = [
        'ANA' => 'linear-gradient(to bottom, #FF6F00, #000000)',
        'ARI' => 'linear-gradient(to bottom, #8C2633, #000000)',
        'BOS' => 'linear-gradient(to bottom, #FFB81C, #000000)',
        'BUF' => 'linear-gradient(to bottom, #002654, #FDBB2F)',
        'CGY' => 'linear-gradient(to bottom, #C8102E, #F1BE48)',
        'CAR' => 'linear-gradient(to bottom, #CC0000, #000000)',
        'CHI' => 'linear-gradient(to bottom, #CF0A2C, #000000)',
        'COL' => 'linear-gradient(to bottom, #6F263D, #236192)',
        'CBJ' => 'linear-gradient(to bottom, #002654, #A6A6A6)',
        'DAL' => 'linear-gradient(to bottom, #006847, #000000)',
        'DET' => 'linear-gradient(to bottom, #CE1126, #FFFFFF)',
        'EDM' => 'linear-gradient(to bottom, #FF4C00, #041E42)',
        'FLA' => 'linear-gradient(to bottom, #041E42, #C8102E)',
        'LAK' => 'linear-gradient(to bottom, #A2AAAD, #000000)',
        'MIN' => 'linear-gradient(to bottom, #154734, #A6192E)',
        'MTL' => 'linear-gradient(to bottom, #AF1E2D, #192168)',
        'NSH' => 'linear-gradient(to bottom, #FFB81C, #041E42)',
        'NJD' => 'linear-gradient(to bottom, #CE1126, #000000)',
        'NYI' => 'linear-gradient(to bottom, #00539B, #F47D30)',
        'NYR' => 'linear-gradient(to bottom, #0038A8, #CE1126)',
        'OTT' => 'linear-gradient(to bottom, #E31837, #000000)',
        'PHI' => 'linear-gradient(to bottom, #FA4616, #000000)',
        'PIT' => 'linear-gradient(to bottom, #FFB81C, #000000)',
        'SEA' => 'linear-gradient(to bottom, #001628, #99D9D9)',
        'SJS' => 'linear-gradient(to bottom, #006D75, #000000)',
        'STL' => 'linear-gradient(to bottom, #002F87, #FDB827)',
        'TBL' => 'linear-gradient(to bottom, #002868, #00529B)',
        'TOR' => 'linear-gradient(to bottom, #00205B, #003E7E)',
        'VAN' => 'linear-gradient(to bottom, #00205B, #00843D)',
        'VGK' => 'linear-gradient(to bottom, #B4975A, #333F48)',
        'WSH' => 'linear-gradient(to bottom, #C8102E, #041E42)',
        'WPG' => 'linear-gradient(to bottom, #041E42, #7B303D)',
    ];
    $fallbackTeamGradient = 'linear-gradient(to bottom, #e5e7eb, #9ca3af)';
@endphp

<section
    x-data="{
        timerNow: Date.now(),
        timerInterval: null,
        draftPanelResizeObserver: null,
        draftPanelHeight: 448,
        draftSupportPanelHeight: 500,
        draftPanelBottomGap: 14,
        activePlayerTab: 'live',
        activeRound: @js($activeRoundIndex),
        roundScrollCanLeft: false,
        roundScrollCanRight: false,
        showAvatars: true,
        showTeamBadges: true,
        draftOptionsOpen: false,
        draftCreateOpen: false,
        draftCreateMode: @js($isFantraxLeague ? 'fantrax' : 'manual'),
        draftRequestLoading: false,
        draftRequestMessage: '',
        draftRequestError: '',
        draftPickClockSeconds: @js($draftPickClockSeconds),
        draftPickClockMinutes: @js($draftPickClockMinutes),
        draftPauseSeconds: @js($draftPauseSeconds),
        draftAutoPickEnabled: @js($draftAutoPickEnabled),
        draftCountdownExpiresAt: @js($draftCountdownExpiresAt),
        draftCanCountdown: @js($draftCanCountdown),
        createDraftUrl: @js($createDraftUrl),
        draftSettingsUrl: @js($draftSettingsUrl),
        search: '',
        positionFilter: '',
        posTypeFilter: '',
        availabilityFilter: '',
        allPlayers: @js($allPlayers),
        availablePlayers: @js($availablePlayers),
        availablePlayersById: Object.fromEntries(@js($availablePlayers).map((player) => [String(player.id), player])),
        myPlayers: @js($myDraftPicks),
        selectedPlayerTeam: '',
        playerTeamOptions: @js($availablePlayerTeams),
        leagueStatsPayloadUrl: @js($leagueStatsPayloadUrl),
        playersPayloadUrl: @js($playersPayloadUrl),
        canShowLeagueStats: @js((bool) ($canShowLeagueStats ?? false)),
        playersPayloadLoaded: @js($availablePlayers->isNotEmpty()),
        playersPayloadLoading: false,
        playersPayloadError: '',
        playerPerspectiveOptions: @js($draftPerspectiveOptions),
        selectedPlayerPerspective: 'prospects',
        playerPerspectiveHeadings: [],
        playerPerspectiveRows: [],
        playerPerspectiveRowsById: {},
        playerPerspectiveLoaded: false,
        playerPerspectiveLoading: false,
        playerPerspectiveError: '',
        playerPerspectiveRequestKey: '',
        playerPerspectiveRequestToken: 0,
        playerPerspectiveCache: {},
        playerSortKey: '',
        playerSortDirection: 'desc',
        draftQueueItems: @js($draftQueueItems),
        queuedPlayerIds: @js($draftQueuedPlayerIds),
        draftQueueStoreUrl: @js($draftQueueStoreUrl),
        draftQueuePayloadUrl: @js($draftQueuePayloadUrl),
        queueRequestLoadingByPlayer: {},
        queuePerspectiveLoading: false,
        queuePerspectiveError: '',
        teamGradients: @js($teamGradients),
        fallbackTeamGradient: @js($fallbackTeamGradient),
        get basePlayers() {
            if (this.activePlayerTab === 'players' && this.showingQueuePerspective) return this.queueDisplayPlayers;
            if (this.activePlayerTab === 'players') return this.availablePerspectivePlayers;
            if (this.activePlayerTab === 'mine') return this.myPlayers;
            return this.availablePlayers;
        },
        get playerPerspectiveOptionsWithQueue() {
            return [
                ...this.playerPerspectiveOptions.filter((perspective) => String(perspective?.slug ?? perspective?.id ?? perspective?.name ?? '') !== '__queue__'),
                { slug: '__queue__', name: 'My Queue' },
            ];
        },
        get showingQueuePerspective() {
            return this.selectedPlayerPerspective === '__queue__';
        },
        get isPlayerPerspectivePending() {
            return this.activePlayerTab === 'players'
                && !this.showingQueuePerspective
                && !this.playerPerspectiveError
                && (this.playerPerspectiveLoading || !this.playerPerspectiveLoaded);
        },
        get availablePerspectivePlayers() {
            if (!this.playerPerspectiveLoaded) return [];

            return this.playerPerspectiveRows
                .filter((row) => this.availablePlayersById[this.perspectivePlayerId(row)])
                .map((row) => ({
                    ...this.availablePlayersById[this.perspectivePlayerId(row)],
                    ...row,
                    id: this.perspectivePlayerId(row),
                    stats: row?.stats && typeof row.stats === 'object' ? row.stats : row,
                }));
        },
        get filteredDraftPlayers() {
            const query = this.search.toLowerCase().trim();

            const filtered = this.basePlayers.filter((player) => {
                const name = String(player.name || '').toLowerCase();
                const team = String(player.team_abbrev || '').toLowerCase();
                const position = String(player.position || '').toUpperCase();
                const posType = String(player.pos_type || '').toUpperCase();
                const status = this.playerStatus(player).toLowerCase();
                const matchesSearch = query === '' || name.includes(query) || team.includes(query) || position.toLowerCase().includes(query);
                const matchesPosition = this.positionFilter === '' || position === this.positionFilter;
                const matchesPosType = !this.showSkaterTypeFilters
                    || this.posTypeFilter === ''
                    || posType === this.posTypeFilter
                    || (this.posTypeFilter === 'F' && ['C', 'L', 'LW', 'R', 'RW', 'W'].includes(position))
                    || (this.posTypeFilter === 'D' && ['D', 'LD', 'RD'].includes(position));
                const matchesAvailability = this.availabilityFilter === '' || status === this.availabilityFilter;
                const matchesTeam = this.selectedPlayerTeam === '' || String(player.team_abbrev || '').toUpperCase() === this.selectedPlayerTeam;

                return matchesSearch && matchesPosition && matchesPosType && matchesAvailability && matchesTeam;
            });

            return this.sortedDraftPlayers(filtered);
        },
        get filteredQueueDisplayPlayers() {
            return this.filteredDraftPlayers;
        },
        get queueDisplayPlayers() {
            const queuedByPlayerId = Object.fromEntries(
                this.draftQueueItems.map((item, index) => [
                    String(item?.player_id ?? item?.id ?? ''),
                    { item, index },
                ]),
            );
            const playersById = Object.fromEntries(
                this.availablePerspectivePlayers.map((player) => [String(player?.id ?? player?.player_id ?? ''), player]),
            );

            return this.draftQueueItems.map((item, index) => {
                const playerId = String(item?.player_id ?? item?.id ?? '');
                const player = playersById[playerId] || {};
                const queued = queuedByPlayerId[playerId]?.item || item;

                return {
                    ...queued,
                    ...player,
                    id: playerId,
                    player_id: queued?.player_id ?? playerId,
                    rank: index + 1,
                    name: player?.name || queued?.name || 'Unknown player',
                    position: player?.position || queued?.position || '',
                    team_abbrev: player?.team_abbrev || queued?.team_abbrev || '',
                    age: player?.age ?? queued?.age ?? null,
                    avatar_url: player?.avatar_url || queued?.avatar_url || null,
                    league: player?.league || player?.league_abbrev || queued?.league || queued?.league_abbrev || null,
                    league_abbrev: player?.league_abbrev || queued?.league_abbrev || null,
                    gp: player?.gp ?? player?.stats?.gp ?? queued?.gp ?? queued?.stats?.gp ?? null,
                    stats: player?.stats && typeof player.stats === 'object'
                        ? player.stats
                        : (queued?.stats && typeof queued.stats === 'object' ? queued.stats : {}),
                    delete_url: queued?.delete_url || '',
                };
            });
        },
        get playerStatHeadings() {
            if (this.showingQueuePerspective) return [];

            const identityKeys = new Set([
                'name',
                'player',
                'team',
                'league',
                'pos',
                'pos_type',
                'age',
                'contract_value',
                'contract_value_num',
                'contract_last_year',
                'contract_last_year_num',
                'avatar_url',
                'head_shot_url',
                'id',
                'nhl_player_id',
                'gp',
            ]);

            return this.playerPerspectiveHeadings
                .filter((heading) => heading?.key && !identityKeys.has(String(heading.key)))
                .slice(0, 8);
        },
        get filterCount() {
            return [this.positionFilter, this.posTypeFilter, this.availabilityFilter, this.selectedPlayerTeam].filter(Boolean).length;
        },
        get draftPerspectiveSlugs() {
            return ['prospects', 'prospects-goalies'];
        },
        get showSkaterTypeFilters() {
            return this.selectedPlayerPerspective === 'prospects';
        },
        init() {
            if (this.draftCanCountdown && this.draftCountdownExpiresAt) {
                this.timerInterval = window.setInterval(() => {
                    this.timerNow = Date.now();
                }, 1000);
            }

            if (this.activePlayerTab === 'live') {
                this.$nextTick(() => {
                    this.updateDraftLivePanelHeight();
                    this.scrollToNextPick();
                    this.updateRoundScrollAffordance();
                });
            }

            this.loadPlayerPerspectiveStats();

            if (window.ResizeObserver) {
                this.draftPanelResizeObserver = new ResizeObserver(() => this.updateDraftLivePanelHeight());
                this.draftPanelResizeObserver.observe(this.$el);
            }
        },
        destroy() {
            if (this.timerInterval) {
                window.clearInterval(this.timerInterval);
            }

            if (this.draftPanelResizeObserver) {
                this.draftPanelResizeObserver.disconnect();
            }
        },
        get draftSecondsRemaining() {
            if (!this.draftCanCountdown || !this.draftCountdownExpiresAt) return null;

            const expiresAt = Date.parse(this.draftCountdownExpiresAt);

            if (Number.isNaN(expiresAt)) return null;

            return Math.max(0, Math.ceil((expiresAt - this.timerNow) / 1000));
        },
        get draftTimerLabel() {
            const remaining = this.draftSecondsRemaining;

            if (remaining === null) return '--:--';

            const hours = Math.floor(remaining / 3600);
            const minutes = Math.floor((remaining % 3600) / 60);
            const seconds = remaining % 60;

            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        },
        get draftTimerProgressPercent() {
            const remaining = this.draftSecondsRemaining;
            const total = Number(this.draftPickClockSeconds || this.draftPickClockMinutes * 60);

            if (remaining === null || !Number.isFinite(total) || total <= 0) return 0;

            return Math.max(0, Math.min(100, (remaining / total) * 100));
        },
        get draftTimerRingStyle() {
            return `background: conic-gradient(#3b82f6 ${this.draftTimerProgressPercent}%, #dbeafe 0);`;
        },
        playerStatus(player) {
            return player?.fantasy_team_name ? 'rostered' : 'available';
        },
        queueStatValue(player, key) {
            const stats = player?.stats && typeof player.stats === 'object' ? player.stats : {};

            return stats[key] ?? player?.[key] ?? null;
        },
        queuePlayerName(player) {
            const name = String(player?.name || '').trim();

            if (name.includes(',')) return name;

            const parts = name.split(/\s+/).filter(Boolean);
            if (parts.length < 2) return name || 'Unknown player';

            const last = parts.pop();

            return `${last}, ${parts.join(' ')}`;
        },
        queueSavePercentage(player) {
            const value = this.queueStatValue(player, 'sv_pct');

            if (value === null || value === undefined || value === '') return '-';

            return Number(value).toFixed(3);
        },
        teamBadgeStyle(teamAbbrev) {
            const key = String(teamAbbrev || '').toUpperCase();
            const background = this.teamGradients[key] || this.fallbackTeamGradient;

            return `background: ${background};`;
        },
        resetFilters() {
            this.search = '';
            this.positionFilter = '';
            this.posTypeFilter = '';
            this.availabilityFilter = '';
            this.selectedPlayerTeam = '';
        },
        togglePosTypeFilter(value) {
            if (!this.showSkaterTypeFilters) return;

            this.posTypeFilter = this.posTypeFilter === value ? '' : value;
        },
        setActivePlayerTab(tab) {
            this.activePlayerTab = tab;

            if (tab === 'live') {
                this.$nextTick(() => {
                    this.updateDraftLivePanelHeight();
                    this.scrollToNextPick();
                });
            }

            if (tab === 'players') {
                this.playerPerspectiveLoaded = false;
                this.playerPerspectiveLoading = true;
                this.loadDraftPlayersPayload().then(() => this.loadPlayerPerspectiveStats(true));
            }
        },
        async loadDraftPlayersPayload() {
            if (this.playersPayloadLoaded || this.playersPayloadLoading || !this.playersPayloadUrl) return;

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
                    throw new Error(payload.message || 'Could not load draft players.');
                }

                const teams = Array.isArray(payload.teams) ? payload.teams : [];
                const allPlayersTeam = teams.find((team) => team?.id === '__all_players__') || {};
                const freeAgentsTeam = teams.find((team) => team?.id === '__free_agents__') || {};
                const availablePlayers = Array.isArray(freeAgentsTeam.players) ? freeAgentsTeam.players : [];

                this.allPlayers = Array.isArray(allPlayersTeam.players) ? allPlayersTeam.players : [];
                this.availablePlayers = availablePlayers;
                this.availablePlayersById = Object.fromEntries(
                    availablePlayers.map((player) => [String(player.id), player]),
                );
                this.playerTeamOptions = [...new Set(
                    availablePlayers
                        .map((player) => String(player.team_abbrev || '').toUpperCase().trim())
                        .filter(Boolean),
                )].sort();
                this.canShowLeagueStats = Boolean(payload.canShowLeagueStats ?? this.canShowLeagueStats);
                this.leagueStatsPayloadUrl = payload.leagueStatsPayloadUrl ?? this.leagueStatsPayloadUrl;
                this.playersPayloadLoaded = true;
            } catch (error) {
                this.playersPayloadError = error?.message || 'Could not load draft players.';
            } finally {
                this.playersPayloadLoading = false;
            }
        },
        playerPerspectiveRow(player) {
            return this.playerPerspectiveRowsById[String(player?.id ?? '')] || {};
        },
        sortPlayerTable(key) {
            if (this.playerSortKey === key) {
                this.playerSortDirection = this.playerSortDirection === 'desc' ? 'asc' : 'desc';
                return;
            }

            this.playerSortKey = key;
            this.playerSortDirection = 'desc';
        },
        playerSortIndicator(key) {
            if (this.playerSortKey !== key) return '';

            return this.playerSortDirection === 'desc' ? '↓' : '↑';
        },
        sortedDraftPlayers(players) {
            if (!this.playerSortKey) return players;

            const direction = this.playerSortDirection === 'asc' ? 1 : -1;
            const key = this.playerSortKey;

            return [...players].sort((a, b) => {
                const left = this.playerSortValue(a, key);
                const right = this.playerSortValue(b, key);

                if (typeof left === 'number' && typeof right === 'number') {
                    return (left - right) * direction;
                }

                return String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' }) * direction;
            });
        },
        playerSortValue(player, key) {
            if (key === 'rank') return Number(player?.rank ?? 0);
            if (key === 'name') return String(player?.name ?? '');
            if (key === 'age') return Number(player?.age ?? 0);
            if (key === 'league') return this.playerLeagueName(player);
            if (key === 'gp') return Number(this.playerGp(player) ?? 0);

            const value = this.playerPerspectiveValue(player, key);

            if (value === null || value === undefined || value === '') {
                return this.playerSortDirection === 'asc' ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY;
            }

            if (typeof value === 'number') return value;

            const numeric = Number(value);

            return Number.isNaN(numeric) ? String(value) : numeric;
        },
        perspectivePlayerId(row) {
            return String(row?.id ?? row?.player_id ?? '');
        },
        playerAge(player) {
            return player?.age ?? this.playerPerspectiveRow(player)?.age ?? '-';
        },
        playerLeagueName(player) {
            return player?.league || player?.league_abbrev || this.playerPerspectiveRow(player)?.league || this.playerPerspectiveRow(player)?.league_abbrev || '-';
        },
        playerGp(player) {
            return player?.gp ?? this.playerPerspectiveRow(player)?.gp ?? '-';
        },
        playerPerspectiveValue(player, key) {
            const row = this.playerPerspectiveRow(player);
            const stats = row?.stats && typeof row.stats === 'object' ? row.stats : {};

            return stats[key] ?? row[key] ?? null;
        },
        formatPerspectiveValue(value) {
            if (value === null || value === undefined || value === '') return '-';

            if (typeof value === 'number') {
                return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.?0+$/, '');
            }

            return String(value);
        },
        resetPlayerPerspectiveRows() {
            this.playerPerspectiveHeadings = [];
            this.playerPerspectiveRows = [];
            this.playerPerspectiveRowsById = {};
            this.playerPerspectiveLoaded = false;
        },
        async loadPlayerPerspectiveStats(force = false) {
            if (this.showingQueuePerspective || (this.playerPerspectiveLoading && !force)) return;

            if (!this.canShowLeagueStats || !this.leagueStatsPayloadUrl) {
                this.playerPerspectiveLoaded = true;
                this.playerPerspectiveLoading = false;
                return;
            }

            const perspective = this.selectedPlayerPerspective || this.playerPerspectiveOptions[0]?.slug || '';
            if (!perspective) {
                this.playerPerspectiveLoaded = true;
                this.playerPerspectiveLoading = false;
                return;
            }

            const requestKey = perspective;
            if (this.playerPerspectiveRequestKey === requestKey && this.playerPerspectiveHeadings.length > 0) {
                this.playerPerspectiveLoaded = true;
                this.playerPerspectiveLoading = false;
                return;
            }

            const requestToken = this.playerPerspectiveRequestToken + 1;
            this.playerPerspectiveRequestToken = requestToken;

            if (this.playerPerspectiveCache[requestKey]) {
                this.applyPlayerPerspectivePayload(this.playerPerspectiveCache[requestKey], requestKey, requestToken);
                if (this.playerPerspectiveRequestToken === requestToken) {
                    this.playerPerspectiveLoading = false;
                }
                return;
            }

            this.playerPerspectiveLoading = true;
            this.playerPerspectiveError = '';

            try {
                const params = new URLSearchParams();
                params.set('perspective', perspective);
                params.set('resource', 'players');
                params.set('period', 'season');
                params.set('slice', 'total');
                params.set('game_type', '2');
                params.set('availability', '0');
                params.set('draft_context', '1');

                const response = await fetch(`${this.leagueStatsPayloadUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Could not load player stats.');
                }

                this.playerPerspectiveCache[requestKey] = payload;
                this.applyPlayerPerspectivePayload(payload, requestKey, requestToken);
            } catch (error) {
                if (this.playerPerspectiveRequestToken === requestToken) {
                    this.playerPerspectiveError = error?.message || 'Could not load player stats.';
                    this.playerPerspectiveLoaded = true;
                }
            } finally {
                if (this.playerPerspectiveRequestToken === requestToken) {
                    this.playerPerspectiveLoading = false;
                }
            }
        },
        applyPlayerPerspectivePayload(payload, requestKey, requestToken = this.playerPerspectiveRequestToken) {
            if (requestToken !== this.playerPerspectiveRequestToken) return;

            const allowed = this.draftPerspectiveSlugs;
            const perspectives = Array.isArray(payload.perspectives)
                ? payload.perspectives.filter((perspective) => allowed.includes(String(perspective?.slug ?? perspective?.id ?? perspective?.name ?? '')))
                : [];
            this.playerPerspectiveOptions = perspectives.length ? perspectives : this.playerPerspectiveOptions;
            if (!this.showingQueuePerspective) {
                this.selectedPlayerPerspective = allowed.includes(String(payload.selectedPerspective ?? ''))
                    ? payload.selectedPerspective
                    : requestKey;
            }
            this.playerPerspectiveHeadings = Array.isArray(payload.headings) ? payload.headings : [];
            this.playerPerspectiveRows = Array.isArray(payload.data) ? payload.data : [];
            this.playerPerspectiveRowsById = Object.fromEntries(
                this.playerPerspectiveRows
                    .filter((row) => this.perspectivePlayerId(row) !== '')
                    .map((row) => [this.perspectivePlayerId(row), row]),
            );
            this.playerPerspectiveRequestKey = requestKey;
            this.playerPerspectiveLoaded = true;
        },
        setPlayerPerspective(value) {
            this.selectedPlayerPerspective = value;
            this.posTypeFilter = '';
            this.playerPerspectiveRequestKey = '';
            this.playerPerspectiveLoading = true;
            this.resetPlayerPerspectiveRows();
            if (this.showingQueuePerspective) {
                this.playerPerspectiveLoading = false;
                this.refreshDraftQueuePayload();
                return;
            }

            this.loadPlayerPerspectiveStats(true);
        },
        applyDraftQueuePayload(items) {
            this.draftQueueItems = Array.isArray(items) ? items : [];
            this.queuedPlayerIds = Object.fromEntries(
                this.draftQueueItems
                    .filter((item) => item?.player_id)
                    .map((item) => [String(item.player_id), true]),
            );
        },
        async refreshDraftQueuePayload() {
            if (!this.draftQueuePayloadUrl || this.queuePerspectiveLoading) return;

            this.queuePerspectiveLoading = true;
            this.queuePerspectiveError = '';

            try {
                const response = await fetch(this.draftQueuePayloadUrl, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Could not load queue.');
                }

                this.applyDraftQueuePayload(payload.items || []);
            } catch (error) {
                this.queuePerspectiveError = error?.message || 'Could not load queue.';
            } finally {
                this.queuePerspectiveLoading = false;
            }
        },
        isPlayerQueued(player) {
            return Boolean(this.queuedPlayerIds[String(player?.id ?? '')]);
        },
        queueLoading(player) {
            return Boolean(this.queueRequestLoadingByPlayer[String(player?.id ?? '')]);
        },
        async addPlayerToQueue(player) {
            const playerId = String(player?.id ?? '');
            if (!playerId || !this.draftQueueStoreUrl || this.isPlayerQueued(player) || this.queueLoading(player)) return;

            this.queueRequestLoadingByPlayer = { ...this.queueRequestLoadingByPlayer, [playerId]: true };

            try {
                const response = await fetch(this.draftQueueStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({ player_id: Number(playerId) }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Could not add player to queue.');
                }

                const item = payload.item || {
                    player_id: Number(playerId),
                    name: player.name,
                    position: player.position,
                    team_abbrev: player.team_abbrev,
                    age: player.age,
                    avatar_url: player.avatar_url,
                };

                if (!this.draftQueueItems.some((queueItem) => String(queueItem.player_id) === playerId)) {
                    this.draftQueueItems = [...this.draftQueueItems, item];
                }

                this.queuedPlayerIds = { ...this.queuedPlayerIds, [playerId]: true };

                if (this.showingQueuePerspective) {
                    this.refreshDraftQueuePayload();
                }
            } catch (error) {
                this.draftRequestError = error?.message || 'Could not add player to queue.';
            } finally {
                const nextLoading = { ...this.queueRequestLoadingByPlayer };
                delete nextLoading[playerId];
                this.queueRequestLoadingByPlayer = nextLoading;
            }
        },
        async removeQueueItem(item) {
            const playerId = String(item?.player_id ?? '');
            if (!playerId || !item?.delete_url || this.queueLoading({ id: playerId })) return;

            this.queueRequestLoadingByPlayer = { ...this.queueRequestLoadingByPlayer, [playerId]: true };

            try {
                const response = await fetch(item.delete_url, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Could not remove player from queue.');
                }

                this.draftQueueItems = this.draftQueueItems.filter((queueItem) => String(queueItem.player_id) !== playerId);

                const nextQueued = { ...this.queuedPlayerIds };
                delete nextQueued[playerId];
                this.queuedPlayerIds = nextQueued;

                if (this.showingQueuePerspective) {
                    this.refreshDraftQueuePayload();
                }
            } catch (error) {
                this.draftRequestError = error?.message || 'Could not remove player from queue.';
            } finally {
                const nextLoading = { ...this.queueRequestLoadingByPlayer };
                delete nextLoading[playerId];
                this.queueRequestLoadingByPlayer = nextLoading;
            }
        },
        setActiveRound(index) {
            this.activeRound = index;
            this.$nextTick(() => this.updateRoundScrollAffordance());
        },
        scrollToNextPick() {
            const target = this.$el.querySelector('[data-next-pick=true]');
            const scroller = target?.closest?.('[data-draft-round-index]');
            if (!target || !scroller) return;

            this.activeRound = Number(scroller.dataset.draftRoundIndex || 0);
            this.$nextTick(() => {
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        this.$el.querySelectorAll('[data-draft-round-index]').forEach((roundScroller) => {
                            roundScroller.scrollTop = 0;
                        });

                        const activeScroller = this.$el.querySelector(`[data-draft-round-index='${this.activeRound}']`);
                        const activeTarget = activeScroller?.querySelector?.('[data-next-pick=true]');

                        if (!activeScroller || !activeTarget) return;

                        activeScroller.scrollTop = Math.max(0, activeTarget.offsetTop - 48);
                        this.updateRoundScrollAffordance();
                    });
                });
            });
        },
        updateDraftLivePanelHeight() {
            const panel = this.$refs.draftTabBody;
            const supportPanel = this.$refs.draftSupportPanel;
            if (!panel && !supportPanel) return;

            window.requestAnimationFrame(() => {
                if (panel) {
                    const top = panel.getBoundingClientRect().top;
                    const visibleTop = Math.max(top, 0);
                    const availableHeight = Math.min(
                        window.innerHeight - this.draftPanelBottomGap,
                        Math.max(320, window.innerHeight - visibleTop - this.draftPanelBottomGap),
                    );

                    this.draftPanelHeight = availableHeight;
                    this.$el.style.setProperty('--draft-live-panel-height', `${availableHeight}px`);
                }

                if (supportPanel) {
                    const supportTop = supportPanel.getBoundingClientRect().top;
                    this.draftSupportPanelHeight = Math.min(
                        window.innerHeight - this.draftPanelBottomGap,
                        Math.max(320, window.innerHeight - Math.max(supportTop, 0) - this.draftPanelBottomGap),
                    );
                }
            });
        },
        updateRoundScrollAffordance() {
            const scroller = this.$refs.roundTabsScroller;
            if (!scroller) return;

            const maxScrollLeft = scroller.scrollWidth - scroller.clientWidth;

            this.roundScrollCanLeft = scroller.scrollLeft > 2;
            this.roundScrollCanRight = scroller.scrollLeft < maxScrollLeft - 2;
        },
        playerInitials(player) {
            return String(player?.name || '')
                .trim()
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map((part) => part.slice(0, 1).toUpperCase())
                .join('') || 'DI';
        },
        async submitDraftRequest(mode, url) {
            if (!url || this.draftRequestLoading) return;

            this.draftRequestLoading = true;
            this.draftRequestMessage = '';
            this.draftRequestError = '';

            try {
                const response = await fetch(url, {
                    method: mode === 'settings' ? 'PUT' : 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        mode,
                        pick_clock_minutes: Number(this.draftPickClockMinutes || 0),
                        pause_between_picks_seconds: Number(this.draftPauseSeconds || 0),
                        auto_pick_enabled: Boolean(this.draftAutoPickEnabled),
                    }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Could not update draft.');
                }

                this.draftRequestMessage = payload.message || 'Draft updated.';

                if (payload.html) {
                    this.draftCreateOpen = false;
                    this.draftOptionsOpen = false;

                    const template = document.createElement('template');
                    template.innerHTML = payload.html.trim();
                    const next = template.content.firstElementChild;

                    if (next) {
                        this.$el.replaceWith(next);
                        window.Alpine?.initTree(next);
                    }
                }
            } catch (error) {
                this.draftRequestError = error?.message || 'Could not update draft.';
            } finally {
                this.draftRequestLoading = false;
            }
        },
        createDraft(mode) {
            return this.submitDraftRequest(mode, this.createDraftUrl);
        },
        saveDraftSettings() {
            return this.submitDraftRequest('settings', this.draftSettingsUrl);
        },
    }"
    x-on:fantrax:draft-pick.window="refreshDraftQueuePayload()"
    class="space-y-4 overflow-hidden"
>
    @if (! $hasCanonicalDraft)
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_18%_10%,rgba(59,130,246,0.13),transparent_28%),linear-gradient(135deg,rgba(248,250,252,1),rgba(239,246,255,0.75))]" aria-hidden="true"></div>
            <div class="relative grid gap-8 px-8 py-10 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-center">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-100">
                        <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                        Draft Central
                    </div>
                    <h3 class="mt-5 text-2xl font-semibold tracking-tight text-slate-950">No draft has been configured for this league.</h3>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-slate-600">
                        Create a draft room for this league. You can mirror Fantrax as a read-only source, or run a manual DynastyIQ draft that stays independent from Fantrax until you decide to export or reconcile results.
                    </p>
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        @if ($canManageDraft)
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-slate-800"
                                x-on:click="draftCreateOpen = true"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                    <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/>
                                </svg>
                                Create draft
                            </button>
                        @else
                            <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600 shadow-sm">
                                A league commissioner can create the draft room.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-xl ring-1 ring-slate-200/70">
                    <div class="grid gap-3">
                        <div class="rounded-xl border border-blue-100 bg-blue-50/70 p-4">
                            <div class="text-sm font-semibold text-slate-950">Connect Fantrax draft</div>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Mirrors Fantrax picks into canonical draft tables. Fantrax remains the live source.</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="text-sm font-semibold text-slate-950">Manual draft</div>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Starts a DynastyIQ-managed board with no Fantrax draft dependency.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($canManageDraft)
        <x-ui.slide-over show="draftCreateOpen" close-action="draftCreateOpen = false" title-id="draft-create-title" max-width="max-w-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <div>
                    <h2 id="draft-create-title" class="text-sm font-semibold text-slate-950">Create draft</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $league?->name }} draft setup</p>
                </div>
                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600" x-on:click="draftCreateOpen = false" aria-label="Close create draft">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                    </svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-6">
                <div class="grid gap-3">
                    @if ($isFantraxLeague)
                        <div class="rounded-xl border border-blue-300 bg-blue-50 p-4 text-left ring-1 ring-blue-100">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Connect Fantrax draft</div>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">Use Fantrax as the read-only draft source and mirror picks into DynastyIQ.</p>
                                </div>
                                <span class="rounded-full bg-blue-100 px-2 py-1 text-[10px] font-semibold uppercase text-blue-700">Mirror</span>
                            </div>
                        </div>
                    @else
                        <div class="rounded-xl border border-slate-400 bg-slate-50 p-4 text-left ring-1 ring-slate-200">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-semibold text-slate-950">Manual DynastyIQ draft</div>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">Run a draft board here without reading platform draft results.</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-semibold uppercase text-slate-600">Manual</span>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="mt-5 rounded-xl border border-slate-200 bg-white p-4">
                    <div class="text-sm font-semibold text-slate-950">Timer settings</div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-600">Pick clock minutes</span>
                            <input type="number" min="0" max="1440" x-model.number="draftPickClockMinutes" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold text-slate-600">Pause between picks</span>
                            <input type="number" min="0" max="3600" x-model.number="draftPauseSeconds" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                        </label>
                    </div>
                    <label class="mt-4 flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2">
                        <input type="checkbox" x-model="draftAutoPickEnabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                        <span class="text-sm font-medium text-slate-700">Enable auto-pick</span>
                    </label>
                </div>

                <div class="mt-4 min-h-5 text-xs">
                    <span x-show="draftRequestMessage" class="text-emerald-700" x-text="draftRequestMessage"></span>
                    <span x-show="draftRequestError" class="text-red-600" x-text="draftRequestError"></span>
                </div>
            </div>

            <div class="border-t border-slate-200 px-6 py-4">
                <button type="button" class="inline-flex w-full items-center justify-center rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60" x-on:click="createDraft(draftCreateMode)" :disabled="draftRequestLoading">
                    <span x-show="!draftRequestLoading">Create draft</span>
                    <span x-show="draftRequestLoading">Creating...</span>
                </button>
            </div>
        </x-ui.slide-over>
        @endif
    @else
    <div class="grid gap-3 lg:grid-cols-[minmax(0,1.15fr)_13rem_minmax(0,1fr)_minmax(0,1.45fr)]">
        <div class="rounded-xl border border-blue-100 bg-white px-4 py-3 shadow-sm">
            <div class="text-[11px] font-semibold uppercase tracking-wide text-blue-600">On The Clock</div>
            <div class="mt-2 flex min-w-0 items-center gap-3">
                @if (! empty($nextPick['team_avatar_url']))
                    <img src="{{ $nextPick['team_avatar_url'] }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                @else
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">OTC</span>
                @endif
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ data_get($nextPick, 'team_name', 'Awaiting pick') }}</div>
                    <div class="mt-0.5 text-xs text-slate-500">
                        Round {{ data_get($nextPick, 'round', '-') }}, Pick {{ data_get($nextPick, 'pick_in_round', data_get($nextPick, 'pick', '-')) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Time Remaining</div>
            <div class="mt-2 grid grid-cols-[minmax(0,1fr)_2.25rem] items-center gap-2">
                <div class="min-w-0 truncate text-2xl font-semibold tracking-tight text-slate-950 tabular-nums" x-text="draftTimerLabel">--:--</div>
                <span class="flex h-9 w-9 justify-self-end rounded-full p-1" :style="draftTimerRingStyle" aria-hidden="true">
                    <span class="h-full w-full rounded-full bg-white"></span>
                </span>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Up Next</div>
            <div class="mt-2 flex min-w-0 items-center gap-3">
                @if (! empty($upNextPick['team_avatar_url']))
                    <img src="{{ $upNextPick['team_avatar_url'] }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                @else
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">UP</span>
                @endif
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ data_get($upNextPick, 'team_name', 'No upcoming pick') }}</div>
                    <div class="mt-0.5 text-xs text-slate-500">
                        Round {{ data_get($upNextPick, 'round', '-') }}, Pick {{ data_get($upNextPick, 'pick_in_round', data_get($upNextPick, 'pick', '-')) }}
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="flex items-baseline gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        <span>Round Progress</span>
                        <span class="font-semibold tracking-normal text-slate-900">{{ $draftedCount }} / {{ $totalPicks }}</span>
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-xs font-medium text-slate-500">
                        <span class="h-2 w-2 rounded-full {{ $statusDotClass }}"></span>
                        <span>{{ $drafting['status_text'] ?? 'Draft' }}</span>
                    </div>
                </div>
                <div class="text-right text-xs font-semibold text-slate-500">
                    @if ($picksUntilOtc === false)
                        <span>No upcoming pick</span>
                    @elseif ((int) $picksUntilOtc === 0)
                        <span class="text-orange-600">OTC</span>
                    @else
                        <span class="inline-flex items-baseline gap-1">
                            <span>OTC</span>
                            <span class="text-base font-bold leading-none text-slate-950">{{ (int) $picksUntilOtc }}</span>
                        </span>
                    @endif
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                @forelse ($draftRounds as $round)
                    <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-[11px] font-semibold {{ (int) ($round['round'] ?? 0) === (int) data_get($activeRound, 'round', 0) ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600' }}">
                        {{ $round['round'] ?? '?' }}
                    </span>
                @empty
                    <span class="text-xs text-slate-500">No draft rounds loaded.</span>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid min-h-0 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-4 pt-3">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex gap-6 text-sm font-semibold">
                        <button type="button" class="border-b-2 pb-3" :class="activePlayerTab === 'live' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'" x-on:click="setActivePlayerTab('live')">Live</button>
                        <button type="button" class="border-b-2 pb-3" :class="activePlayerTab === 'players' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'" x-on:click="setActivePlayerTab('players')">Players</button>
                        <button type="button" class="border-b-2 pb-3" :class="activePlayerTab === 'mine' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'" x-on:click="setActivePlayerTab('mine')">My Picks</button>
                    </div>
                    @if ($canManageDraft)
                        <button
                            type="button"
                            class="mb-2 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600"
                            x-on:click="draftOptionsOpen = !draftOptionsOpen"
                            aria-label="Toggle draft options"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.397-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </button>
                    @endif
                </div>
            </div>

            <div
                x-ref="draftTabBody"
                x-on:resize.window.debounce.150ms="updateDraftLivePanelHeight()"
                class="flex min-h-0 flex-col overflow-hidden"
                :style="`height: ${draftPanelHeight}px`"
            >
                <div x-show="activePlayerTab === 'live'" x-cloak class="h-full min-h-0 flex-1 overflow-hidden">
                    @include('leagues._draft-live-panel', [
                        'drafting' => $drafting,
                        'draftRounds' => $draftRounds,
                    ])
                </div>

                <div x-show="activePlayerTab === 'mine'" x-cloak class="min-h-0 flex-1 p-4">
                    @if ($myDraftPickRows->isEmpty())
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                            No picks have been made by your team yet.
                        </div>
                    @else
                        <ol class="h-full divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                            @foreach ($myDraftPickRows as $row)
                                @php
                                    $teamAbbrev = strtoupper((string) ($row['team_abbrev'] ?? ''));
                                    $teamBadgeBackground = $teamGradients[$teamAbbrev] ?? $fallbackTeamGradient;
                                    $pickInRound = $row['pick_in_round'] ?? $row['pick'] ?? null;
                                    $overallPick = $row['overall_pick'] ?? $row['pick'] ?? null;
                                    $roundPickLabel = ($row['round'] ?? null) && $pickInRound
                                        ? 'R' . $row['round'] . '-' . $pickInRound
                                        : '';
                                    $isGoalie = strtoupper((string) ($row['position'] ?? '')) === 'G';
                                    $statColumns = $isGoalie
                                        ? ['gp' => 'GP', 'wins' => 'W', 'sv_pct' => 'SV%']
                                        : ['gp' => 'GP', 'g' => 'G', 'a' => 'A', 'pts' => 'PTS'];
                                @endphp

                            <li class="grid grid-cols-[3.25rem_minmax(0,1fr)_4rem_minmax(8rem,0.75fr)] items-center gap-1.5 bg-white px-4 py-2">
                                <div class="flex flex-col items-center justify-center tabular-nums">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 bg-white text-xs font-semibold text-slate-700">
                                        {{ $overallPick ?? '-' }}
                                    </div>
                                    <div class="mt-1 text-[10px] font-medium text-slate-400">
                                        {{ $roundPickLabel }}
                                    </div>
                                </div>

                                <div class="flex min-w-0 items-center gap-3">
                                    <div x-show="showAvatars" class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                        @if (! empty($row['avatar_url']))
                                            <img src="{{ $row['avatar_url'] }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                        @else
                                            {{ collect(explode(' ', $row['player_name'] ?? ''))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: '?' }}
                                        @endif
                                    </div>

                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-slate-900">
                                            {{ $row['player_name'] }}
                                        </div>
                                        <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                            @if (! empty($row['position']))
                                                <span class="shrink-0">{{ $row['position'] }}</span>
                                            @endif
                                            @if (! empty($row['position']) && ! empty($row['league_abbrev']))
                                                <span class="shrink-0 text-slate-300">/</span>
                                            @endif
                                            @if (! empty($row['league_abbrev']))
                                                <span class="truncate">{{ $row['league_abbrev'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-center" x-show="showTeamBadges">
                                    <span
                                        class="inline-flex h-7 min-w-14 items-center justify-center rounded-md px-3 text-xs font-semibold tracking-wide text-white shadow-sm"
                                        style="background: {{ $teamBadgeBackground }};"
                                    >
                                        {{ $teamAbbrev !== '' ? $teamAbbrev : '-' }}
                                    </span>
                                </div>

                                <div class="grid gap-2 text-right tabular-nums {{ $isGoalie ? 'grid-cols-3' : 'grid-cols-4' }}">
                                    @foreach ($statColumns as $statKey => $label)
                                        <div>
                                            <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</div>
                                            <div class="text-xs font-semibold text-slate-900">
                                                @if ($statKey === 'sv_pct' && data_get($row, 'stats.' . $statKey) !== null)
                                                    {{ number_format((float) data_get($row, 'stats.' . $statKey), 3) }}
                                                @else
                                                    {{ data_get($row, 'stats.' . $statKey) ?? '-' }}
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </li>
                            @endforeach
                        </ol>
                    @endif
                </div>

                <div x-show="activePlayerTab === 'players'" x-cloak class="flex min-h-0 flex-1 flex-col overflow-hidden">
                    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50/70 px-3 py-2">
                        <div class="w-48 shrink-0">
                            <label class="sr-only" for="draft-player-search-{{ $league?->id }}">Search players</label>
                            <input
                                id="draft-player-search-{{ $league?->id }}"
                                type="search"
                                x-model.debounce.150ms="search"
                                class="h-9 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                                placeholder="Search players"
                            >
                        </div>

                        <label class="sr-only" for="draft-player-team-{{ $league?->id }}">Team</label>
                        <select
                            id="draft-player-team-{{ $league?->id }}"
                            x-model="selectedPlayerTeam"
                            class="h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm outline-none transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="">All Teams</option>
                            <template x-for="team in playerTeamOptions" :key="team">
                                <option :value="team" x-text="team"></option>
                            </template>
                        </select>

                        <label class="sr-only" for="draft-player-perspective-{{ $league?->id }}">Perspective</label>
                        <select
                            id="draft-player-perspective-{{ $league?->id }}"
                            x-model="selectedPlayerPerspective"
                            x-on:change="setPlayerPerspective($event.target.value)"
                            class="h-9 min-w-44 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm outline-none transition focus:border-blue-300 focus:ring-2 focus:ring-blue-100"
                        >
                            <template x-for="perspective in playerPerspectiveOptionsWithQueue" :key="perspective.slug || perspective.id || perspective.name">
                                <option :value="perspective.slug || perspective.id || perspective.name" x-text="perspective.name || perspective.slug"></option>
                            </template>
                        </select>

                        <div x-show="showSkaterTypeFilters" class="inline-flex h-9 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <button
                                type="button"
                                x-on:click="togglePosTypeFilter('D')"
                                class="w-9 text-xs font-semibold transition"
                                :class="posTypeFilter === 'D' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            >
                                D
                            </button>
                            <button
                                type="button"
                                x-on:click="togglePosTypeFilter('F')"
                                class="w-9 border-l border-slate-200 text-xs font-semibold transition"
                                :class="posTypeFilter === 'F' ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-50'"
                            >
                                F
                            </button>
                        </div>

                        <button
                            type="button"
                            x-show="filterCount > 0 || search"
                            x-on:click="resetFilters()"
                            class="h-9 rounded-lg px-2 text-xs font-semibold text-slate-500 transition hover:bg-white hover:text-slate-800"
                        >
                            Reset
                        </button>
                    </div>

                    <div x-show="playersPayloadError" class="border-b border-amber-100 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700" x-text="playersPayloadError"></div>
                    <div x-show="playerPerspectiveError" class="border-b border-amber-100 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700" x-text="playerPerspectiveError"></div>
                    <div x-show="queuePerspectiveError" class="border-b border-amber-100 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700" x-text="queuePerspectiveError"></div>

                    <div x-show="showingQueuePerspective" x-cloak class="min-h-0 flex-1 overflow-hidden p-4">
                        <div x-show="queuePerspectiveLoading" class="h-full overflow-hidden rounded-xl border border-slate-200">
                            <template x-for="index in 6" :key="`queue-loading-${index}`">
                                <div class="grid grid-cols-[3.25rem_minmax(0,1fr)_4rem_minmax(8rem,0.75fr)] items-center gap-1.5 border-b border-slate-100 bg-white px-4 py-2">
                                    <div class="flex flex-col items-center gap-1">
                                        <div class="h-8 w-8 animate-pulse rounded-full bg-slate-200"></div>
                                        <div class="h-2 w-8 animate-pulse rounded-full bg-slate-100"></div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 animate-pulse rounded-full bg-slate-200"></div>
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <div class="h-3 w-40 animate-pulse rounded-full bg-slate-200"></div>
                                            <div class="h-2 w-24 animate-pulse rounded-full bg-slate-100"></div>
                                        </div>
                                    </div>
                                    <div class="h-7 w-14 animate-pulse rounded-md bg-slate-200"></div>
                                    <div class="grid grid-cols-4 gap-2">
                                        <div class="h-4 animate-pulse rounded-full bg-slate-100"></div>
                                        <div class="h-4 animate-pulse rounded-full bg-slate-100"></div>
                                        <div class="h-4 animate-pulse rounded-full bg-slate-100"></div>
                                        <div class="h-4 animate-pulse rounded-full bg-slate-100"></div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div x-show="!queuePerspectiveLoading && filteredQueueDisplayPlayers.length === 0" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                            No queued players match this view.
                        </div>

                        <ol x-show="!queuePerspectiveLoading && filteredQueueDisplayPlayers.length > 0" class="h-full divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                            <template x-for="player in filteredQueueDisplayPlayers" :key="`queue-view-${player.id}`">
                                <li class="grid grid-cols-[3.25rem_minmax(0,1fr)_4rem_minmax(8rem,0.75fr)] items-center gap-1.5 bg-white px-4 py-2">
                                    <div class="flex flex-col items-center justify-center tabular-nums">
                                        <div class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 bg-white text-xs font-semibold text-slate-700" x-text="player.rank || '-'"></div>
                                    </div>

                                    <div class="flex min-w-0 items-center gap-3">
                                        <div x-show="showAvatars" class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                            <template x-if="player.avatar_url">
                                                <img :src="player.avatar_url" alt="" class="h-full w-full object-cover" loading="lazy">
                                            </template>
                                            <template x-if="!player.avatar_url">
                                                <span x-text="playerInitials(player)"></span>
                                            </template>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-slate-900" x-text="queuePlayerName(player)"></div>
                                            <div class="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-slate-500">
                                                <span x-show="player.position" class="shrink-0" x-text="player.position"></span>
                                                <span x-show="player.position && playerLeagueName(player) !== '-'" class="shrink-0 text-slate-300">/</span>
                                                <span x-show="playerLeagueName(player) !== '-'" class="truncate" x-text="playerLeagueName(player)"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex justify-center" x-show="showTeamBadges">
                                        <span class="inline-flex h-7 min-w-14 items-center justify-center rounded-md px-3 text-xs font-semibold tracking-wide text-white shadow-sm" :style="teamBadgeStyle(player.team_abbrev)" x-text="player.team_abbrev || '-'"></span>
                                    </div>

                                    <template x-if="String(player.position || '').toUpperCase() === 'G'">
                                        <div class="grid w-36 grid-cols-3 gap-2 justify-self-end text-right tabular-nums">
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">GP</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="formatPerspectiveValue(queueStatValue(player, 'gp'))"></div>
                                            </div>
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">W</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="formatPerspectiveValue(queueStatValue(player, 'wins'))"></div>
                                            </div>
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">SV%</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="queueSavePercentage(player)"></div>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="String(player.position || '').toUpperCase() !== 'G'">
                                        <div class="grid w-36 grid-cols-4 gap-2 justify-self-end text-right tabular-nums">
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">GP</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="formatPerspectiveValue(queueStatValue(player, 'gp'))"></div>
                                            </div>
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">G</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="formatPerspectiveValue(queueStatValue(player, 'g'))"></div>
                                            </div>
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">A</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="formatPerspectiveValue(queueStatValue(player, 'a'))"></div>
                                            </div>
                                            <div>
                                                <div class="text-[9px] font-semibold uppercase tracking-wide text-slate-400">PTS</div>
                                                <div class="text-xs font-semibold text-slate-900" x-text="formatPerspectiveValue(queueStatValue(player, 'pts'))"></div>
                                            </div>
                                        </div>
                                    </template>
                                </li>
                            </template>
                        </ol>
                    </div>

                    <div x-show="!showingQueuePerspective" class="min-h-0 flex-1 overflow-auto">
                        <table class="min-w-full divide-y divide-slate-100 text-left">
                            <thead class="sticky top-0 z-10 bg-slate-50 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th scope="col" class="w-10 px-3 py-2.5"></th>
                                    <th scope="col" class="w-14 px-4 py-2.5">
                                        <button type="button" class="inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortPlayerTable('rank')">
                                            <span>Rank</span>
                                            <span class="text-[10px] text-blue-600" x-text="playerSortIndicator('rank')"></span>
                                        </button>
                                    </th>
                                    <th scope="col" class="min-w-56 px-3 py-2.5">
                                        <button type="button" class="inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortPlayerTable('name')">
                                            <span>Player</span>
                                            <span class="text-[10px] text-blue-600" x-text="playerSortIndicator('name')"></span>
                                        </button>
                                    </th>
                                    <th scope="col" class="whitespace-nowrap px-2 py-2.5 text-right">
                                        <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortPlayerTable('age')">
                                            <span>Age</span>
                                            <span class="text-[10px] text-blue-600" x-text="playerSortIndicator('age')"></span>
                                        </button>
                                    </th>
                                    <th scope="col" class="min-w-24 px-2 py-2.5">
                                        <button type="button" class="inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortPlayerTable('league')">
                                            <span>League</span>
                                            <span class="text-[10px] text-blue-600" x-text="playerSortIndicator('league')"></span>
                                        </button>
                                    </th>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2.5 text-right">
                                        <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortPlayerTable('gp')">
                                            <span>GP</span>
                                            <span class="text-[10px] text-blue-600" x-text="playerSortIndicator('gp')"></span>
                                        </button>
                                    </th>
                                    <template x-for="heading in playerStatHeadings" :key="heading.key">
                                        <th scope="col" class="whitespace-nowrap px-3 py-2.5 text-right">
                                            <button type="button" class="ml-auto inline-flex items-center gap-1 transition hover:text-slate-800" x-on:click="sortPlayerTable(heading.key)">
                                                <span x-text="heading.label || heading.key"></span>
                                                <span class="text-[10px] text-blue-600" x-text="playerSortIndicator(heading.key)"></span>
                                            </button>
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white text-sm">
                            <template x-for="(player, index) in filteredDraftPlayers" :key="player.id">
                                <tr class="transition-colors hover:bg-slate-50/80" :class="isPlayerQueued(player) ? 'bg-blue-100 hover:bg-blue-100' : ''">
                                    <td class="px-3 py-2.5">
                                        <button
                                            type="button"
                                            x-show="!isPlayerQueued(player)"
                                            x-on:click="addPlayerToQueue(player)"
                                            :disabled="queueLoading(player)"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-sm font-semibold text-slate-600 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-wait disabled:opacity-60"
                                            aria-label="Add player to queue"
                                        >
                                            +
                                        </button>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs font-semibold text-slate-500" x-text="index + 1"></td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <template x-if="player.avatar_url">
                                                <img :src="player.avatar_url" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy">
                                            </template>
                                            <template x-if="!player.avatar_url">
                                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 ring-1 ring-slate-200" x-text="playerInitials(player)"></span>
                                            </template>
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-semibold text-slate-900" x-text="player.name"></div>
                                                <div class="mt-0.5 truncate text-[11px] text-slate-500">
                                                    <span x-text="player.team_abbrev || 'FA'"></span>
                                                    <span x-show="player.position"> / <span x-text="player.position"></span></span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-2 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="playerAge(player)"></td>
                                    <td class="whitespace-nowrap px-2 py-2.5 text-xs font-semibold text-slate-600" x-text="playerLeagueName(player)"></td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="playerGp(player)"></td>
                                    <template x-for="heading in playerStatHeadings" :key="`${player.id}-${heading.key}`">
                                        <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs font-semibold tabular-nums text-slate-700" x-text="formatPerspectiveValue(playerPerspectiveValue(player, heading.key))"></td>
                                    </template>
                                </tr>
                            </template>
                            <tr x-show="!isPlayerPerspectivePending && playerPerspectiveLoaded && filteredDraftPlayers.length === 0">
                                <td :colspan="Math.max(6 + playerStatHeadings.length, 6)" class="px-4 py-8 text-center text-sm text-slate-500">No players match this draft view.</td>
                            </tr>
                            <template x-for="index in (isPlayerPerspectivePending ? 6 : 0)" :key="`players-loading-${index}`">
                                <tr class="animate-pulse">
                                    <td class="px-3 py-2.5">
                                        <div class="h-7 w-7 rounded-full bg-slate-100"></div>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="h-3 w-5 rounded-full bg-slate-200"></div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <div class="h-8 w-8 shrink-0 rounded-full bg-slate-200"></div>
                                            <div class="min-w-0 flex-1 space-y-2">
                                                <div class="h-3 w-40 max-w-full rounded-full bg-slate-200"></div>
                                                <div class="h-2 w-24 rounded-full bg-slate-100"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <div class="h-3 w-16 rounded-full bg-slate-100"></div>
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div>
                                    </td>
                                    <template x-for="heading in playerStatHeadings" :key="`players-loading-${index}-${heading.key}`">
                                        <td class="px-3 py-2.5">
                                            <div class="ml-auto h-3 w-8 rounded-full bg-slate-100"></div>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

        <div x-ref="draftSupportPanel" class="flex h-full min-h-0 self-stretch overflow-hidden" :style="`height: ${draftSupportPanelHeight}px`">
            <div class="flex h-full min-h-0 flex-1 flex-col rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex border-b border-slate-200 px-3 pt-3 text-xs font-semibold">
                    <button type="button" class="border-b-2 border-blue-600 px-3 pb-2 text-blue-700">My Queue</button>
                </div>

                <div class="flex min-h-0 flex-1 flex-col p-4">
                    <div x-show="draftQueueItems.length === 0" class="flex min-h-0 flex-1 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Queue is empty</div>
                            <div class="mt-1 text-xs leading-5 text-slate-500">Use the plus action to prepare your draft targets.</div>
                        </div>
                    </div>

                    <ol x-show="draftQueueItems.length > 0" class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                        <template x-for="(item, index) in draftQueueItems" :key="item.id || item.player_id">
                            <li class="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-100 px-3 py-2">
                                <div class="w-5 shrink-0 text-center text-[11px] font-semibold tabular-nums text-blue-700" x-text="index + 1"></div>
                                <template x-if="item.avatar_url">
                                    <img :src="item.avatar_url" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-blue-200" loading="lazy">
                                </template>
                                <template x-if="!item.avatar_url">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-semibold text-blue-700 ring-1 ring-blue-200" x-text="playerInitials(item)"></span>
                                </template>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold text-slate-900" x-text="item.name"></div>
                                    <div class="mt-0.5 truncate text-[11px] text-slate-600">
                                        <span x-text="item.position || '-'"></span>
                                        <span class="text-slate-300"> / </span>
                                        <span x-text="item.team_abbrev || '-'"></span>
                                        <span class="text-slate-300"> / </span>
                                        <span>Age </span><span x-text="item.age ?? '-'"></span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    x-on:click="removeQueueItem(item)"
                                    :disabled="queueLoading({ id: item.player_id })"
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-blue-200 bg-white text-sm font-semibold text-slate-500 shadow-sm transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 disabled:cursor-wait disabled:opacity-60"
                                    aria-label="Remove player from queue"
                                >
                                    -
                                </button>
                            </li>
                        </template>
                    </ol>
                </div>

            </div>

        </div>
    </div>

    @if ($canManageDraft)
    <x-ui.slide-over show="draftOptionsOpen" close-action="draftOptionsOpen = false" title-id="draft-options-title" max-width="max-w-md">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h2 id="draft-options-title" class="text-base font-semibold text-slate-950">Draft options</h2>
            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600" x-on:click="draftOptionsOpen = false" aria-label="Close draft options">
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 1 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                </svg>
            </button>
        </div>

        <div class="flex-1 divide-y divide-slate-200 overflow-y-auto px-5">
            <section class="py-4">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Quick Actions</div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">My Queue</button>
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">Export Board</button>
                </div>
            </section>

            @if ($canManageDraft && $draftSettingsUrl !== '')
                <section class="py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Draft Preferences</div>
                    <div class="mt-3 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-semibold text-slate-600">Pick clock minutes</span>
                                <input type="number" min="0" max="1440" x-model.number="draftPickClockMinutes" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold text-slate-600">Pause seconds</span>
                                <input type="number" min="0" max="3600" x-model.number="draftPauseSeconds" class="mt-1 block w-full rounded-lg border-0 bg-white py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600">
                            </label>
                        </div>
                        <label class="flex items-center gap-3 rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">
                            <input type="checkbox" x-model="draftAutoPickEnabled" class="rounded border-slate-300 text-blue-600 focus:ring-blue-600">
                            <span class="text-sm font-medium text-slate-700">Enable auto-pick</span>
                        </label>
                        <div class="min-h-5 text-xs">
                            <span x-show="draftRequestMessage" class="text-emerald-700" x-text="draftRequestMessage"></span>
                            <span x-show="draftRequestError" class="text-red-600" x-text="draftRequestError"></span>
                        </div>
                        <button type="button" class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-500 disabled:cursor-not-allowed disabled:opacity-60" x-on:click="saveDraftSettings()" :disabled="draftRequestLoading">
                            <span x-show="!draftRequestLoading">Save draft settings</span>
                            <span x-show="draftRequestLoading">Saving...</span>
                        </button>
                    </div>
                </section>
            @endif

            @foreach ([
                'Auto-Pick' => ['Enable Auto-Pick', 'Auto-Pick Strategy'],
                'Queue Settings' => ['Queue Prioritization', 'Max Queue Size', 'Duplicates'],
                'Sound & Notifications' => ['Pick Time Alerts', 'Sound Notifications'],
                'League Info' => ['League Home', 'View Managers', 'Draft Order'],
            ] as $section => $items)
                <section class="py-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $section }}</div>
                    <div class="mt-2 space-y-1">
                        @foreach ($items as $item)
                            <button type="button" class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-slate-50">
                                <span class="text-sm font-semibold text-slate-800">{{ $item }}</span>
                                <svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                </svg>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </x-ui.slide-over>
    @endif
    @endif
</section>
