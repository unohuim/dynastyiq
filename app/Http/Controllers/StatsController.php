<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Perspective;
use App\Models\Player;
use App\Models\PlatformLeague;
use App\Models\Stat;
use App\Models\NhlSeasonStat;
use App\Models\NhlGameSummary;
use App\Services\FantasyLeagueAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;


class StatsController extends BaseController
{
    public function index(Request $request): View
    {
        $user = Auth::user();

        $connectedLeagues = $this->connectedLeaguesForUser($user);

        $perspModels = Perspective::forUser($user)->orderBy('id')->get();

        $perspectives = $perspModels->map(fn ($p) => [
            'id'          => $p->id,
            'slug'        => $p->slug ?? $p->name,
            'name'        => $p->name,
            'is_slicable' => (bool)($p->is_slicable ?? true),
        ])->values();

        $requestedPerspective = (string) $request->query('perspective', '');
        $defaultPerspective = $perspModels->firstWhere('slug', 'skaters')
            ?? $perspModels->firstWhere('name', 'Skaters')
            ?? $perspModels->first();
        $first = $requestedPerspective !== ''
            ? ($perspModels->firstWhere('slug', $requestedPerspective) ?? $perspModels->firstWhere('name', $requestedPerspective) ?? $defaultPerspective)
            : $defaultPerspective;
        $selectedPerspectiveId = $first?->id;
        $selectedSlug          = $first?->slug ?? $first?->name ?? null;
        $seasonFilter          = $request->query('season_id', $request->query('season'));
        $slice                 = (string) $request->query('slice', 'total');
        $gameType              = (int) $request->query('game_type', 2);

        if ($selectedPerspectiveId) {
            [$payload] = $this->buildAndFormatPlayersPayload(
                $user,
                $selectedPerspectiveId,
                is_string($seasonFilter) ? $seasonFilter : null,
                in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
                in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
                null
            );
        } else {
            $payload = [
                'headings' => [],
                'data'     => collect(),
                'settings' => [
                    'sortable'             => [],
                    'defaultSort'          => null,
                    'defaultSortDirection' => 'desc',
                    'resource'             => 'players',
                    'slice'                => 'total',
                ],
                'meta' => [
                    'availableSeasons'   => [],
                    'availableGameTypes' => [2],
                    'season'             => null,
                    'game_type'          => 2,
                    'canSlice'           => true,
                    'filterSchema'       => [],
                    'appliedFilters'     => [],
                    'pos'                => [],
                    'pos_type'           => [],
                    'positionButtons'    => ['LW', 'C', 'RW', 'F', 'D', 'G'],
                    'supportsDateRange'  => true,
                ],
            ];
        }

        return view('stats.index', [
            'payload'               => $payload,
            'perspectives'          => $perspectives,
            'selectedPerspectiveId' => $selectedPerspectiveId,
            'selectedSlug'          => $selectedSlug,
            'defaultSeason'         => $payload['meta']['season'] ?? null,
            'connectedLeagues'      => $connectedLeagues,
        ]);
    }

    /**
     * Return a stats payload for an ephemeral Yahoo league scoring perspective.
     */
    public function leaguePayload(Request $request, string $leagueId)
    {
        $request->validate([
            'perspectiveId' => 'nullable|integer|exists:perspectives,id',
            'perspective'   => 'nullable|string',
            'season'        => 'nullable|string',
            'season_id'     => 'nullable|string',
            'slice'         => 'nullable|in:total,pgp,p60',
            'resource'      => 'nullable|in:players,units',
            'period'        => 'nullable|in:season,range,lastWeek,thisWeek,past30days',
            'game_type'     => 'nullable|in:1,2,3',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'availability'  => 'nullable|integer',
            'draft_context' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before loading league stats.',
            ], 409);
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        $season = $request->input('season_id', $request->input('season'));
        $slice = (string) $request->input('slice', 'total');
        $gameType = (int) $request->input('game_type', 2);
        $period = (string) $request->input('period', 'season');
        $leaguePerspectiveSlug = $this->leagueScoringPerspectiveSlug($league);
        $fantraxPerspectiveSlug = $this->fantraxLeaguePerspectiveSlug($league);
        $defaultPerspectiveSlug = $this->defaultLeagueStatsPerspectiveSlug($user, $league);
        $requestedPerspective = (string) $request->query('perspective', $defaultPerspectiveSlug);
        $isLeagueScoringPerspective = ! $request->filled('perspectiveId')
            && $leaguePerspectiveSlug !== null
            && ($requestedPerspective === '' || $requestedPerspective === $leaguePerspectiveSlug);
        $isFantraxLeaguePerspective = ! $request->filled('perspectiveId')
            && $fantraxPerspectiveSlug !== null
            && ($requestedPerspective === '' || $requestedPerspective === $fantraxPerspectiveSlug);

        if ($isLeagueScoringPerspective) {
            $settings = $this->leagueScoringPerspectiveSettings($league);

            if ($settings === null) {
                return response()->json([
                    'message' => 'Map every scoring category before loading league stats.',
                ], 409);
            }

            $perspective = (object) [
                'slug' => $leaguePerspectiveSlug,
                'name' => $league->name . ' Scoring',
                'is_slicable' => true,
            ];
            $canSlice = true;
        } elseif ($isFantraxLeaguePerspective) {
            $settings = $this->leagueScoringPerspectiveSettings($league);
            $basePerspective = null;

            if ($settings === null) {
                $basePerspective = $this->defaultSavedStatsPerspective($user);
                $settings = is_array($basePerspective->settings)
                    ? $basePerspective->settings
                    : (json_decode($basePerspective->settings ?? '[]', true) ?: []);
            }

            $perspective = (object) [
                'slug' => $fantraxPerspectiveSlug,
                'name' => $league->name . ' Fantrax',
                'is_slicable' => (bool) ($basePerspective?->is_slicable ?? true),
            ];
            $canSlice = (bool) ($basePerspective?->is_slicable ?? true);
        } else {
            $perspective = $this->resolveStatsPerspective($request, $user, $requestedPerspective);
            $settings = is_array($perspective->settings)
                ? $perspective->settings
                : (json_decode($perspective->settings ?? '[]', true) ?: []);
            $canSlice = (bool) ($perspective->is_slicable ?? true);

            if ($this->isProspectsPerspective($perspective, $settings['filters'] ?? [])) {
                $period = 'season';
            }
        }

        if ($period === 'season') {
            [$payload] = $this->buildAndFormatPlayersPayloadFromSettings(
                $user,
                $perspective,
                $settings,
                $canSlice,
                is_string($season) ? $season : null,
                $canSlice && in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
                in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
                $request,
            );
        } else {
            [$fromDate, $toDate] = $this->resolveDates($period, $request->query('from'), $request->query('to'));

            [$payload] = $this->buildAndFormatPlayersPayloadRangeFromSettings(
                $user,
                $settings,
                $canSlice,
                $canSlice && in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
                in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
                $fromDate,
                $toDate,
                $request,
            );
        }

        $payload = $this->filterPayloadToPlatformPlayerUniverse($payload, $league);
        $payload = $this->withLeagueOwnership($payload, $league, $user?->id);
        $payload['settings'] = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $payload['settings']['ownerColumn'] = true;
        $payload['settings']['leaguePlatform'] = (string) ($league->platform ?? '');
        $payload['connectedLeagues'] = $this->connectedLeaguesForUser($user);
        $payload['perspectives'] = $this->leagueStatsPerspectives($user, $league);
        $payload['selectedPerspective'] = $perspective->slug ?? $perspective->name ?? $defaultPerspectiveSlug;

        return response()->json($payload);
    }

    /**
     * Limit league-context stats rows to players known by that fantasy platform.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function filterPayloadToPlatformPlayerUniverse(array $payload, PlatformLeague $league): array
    {
        $platform = (string) ($league->platform ?? '');

        if (! in_array($platform, ['fantrax', 'yahoo'], true)) {
            return $payload;
        }

        $universe = $this->platformPlayerUniverse($league);

        if ($universe['player_ids'] === [] && $universe['nhl_ids'] === []) {
            $payload['data'] = [];

            return $payload;
        }

        $payload['data'] = collect($payload['data'] ?? [])
            ->filter(static function (mixed $row) use ($universe): bool {
                if (! is_array($row)) {
                    return false;
                }

                $playerId = (string) ($row['player_id'] ?? $row['id'] ?? '');
                $nhlId = (string) ($row['nhl_player_id'] ?? '');

                return ($playerId !== '' && isset($universe['player_ids'][$playerId]))
                    || ($nhlId !== '' && isset($universe['nhl_ids'][$nhlId]));
            })
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Return canonical player ids observed in the provider plus current league roster evidence.
     *
     * @return array{player_ids:array<string,bool>,nhl_ids:array<string,bool>}
     */
    private function platformPlayerUniverse(PlatformLeague $league): array
    {
        $platform = (string) ($league->platform ?? '');

        $providerPlayerIds = match ($platform) {
            'fantrax' => DB::table('fantrax_players')
                ->whereNotNull('player_id')
                ->pluck('player_id'),
            'yahoo' => DB::table('yahoo_players')
                ->whereNotNull('player_id')
                ->pluck('player_id'),
            default => collect(),
        };

        $rosterPlayerIds = DB::table('platform_roster_memberships as prm')
            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
            ->where('pt.platform_league_id', $league->id)
            ->where('prm.platform', $platform)
            ->whereNull('prm.ends_at')
            ->pluck('prm.player_id');

        $playerIds = $providerPlayerIds
            ->merge($rosterPlayerIds)
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($playerIds->isEmpty()) {
            return [
                'player_ids' => [],
                'nhl_ids' => [],
            ];
        }

        $nhlIds = DB::table('players')
            ->whereIn('id', $playerIds)
            ->whereNotNull('nhl_id')
            ->pluck('nhl_id')
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        return [
            'player_ids' => $playerIds
                ->mapWithKeys(static fn (int $id): array => [(string) $id => true])
                ->all(),
            'nhl_ids' => $nhlIds
                ->mapWithKeys(static fn (string $id): array => [$id => true])
                ->all(),
        ];
    }

    /**
     * Return the synthetic league scoring perspective slug when the platform supports one.
     */
    private function leagueScoringPerspectiveSlug(PlatformLeague $league): ?string
    {
        return (string) ($league->platform ?? '') === 'yahoo'
            ? 'yahoo-league-' . $league->id
            : null;
    }

    /**
     * Return the synthetic Fantrax league perspective slug when applicable.
     */
    private function fantraxLeaguePerspectiveSlug(PlatformLeague $league): ?string
    {
        return (string) ($league->platform ?? '') === 'fantrax'
            ? 'fantrax-league-' . $league->id
            : null;
    }

    /**
     * Return the default stats perspective slug for the league page.
     */
    private function defaultLeagueStatsPerspectiveSlug($user, PlatformLeague $league): string
    {
        $leaguePerspectiveSlug = $this->leagueScoringPerspectiveSlug($league);
        $fantraxPerspectiveSlug = $this->fantraxLeaguePerspectiveSlug($league);

        if ($leaguePerspectiveSlug !== null && $this->leagueScoringPerspectiveSettings($league) !== null) {
            return $leaguePerspectiveSlug;
        }

        if ($fantraxPerspectiveSlug !== null) {
            return $fantraxPerspectiveSlug;
        }

        $perspective = $this->defaultSavedStatsPerspective($user);

        return (string) ($perspective->slug ?? $perspective->name);
    }

    /**
     * Return the default saved stats perspective for non-scoring league views.
     */
    private function defaultSavedStatsPerspective($user): Perspective
    {
        $perspective = Perspective::forUser($user)
            ->where(static function ($query): void {
                $query->where('slug', 'skaters')
                    ->orWhere('name', 'Skaters');
            })
            ->orderByRaw("CASE WHEN slug = 'skaters' OR name = 'Skaters' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first()
            ?? Perspective::forUser($user)->orderBy('id')->first();

        abort_if($perspective === null, 409, 'Create a stats perspective before loading league stats.');

        return $perspective;
    }

    /**
     * Resolve a normal saved stats perspective for the current user.
     */
    private function resolveStatsPerspective(Request $request, $user, string $requestedPerspective): Perspective
    {
        if ($request->filled('perspectiveId')) {
            return Perspective::forUser($user)
                ->whereKey($request->integer('perspectiveId'))
                ->firstOrFail();
        }

        return Perspective::forUser($user)
            ->where(static function ($query) use ($requestedPerspective): void {
                $query->where('slug', $requestedPerspective)
                    ->orWhere('name', $requestedPerspective);
            })
            ->firstOrFail();
    }

    /**
     * Return the synthetic league scoring perspective followed by normal user perspectives.
     *
     * @return array<int,array{id:int|string|null,slug:string,name:string,is_slicable:bool}>
     */
    private function leagueStatsPerspectives($user, PlatformLeague $league): array
    {
        $leaguePerspectiveSlug = $this->leagueScoringPerspectiveSlug($league);
        $fantraxPerspectiveSlug = $this->fantraxLeaguePerspectiveSlug($league);
        $leaguePerspective = $leaguePerspectiveSlug !== null && $this->leagueScoringPerspectiveSettings($league) !== null
            ? [[
                'id' => $leaguePerspectiveSlug,
                'slug' => $leaguePerspectiveSlug,
                'name' => $league->name . ' Scoring',
                'is_slicable' => true,
            ]]
            : [];
        $fantraxPerspective = $fantraxPerspectiveSlug !== null
            ? [[
                'id' => $fantraxPerspectiveSlug,
                'slug' => $fantraxPerspectiveSlug,
                'name' => $league->name . ' Fantrax',
                'is_slicable' => true,
            ]]
            : [];

        $perspectives = Perspective::forUser($user)
            ->orderBy('id')
            ->get()
            ->map(static fn (Perspective $perspective): array => [
                'id' => $perspective->id,
                'slug' => $perspective->slug ?? $perspective->name,
                'name' => $perspective->name,
                'is_slicable' => (bool) ($perspective->is_slicable ?? true),
            ])
            ->values()
            ->all();

        return [...$leaguePerspective, ...$fantraxPerspective, ...$perspectives];
    }

    /**
     * Add current fantasy ownership metadata to a league-specific stats payload.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function withLeagueOwnership(array $payload, PlatformLeague $league, ?int $userId = null): array
    {
        $ownership = $this->leagueOwnership($league, $userId);
        $ownershipByPlayerId = $ownership['by_player_id'];
        $ownershipByNhlId = $ownership['by_nhl_id'];
        $seenPlayerIds = [];
        $seenNhlIds = [];

        $payload['data'] = collect($payload['data'] ?? [])
            ->map(static function (mixed $row) use ($ownershipByPlayerId, $ownershipByNhlId, &$seenPlayerIds, &$seenNhlIds): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                $playerId = (string) ($row['player_id'] ?? '');
                $nhlPlayerId = (string) ($row['nhl_player_id'] ?? '');

                if ($playerId !== '') {
                    $seenPlayerIds[$playerId] = true;
                }

                if ($nhlPlayerId !== '') {
                    $seenNhlIds[$nhlPlayerId] = true;
                }

                $rowOwnership = $playerId !== ''
                    ? ($ownershipByPlayerId[$playerId] ?? null)
                    : null;
                $rowOwnership ??= $nhlPlayerId !== '' ? ($ownershipByNhlId[$nhlPlayerId] ?? null) : null;

                return array_merge($row, [
                    'fantasy_team_id' => $rowOwnership['fantasy_team_id'] ?? null,
                    'fantasy_team_name' => $rowOwnership['fantasy_team_name'] ?? null,
                    'fantasy_team_avatar_url' => $rowOwnership['fantasy_team_avatar_url'] ?? null,
                    'fantasy_team_is_user_team' => $rowOwnership['fantasy_team_is_user_team'] ?? false,
                    'roster_slot' => $rowOwnership['roster_slot'] ?? null,
                    'roster_status' => $rowOwnership['roster_status'] ?? null,
                    'roster_group' => $rowOwnership['roster_group'] ?? null,
                    'roster_sort_order' => $rowOwnership['roster_sort_order'] ?? null,
                    'roster_group_sort_order' => $rowOwnership['roster_group_sort_order'] ?? null,
                    'roster_status_sort_order' => $rowOwnership['roster_status_sort_order'] ?? null,
                ]);
            })
            ->values()
            ->all();

        if ((string) ($league->platform ?? '') === 'yahoo') {
            $missingRows = collect($ownership['roster_rows'])
                ->reject(static function (array $row) use ($seenPlayerIds, $seenNhlIds): bool {
                    $playerId = (string) ($row['player_id'] ?? '');
                    $nhlPlayerId = (string) ($row['nhl_player_id'] ?? '');

                    return ($playerId !== '' && isset($seenPlayerIds[$playerId]))
                        || ($nhlPlayerId !== '' && isset($seenNhlIds[$nhlPlayerId]));
                })
                ->values()
                ->all();

            $payload['data'] = array_values(array_merge($payload['data'], $missingRows));
        }

        return $payload;
    }

    /**
     * Return current fantasy owner metadata keyed by local and NHL player ids.
     *
     * @return array{by_player_id:array<string,array<string,mixed>>,by_nhl_id:array<string,array<string,mixed>>,roster_rows:array<int,array<string,mixed>>}
     */
    private function leagueOwnership(PlatformLeague $league, ?int $userId = null): array
    {
        $usesProviderSlotOrder = (string) ($league->platform ?? '') === 'yahoo';
        $rosterSlots = $league->rosterSlots()
            ->get(['slot', 'count', 'sort_order']);
        $slotOrder = $rosterSlots
            ->pluck('sort_order', 'slot')
            ->map(static fn ($value): int => (int) $value)
            ->all();
        $teams = $league->teams()
            ->select('id', 'platform_team_id', 'name')
            ->with([
                'roster:id,nhl_id,full_name,first_name,last_name,position,pos_type,dob,team_abbrev,head_shot_url,is_goalie,status',
                'users' => static function ($query): void {
                    $query->wherePivot('is_active', true)
                        ->select('users.id')
                        ->with(['socialAccounts:id,user_id,avatar']);
                },
            ])
            ->get();

        $byPlayerId = [];
        $byNhlId = [];
        $rosterRows = [];

        foreach ($teams as $team) {
            $defaultAvatar = config('ui.default_team_avatar')
                ?: 'https://ui-avatars.com/api/?name=' . urlencode((string) $team->name) . '&background=E5E7EB&color=111827&size=64';
            $ownerAvatar = $defaultAvatar;

            foreach ($team->users as $user) {
                $avatar = optional($user->socialAccounts->first())->avatar;

                if (filled($avatar)) {
                    $ownerAvatar = (string) $avatar;
                    break;
                }
            }
            $isUserTeam = $userId !== null && $team->users->contains(static fn ($user): bool => (int) $user->id === $userId);

            $ownerPayload = [
                'fantasy_team_id' => (string) $team->platform_team_id,
                'fantasy_team_name' => (string) $team->name,
                'fantasy_team_avatar_url' => $ownerAvatar,
                'fantasy_team_is_user_team' => $isUserTeam,
            ];
            $teamSlotCounts = [];

            foreach ($team->roster as $player) {
                $slot = (string) ($player->pivot->slot ?? '');
                $slotKey = strtoupper(trim($slot));
                $rosterStatus = (string) ($player->pivot->status ?? '');
                $eligibility = $this->normalizeRosterEligibility($player->pivot->eligibility ?? null);
                $displaySlot = $usesProviderSlotOrder
                    ? ($slotKey !== '' ? $slotKey : (strtolower(trim($rosterStatus)) === 'na' ? 'NA' : ''))
                    : $this->displayRosterSlot($slot, $rosterStatus, $eligibility, (string) ($player->position ?? ''));
                $rosterGroup = $this->isMinorRosterRow($displaySlot, $rosterStatus) ? 'minor' : 'active';
                $rosterSortOrder = $usesProviderSlotOrder
                    ? ($slotOrder[$slot] ?? $slotOrder[$slotKey] ?? $slotOrder[$displaySlot] ?? $this->fallbackRosterSlotOrder($displaySlot))
                    : ($rosterGroup === 'minor'
                        ? $this->minorRosterPositionSortOrder($eligibility)
                        : ($slotOrder[$slot] ?? $this->fallbackRosterSlotOrder($displaySlot)));
                $rosterPayload = array_merge($ownerPayload, [
                    'roster_slot' => $displaySlot,
                    'roster_status' => $rosterStatus,
                    'roster_group' => $rosterGroup,
                    'roster_sort_order' => $rosterSortOrder,
                    'roster_group_sort_order' => $usesProviderSlotOrder ? 0 : ($rosterGroup === 'minor' ? 1 : 0),
                    'roster_status_sort_order' => match ($rosterStatus) {
                        'active' => 10,
                        'bench' => 20,
                        'ir' => 30,
                        'na' => 40,
                        'taxi' => 50,
                        default => 90,
                    },
                ]);
                $teamSlotCounts[$displaySlot] = ($teamSlotCounts[$displaySlot] ?? 0) + 1;

                $byPlayerId[(string) $player->id] = $rosterPayload;

                if (filled($player->nhl_id)) {
                    $byNhlId[(string) $player->nhl_id] = $rosterPayload;
                }

                $rosterRows[] = array_merge($this->rosterOnlyStatsRow($player), $rosterPayload);
            }

            if ($usesProviderSlotOrder) {
                foreach ($rosterSlots as $slotSetting) {
                    $slot = strtoupper(trim((string) $slotSetting->slot));
                    $missingCount = max(0, (int) $slotSetting->count - (int) ($teamSlotCounts[$slot] ?? 0));

                    for ($i = 0; $i < $missingCount; $i++) {
                        $rosterRows[] = $this->emptyRosterSlotStatsRow(
                            $ownerPayload,
                            $slot,
                            (int) $slotSetting->sort_order,
                        );
                    }
                }
            }
        }

        return [
            'by_player_id' => $byPlayerId,
            'by_nhl_id' => $byNhlId,
            'roster_rows' => $rosterRows,
        ];
    }

    /**
     * Build a league stats row for rostered players absent from the stats payload.
     */
    private function rosterOnlyStatsRow(Player $player): array
    {
        return [
            'name' => (string) ($player->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? ''))),
            'player_id' => (int) $player->id,
            'avatar_url' => $player->head_shot_url,
            'age' => $this->playerAge($player),
            'team' => $player->team_abbrev,
            'league' => null,
            'pos' => $player->position,
            'pos_type' => $player->pos_type,
            'contract_value' => null,
            'contract_value_num' => null,
            'contract_last_year' => null,
            'contract_last_year_num' => null,
            'gp' => null,
            'nhl_player_id' => $player->nhl_id,
            'toi_seconds' => null,
            'toi' => null,
            'league_roster_only' => true,
        ];
    }

    /**
     * @param array<string,mixed> $ownerPayload
     */
    private function emptyRosterSlotStatsRow(array $ownerPayload, string $slot, int $sortOrder): array
    {
        $slot = strtoupper(trim($slot));
        $status = match ($slot) {
            'BN' => 'bench',
            'IR', 'IR+' => 'ir',
            'NA' => 'na',
            default => 'active',
        };

        return array_merge([
            'name' => '',
            'player_id' => null,
            'avatar_url' => null,
            'age' => null,
            'team' => null,
            'league' => null,
            'pos' => null,
            'pos_type' => null,
            'contract_value' => null,
            'contract_value_num' => null,
            'contract_last_year' => null,
            'contract_last_year_num' => null,
            'gp' => null,
            'nhl_player_id' => null,
            'toi_seconds' => null,
            'toi' => null,
            'league_roster_only' => true,
            'league_roster_placeholder' => true,
        ], $ownerPayload, [
            'roster_slot' => $slot,
            'roster_status' => $status,
            'roster_group' => 'active',
            'roster_sort_order' => $sortOrder,
            'roster_group_sort_order' => 0,
            'roster_status_sort_order' => match ($status) {
                'active' => 10,
                'bench' => 20,
                'ir' => 30,
                'na' => 40,
                default => 90,
            },
        ]);
    }

    /**
     * Normalize stored roster eligibility into a flat string list.
     *
     * @return array<int,string>
     */
    private function normalizeRosterEligibility(mixed $eligibility): array
    {
        if (is_string($eligibility)) {
            $decoded = json_decode($eligibility, true);
            $eligibility = json_last_error() === JSON_ERROR_NONE ? $decoded : [$eligibility];
        }

        if (! is_array($eligibility)) {
            return [];
        }

        return collect($eligibility)
            ->flatten()
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Return the display roster slot used for league roster ordering.
     *
     * @param array<int,string> $eligibility
     */
    private function displayRosterSlot(string $slot, string $status, array $eligibility, string $position): string
    {
        $slot = strtoupper(trim($slot));

        if ($slot !== '') {
            return match ($slot) {
                'BN' => 'BEN',
                'MINOR', 'MINORS', 'MINORS_ROSTER', 'MINORSROSTER' => 'MIN',
                default => $slot,
            };
        }

        if (strtolower(trim($status)) === 'na') {
            return 'MIN';
        }

        return collect($eligibility)
            ->map(static fn (string $value): string => strtoupper(trim($value)))
            ->first(static fn (string $value): bool => $value !== '' && ! in_array($value, [
                'F',
                'UTIL',
                'UTILS',
                'UTILITY',
                'UTL',
                'W/R/T',
            ], true)) ?: strtoupper(trim($position));
    }

    /**
     * Return provider-neutral fallback roster slot order.
     */
    private function fallbackRosterSlotOrder(string $slot): int
    {
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

        return $order[$this->normalizedFallbackSlot($slot)] ?? 999;
    }

    /**
     * Return minor roster player ordering by actual hockey position.
     *
     * @param array<int,string> $eligibility
     */
    private function minorRosterPositionSortOrder(array $eligibility): int
    {
        $order = [
            'C' => 10,
            'LW' => 20,
            'RW' => 30,
            'D' => 40,
            'G' => 50,
        ];

        return collect($eligibility)
            ->map(fn (string $value): string => $this->normalizedMinorPosition($value))
            ->filter()
            ->map(static fn (string $value): int => $order[$value] ?? 999)
            ->min() ?? 999;
    }

    /**
     * Normalize provider position variants into the minor roster ordering vocabulary.
     */
    private function normalizedMinorPosition(string $position): string
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
     * Normalize provider slot variants into fallback roster ordering vocabulary.
     */
    private function normalizedFallbackSlot(string $slot): string
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
    private function isMinorRosterRow(string $slot, string $status): bool
    {
        return $this->normalizedFallbackSlot($slot) === 'MIN' || strtolower(trim($status)) === 'na';
    }


    private function connectedLeaguesForUser($user): array
    {
        if (!$user) return [];

        // Adjust table/column names if yours differ
        $rows = DB::table('platform_leagues as pl')
            ->join('league_user_teams as lut', 'lut.platform_league_id', '=', 'pl.id')
            ->where('lut.user_id', $user->id)
            // Optional: uncomment if you have an "active" flag/soft-deletes on the pivot
            // ->where('lut.is_active', true)
            // ->whereNull('lut.deleted_at')
            ->select('pl.id', 'pl.name')
            ->distinct()
            ->orderBy('pl.name')
            ->get();

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
                    ->values()
                    ->all();
    }



    // helper (place once in the controller)
    private function formatTimeOnIce(int $seconds): string
    {
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }



    /** ───────────────────────────── API (AJAX) ───────────────────────────── */
    public function payload(Request $request)
    {
        \Log::info('pre validate request: ', ['request' => $request]);
        $request->validate([
            'perspectiveId' => 'nullable|integer|exists:perspectives,id',
            'perspective'   => 'nullable|string',
            'season'        => 'nullable|string',
            'season_id'     => 'nullable|string',
            'slice'         => 'nullable|in:total,pgp,p60',
            'resource'      => 'nullable|in:players,units',
            'period'        => 'nullable|in:season,range,lastWeek,thisWeek,past30days',
            'game_type'     => 'nullable|in:1,2,3',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'availability'  => 'nullable|integer',
        ]);

        \Log::info('post validate request: ', ['request' => $request]);

        $user = $request->user();

        $connectedLeagues = $this->connectedLeaguesForUser($user);

        // Resolve perspective (prefer slug)
        if ($request->filled('perspectiveId')) {
            $perspective = Perspective::forUser($user)
                ->whereKey($request->integer('perspectiveId'))
                ->firstOrFail();
        } else {
            $slug = (string) $request->query('perspective', '');
            $perspective = Perspective::forUser($user)
                ->where('slug', $slug)
                ->orWhere('name', $slug)
                ->firstOrFail();
        }

        \Log::info('api request: ', ['request' => $request]);
        $settings = is_array($perspective->settings)
            ? $perspective->settings
            : (json_decode($perspective->settings ?? '[]', true) ?: []);
        $isProspects = $this->isProspectsPerspective($perspective, $settings['filters'] ?? []);
        $season         = $request->input('season_id', $request->input('season'));
        $sliceParam     = $request->input('slice', 'total');
        $gameType       = (int) $request->input('game_type', 2);
        $period         = (string) $request->input('period', 'season');
        $canSlice       = (bool)($perspective->is_slicable ?? true);
        $effectiveSlice = $canSlice ? $sliceParam : 'total';

        if ($isProspects) {
            $period = 'season';
        }

        if ($period === 'season') {
            [$payload] = $this->buildAndFormatPlayersPayload(
                $user,
                $perspective->id,
                $season,
                $effectiveSlice,
                $gameType,
                $request
            );

            $payload['connectedLeagues'] = $connectedLeagues;

            return response()->json($payload);
        }

        // Ranges / partial periods
        [$fromDate, $toDate] = $this->resolveDates($period, $request->query('from'), $request->query('to'));

        [$payload] = $this->buildAndFormatPlayersPayloadRange(
            $user,
            $perspective->id,
            $effectiveSlice,
            $gameType,
            $fromDate,
            $toDate,
            $request
        );

        $payload['connectedLeagues'] = $connectedLeagues;

        \Log::info('updated payload: ', ['payload' => $payload]);

        return response()->json($payload);
    }



    /**
     * Apply "availability" from a single integer param:
     *   0 => no constraint
     *   -1 => "available in any of the user's leagues"
     *   N (>1) => "available in internal league id N (platform_leagues.id)"
     *
     * Current = platform_roster_memberships.ends_at IS NULL.
     * player match uses local players.id (pf.id).
     */
    private function applyAvailabilityToBase($base, ?Request $request, $user): void
    {
        $val = (int) ($request?->input('availability', 0) ?? 0);
        if ($val === 0) return;


        if ($val === -1) {
            // robust user id (works for web/ajax/api)
            \Log::info('verifying user');
            $uid = $user?->id ?? Auth::id();
            if (!$uid) return;

            \Log::info('user authed');

            // get internal platform_leagues.id values from the pivot
            $leagueIds = DB::table('league_user_teams')
                ->where('user_id', $uid)
                ->pluck('platform_league_id')
                ->map(fn ($i) => (int) $i)
                ->all();

            \Log::info('Availability ANY: fetched league ids', ['uid' => $uid, 'leagueIdsCount' => count($leagueIds)]);

            if (empty($leagueIds)) return;

            $base->whereExists(function ($q) use ($leagueIds) {
                $q->selectRaw('1')
                ->from('platform_leagues as l')
                ->whereIn('l.id', $leagueIds)
                ->whereNotExists(function ($q2) {
                    $q2->from('platform_roster_memberships as prm')
                        ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
                        ->whereNull('prm.ends_at')
                        ->whereColumn('pt.platform_league_id', 'l.id')  // this league
                        ->whereColumn('prm.player_id', 'pf.id');        // this player
                });
            });

            \Log::info('Availability ANY applied');
            return;
        }

        // specific league stays the same
        $leagueId = $val;
        $base->whereNotExists(function ($q) use ($leagueId) {
            $q->from('platform_roster_memberships as prm')
            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
            ->whereNull('prm.ends_at')
            ->where('pt.platform_league_id', $leagueId)
            ->whereColumn('prm.player_id', 'pf.id')
            ->selectRaw('1');
        });
        \Log::info('Availability SPECIFIC applied', ['leagueId' => $leagueId]);
    }









    /** ─────────────── Build + format PLAYERS payload (SEASON) ─────────────── */
    private function buildAndFormatPlayersPayload(
        $user,
        int $perspectiveId,
        ?string $seasonFilter,
        string $slice = 'total',
        ?int $gameType = 2,
        ?Request $request = null
    ): array {
        $perspective = Perspective::findOrFail($perspectiveId);
        $settings    = is_array($perspective->settings) ? $perspective->settings : (json_decode($perspective->settings ?? '[]', true) ?: []);
        $canSlice    = (bool)($perspective->is_slicable ?? true);

        return $this->buildAndFormatPlayersPayloadFromSettings(
            $user,
            $perspective,
            $settings,
            $canSlice,
            $seasonFilter,
            $slice,
            $gameType,
            $request,
        );
    }

    /**
     * Build and format a season players payload from a persisted or ephemeral perspective settings array.
     */
    private function buildAndFormatPlayersPayloadFromSettings(
        $user,
        object $perspective,
        array $settings,
        bool $canSlice,
        ?string $seasonFilter,
        string $slice = 'total',
        ?int $gameType = 2,
        ?Request $request = null
    ): array {
        $filters = $settings['filters'] ?? [];
        $columns = $settings['columns'] ?? [];
        $sort    = $settings['sort']    ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

        $isProspects   = $this->isProspectsPerspective($perspective, $filters);
        $isDraftCentralContext = (bool) ($request?->boolean('draft_context') ?? false);
        $lockedSeason  = $filters['season_id']['value'] ?? null;
        $season        = $lockedSeason ?: $seasonFilter;

        $identityCols = [
            ['key' => 'name',               'label' => 'Player'],
            ['key' => 'age',                'label' => 'Age'],
            ['key' => 'team',               'label' => 'Team'],
            ['key' => 'pos_type',           'label' => 'Type'],
            ['key' => 'contract_value_num', 'label' => 'AAV'],
            ['key' => 'contract_last_year', 'label' => 'Term End'],
            ['key' => 'gp',                 'label' => 'GP'],
        ];

        if ($isProspects) {
            array_splice($identityCols, 3, 0, [[
                'key' => 'league',
                'label' => 'League',
            ]]);
        }

        $rows = collect();
        $availableSeasons   = [];
        $availableLeagues   = [];
        $availableGameTypes = $isProspects ? [2] : [1, 2, 3];
        $effectiveGameType  = 2;

        if ($isProspects) {
            $base = Stat::query()
                ->with(['player.contracts.seasons'])
                ->regularSeason()
                ->where('league_abbrev', '!=', 'NHL')
                ->whereHas('player', fn ($query) => $query->where('is_prospect', true));

            $base->select($base->getModel()->getTable() . '.*');

            [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request, $filters);
            $availableLeagues = $this->leagueOptionsForBase($base);

            $availableSeasons = (clone $base)
                ->reorder()
                ->select('stats.season_id')
                ->distinct()
                ->pluck('stats.season_id')
                ->map(static fn (mixed $seasonId): string => (string) $seasonId)
                ->sortDesc()
                ->values()
                ->all();
            if (!$season && $isDraftCentralContext) {
                $lastCompletedSeason = (string) ((int) now()->year - 1) . (string) now()->year;
                $season = in_array($lastCompletedSeason, $availableSeasons, true)
                    ? $lastCompletedSeason
                    : ($availableSeasons[0] ?? null);
            } elseif (!$season) {
                $season = $availableSeasons[0] ?? null;
            }

            if ($season) $base->where('season_id', $season);

            $stats = $base->get();
            $rows = $this->assembleRowsFromCollection(
                $stats,
                $columns,
                $slice,
                $canSlice,
                'prospects',
                ['draft_context' => $isDraftCentralContext],
            );
            $effectiveGameType = 2;
        } else {
            if (!$season) $season = (string) NhlSeasonStat::query()->max('season_id');

            $effectiveGameType = (int)($gameType ?? 2);
            if (isset($filters['game_type']['value'])) $effectiveGameType = (int)$filters['game_type']['value'];

            $base = NhlSeasonStat::query()
                ->with(['player.contracts.seasons'])
                ->where('season_id', $season)
                ->where('game_type', $effectiveGameType);

            // avoid SELECT * + aggregates trouble in bounds()
            $base->select($base->getModel()->getTable() . '.*');

            [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request, $filters);

            $stats = $base->get();

            $availableSeasons = NhlSeasonStat::query()
                ->select('season_id')->distinct()->pluck('season_id')->sortDesc()->values()->all();

            $rows = $this->assembleRowsFromCollection($stats, $columns, $slice, $canSlice, 'season', [
                'season_id' => $season,
                'game_type' => $effectiveGameType,
            ]);
            $rows = $this->appendOnIceRows($rows, $columns, [
                'season_id' => $season,
                'game_type' => $effectiveGameType,
            ]);
        }

        // Post filters + virtual schema
        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($request, $rows);

        // Sort
        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

        // Headings + schema merge
        $headings = $this->mergeHeadings($identityCols, $columns);

        $seen = [];
        $mergedSchema = [];
        foreach (array_merge($schema ?? [], $virtualSchema ?? []) as $def) {
            $k = $def['key'] ?? null;
            if (!$k || isset($seen[$k])) continue;
            $seen[$k] = true;
            $mergedSchema[] = $def;
        }

        // Merge applied echo
        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);
        $avail = (int) ($request?->input('availability', 0) ?? 0);
        $leagueIdMeta = $avail > 0 ? $avail : null;

        $formatted = [
            'headings' => $headings,
            'data'     => $sorted,
            'settings' => [
                'sortable'             => collect($headings)->pluck('key')->values()->all(),
                'defaultSort'          => $sortKey,
                'defaultSortDirection' => $sortDir,
                'resource'             => 'players',
                'slice'                => $canSlice ? $slice : 'total',
                'columnGroups'         => $settings['columnGroups'] ?? null,
                'columnGroupSort'      => $settings['columnGroupSort'] ?? null,
                'activeColumnGroup'    => $settings['activeColumnGroup'] ?? null,
            ],
            'meta' => [
                'availableSeasons'   => $availableSeasons,
                'availableLeagues'   => $availableLeagues,
                'availableGameTypes' => $availableGameTypes,
                'season'             => $season,
                'game_type'          => $effectiveGameType,
                'canSlice'           => $canSlice,
                'filterSchema'       => $mergedSchema,
                'appliedFilters'     => $applied['filters'] ?? [],
                'pos'                => $applied['pos'] ?? [],
                'pos_type'           => $applied['pos_type'] ?? [],
                'availability'       => $avail,
                'league_id'          => $leagueIdMeta,
                'positionButtons'    => $this->positionButtons($settings),
                'supportsDateRange'  => ! $isProspects,
            ],
        ];

        return [$formatted, $availableSeasons, $season];
    }

    /** ───────────── Build + format PLAYERS payload (RANGE/PARTIAL) ───────────── */
    private function buildAndFormatPlayersPayloadRange(
        $user,
        int $perspectiveId,
        string $slice,
        ?int $gameType,
        ?Carbon $from,
        ?Carbon $to,
        Request $request
    ): array {
        $perspective = Perspective::findOrFail($perspectiveId);
        $settings    = is_array($perspective->settings) ? $perspective->settings : (json_decode($perspective->settings ?? '[]', true) ?: []);
        $canSlice    = (bool)($perspective->is_slicable ?? true);

        return $this->buildAndFormatPlayersPayloadRangeFromSettings(
            $user,
            $settings,
            $canSlice,
            $slice,
            $gameType,
            $from,
            $to,
            $request,
        );
    }

    /**
     * Build and format a date-range players payload from a settings array.
     */
    private function buildAndFormatPlayersPayloadRangeFromSettings(
        $user,
        array $settings,
        bool $canSlice,
        string $slice,
        ?int $gameType,
        ?Carbon $from,
        ?Carbon $to,
        Request $request
    ): array {
        $columns = $settings['columns'] ?? [];
        $sort    = $settings['sort']    ?? ['sortKey' => 'pts', 'sortDirection' => 'desc'];

        $identityCols = [
            ['key' => 'name',               'label' => 'Player'],
            ['key' => 'age',                'label' => 'Age'],
            ['key' => 'team',               'label' => 'Team'],
            ['key' => 'pos_type',           'label' => 'Type'],
            ['key' => 'contract_value_num', 'label' => 'AAV'],
            ['key' => 'contract_last_year', 'label' => 'Term End'],
            ['key' => 'gp',                 'label' => 'GP'],
        ];

        $base = NhlGameSummary::query()
            ->with(['player.contracts.seasons'])
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'nhl_game_summaries.nhl_game_id')
            ->select('nhl_game_summaries.*');

        if ($from) {
            $base->whereDate('g.game_date', '>=', $from->toDateString());
        }
        if ($to) {
            $base->whereDate('g.game_date', '<=', $to->toDateString());
        }
        if (in_array((string)$gameType, ['1','2','3'], true)) {
            $base->where('g.game_type', (int)$gameType);
        }

        [$schema, $applied] = $this->buildSchemaAndApplyFilters($base, $columns, $request, $settings['filters'] ?? []);

        $results = $base->get();
        $rows    = $this->assembleRowsFromCollection($results, $columns, $slice, $canSlice, 'range', [
            'game_type' => $gameType,
            'date_from' => $from?->toDateString(),
            'date_to' => $to?->toDateString(),
        ]);
        $rows    = $this->appendOnIceRows($rows, $columns, [
            'game_type' => $gameType,
            'date_from' => $from?->toDateString(),
            'date_to' => $to?->toDateString(),
        ]);

        // Post filters + virtual schema
        [$rows, $appliedExtra, $virtualSchema] = $this->applyPostFilters($request, $rows);

        // Sort
        $sortKey = $sort['sortKey'] ?? 'pts';
        $sortDir = strtolower($sort['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sorted  = $rows->sortBy([[$sortKey, $sortDir]])->values();

        // Headings + schema merge
        $headings = $this->mergeHeadings($identityCols, $columns);

        $seen = [];
        $mergedSchema = [];
        foreach (array_merge($schema ?? [], $virtualSchema ?? []) as $def) {
            $k = $def['key'] ?? null;
            if (!$k || isset($seen[$k])) continue;
            $seen[$k] = true;
            $mergedSchema[] = $def;
        }

        $applied['filters'] = array_merge($applied['filters'] ?? [], $appliedExtra['filters'] ?? []);
        $avail = (int) ($request?->input('availability', 0) ?? 0);
        $leagueIdMeta = $avail > 0 ? $avail : null;


        $formatted = [
            'headings' => $headings,
            'data'     => $sorted,
            'settings' => [
                'sortable'             => collect($headings)->pluck('key')->values()->all(),
                'defaultSort'          => $sortKey,
                'defaultSortDirection' => $sortDir,
                'resource'             => 'players',
                'slice'                => $canSlice ? $slice : 'total',
                'columnGroups'         => $settings['columnGroups'] ?? null,
                'columnGroupSort'      => $settings['columnGroupSort'] ?? null,
                'activeColumnGroup'    => $settings['activeColumnGroup'] ?? null,
            ],
            'meta' => [
                'availableSeasons'   => [],
                'availableGameTypes' => [1,2,3],
                'season'             => null,
                'game_type'          => (int) ($gameType ?? 2),
                'canSlice'           => $canSlice,
                'filterSchema'       => $mergedSchema,
                'appliedFilters'     => $applied['filters'] ?? [],
                'pos'                => $applied['pos'] ?? [],
                'pos_type'           => $applied['pos_type'] ?? [],
                'availability' => $avail,
                'league_id'    => $leagueIdMeta,
                'positionButtons' => $this->positionButtons($settings),
                'supportsDateRange'  => true,
            ],
        ];

        return [$formatted, [], null];
    }

    /** ───────────────────────── Row assembly (grouped) ───────────────────────── */
    private function assembleRowsFromCollection(
        Collection $collection,
        array $columns,
        string $slice,
        bool $canSlice,
        string $mode,
        array $filters = []
    ): Collection {
        $rows = collect();
        $officialToiByPlayer = $this->officialBoxscoreToiByPlayer($collection, $mode, $filters);

        $grouped = $collection->groupBy(function ($row) use ($mode, $filters): string {
            $playerId = (string) ($row->player_id ?? $row->nhl_player_id ?? '');

            if ($mode === 'prospects' && ! (bool) ($filters['draft_context'] ?? false)) {
                return $playerId . '|' . (string) ($row->league_abbrev ?? '');
            }

            return $playerId;
        });

        foreach ($grouped as $playerStats) {
            $entry    = $playerStats->count() === 1 ? $playerStats->first() : $playerStats->sortByDesc('gp')->first();
            $player   = $entry->player;
            $isSeason = ($mode === 'season' || ($mode === 'prospects' && (bool) ($filters['draft_context'] ?? false)));

            $contract        = $player?->contracts()->exists() ? $player->contracts()->first() : null;
            $contractSeason  = $contract?->seasons->last();
            $contractLastLbl = $contractSeason?->label ?? '';
            $contractAavRaw  = is_numeric($contractSeason?->aav) ? (float) $contractSeason->aav : 0.0;

            $contractAavM    = $contractAavRaw > 0 ? $contractAavRaw / 1_000_000 : 0.0;
            $contractAav     = $contractAavRaw > 0 ? '$' . number_format($contractAavM, 1) . 'm' : '$0.0m';

            $lastYearNum     = $this->parseContractLastYear($contractLastLbl);

            // GP & TOI (normalize to INT seconds first)
            if ($isSeason) {
                $gpSum = (int) ($entry->gp ?? 0);

                $toiSec = 0;
                if (isset($entry->toi_seconds) && is_numeric($entry->toi_seconds)) {
                    $toiSec = (int) $entry->toi_seconds;
                } elseif (isset($entry->toi) && is_numeric($entry->toi)) {
                    $toiSec = (int) $entry->toi; // seconds in your schema
                } elseif (isset($entry->toi_minutes) && is_numeric($entry->toi_minutes)) {
                    $v = (float) $entry->toi_minutes;
                    $toiSec = (int) (($v > 4000) ? $v : $v * 60.0);
                }
            } else {
                $gpSum = ($mode === 'range')
                    ? (int) $playerStats->pluck('nhl_game_id')->unique()->count()
                    : (int) $playerStats->sum('gp');

                $toiSec = 0;
                if (($sum = (int) $playerStats->sum('toi_seconds')) > 0) {
                    $toiSec = $sum;
                } elseif (($sum = (float) $playerStats->sum('toi_minutes')) > 0) {
                    $toiSec = (int) (($sum > 4000) ? $sum : $sum * 60.0);
                } elseif (($sum = (float) $playerStats->sum('toi')) > 0) {
                    $toiSec = (int) (($sum > 4000) ? $sum : $sum * 60.0);
                }
            }

            $nhlPlayerId = (int) ($player?->nhl_id ?? $entry->nhl_player_id ?? 0);
            $officialToiSec = (int) ($officialToiByPlayer->get($nhlPlayerId) ?? 0);
            if ($officialToiSec > 0) {
                $toiSec = $officialToiSec;
            }

            $toiPerGameSec = ($gpSum > 0) ? (int) floor($toiSec / $gpSum) : 0;

            $row = [
                'name'                   => $player?->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? '')),
                'player_id'              => $player?->id ?? $entry->player_id ?? null,
                'avatar_url'             => $player?->head_shot_url,
                'age'                    => $this->playerAge($player),
                'team'                   => $player?->team_abbrev ?? $entry->team_abbrev ?? ($entry->nhl_team_abbrev ?? null),
                'league'                 => $mode === 'prospects' ? ($entry->league_abbrev ?? null) : null,
                'pos'                    => $player?->position,
                'pos_type'               => $player?->pos_type,
                'contract_value'         => $contractAav,
                'contract_value_num'     => round($contractAavM, 1),
                'contract_last_year'     => $contractLastLbl,
                'contract_last_year_num' => $lastYearNum,
                'gp'                     => max(0, $gpSum),
                'nhl_player_id'          => $player?->nhl_id ?? $entry->nhl_player_id ?? null,

                // TOI immediately after GP
                'toi_seconds'            => $toiPerGameSec,
                'toi'                    => $this->formatTimeOnIce($toiPerGameSec),
            ];

            foreach ($columns as $col) {
                $key = $col['key'] ?? null;
                if (!$key || $key === 'gp' || $key === 'toi' || $key === 'toi_seconds') {
                    continue;
                }

                // total for this key
                $total = $isSeason
                    ? (float) ($entry->{$key} ?? 0)
                    : (float) $playerStats->sum($key);

                if ($canSlice && $slice !== 'total') {
                    if ($slice === 'pgp') {
                        $row[$key] = $gpSum > 0 ? round($total / $gpSum, 2) : 0.0;
                    } elseif ($slice === 'p60') {
                        // use INT seconds, convert to hours
                        $row[$key] = $toiSec > 0 ? round($total / ($toiSec / 3600), 2) : 0.0;
                    }
                } else {
                    $row[$key] = fmod($total, 1.0) === 0.0 ? (int) $total : $total;
                }
            }

            $row = $this->withNativeFantasyAliases($row, $playerStats, $gpSum, $toiSec, $isSeason);

            $rows->push($row);
        }

        return $rows;
    }

    /**
     * Load official boxscore TOI for read-only display/rate calculations.
     *
     * @param Collection<int,object> $collection
     * @param array<string,mixed> $filters
     * @return Collection<int,int>
     */
    private function officialBoxscoreToiByPlayer(Collection $collection, string $mode, array $filters): Collection
    {
        if ($mode === 'prospects' || $collection->isEmpty()) {
            return collect();
        }

        $playerIds = $collection
            ->map(function (object $row): int {
                if (isset($row->nhl_player_id)) {
                    return (int) $row->nhl_player_id;
                }

                $player = $row->player ?? null;

                return (int) ($player?->nhl_id ?? 0);
            })
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($playerIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('nhl_boxscores as b')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'b.nhl_game_id')
            ->whereIn('b.nhl_player_id', $playerIds->all())
            ->select('b.nhl_player_id', 'b.toi_seconds', 'b.toi');

        if (! empty($filters['season_id'])) {
            $query->where('g.season_id', (string) $filters['season_id']);
        }

        if (! empty($filters['game_type'])) {
            $query->where('g.game_type', (int) $filters['game_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('g.game_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('g.game_date', '<=', (string) $filters['date_to']);
        }

        return $query->get()
            ->groupBy(fn (object $row): int => (int) $row->nhl_player_id)
            ->map(fn (Collection $rows): int => (int) $rows->sum(
                fn (object $row): int => $this->boxscoreToiSeconds($row->toi_seconds, $row->toi)
            ));
    }

    private function boxscoreToiSeconds(mixed $seconds, mixed $toi): int
    {
        if (is_numeric($seconds) && (int) $seconds > 0) {
            return (int) $seconds;
        }

        if (! is_string($toi) || ! str_contains($toi, ':')) {
            return 0;
        }

        [$minutes, $remainingSeconds] = array_pad(explode(':', $toi, 2), 2, '0');

        return ((int) $minutes * 60) + (int) $remainingSeconds;
    }

    /**
     * Add native fantasy aliases that can be derived from already-loaded player totals.
     *
     * @param Collection<int,object> $playerStats
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withNativeFantasyAliases(array $row, Collection $playerStats, int $gamesPlayed, int $toiSeconds, bool $isSeason): array
    {
        $total = function (string $key) use ($playerStats, $isSeason): float {
            if ($isSeason) {
                $entry = $playerStats->first();

                return (float) ($entry->{$key} ?? 0);
            }

            return (float) $playerStats->sum($key);
        };
        $totalFirstAvailable = function (array $keys) use ($playerStats, $isSeason): float {
            if ($isSeason) {
                $entry = $playerStats->first();

                foreach ($keys as $key) {
                    if (isset($entry->{$key}) && is_numeric($entry->{$key})) {
                        return (float) $entry->{$key};
                    }
                }

                return 0.0;
            }

            foreach ($keys as $key) {
                $value = (float) $playerStats->sum($key);

                if ($value !== 0.0) {
                    return $value;
                }
            }

            return 0.0;
        };

        $sog = $total('sog');
        $sat = $total('sat');
        $hits = $total('h');
        $blocks = $total('b');
        $shotsAgainst = $totalFirstAvailable(['sa', 'shots_against']);
        $goalsAgainst = $totalFirstAvailable(['ga', 'goals_against']);
        $saves = $totalFirstAvailable(['sv', 'saves']);
        if ($saves <= 0 && $shotsAgainst > 0) {
            $saves = max(0, $shotsAgainst - $goalsAgainst);
        }
        $evShotsAgainst = $total('evsa');
        $evSaves = $total('evsv');
        $ppShotsAgainst = $total('ppsa');
        $ppSaves = $total('ppsv');
        $pkShotsAgainst = $total('pksa');
        $pkSaves = $total('pksv');

        return array_merge($row, [
            'sog_per_gp' => $this->perGameAlias($sog, $gamesPlayed),
            'sat_per_gp' => $this->perGameAlias($sat, $gamesPlayed),
            'hits_per_gp' => $this->perGameAlias($hits, $gamesPlayed),
            'blocks_per_gp' => $this->perGameAlias($blocks, $gamesPlayed),
            'fow_per_gp' => $this->perGameAlias($total('fow'), $gamesPlayed),
            'saves_per_gp' => $this->perGameAlias($saves, $gamesPlayed),
            'shots_against_per_gp' => $this->perGameAlias($shotsAgainst, $gamesPlayed),
            'ga_per_gp' => $this->perGameAlias($goalsAgainst, $gamesPlayed),
            'sog_per_60' => $this->per60Alias($sog, $toiSeconds),
            'sat_per_60' => $this->per60Alias($sat, $toiSeconds),
            'hits_per_60' => $this->per60Alias($hits, $toiSeconds),
            'blocks_per_60' => $this->per60Alias($blocks, $toiSeconds),
            'a1_per_60' => $this->per60Alias($total('a1'), $toiSeconds),
            'a2_per_60' => $this->per60Alias($total('a2'), $toiSeconds),
            'shots_plus_blocks' => (int) ($sog + $blocks),
            'hits_plus_blocks' => (int) ($hits + $blocks),
            'saves' => (int) $saves,
            'shots_against' => (int) $shotsAgainst,
            'goals_against' => (int) $goalsAgainst,
            'sv_pct' => $shotsAgainst > 0 ? round($saves / $shotsAgainst, 3) : (float) ($row['sv_pct'] ?? 0),
            'gaa' => $toiSeconds > 0 ? round(($goalsAgainst * 3600) / $toiSeconds, 3) : (float) ($row['gaa'] ?? 0),
            'ev_sv_pct' => $evShotsAgainst > 0 ? round($evSaves / $evShotsAgainst, 3) : (float) ($row['ev_sv_pct'] ?? 0),
            'pp_sv_pct' => $ppShotsAgainst > 0 ? round($ppSaves / $ppShotsAgainst, 3) : (float) ($row['pp_sv_pct'] ?? 0),
            'pk_sv_pct' => $pkShotsAgainst > 0 ? round($pkSaves / $pkShotsAgainst, 3) : (float) ($row['pk_sv_pct'] ?? 0),
        ]);
    }

    /**
     * Merge native on-ice strength totals when a perspective asks for advanced on-ice keys.
     *
     * @param Collection<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    private function appendOnIceRows(Collection $rows, array $columns, array $filters): Collection
    {
        if (! $this->columnsNeedOnIce($columns) || $rows->isEmpty()) {
            return $rows;
        }

        $playerIds = $rows
            ->pluck('nhl_player_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($playerIds === []) {
            return $rows;
        }

        $onIceRows = $this->nativeOnIceTotals($filters, $playerIds);

        if ($onIceRows->isEmpty()) {
            return $rows;
        }

        return $rows->map(function (array $row) use ($onIceRows): array {
            $nhlPlayerId = (int) ($row['nhl_player_id'] ?? 0);

            if ($nhlPlayerId === 0 || ! $onIceRows->has($nhlPlayerId)) {
                return $row;
            }

            return array_merge($row, $this->nativeOnIceAliases($onIceRows->get($nhlPlayerId)));
        });
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     */
    private function columnsNeedOnIce(array $columns): bool
    {
        $onIceKeys = [
            'ipp', 'individual_g', 'individual_a', 'individual_pts',
            'gf', 'ga', 'gf_pct', 'cf', 'ca', 'cf_pct', 'ff', 'fa', 'ff_pct',
            'sf', 'sa', 'sf_pct', 'pdo', 'on_ice_shooting_percentage',
            'on_ice_save_percentage', 'ozs_pct', 'dzs_pct',
        ];

        return collect($columns)
            ->pluck('key')
            ->intersect($onIceKeys)
            ->isNotEmpty();
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,int> $playerIds
     * @return Collection<int,object>
     */
    private function nativeOnIceTotals(array $filters, array $playerIds): Collection
    {
        $query = DB::table('nhl_player_game_strength_summaries as s')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 's.nhl_game_id')
            ->whereIn('s.nhl_player_id', $playerIds)
            ->groupBy('s.nhl_player_id')
            ->selectRaw(<<<'SQL'
                s.nhl_player_id,
                COUNT(DISTINCT s.nhl_game_id) as gp,
                SUM(s.toi) as toi,
                SUM(s.gf) as gf,
                SUM(s.ga) as ga,
                SUM(s.sf) as sf,
                SUM(s.sa) as sa,
                SUM(s.satf) as satf,
                SUM(s.sata) as sata,
                SUM(s.ff) as ff,
                SUM(s.fa) as fa,
                SUM(s.ozs) as ozs,
                SUM(s.dzs) as dzs,
                SUM(s.individual_g) as individual_g,
                SUM(s.individual_a) as individual_a,
                SUM(s.individual_pts) as individual_pts
            SQL);

        if (! empty($filters['season_id'])) {
            $query->where('g.season_id', (string) $filters['season_id']);
        }

        if (! empty($filters['game_type'])) {
            $query->where('g.game_type', (int) $filters['game_type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('g.game_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('g.game_date', '<=', (string) $filters['date_to']);
        }

        return $query->get()->keyBy(fn (object $row): int => (int) $row->nhl_player_id);
    }

    /**
     * @return array<string,float|int>
     */
    private function nativeOnIceAliases(object $row): array
    {
        $gf = (float) ($row->gf ?? 0);
        $ga = (float) ($row->ga ?? 0);
        $sf = (float) ($row->sf ?? 0);
        $sa = (float) ($row->sa ?? 0);
        $cf = (float) ($row->satf ?? 0);
        $ca = (float) ($row->sata ?? 0);
        $ff = (float) ($row->ff ?? 0);
        $fa = (float) ($row->fa ?? 0);
        $ozs = (float) ($row->ozs ?? 0);
        $dzs = (float) ($row->dzs ?? 0);
        $toi = (int) ($row->toi ?? 0);
        $individualPoints = (float) ($row->individual_pts ?? 0);
        $onIceShooting = $sf > 0 ? round($gf / $sf, 3) : 0.0;
        $onIceSave = $sa > 0 ? round(1 - ($ga / $sa), 3) : 0.0;

        return [
            'individual_g' => (int) ($row->individual_g ?? 0),
            'individual_a' => (int) ($row->individual_a ?? 0),
            'individual_pts' => (int) ($row->individual_pts ?? 0),
            'ipp' => $gf > 0 ? round($individualPoints / $gf, 3) : 0.0,
            'gf' => (int) $gf,
            'ga' => (int) $ga,
            'gf_pct' => $this->ratioAlias($gf, $gf + $ga),
            'sf' => (int) $sf,
            'sa' => (int) $sa,
            'sf_pct' => $this->ratioAlias($sf, $sf + $sa),
            'cf' => (int) $cf,
            'ca' => (int) $ca,
            'cf_pct' => $this->ratioAlias($cf, $cf + $ca),
            'ff' => (int) $ff,
            'fa' => (int) $fa,
            'ff_pct' => $this->ratioAlias($ff, $ff + $fa),
            'gf_per_60' => $this->per60Alias($gf, $toi),
            'ga_per_60' => $this->per60Alias($ga, $toi),
            'sf_per_60' => $this->per60Alias($sf, $toi),
            'sa_per_60' => $this->per60Alias($sa, $toi),
            'cf_per_60' => $this->per60Alias($cf, $toi),
            'ca_per_60' => $this->per60Alias($ca, $toi),
            'ff_per_60' => $this->per60Alias($ff, $toi),
            'fa_per_60' => $this->per60Alias($fa, $toi),
            'on_ice_shooting_percentage' => $onIceShooting,
            'on_ice_save_percentage' => $onIceSave,
            'pdo' => round($onIceShooting + $onIceSave, 3),
            'ozs' => (int) $ozs,
            'dzs' => (int) $dzs,
            'ozs_pct' => $this->ratioAlias($ozs, $ozs + $dzs),
            'dzs_pct' => $this->ratioAlias($dzs, $ozs + $dzs),
        ];
    }

    private function perGameAlias(float $total, int $gamesPlayed): float
    {
        return $gamesPlayed > 0 ? round($total / $gamesPlayed, 3) : 0.0;
    }

    private function per60Alias(float $total, int $toiSeconds): float
    {
        return $toiSeconds > 0 ? round($total / ($toiSeconds / 3600), 3) : 0.0;
    }

    private function ratioAlias(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 3) : 0.0;
    }

    /** ───────────────────── Post-assembly filters + schema ───────────────────── */
    private function applyPostFilters(?Request $request, Collection $rows): array
    {
        $bounds = [
            'gp' => [
                'min' => (int) ($rows->min('gp') ?? 0),
                'max' => (int) ($rows->max('gp') ?? 0),
            ],
            'contract_value_num' => [
                'min' => (float) ($rows->min('contract_value_num') ?? 0.0),
                'max' => (float) ($rows->max('contract_value_num') ?? 0.0),
            ],
            'contract_last_year_num' => [
                'min' => (int) ($rows->min('contract_last_year_num') ?? 0),
                'max' => (int) ($rows->max('contract_last_year_num') ?? 0),
            ],
        ];

        $virtualSchema = [];
        if ($bounds['gp']['max'] > 0) {
            $virtualSchema[] = ['key' => 'gp', 'label' => 'GP', 'type' => 'number', 'bounds' => $bounds['gp'], 'step' => 1];
        }
        if ($bounds['contract_value_num']['max'] > 0) {
            $virtualSchema[] = [
                'key' => 'contract_value_num', 'label' => 'AAV', 'type' => 'number',
                'bounds' => [
                    'min' => (float) floor($bounds['contract_value_num']['min']),
                    'max' => (float) ceil($bounds['contract_value_num']['max']),
                ],
                'step' => 0.1
            ];
        }
        if ($bounds['contract_last_year_num']['max'] > 0) {
            $virtualSchema[] = ['key' => 'contract_last_year_num', 'label' => 'Term End', 'type' => 'number', 'bounds' => $bounds['contract_last_year_num'], 'step' => 1];
        }

        if (!$request) {
            return [$rows, ['filters' => []], $virtualSchema];
        }

        $gpMin  = $request->query('gp_min');  $gpMax  = $request->query('gp_max');
        $cvMin  = $request->query('contract_value_num_min');  $cvMax  = $request->query('contract_value_num_max');
        $lyMin  = $request->query('contract_last_year_num_min');  $lyMax  = $request->query('contract_last_year_num_max');

        $filtered = $rows->filter(function ($r) use ($gpMin, $gpMax, $cvMin, $cvMax, $lyMin, $lyMax) {
            if ($gpMin !== null && $r['gp'] < (int)$gpMin) return false;
            if ($gpMax !== null && $r['gp'] > (int)$gpMax) return false;

            if ($cvMin !== null && (float)$r['contract_value_num'] < (float)$cvMin) return false;
            if ($cvMax !== null && (float)$r['contract_value_num'] > (float)$cvMax) return false;

            if ($lyMin !== null && (int)$r['contract_last_year_num'] < (int)$lyMin) return false;
            if ($lyMax !== null && (int)$r['contract_last_year_num'] > (int)$lyMax) return false;

            return true;
        })->values();

        $applied = ['filters' => []];
        if ($gpMin !== null || $gpMax !== null) {
            $applied['filters']['gp'] = ['min' => $gpMin !== null ? (int)$gpMin : null, 'max' => $gpMax !== null ? (int)$gpMax : null];
        }
        if ($cvMin !== null || $cvMax !== null) {
            $applied['filters']['contract_value_num'] = ['min' => $cvMin !== null ? (float)$cvMin : null, 'max' => $cvMax !== null ? (float)$cvMax : null];
        }
        if ($lyMin !== null || $lyMax !== null) {
            $applied['filters']['contract_last_year_num'] = ['min' => $lyMin !== null ? (int)$lyMin : null, 'max' => $lyMax !== null ? (int)$lyMax : null];
        }

        return [$filtered, $applied, $virtualSchema];
    }



    /** ───────────────────────────── Small helpers ───────────────────────────── */
    private function parseContractLastYear(?string $label): ?int
    {
        if (!$label) return null;
        $label = trim($label);

        if (preg_match('/\b(20\d{2})\b/', $label, $m)) {
            $year = (int) $m[1];

            if (preg_match('/20(\d{2})\D+(\d{2})\b/', $label, $mm)) {
                $first = (int) ('20' . $mm[1]);
                $end2  = (int) $mm[2];
                $second = $first >= 2000 ? ($first - 2000) : 0;
                $second = $second <= 99 ? (int) ('20' . str_pad((string)$end2, 2, '0', STR_PAD_LEFT)) : $year;
                return $second;
            }
            return $year;
        }
        return null;
    }

    private function resolveDates(string $period, $from, $to): array
    {
        $today = Carbon::today();

        return match ($period) {
            'lastWeek'   => [$today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek()],
            'thisWeek'   => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
            'past30days' => [$today->copy()->subDays(30), $today],
            'range'      => [$from ? Carbon::parse($from) : null, $to ? Carbon::parse($to) : null],
            default      => [null, null],
        };
    }

    private function isProspectsPerspective(object $p, array $filters): bool
    {
        $slug = strtolower((string) ($p->slug ?? ''));

        return in_array($slug, ['prospects', 'prospects-goalies'], true);
    }

    /**
     * Build ephemeral perspective settings from a fully mapped league scoring configuration.
     */
    private function leagueScoringPerspectiveSettings(PlatformLeague $league): ?array
    {
        $categories = data_get($league, 'scoring_settings.categories', []);

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        $scoringOrderByStatId = $this->leagueScoringOrderByStatId($league);
        $categoryRows = collect(array_is_list($categories) ? $categories : array_values($categories))
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(static function (array $category) use ($scoringOrderByStatId): array {
                $category['display_order'] = (int) (
                    $scoringOrderByStatId[(string) ($category['id'] ?? '')]
                    ?? $category['scoring_order']
                    ?? PHP_INT_MAX
                );

                return $category;
            })
            ->sortBy('display_order')
            ->values();

        if ($categoryRows->isEmpty()) {
            return null;
        }

        if ($categoryRows->contains(static fn (array $category): bool => trim((string) ($category['stat_key'] ?? '')) === '')) {
            return null;
        }

        $columns = $categoryRows
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(static function (array $category): ?array {
                $statKey = trim((string) ($category['stat_key'] ?? ''));

                if ($statKey === '') {
                    return null;
                }

                $label = trim((string) (
                    $category['short']
                    ?? $category['label']
                    ?? $category['name']
                    ?? strtoupper($statKey)
                ));

                return [
                    'key' => $statKey,
                    'label' => $label !== '' ? $label : strtoupper($statKey),
                    'type' => in_array($statKey, ['sv_pct', 'gaa'], true) ? 'float' : 'int',
                ];
            })
            ->filter()
            ->unique(static fn (array $column): string => $column['key'])
            ->values()
            ->all();

        if ($columns === []) {
            return null;
        }

        $goalieKeys = $this->goalieScoringStatKeys();
        $goalieColumns = collect($columns)
            ->filter(static fn (array $column): bool => in_array($column['key'], $goalieKeys, true))
            ->values()
            ->all();
        $skaterColumns = collect($columns)
            ->reject(static fn (array $column): bool => in_array($column['key'], $goalieKeys, true))
            ->values()
            ->all();

        $sortKey = collect(['pts', 'g', 'a', 'wins', 'sv', 'sog'])
            ->first(static fn (string $key): bool => collect($columns)->contains('key', $key))
            ?? $columns[0]['key'];
        $skaterSortKey = collect(['pts', 'g', 'a', 'sog'])
            ->first(static fn (string $key): bool => collect($skaterColumns)->contains('key', $key))
            ?? ($skaterColumns[0]['key'] ?? $sortKey);
        $goalieSortKey = collect(['wins', 'sv', 'sv_pct', 'gaa'])
            ->first(static fn (string $key): bool => collect($goalieColumns)->contains('key', $key))
            ?? ($goalieColumns[0]['key'] ?? $sortKey);

        return [
            'columns' => $columns,
            'sort' => [
                'sortKey' => $skaterSortKey,
                'sortDirection' => 'desc',
            ],
            'columnGroups' => [
                'skater' => $skaterColumns,
                'goalie' => $goalieColumns,
            ],
            'columnGroupSort' => [
                'skater' => [
                    'sortKey' => $skaterSortKey,
                    'sortDirection' => 'desc',
                ],
                'goalie' => [
                    'sortKey' => $goalieSortKey,
                    'sortDirection' => 'desc',
                ],
            ],
            'activeColumnGroup' => 'skater',
            'filters' => [],
            'ui' => [
                'positionButtons' => ['F', 'C', 'LW', 'RW', 'D', 'G'],
            ],
        ];
    }

    /**
     * Stat keys treated as goalie-only when splitting league scoring columns.
     *
     * @return array<int,string>
     */
    private function goalieScoringStatKeys(): array
    {
        return [
            'wins',
            'losses',
            'ot_losses',
            'starts',
            'relief_appearances',
            'quality_starts',
            'really_bad_starts',
            'quality_start_percentage',
            'sv',
            'saves',
            'sa',
            'shots_against',
            'ga',
            'goals_against',
            'gaa',
            'sv_pct',
            'ev_sv_pct',
            'pp_sv_pct',
            'pk_sv_pct',
            'so',
            'shutouts',
            'shosv',
        ];
    }

    /**
     * Derive selected Yahoo scoring category order from stored raw stat modifiers.
     *
     * @return array<string,int>
     */
    private function leagueScoringOrderByStatId(PlatformLeague $league): array
    {
        $stats = data_get($league, 'scoring_settings.raw_payload.stat_modifiers.stats.stat', []);

        if (! is_array($stats)) {
            return [];
        }

        if (isset($stats['stat_id'])) {
            $stats = [$stats];
        }

        $rows = array_values($stats);
        $count = count($rows);

        return collect($rows)
            ->filter(static fn (mixed $stat): bool => is_array($stat))
            ->mapWithKeys(static function (array $stat, int $index) use ($count): array {
                $statId = trim((string) ($stat['stat_id'] ?? ''));

                return $statId !== '' ? [$statId => $count - $index] : [];
            })
            ->all();
    }



    /**
     * Build the filter schema from the perspective columns + identity fields,
     * then apply filters from the query string onto $base.
     *
     * Returns: [$schema, $applied]
     * - $schema: array of filter definitions for the UI (with numeric bounds)
     * - $applied: echo of what was applied (for the UI to hydrate)
     */
    private function buildSchemaAndApplyFilters($base, array $columns, ?Request $request, array $perspectiveFilters = []): array
    {
        $table = $base->getModel()->getTable();

        // Join players as `pf` so we can filter by team/pos/age everywhere.
        if ($table === 'stats') {
            $base->leftJoin('players as pf', 'pf.id', '=', "{$table}.player_id");
        } else { // nhl_season_stats / nhl_game_summaries
            $base->leftJoin('players as pf', 'pf.nhl_id', '=', "{$table}.nhl_player_id");
        }

        $this->applyAvailabilityToBase($base, $request, $request?->user());
        $this->applyPerspectiveFiltersToBase($base, $perspectiveFilters);


        // Base schema (always available in the UI)
        $schema = [
            ['key' => 'age',  'label' => 'Age',      'type' => 'int',  'bounds' => $this->ageBoundsForBase($base)],
            ['key' => 'team', 'label' => 'Team',     'type' => 'enum', 'options' => $this->teamOptionsForBase($base)],
            ['key' => 'pos',  'label' => 'Position', 'type' => 'enum', 'options' => $this->positionOptionsForBase($base)],
        ];

        if ($table === 'stats') {
            $schema[] = [
                'key' => 'league',
                'label' => 'League',
                'type' => 'enum',
                'options' => $this->leagueOptionsForBase($base),
            ];
        }

        // Perspective numeric columns -> add with bounds
        foreach ($columns as $col) {
            $key = $col['key'] ?? null;
            if (!$key || in_array($key, ['name','age','team','contract_value','gp'], true)) {
                continue;
            }

            $b = $this->bounds($base, $key);
            $schema[] = [
                'key'    => $key,
                'label'  => $col['label'] ?? \Illuminate\Support\Str::title(str_replace('_',' ', $key)),
                'type'   => 'number',
                'bounds' => $b,
                'step'   => 1,
            ];
        }

        // Expose GP slider only for tables that physically have it.
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'gp')) {
            $schema[] = [
                'key'    => 'gp',
                'label'  => 'GP',
                'type'   => 'number',
                'bounds' => $this->bounds($base, 'gp'),
                'step'   => 1,
            ];
        }

        // Derived/virtual numeric keys that must NOT be pushed to SQL in the generic pass.
        $virtualOrDerived = ['age', 'contract_value_num', 'contract_last_year_num'];

        // Whitelist of physical numeric keys we can safely filter on.
        $physicalNumeric = collect($schema)
            ->filter(function ($s) use ($virtualOrDerived) {
                return strtolower($s['type'] ?? '') === 'number'
                    && !empty($s['key'])
                    && !in_array($s['key'], $virtualOrDerived, true);
            })
            ->pluck('key')
            ->flip(); // O(1) lookup

        // Echo back what was applied (for UI hydration)
        $applied = [
            'filters'  => [],
            'pos'      => array_values(array_filter((array)($request?->query('pos', []) ?? []), 'strlen')),
            'pos_type' => array_values(array_filter((array)($request?->query('pos_type', []) ?? []), 'strlen')),
        ];

        // pos_type / pos filters (on pf.*)
        if (!empty($applied['pos_type'])) {
            $base->whereIn('pf.pos_type', $applied['pos_type']);
            if (in_array('G', array_map('strtoupper', $applied['pos_type']), true)) {
                $base->where('pf.position', 'G');
            }
        }

        if (!empty($applied['pos'])) {
            $posU = $this->expandPositionFilterValues($applied['pos']);
            if (array_diff($posU, ['G'])) {
                $posU = array_values(array_diff($posU, ['G']));
            }
            if (!empty($posU)) {
                $base->whereIn('pf.position', $posU);
            }
        }

        // Teams
        $teams = array_values(array_filter((array)($request?->query('team', []) ?? []), 'strlen'));
        if (!empty($teams)) {
            if ($table === 'stats') {
                $base->whereIn("{$table}.nhl_team_abbrev", $teams);
            } else {
                $base->whereIn('pf.team_abbrev', $teams);
            }
            $applied['filters']['team'] = $teams;
        }

        $leagues = array_values(array_filter((array)($request?->query('league', []) ?? []), 'strlen'));
        if ($table === 'stats' && !empty($leagues)) {
            $base->whereIn("{$table}.league_abbrev", $leagues);
            $applied['filters']['league'] = $leagues;
        }

        // Age min/max -> pf.dob range (DB-agnostic)
        $ageMin = $request?->query('age_min');
        $ageMax = $request?->query('age_max');
        if ($ageMin !== null || $ageMax !== null) {
            $today       = \Illuminate\Support\Carbon::today();
            $youngestDob = $ageMin !== null ? $today->copy()->subYears((int)$ageMin)->toDateString() : null;              // newest DOB
            $oldestDob   = $ageMax !== null ? $today->copy()->subYears((int)$ageMax + 1)->addDay()->toDateString() : null; // oldest DOB

            if ($oldestDob && $youngestDob) {
                $base->whereBetween('pf.dob', [$oldestDob, $youngestDob]);
            } elseif ($oldestDob) {
                $base->where('pf.dob', '>=', $oldestDob);
            } elseif ($youngestDob) {
                $base->where('pf.dob', '<=', $youngestDob);
            }

            $applied['filters']['age'] = [
                'min' => $ageMin !== null ? (int)$ageMin : null,
                'max' => $ageMax !== null ? (int)$ageMax : null,
            ];
        }

        // Dynamic numeric filters via *_min / *_max for physical columns only.
        foreach (($request?->query() ?? []) as $k => $v) {
            if (!is_scalar($v)) continue;
            if (!preg_match('/^(.*)_(min|max)$/', $k, $m)) continue;

            $baseKey = $m[1];
            $bound   = $m[2];

            if ($baseKey === 'age') continue;                 // handled above via DOB
            if (!isset($physicalNumeric[$baseKey])) continue; // only real numeric columns

            $col = $this->mapFilterColumn($base, $baseKey);
            if (!$col) continue;

            $val  = is_numeric($v) ? (float)$v : null;
            $pair = $applied['filters'][$baseKey] ?? ['min' => null, 'max' => null];

            if ($bound === 'min') {
                $pair['min'] = $val;
                if ($val !== null) $base->where($col, '>=', $val);
            } else {
                $pair['max'] = $val;
                if ($val !== null) $base->where($col, '<=', $val);
            }

            $applied['filters'][$baseKey] = $pair;
        }

        return [$schema, $applied];
    }

    /**
     * Expand UI position labels into stored NHL position variants.
     *
     * @param array<int,string> $positions
     * @return array<int,string>
     */
    private function expandPositionFilterValues(array $positions): array
    {
        return collect($positions)
            ->flatMap(function (string $position): array {
                return match (strtoupper(trim($position))) {
                    'LW' => ['LW', 'L'],
                    'RW' => ['RW', 'R'],
                    default => [strtoupper(trim($position))],
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Apply locked perspective-level player filters after the stats query has joined players as pf.
     *
     * @param array<string,mixed> $filters
     */
    private function applyPerspectiveFiltersToBase($base, array $filters): void
    {
        foreach (['pos', 'pos_type'] as $key) {
            $filter = $filters[$key] ?? null;

            if (! is_array($filter)) {
                continue;
            }

            $value = $filter['value'] ?? null;
            $operator = strtoupper((string) ($filter['operator'] ?? '='));
            $values = is_array($value) ? array_values($value) : [$value];
            $values = array_values(array_filter(
                array_map(fn (mixed $item): string => strtoupper(trim((string) $item)), $values),
                fn (string $item): bool => $item !== ''
            ));

            if ($values === []) {
                continue;
            }

            $column = $key === 'pos' ? 'pf.position' : 'pf.pos_type';

            if (in_array($operator, ['!=', '<>'], true)) {
                $base->whereNotIn($column, $values);
            } else {
                $base->whereIn($column, $values);
            }
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<int,string>
     */
    private function positionButtons(array $settings): array
    {
        $buttons = $settings['ui']['positionButtons'] ?? null;

        if (! is_array($buttons)) {
            return ['LW', 'C', 'RW', 'F', 'D', 'G'];
        }

        return collect($buttons)
            ->map(fn (mixed $button): string => strtoupper(trim((string) $button)))
            ->filter(fn (string $button): bool => in_array($button, ['F', 'C', 'LW', 'RW', 'D', 'G'], true))
            ->values()
            ->all();
    }





    private function teamOptionsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        if ($table === 'stats') {
            return (clone $base)
                ->reorder()
                ->select('nhl_team_abbrev')
                ->whereNotNull('nhl_team_abbrev')
                ->distinct()
                ->pluck('nhl_team_abbrev')
                ->filter()->values()->all();
        }

        return (clone $base)
            ->reorder()
            ->select('pf.team_abbrev')
            ->whereNotNull('pf.team_abbrev')
            ->distinct()
            ->pluck('pf.team_abbrev')
            ->filter()->values()->all();
    }

    private function leagueOptionsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        if ($table !== 'stats') {
            return [];
        }

        return (clone $base)
            ->reorder()
            ->select('league_abbrev')
            ->whereNotNull('league_abbrev')
            ->distinct()
            ->pluck('league_abbrev')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    private function positionOptionsForBase($base): array
    {
        $table = $base->getModel()->getTable();

        if ($table === 'stats') {
            return (clone $base)
                ->reorder()
                ->join('players as ppos', 'ppos.id', '=', 'stats.player_id')
                ->select('ppos.position')
                ->whereNotNull('ppos.position')
                ->distinct()
                ->pluck('ppos.position')
                ->filter()->values()->all();
        }

        return (clone $base)
            ->reorder()
            ->select('pf.position')
            ->whereNotNull('pf.position')
            ->distinct()
            ->pluck('pf.position')
            ->filter()->values()->all();
    }


    /**
     * Return min/max for a numeric column on the active base query.
     * MySQL/Postgres safe. Handles TOI name differences by trying
     * multiple candidate columns and swallowing "unknown column" errors.
     */
    private function bounds($base, string $key): array
    {
        $table = $base->getModel()->getTable();
        $col = $this->mapFilterColumn($base, $key);
        if (!$col) return ['min' => 0, 'max' => 0];

        $qb = (clone $base)->reorder();

        try {
            $minVal = (clone $qb)->min($col);
            $maxVal = (clone $qb)->max($col);
        } catch (\Illuminate\Database\QueryException $e) {
            // MySQL 42S22 / Postgres 42703 => unknown column / invalid identifier
            \Log::error('bounds() failed', [
                'table'    => $table,
                'key'      => $key,
                'col'      => $col,
                'sql'      => (clone $qb)->toSql(),
                'bindings' => (clone $qb)->getBindings(),
                'code'     => $e->getCode(),
                'message'  => $e->getMessage(),
            ]);

            if ($e->getCode() === '42S22' || str_contains($e->getMessage(), 'Unknown column') ||
                str_contains($e->getMessage(), '42703')) {
                return ['min' => 0, 'max' => 0];
            }
            throw $e;
        }

        $min = (float) ($minVal ?? 0);
        $max = (float) ($maxVal ?? 0);
        if ($min > $max) { [$min, $max] = [$max, $min]; }

        return ['min' => (int) floor($min), 'max' => (int) ceil($max)];
    }




    /** │ Age bounds: portable */
    private function ageBoundsForBase($base): array
    {
        $qb = (clone $base)
            ->reorder()
            ->whereNotNull('pf.dob');

        $earliestDob = $qb->clone()->min('pf.dob');
        $latestDob   = $qb->clone()->max('pf.dob');

        if (!$earliestDob && !$latestDob) return ['min' => 16, 'max' => 45];

        $minAge = $latestDob ? Carbon::parse($latestDob)->age : null;
        $maxAge = $earliestDob ? Carbon::parse($earliestDob)->age : null;

        if ($minAge === null && $maxAge !== null) $minAge = $maxAge;
        if ($maxAge === null && $minAge !== null) $maxAge = $minAge;
        if ($minAge === null || $maxAge === null) return ['min' => 16, 'max' => 45];
        if ($minAge > $maxAge) [$minAge, $maxAge] = [$maxAge, $minAge];

        return ['min' => (int)$minAge, 'max' => (int)$maxAge];
    }

    /** ───────────── Column mapping that tolerates MySQL/PG view drift ───────────── */
    private function mapFirstExisting(string $table, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            try {
                if (Schema::hasColumn($table, $c)) {
                    return "{$table}.{$c}";
                }
            } catch (\Throwable $e) {
                // On some drivers/views hasColumn can be finicky; a try/catch keeps us safe
            }
        }
        return null;
    }



    private function mapFilterColumn($base, string $key): ?string
    {
        $table = $base->getModel()->getTable();

        // direct aliases for rate fields that actually exist in your schema
        $aliases = [
            'nhl_season_stats' => [
                'g_per_gp' => 'g_pg',  'a_per_gp' => 'a_pg',  'pts_per_gp' => 'pts_pg',
                'b_per_gp' => 'b_pg',  'h_per_gp' => 'h_pg',  'th_per_gp'  => 'th_pg',
                'g_per_60' => 'g_p60', 'a_per_60' => 'a_p60', 'pts_per_60' => 'pts_p60',
                'sog_per_60' => 'sog_p60', 'sat_per_60' => 'sat_p60',
                'hits_per_60' => 'hits_p60', 'blocks_per_60' => 'blocks_p60',
            ],
        ];

        if (isset($aliases[$table][$key])) {
            return $table . '.' . $aliases[$table][$key];
        }

        // keys that vary by install; pick the first column that actually exists
        $variants = [
            'toi' => [
                // your MySQL migration has "toi" (seconds). Some installs might use
                // toi_seconds or toi_minutes, so try in a safe order.
                'nhl_season_stats'   => ['toi', 'toi_seconds', 'toi_minutes'],
                'nhl_game_summaries' => ['toi_seconds', 'toi', 'toi_minutes'],
                'stats'              => ['toi'],
            ],
        ];

        if (isset($variants[$key][$table])) {
            foreach ($variants[$key][$table] as $cand) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($table, $cand)) {
                    return $table . '.' . $cand;
                }
            }
            // no physical column → skip building a filter for this key
            return null;
        }

        // generic fallback only if the column exists
        return \Illuminate\Support\Facades\Schema::hasColumn($table, $key)
            ? $table . '.' . $key
            : null;
    }




    private function playerAge($player): ?int
    {
        if (!$player) return null;
        if (method_exists($player, 'age')) return $player->age();
        if (!empty($player->dob)) return Carbon::parse($player->dob)->age;
        return null;
    }


    private function getStatValue(object $st, string $key, string $slice)
    {
        $total = fn() => (float) ($st->{$key} ?? 0);

        if ($slice === 'pgp') {
            $mapped = $this->rateMaps()['pg'][$key] ?? null;
            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1);
            }
            $gp = (int) ($st->gp ?? 0);
            return $gp > 0 ? round($total() / $gp, 1) : 0.0;
        }

        if ($slice === 'p60') {
            $mapped = $this->rateMaps()['p60'][$key] ?? null;
            if ($mapped && isset($st->{$mapped}) && is_numeric($st->{$mapped})) {
                return round((float) $st->{$mapped}, 1);
            }

            // TOI minutes regardless of the backing column name
            $toiMin = 0.0;
            if (isset($st->toi_seconds) && is_numeric($st->toi_seconds)) {
                $toiMin = (float)$st->toi_seconds / 60.0;
            } elseif (isset($st->toi) && is_numeric($st->toi)) {
                // your schema: seconds
                $toiMin = (float)$st->toi / 60.0;
            } elseif (isset($st->toi_minutes) && is_numeric($st->toi_minutes)) {
                $toiMin = (float)$st->toi_minutes; // already minutes
            }

            return $toiMin > 0 ? round($total() / ($toiMin / 60.0), 1) : 0.0;
        }

        return $st->{$key} ?? 0;
    }




    private function deriveFromTotals(string $key, float|int $total, string $slice, int $gpSum, float $toiSec)
    {
        if ($slice === 'pgp') return $gpSum > 0 ? round($total / $gpSum, 3) : 0;
        if ($slice === 'p60') return $toiSec > 0 ? round($total / ($toiSec / 3600), 3) : 0;
        return $total;
    }

    private function rateMaps(): array
    {
        return [
            'pg'  => [
                'g'   => 'g_pg',
                'a'   => 'a_pg',
                'pts' => 'pts_pg',
                'pim' => 'pim_pg',
                'sog' => 'sog_pg',
                'ppp' => 'ppp_pg',
            ],
            'p60' => [
                'g'   => 'g_p60',
                'a'   => 'a_p60',
                'pts' => 'pts_p60',
                'sog' => 'sog_p60',
                'sat' => 'sat_p60',
                'h'   => 'hits_p60',
                'b'   => 'blocks_p60',
                'pim' => 'pim_p60',
                'ppp' => 'ppp_p60',
            ],
        ];
    }

    private function mergeHeadings(array $identity, array $columns): array
    {
        $seen = [];
        $out  = [];

        foreach (array_merge($identity, $columns) as $col) {
            $key = $col['key'] ?? null;
            if (!$key || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['key' => $key, 'label' => $col['label'] ?? strtoupper($key)];
        }

        return $out;
    }
}
