<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncFantraxLeagueJob;
use App\Models\FantraxPlayer;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\Stat;
use App\Services\FantraxDraftingWindow;
use App\Services\FantasyLeagueAccess;
use App\Services\YahooFantasyLeagueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class LeagueController extends Controller
{
    /**
     * Available nhl_season_stats fields for scoring category alignment.
     *
     * @var array<int,array{key:string,label:string}>
     */
    private const AVAILABLE_STAT_FIELDS = [
        ['key' => 'gp', 'label' => 'Games Played'],
        ['key' => 'g', 'label' => 'Goals'],
        ['key' => 'a', 'label' => 'Assists'],
        ['key' => 'pts', 'label' => 'Points'],
        ['key' => 'plus_minus', 'label' => 'Plus/Minus'],
        ['key' => 'pim', 'label' => 'Penalty Minutes'],
        ['key' => 'ppg', 'label' => 'Power Play Goals'],
        ['key' => 'ppa', 'label' => 'Power Play Assists'],
        ['key' => 'ppp', 'label' => 'Power Play Points'],
        ['key' => 'pkg', 'label' => 'Shorthanded Goals'],
        ['key' => 'pka', 'label' => 'Shorthanded Assists'],
        ['key' => 'pkp', 'label' => 'Shorthanded Points'],
        ['key' => 'gwg', 'label' => 'Game-Winning Goals'],
        ['key' => 'sog', 'label' => 'Shots on Goal'],
        ['key' => 'h', 'label' => 'Hits'],
        ['key' => 'b', 'label' => 'Blocks'],
        ['key' => 'fow', 'label' => 'Faceoffs Won'],
        ['key' => 'fol', 'label' => 'Faceoffs Lost'],
        ['key' => 'wins', 'label' => 'Wins'],
        ['key' => 'losses', 'label' => 'Losses'],
        ['key' => 'ot_losses', 'label' => 'Overtime Losses'],
        ['key' => 'sv', 'label' => 'Saves'],
        ['key' => 'sa', 'label' => 'Shots Against'],
        ['key' => 'ga', 'label' => 'Goals Against'],
        ['key' => 'gaa', 'label' => 'Goals Against Average'],
        ['key' => 'sv_pct', 'label' => 'Save Percentage'],
        ['key' => 'so', 'label' => 'Shutouts'],
        ['key' => 'shosv', 'label' => 'Shootout Saves'],
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Connect a fantasy provider to view leagues.');
        }

        $leagues = $leagueAccess->activeLeaguesForUser($user)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $activeLeagueId = $request->integer('active') ?? ($leagues->first()->id ?? null);
        $activeLeague = $activeLeagueId
            ? $leagues->firstWhere('id', $activeLeagueId)
            : $leagues->first();

        $teams = $activeLeague ? $this->teamsPayload($activeLeague) : [];
        $drafting = $activeLeague
            ? $this->draftingPayload($activeLeague)
            : app(FantraxDraftingWindow::class)->normalize([], []);

        return view('leagues', [
            'leagues' => $leagues,
            'activeLeagueId' => $activeLeague?->id,
            'activeLeague' => $activeLeague,
            'teams' => $teams,
            'drafting' => $drafting,
            'scoringCategories' => $activeLeague ? $this->scoringCategoriesPayload($activeLeague) : [],
            'scoringAlignmentCategories' => $activeLeague ? $this->scoringAlignmentCategoriesPayload($activeLeague) : [],
            'manualScoringMappings' => $activeLeague ? $this->manualScoringMappingsPayload($activeLeague) : [],
            'availableStatFields' => $this->availableStatFieldsPayload(),
            'searchPlayers' => $activeLeague ? $this->searchPlayersPayload() : [],
            'scoringSettingsUpdateUrl' => $activeLeague ? route('leagues.scoring-settings.update', $activeLeague->id) : '',
            'leagueStatsPayloadUrl' => $activeLeague ? route('leagues.stats.payload', $activeLeague->id) : '',
            'isScoringFullyMapped' => $activeLeague ? $this->isScoringFullyMapped($activeLeague) : false,
            'canShowLeagueStats' => $activeLeague ? $this->canShowLeagueStats($activeLeague) : false,
        ]);
    }

    public function panel(Request $request, string $leagueId): View|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Connect a fantasy provider to view leagues.');
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->firstOrFail();

        return view('leagues._panel', [
            'league' => $league,
            'teams'  => $this->teamsPayload($league),
            'drafting' => $this->draftingPayload($league),
            'scoringCategories' => $this->scoringCategoriesPayload($league),
            'scoringAlignmentCategories' => $this->scoringAlignmentCategoriesPayload($league),
            'manualScoringMappings' => $this->manualScoringMappingsPayload($league),
            'availableStatFields' => $this->availableStatFieldsPayload(),
            'searchPlayers' => $this->searchPlayersPayload(),
            'scoringSettingsUpdateUrl' => route('leagues.scoring-settings.update', $league->id),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $league->id),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($league),
            'canShowLeagueStats' => $this->canShowLeagueStats($league),
        ]);
    }

    /**
     * Persist manual scoring category mappings for the selected league.
     */
    public function updateScoringSettings(Request $request, string $leagueId): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before updating league settings.',
            ], 409);
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();
        $statKeys = collect($this->availableStatFieldsPayload())->pluck('key')->all();
        $validated = $request->validate([
            'mappings' => ['nullable', 'array'],
            'mappings.*' => ['nullable', 'string', Rule::in($statKeys)],
        ]);
        $manualMappings = collect($validated['mappings'] ?? [])
            ->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (string) $value])
            ->filter(static fn (string $value): bool => $value !== '')
            ->all();
        $scoringSettings = is_array($league->scoring_settings) ? $league->scoring_settings : [];
        $categories = $this->applyManualScoringMappingsToCategories(
            is_array($scoringSettings['categories'] ?? null) ? $scoringSettings['categories'] : [],
            $manualMappings,
        );

        $scoringSettings['manual_mappings'] = $manualMappings;
        $scoringSettings['categories'] = $categories;

        $league->forceFill([
            'scoring_settings' => $scoringSettings,
        ])->save();

        $league->refresh();

        return response()->json([
            'message' => 'Scoring category alignment saved.',
            'scoringCategories' => $this->scoringCategoriesPayload($league),
            'scoringAlignmentCategories' => $this->scoringAlignmentCategoriesPayload($league),
            'manualScoringMappings' => $this->manualScoringMappingsPayload($league),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($league),
            'canShowLeagueStats' => $this->canShowLeagueStats($league),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $league->id),
        ]);
    }

    /**
     * Refresh Yahoo leagues, teams, owned assignments, and queued roster syncs.
     */
    public function resyncYahoo(Request $request, YahooFantasyLeagueService $leagueService): JsonResponse
    {
        $connection = $request->user()
            ->yahooFantasyConnection()
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            return response()->json([
                'message' => 'Connect Yahoo before resyncing leagues.',
            ], 409);
        }

        return response()->json([
            'message' => 'Yahoo league sync queued roster refreshes.',
            'summary' => $leagueService->syncForConnection($connection, $request->user()->id),
        ]);
    }

    /**
     * Refresh all connected fantasy leagues for the current user.
     */
    public function resync(Request $request, YahooFantasyLeagueService $leagueService): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before resyncing leagues.',
            ], 409);
        }

        $summary = [
            'yahoo' => null,
            'fantrax' => [
                'platform' => 'fantrax',
                'platform_league_ids' => [],
                'queued_job_count' => 0,
            ],
        ];

        $connection = $user
            ->yahooFantasyConnection()
            ->where('status', 'connected')
            ->first();

        if ($connection) {
            $summary['yahoo'] = $leagueService->syncForConnection($connection, $user->id);
        }

        $fantraxLeagueIds = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.platform', 'fantrax')
            ->pluck('platform_leagues.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();

        foreach ($fantraxLeagueIds as $fantraxLeagueId) {
            SyncFantraxLeagueJob::dispatch($fantraxLeagueId, $user->id);
        }

        $summary['fantrax']['platform_league_ids'] = $fantraxLeagueIds;
        $summary['fantrax']['queued_job_count'] = count($fantraxLeagueIds);

        return response()->json([
            'message' => 'League sync queued.',
            'summary' => $summary,
        ]);
    }

    public function show(Request $request, string $leagueId): View|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return redirect()
                ->route('dashboard')
                ->with('status', 'Connect a fantasy provider to view leagues.');
        }

        $leagues = $leagueAccess->activeLeaguesForUser($user)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $activeLeague = $leagues->firstWhere('id', (int) $leagueId);
        abort_if($activeLeague === null, 404);

        return view('leagues', [
            'leagues' => $leagues,
            'activeLeagueId' => $activeLeague->id,
            'activeLeague' => $activeLeague,
            'teams' => $this->teamsPayload($activeLeague),
            'drafting' => $this->draftingPayload($activeLeague),
            'scoringCategories' => $this->scoringCategoriesPayload($activeLeague),
            'scoringAlignmentCategories' => $this->scoringAlignmentCategoriesPayload($activeLeague),
            'manualScoringMappings' => $this->manualScoringMappingsPayload($activeLeague),
            'availableStatFields' => $this->availableStatFieldsPayload(),
            'searchPlayers' => $this->searchPlayersPayload(),
            'scoringSettingsUpdateUrl' => route('leagues.scoring-settings.update', $activeLeague->id),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $activeLeague->id),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($activeLeague),
            'canShowLeagueStats' => $this->canShowLeagueStats($activeLeague),
        ]);
    }

    /**
     * Build the team, avatar, ownership, and roster payload for a league.
     */
    private function teamsPayload($league): array
    {
        $authId = auth()->id();
        $slotOrder = $league->rosterSlots()
            ->pluck('sort_order', 'slot')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $teams = $league->teams()
            ->select('id', 'platform_team_id', 'name')
            ->with([
                'roster' => static function ($q): void {
                    $q->select(
                        'players.id',
                        'players.full_name',
                        'players.first_name',
                        'players.last_name',
                        'players.position',
                        'players.pos_type',
                        'players.dob',
                        'players.team_abbrev',
                        'players.head_shot_url',
                        'players.is_goalie',
                        'players.status'
                    )->withPivot(['platform', 'platform_player_id', 'slot', 'status', 'eligibility', 'starts_at', 'ends_at']);
                },
                'users' => static function ($q): void {
                    $q->wherePivot('is_active', true)
                        ->select('users.id')
                        ->with(['socialAccounts:id,user_id,avatar']);
                },
            ])
            ->orderBy('name')
            ->get();

        $fantraxEligibility = $league->platform === 'fantrax'
            ? self::fantraxEligibilityByPlatformPlayerId($teams->pluck('roster')->flatten())
            : [];

        $teamRows = $teams
            ->map(static function ($t) use ($authId, $slotOrder, $fantraxEligibility, $league): array {
                // default avatar per TEAM name
                $defaultAvatar = config('ui.default_team_avatar')
                    ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->name) . '&background=E5E7EB&color=111827&size=64';

                $ownerAvatar = $defaultAvatar;
                foreach ($t->users as $u) {
                    $avatar = optional($u->socialAccounts->first())->avatar;
                    if (filled($avatar)) {
                        $ownerAvatar = (string) $avatar;
                        break;
                    }
                }

                $ownerIds = $t->users->pluck('id')->map(static fn ($v) => (int) $v)->values()->all();
                $ownedByMe = $authId ? in_array((int) $authId, $ownerIds, true) : false;

                return [
                    'id'                => (string) $t->platform_team_id,
                    'name'              => (string) $t->name,
                    'owner_avatar_url'  => $ownerAvatar,
                    'owned_by_me'       => $ownedByMe,
                    'owner_user_ids'    => $ownerIds,
                    'players'           => $t->roster
                        ->map(static function ($p) use ($slotOrder, $fantraxEligibility, $league, $t, $ownerAvatar): array {
                            $slot = (string) ($p->pivot->slot ?? '');
                            $rosterStatus = (string) ($p->pivot->status ?? '');
                            $eligibility = self::normalizeEligibility($p->pivot->eligibility);
                            $platformEligibility = self::platformEligibility(
                                (string) $league->platform,
                                (string) ($p->pivot->platform_player_id ?? ''),
                                $eligibility,
                                $fantraxEligibility,
                            );
                            $displaySlot = self::displayRosterSlot(
                                $slot,
                                $rosterStatus,
                                $platformEligibility,
                                (string) ($p->position ?? ''),
                            );
                            $rosterGroup = self::isMinorRosterRow($displaySlot, $rosterStatus)
                                ? 'minor'
                                : 'active';
                            $rosterSortOrder = $rosterGroup === 'minor'
                                ? self::minorRosterPositionSortOrder($platformEligibility)
                                : ($slotOrder[$slot] ?? self::fallbackRosterSlotOrder($displaySlot));

                            return [
                                'id'            => (int) $p->id,
                                'first_name'    => (string) ($p->first_name ?? ''),
                                'last_name'     => (string) ($p->last_name ?? ''),
                                'name'          => (string) ($p->full_name ?? trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''))),
                                'position'      => (string) ($p->position ?? ''),
                                'age'           => $p->age(),
                                'pos_type'      => (string) ($p->pos_type ?? ''),
                                'team_abbrev'   => (string) ($p->team_abbrev ?? ''),
                                'avatar_url'    => (string) ($p->head_shot_url ?? ''),
                                'is_goalie'     => (bool) $p->is_goalie,
                                'status'        => (string) $p->status,
                                'fantasy_team_id' => (string) $t->platform_team_id,
                                'fantasy_team_name' => (string) $t->name,
                                'fantasy_team_avatar_url' => $ownerAvatar,
                                'roster_slot'   => $displaySlot,
                                'roster_status' => $rosterStatus,
                                'roster_group'  => $rosterGroup,
                                'eligibility'   => $platformEligibility,
                                'starts_at'     => (string) $p->pivot->starts_at,
                                'ends_at'       => (string) $p->pivot->ends_at,
                                'roster_sort_order' => $rosterSortOrder,
                                'roster_group_sort_order' => $rosterGroup === 'minor' ? 1 : 0,
                                'roster_status_sort_order' => match ($rosterStatus) {
                                    'active' => 10,
                                    'bench' => 20,
                                    'ir' => 30,
                                    'na' => 40,
                                    'taxi' => 50,
                                    default => 90,
                                },
                            ];
                        })
                        ->sortBy(static fn (array $player): string => sprintf(
                            '%03d-%03d-%03d-%s',
                            $player['roster_group_sort_order'],
                            $player['roster_sort_order'],
                            $player['roster_status_sort_order'],
                            $player['name'],
                        ))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $freeAgents = $this->freeAgentsPayload($league);
        $allPlayers = collect($teamRows)
            ->pluck('players')
            ->flatten(1)
            ->concat($freeAgents)
            ->unique(static fn (array $player): int => (int) $player['id'])
            ->sortBy(static fn (array $player): string => (string) $player['name'])
            ->values()
            ->all();

        $teams = [[
            'id' => '__all_players__',
            'name' => 'All Players',
            'owner_avatar_url' => null,
            'owned_by_me' => false,
            'owner_user_ids' => [],
            'players' => $allPlayers,
        ], ...$teamRows];

        $teams[] = [
            'id' => '__free_agents__',
            'name' => 'Free Agents',
            'owner_avatar_url' => null,
            'owned_by_me' => false,
            'owner_user_ids' => [],
            'players' => $freeAgents,
        ];

        return $teams;
    }

    /**
     * Build the selected league draft panel from persisted Fantrax draft state.
     *
     * This intentionally does not hydrate missing draft data. League-page discovery
     * stays in the community league workflow where the platform context is explicit.
     */
    private function draftingPayload($league): array
    {
        if ((string) ($league->platform ?? '') !== 'fantrax') {
            return app(FantraxDraftingWindow::class)->normalize([], []);
        }

        $state = $league->fantraxDraftState()->first();

        if (! $state) {
            return app(FantraxDraftingWindow::class)->normalize([], []);
        }

        $draftResults = is_array($state->raw_draft_results) ? $state->raw_draft_results : [];
        $draftPickInfo = is_array($state->raw_draft_pick_info) ? $state->raw_draft_pick_info : [];

        if ($state->draft_at && ! isset($draftResults['draft_at'])) {
            $draftResults['draft_at'] = $state->draft_at->toIso8601String();
        }

        if ($state->current_draft_pick_count !== null && ! isset($draftPickInfo['currentDraftPicks'])) {
            $draftPickInfo['currentDraftPicks'] = array_fill(0, (int) $state->current_draft_pick_count, true);
        }

        $draftingWindow = app(FantraxDraftingWindow::class);
        $fantraxPlayerIds = $draftingWindow->fantraxPlayerIds($draftResults);

        return $draftingWindow->normalize(
            [],
            $draftResults,
            null,
            null,
            $this->fantraxDraftPlayerMap($fantraxPlayerIds),
            $this->fantraxDraftTeamMap((int) $league->id),
            $draftPickInfo,
        );
    }

    /**
     * Build a display map for drafted Fantrax player IDs from local identity tables.
     *
     * @param array<int,string> $fantraxPlayerIds
     *
     * @return array<string,array<string,mixed>>
     */
    private function fantraxDraftPlayerMap(array $fantraxPlayerIds): array
    {
        $fantraxPlayerIds = collect($fantraxPlayerIds)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($fantraxPlayerIds === []) {
            return [];
        }

        $map = [];

        $fantraxPlayers = FantraxPlayer::query()
            ->with('player:id,full_name,nhl_id,position,head_shot_url')
            ->whereIn('fantrax_id', $fantraxPlayerIds)
            ->get();
        $playerIds = $fantraxPlayers
            ->pluck('player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values()
            ->all();
        $latestStatsByPlayerId = $this->latestStatsByPlayerId($playerIds);

        $fantraxPlayers->each(static function (FantraxPlayer $fantraxPlayer) use (&$map, $latestStatsByPlayerId): void {
            $playerId = $fantraxPlayer->player_id ? (int) $fantraxPlayer->player_id : null;
            $latestStats = $playerId ? ($latestStatsByPlayerId[$playerId] ?? null) : null;

            $map[(string) $fantraxPlayer->fantrax_id] = [
                'name' => $fantraxPlayer->name ?: $fantraxPlayer->player?->full_name,
                'player_id' => $playerId,
                'nhl_id' => $fantraxPlayer->player?->nhl_id ? (int) $fantraxPlayer->player->nhl_id : null,
                'position' => $fantraxPlayer->player?->position ?: $fantraxPlayer->position,
                'league_abbrev' => $latestStats?->league_abbrev,
                'team_abbrev' => $latestStats?->nhl_team_abbrev,
                'avatar_url' => $fantraxPlayer->player?->head_shot_url,
                'stats' => [
                    'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                    'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                    'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                    'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                ],
            ];
        });

        $externalIdentities = PlayerExternalIdentity::query()
            ->with('player:id,full_name,nhl_id,position,head_shot_url')
            ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
            ->whereIn('provider_player_id', $fantraxPlayerIds)
            ->get();
        $identityPlayerIds = $externalIdentities
            ->pluck('player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values()
            ->all();
        $identityLatestStatsByPlayerId = $this->latestStatsByPlayerId($identityPlayerIds);

        $externalIdentities->each(function (PlayerExternalIdentity $identity) use (&$map, $identityLatestStatsByPlayerId): void {
            $fantraxId = (string) $identity->provider_player_id;
            $existing = $map[$fantraxId] ?? [];
            $playerId = $identity->player_id ? (int) $identity->player_id : null;
            $latestStats = $playerId ? ($identityLatestStatsByPlayerId[$playerId] ?? null) : null;

            $map[$fantraxId] = [
                'name' => $existing['name'] ?? $identity->display_name ?? $identity->player?->full_name,
                'player_id' => $existing['player_id'] ?? $playerId,
                'nhl_id' => $existing['nhl_id'] ?? ($identity->player?->nhl_id ? (int) $identity->player->nhl_id : null),
                'position' => $existing['position'] ?? $identity->position ?? $identity->player?->position,
                'league_abbrev' => $existing['league_abbrev'] ?? $latestStats?->league_abbrev,
                'team_abbrev' => $existing['team_abbrev'] ?? $latestStats?->nhl_team_abbrev,
                'avatar_url' => $existing['avatar_url'] ?? $identity->player?->head_shot_url,
                'stats' => $this->hasResolvedStats($existing['stats'] ?? null)
                    ? $existing['stats']
                    : [
                        'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                        'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                        'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                        'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                    ],
            ];
        });

        return $map;
    }

    /**
     * Determine whether a stored draft stats payload includes at least one rendered value.
     */
    private function hasResolvedStats(mixed $stats): bool
    {
        if (! is_array($stats)) {
            return false;
        }

        return collect(['gp', 'g', 'a', 'pts'])
            ->contains(static fn (string $key): bool => ($stats[$key] ?? null) !== null);
    }

    /**
     * Return the most-used league stat snapshot from each player's latest available season.
     *
     * @param array<int,int> $playerIds
     *
     * @return array<int,Stat>
     */
    private function latestStatsByPlayerId(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        return Stat::query()
            ->whereIn('player_id', $playerIds)
            ->orderByDesc('season_id')
            ->orderByDesc('gp')
            ->orderByDesc('updated_at')
            ->get(['player_id', 'league_abbrev', 'nhl_team_abbrev', 'gp', 'g', 'a', 'pts', 'season_id', 'updated_at'])
            ->groupBy(static fn (Stat $stat): int => (int) $stat->player_id)
            ->mapWithKeys(static function ($playerStats): array {
                $latestSeasonId = $playerStats->max('season_id');
                $stat = $playerStats
                    ->where('season_id', $latestSeasonId)
                    ->sortByDesc(static fn (Stat $stat): int => (int) $stat->gp)
                    ->first();

                return $stat ? [(int) $stat->player_id => $stat] : [];
            })
            ->all();
    }

    /**
     * Build drafting team owner avatar metadata keyed by Fantrax platform team id.
     *
     * @return array<string,array{owner_avatar_url:string|null}>
     */
    private function fantraxDraftTeamMap(?int $platformLeagueId): array
    {
        if (! $platformLeagueId) {
            return [];
        }

        return PlatformTeam::query()
            ->where('platform_league_id', $platformLeagueId)
            ->with(['users' => static function ($query): void {
                $query->wherePivot('is_active', true)
                    ->select('users.id')
                    ->with(['socialAccounts' => static function ($query): void {
                        $query->select('id', 'user_id', 'avatar')
                            ->where('provider', 'discord');
                    }]);
            }])
            ->get(['id', 'platform_team_id'])
            ->mapWithKeys(static function (PlatformTeam $team): array {
                $avatar = null;

                foreach ($team->users as $user) {
                    $avatar = optional($user->socialAccounts->first())->avatar;

                    if (filled($avatar)) {
                        break;
                    }
                }

                return [
                    (string) $team->platform_team_id => [
                        'owner_avatar_url' => filled($avatar) ? (string) $avatar : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Build free-agent player rows for the selected platform league.
     */
    private function freeAgentsPayload($league): array
    {
        $teamIds = $league->teams()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $players = Player::query()
            ->select([
                'id',
                'full_name',
                'first_name',
                'last_name',
                'position',
                'pos_type',
                'dob',
                'team_abbrev',
                'head_shot_url',
                'is_goalie',
                'status',
            ])
            ->when($teamIds !== [], static function ($query) use ($teamIds): void {
                $query->whereNotExists(static function ($subquery) use ($teamIds): void {
                    $subquery->selectRaw('1')
                        ->from('platform_roster_memberships')
                        ->whereIn('platform_team_id', $teamIds)
                        ->whereNull('ends_at')
                        ->whereColumn('platform_roster_memberships.player_id', 'players.id');
                });
            })
            ->orderBy('full_name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return $players
            ->map(static function (Player $player): array {
                $position = (string) ($player->position ?? '');

                return [
                    'id' => (int) $player->id,
                    'first_name' => (string) ($player->first_name ?? ''),
                    'last_name' => (string) ($player->last_name ?? ''),
                    'name' => (string) ($player->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? ''))),
                    'position' => $position,
                    'age' => $player->age(),
                    'pos_type' => (string) ($player->pos_type ?? ''),
                    'team_abbrev' => (string) ($player->team_abbrev ?? ''),
                    'avatar_url' => (string) ($player->head_shot_url ?? ''),
                    'is_goalie' => (bool) $player->is_goalie,
                    'status' => (string) $player->status,
                    'roster_slot' => 'FA',
                    'roster_status' => 'free_agent',
                    'roster_group' => 'active',
                    'eligibility' => self::realRosterPositions([$position]),
                    'starts_at' => '',
                    'ends_at' => '',
                    'roster_sort_order' => self::fallbackRosterSlotOrder($position),
                    'roster_group_sort_order' => 0,
                    'roster_status_sort_order' => 80,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build locally searchable player rows for client-side filtering.
     */
    private function searchPlayersPayload(): array
    {
        return Player::query()
            ->select([
                'id',
                'full_name',
                'first_name',
                'last_name',
                'position',
                'pos_type',
                'dob',
                'team_abbrev',
                'head_shot_url',
                'is_goalie',
                'status',
            ])
            ->orderBy('full_name')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(static function (Player $player): array {
                $position = (string) ($player->position ?? '');

                return [
                    'id' => (int) $player->id,
                    'first_name' => (string) ($player->first_name ?? ''),
                    'last_name' => (string) ($player->last_name ?? ''),
                    'name' => (string) ($player->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? ''))),
                    'position' => $position,
                    'age' => $player->age(),
                    'pos_type' => (string) ($player->pos_type ?? ''),
                    'team_abbrev' => (string) ($player->team_abbrev ?? ''),
                    'avatar_url' => (string) ($player->head_shot_url ?? ''),
                    'is_goalie' => (bool) $player->is_goalie,
                    'status' => (string) $player->status,
                    'roster_slot' => 'DB',
                    'roster_status' => 'database',
                    'roster_group' => 'active',
                    'eligibility' => self::realRosterPositions([$position]),
                    'starts_at' => '',
                    'ends_at' => '',
                    'roster_sort_order' => self::fallbackRosterSlotOrder($position),
                    'roster_group_sort_order' => 0,
                    'roster_status_sort_order' => 90,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build locally available scoring category labels for the selected league.
     */
    private function scoringCategoriesPayload($league): array
    {
        $settings = data_get($league, 'scoring_settings.categories')
            ?? data_get($league, 'scoring_settings')
            ?? data_get($league, 'settings.scoring_categories')
            ?? data_get($league, 'extras.scoring_categories')
            ?? [];

        if (! is_array($settings)) {
            return [];
        }

        $categories = array_is_list($settings) ? $settings : array_values($settings);

        return collect($categories)
            ->map(static function (mixed $category): ?array {
                if (is_string($category)) {
                    $label = trim($category);

                    return $label !== '' ? ['label' => $label, 'value' => null] : null;
                }

                if (! is_array($category)) {
                    return null;
                }

                $label = (string) (
                    $category['short']
                    ?? $category['label']
                    ?? $category['name']
                    ?? $category['code']
                    ?? ''
                );

                if (trim($label) === '') {
                    return null;
                }

                return [
                    'label' => trim($label),
                    'value' => $category['points'] ?? $category['value'] ?? null,
                    'stat_key' => $category['stat_key'] ?? null,
                    'is_mapped' => filled($category['stat_key'] ?? null),
                    'mapping_source' => $category['mapping_source'] ?? null,
                ];
            })
            ->filter()
            ->unique(static fn (array $category): string => $category['label'])
            ->values()
            ->all();
    }

    /**
     * Build editable Yahoo scoring alignment rows for the league options drawer.
     */
    private function scoringAlignmentCategoriesPayload($league): array
    {
        $settings = data_get($league, 'scoring_settings.categories') ?? [];

        if (! is_array($settings)) {
            return [];
        }

        return collect(array_is_list($settings) ? $settings : array_values($settings))
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(static function (array $category): array {
                return [
                    'id' => (string) ($category['id'] ?? $category['label'] ?? $category['name'] ?? ''),
                    'label' => (string) ($category['label'] ?? $category['short'] ?? $category['name'] ?? ''),
                    'name' => (string) ($category['name'] ?? ''),
                    'short' => (string) ($category['short'] ?? ''),
                    'value' => $category['points'] ?? $category['value'] ?? null,
                    'auto_stat_key' => $category['auto_stat_key'] ?? null,
                    'stat_key' => $category['stat_key'] ?? null,
                    'is_mapped' => filled($category['stat_key'] ?? null),
                    'mapping_source' => $category['mapping_source'] ?? null,
                ];
            })
            ->filter(static fn (array $category): bool => $category['id'] !== '' && $category['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * Build stored manual scoring mappings keyed by Yahoo stat id.
     *
     * @return array<string,string>
     */
    private function manualScoringMappingsPayload($league): array
    {
        $mappings = data_get($league, 'scoring_settings.manual_mappings', []);

        if (! is_array($mappings)) {
            return [];
        }

        return collect($mappings)
            ->mapWithKeys(static fn (mixed $value, mixed $key): array => [(string) $key => (string) $value])
            ->filter(static fn (string $value): bool => $value !== '')
            ->all();
    }

    /**
     * Build stat field options exposed to the scoring alignment drawer.
     *
     * @return array<int,array{key:string,label:string}>
     */
    private function availableStatFieldsPayload(): array
    {
        return self::AVAILABLE_STAT_FIELDS;
    }

    /**
     * Apply saved manual mappings to category rows without removing auto matches.
     *
     * @param array<int,array<string,mixed>> $categories
     * @param array<string,string> $manualMappings
     *
     * @return array<int,array<string,mixed>>
     */
    private function applyManualScoringMappingsToCategories(array $categories, array $manualMappings): array
    {
        return collect($categories)
            ->map(static function (array $category) use ($manualMappings): array {
                $statId = (string) ($category['id'] ?? '');
                $manualStatKey = $manualMappings[$statId] ?? null;
                $autoStatKey = $category['auto_stat_key'] ?? null;
                $statKey = $manualStatKey ?: $autoStatKey;

                $category['stat_key'] = $statKey;
                $category['is_mapped'] = $statKey !== null && $statKey !== '';
                $category['mapping_source'] = $manualStatKey ? 'manual' : ($autoStatKey ? 'auto' : null);

                return $category;
            })
            ->values()
            ->all();
    }

    /**
     * Determine whether every imported scoring category has a resolved stat key.
     */
    private function isScoringFullyMapped($league): bool
    {
        $categories = data_get($league, 'scoring_settings.categories', []);

        if (! is_array($categories) || $categories === []) {
            return false;
        }

        return collect(array_is_list($categories) ? $categories : array_values($categories))
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->every(static fn (array $category): bool => filled($category['stat_key'] ?? null));
    }

    /**
     * Determine whether the league stats component can be shown for this platform league.
     */
    private function canShowLeagueStats($league): bool
    {
        return match ((string) ($league->platform ?? '')) {
            'fantrax' => true,
            'yahoo' => $this->isScoringFullyMapped($league),
            default => false,
        };
    }

    /**
     * Normalize roster membership eligibility into a flat list for the UI.
     */
    private static function normalizeEligibility(mixed $eligibility): array
    {
        if ($eligibility === null || $eligibility === '') {
            return [];
        }

        if (is_array($eligibility)) {
            return collect($eligibility)->flatten()->filter()->values()->all();
        }

        if (! is_string($eligibility)) {
            return [];
        }

        $value = trim($eligibility);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect($decoded)->flatten()->filter()->values()->all();
        }

        return collect(explode(',', $value))
            ->map(static fn (string $position): string => trim($position))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve the eligibility list that should be displayed for a platform player.
     *
     * @param array<string,array<int,string>> $fantraxEligibility
     * @param array<int,string> $rowEligibility
     *
     * @return array<int,string>
     */
    private static function platformEligibility(
        string $platform,
        string $platformPlayerId,
        array $rowEligibility,
        array $fantraxEligibility,
    ): array {
        if ($platform === 'fantrax' && $platformPlayerId !== '') {
            return $fantraxEligibility[$platformPlayerId] ?? [];
        }

        return self::realRosterPositions($rowEligibility);
    }

    /**
     * Build Fantrax eligibility from observed provider roster-slot usage.
     *
     * @param iterable<mixed> $players
     *
     * @return array<string,array<int,string>>
     */
    private static function fantraxEligibilityByPlatformPlayerId(iterable $players): array
    {
        $platformPlayerIds = collect($players)
            ->map(static fn ($player): string => (string) ($player->pivot->platform_player_id ?? ''))
            ->filter()
            ->unique()
            ->values();

        if ($platformPlayerIds->isEmpty()) {
            return [];
        }

        $eligibility = [];

        DB::table('platform_roster_memberships')
            ->select('platform_player_id', 'slot')
            ->where('platform', 'fantrax')
            ->whereIn('platform_player_id', $platformPlayerIds)
            ->whereNull('ends_at')
            ->orderBy('id')
            ->get()
            ->each(static function ($membership) use (&$eligibility): void {
                $platformPlayerId = (string) $membership->platform_player_id;
                $position = self::normalizedRosterPosition((string) $membership->slot);

                if ($position === null) {
                    return;
                }

                $eligibility[$platformPlayerId] ??= [];
                $eligibility[$platformPlayerId][] = $position;
            });

        $fallbackPositions = DB::table('fantrax_players')
            ->whereIn('fantrax_id', $platformPlayerIds)
            ->pluck('position', 'fantrax_id')
            ->map(static fn ($position): array => self::realRosterPositions(
                preg_split('/[\\/,]/', (string) $position) ?: [],
            ))
            ->all();

        return $platformPlayerIds
            ->mapWithKeys(static function (string $platformPlayerId) use ($eligibility, $fallbackPositions): array {
                $positions = self::sortRosterPositions($eligibility[$platformPlayerId] ?? []);

                if ($positions === []) {
                    $positions = self::sortRosterPositions($fallbackPositions[$platformPlayerId] ?? []);
                }

                return [$platformPlayerId => $positions];
            })
            ->all();
    }

    /**
     * Normalize a list to real hockey roster positions only.
     *
     * @param array<int,mixed> $positions
     *
     * @return array<int,string>
     */
    private static function realRosterPositions(array $positions): array
    {
        return self::sortRosterPositions(
            collect($positions)
                ->map(static fn (mixed $position): ?string => self::normalizedRosterPosition((string) $position))
                ->filter()
                ->values()
                ->all(),
        );
    }

    /**
     * Normalize one provider roster position and drop non-position containers.
     */
    private static function normalizedRosterPosition(string $position): ?string
    {
        $position = strtoupper(trim($position));

        return match ($position) {
            'C' => 'C',
            'L', 'LW' => 'LW',
            'R', 'RW' => 'RW',
            'D', 'LD', 'RD' => 'D',
            'G' => 'G',
            default => null,
        };
    }

    /**
     * Sort and de-duplicate hockey roster positions for display.
     *
     * @param array<int,string> $positions
     *
     * @return array<int,string>
     */
    private static function sortRosterPositions(array $positions): array
    {
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'D' => 40,
            'G' => 50,
        ];

        return collect($positions)
            ->filter(static fn (string $position): bool => isset($order[$position]))
            ->unique()
            ->sortBy(static fn (string $position): int => $order[$position])
            ->values()
            ->all();
    }

    /**
     * Choose a roster slot label for display when a provider did not store one.
     *
     * @param array<int,mixed> $eligibility
     */
    private static function displayRosterSlot(
        string $slot,
        string $status,
        array $eligibility,
        string $position,
    ): string {
        if ($slot !== '') {
            return $slot;
        }

        $statusSlot = match ($status) {
            'bench' => 'BN',
            'ir' => 'IR',
            'na' => 'NA',
            'taxi' => 'TAXI',
            default => null,
        };

        if ($statusSlot !== null) {
            return $statusSlot;
        }

        $specificEligibility = collect($eligibility)
            ->map(static fn (mixed $value): string => strtoupper(trim((string) $value)))
            ->first(static fn (string $value): bool => $value !== '' && ! in_array($value, [
                'F',
                'UTIL',
                'UTILS',
                'UTILITY',
                'UTL',
                'W/R/T',
            ], true));

        return $specificEligibility ?: $position;
    }

    /**
     * Return the provider-neutral fallback order for leagues without roster slot settings.
     */
    private static function fallbackRosterSlotOrder(string $slot): int
    {
        $normalizedSlot = self::normalizedFallbackSlot($slot);
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'F' => 40,
            'D' => 50,
            'SKT' => 60,
            'G' => 70,
            'RES' => 80,
            'BEN' => 90,
            'IR' => 100,
            'MIN' => 110,
        ];

        return $order[$normalizedSlot] ?? 999;
    }

    /**
     * Return minor roster player ordering by actual hockey position.
     *
     * @param array<int,mixed> $eligibility
     */
    private static function minorRosterPositionSortOrder(array $eligibility): int
    {
        $positions = collect($eligibility)
            ->map(static fn (mixed $value): string => self::normalizedMinorPosition((string) $value))
            ->filter()
            ->values();

        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'D' => 40,
            'G' => 50,
        ];

        return $positions
            ->map(static fn (string $value): int => $order[$value] ?? 999)
            ->min() ?? 999;
    }

    /**
     * Normalize provider position variants into the minor roster ordering vocabulary.
     */
    private static function normalizedMinorPosition(string $position): string
    {
        $position = strtoupper(trim($position));

        return match ($position) {
            'L' => 'LW',
            'R' => 'RW',
            'LD', 'RD' => 'D',
            default => $position,
        };
    }

    /**
     * Normalize provider slot variants into the fallback roster ordering vocabulary.
     */
    private static function normalizedFallbackSlot(string $slot): string
    {
        $slot = strtoupper(trim($slot));

        return match ($slot) {
            'L' => 'LW',
            'R' => 'RW',
            'BN', 'BENCH' => 'BEN',
            'MINOR', 'MINORS', 'MINORS_ROSTER', 'MINORSROSTER' => 'MIN',
            'UTIL', 'UTILS', 'UTILITY', 'UTL', 'W/R/T' => 'F',
            default => $slot,
        };
    }

    /**
     * Determine whether a roster row belongs under the minor league separator.
     */
    private static function isMinorRosterRow(string $slot, string $status): bool
    {
        return self::normalizedFallbackSlot($slot) === 'MIN' || strtolower(trim($status)) === 'na';
    }
}
