<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\DraftQueueItem;
use App\Models\Perspective;
use App\Models\Player;
use App\Models\PlatformLeague;
use App\Services\FantasyLeagueAccess;
use App\Support\FantraxViewerScope;
use App\Services\PlatformLeaguePlayerStatService;
use App\Support\Stats\LeagueStatsOwnershipHydrator;
use App\Support\Stats\LeagueStatsPerspectiveFactory;
use App\Support\Stats\LeagueStatsPlayerUniverseFilter;
use App\Support\Stats\NhleProspectLens;
use App\Support\Stats\RangeStatsPayloadRequest;
use App\Support\Stats\SeasonStatsPayloadRequest;
use App\Support\Stats\StatsFilterSet;
use App\Support\Stats\StatsPayloadBuilder;
use App\Support\Stats\StatsQueryContext;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;


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
        $logStatsTiming = (bool) config('services.stats_timing.log', false);
        $profileStart = $logStatsTiming ? hrtime(true) : null;
        $profileLast = $profileStart;
        $timings = [];
        $mark = static function (string $key) use ($logStatsTiming, &$profileLast, &$timings): void {
            if (! $logStatsTiming || $profileLast === null) {
                return;
            }

            $now = hrtime(true);
            $timings[$key] = round(($now - $profileLast) / 1_000_000, 2);
            $profileLast = $now;
        };

        $request->validate([
            'perspectiveId' => 'nullable|integer|exists:perspectives,id',
            'perspective'   => 'nullable|string',
            'season'        => 'nullable|string',
            'season_id'     => 'nullable|string',
            'slice'         => 'nullable|in:total,pgp,p60',
            'resource'      => 'nullable|in:players,units,teams',
            'period'        => 'nullable|in:season,range,lastWeek,thisWeek,past30days',
            'game_type'     => 'nullable|in:1,2,3',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'availability'  => 'nullable|integer',
            'draft_context' => 'nullable|boolean',
            'entry_draft_year' => 'nullable|integer',
            'entry_draft_year_min' => 'nullable|integer',
            'entry_draft_year_max' => 'nullable|integer',
            'column_group'  => 'nullable|in:goalie',
            'nhle'          => 'nullable|boolean',
        ]);
        $mark('validate_ms');

        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before loading league stats.',
            ], 409);
        }
        $mark('access_ms');

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();
        $viewerFantraxScope = app(FantraxViewerScope::class)->resolve($league, $user);
        $mark('league_lookup_ms');

        $leaguePerspectiveFactory = app(LeagueStatsPerspectiveFactory::class);
        $syntheticDraftPerspective = $this->applySyntheticDraftPerspectiveRequest($request, $leaguePerspectiveFactory);
        $leaguePerspectiveSlug = $leaguePerspectiveFactory->leagueScoringPerspectiveSlug($league);
        $fantraxPerspectiveSlug = $leaguePerspectiveFactory->fantraxLeaguePerspectiveSlug($league);
        $defaultPerspectiveSlug = $leaguePerspectiveFactory->defaultPerspectiveSlug(
            $user,
            $league,
            fn ($user): Perspective => $this->defaultSavedStatsPerspective($user),
        );
        $context = StatsQueryContext::fromRequest($request, $league, null, $defaultPerspectiveSlug);
        $filterSet = StatsFilterSet::fromRequest($request);
        $season = $context->season;
        $slice = $context->slice;
        $gameType = $context->gameType;
        $resource = (string) $request->query('resource', 'players');
        $period = $context->period;
        $requestedColumnGroup = (string) ($context->columnGroup ?? '');
        $requestedPerspective = $context->requestedPerspective;
        $queuePlayerIds = $syntheticDraftPerspective === 'my-queue'
            ? $this->draftQueuePlayerIdsForLeague($league, (int) $user->id)
            : null;
        $nhlCurrentOrLastSeasonPlayerIds = $syntheticDraftPerspective === 'nhl-current-or-last-season'
            ? $this->nhlCurrentOrLastSeasonPlayerIds()
            : null;
        $leagueReportPerspective = $this->leagueReportPerspectiveSlug($requestedPerspective);
        $leagueReportUnderlyingPerspective = $leagueReportPerspective !== null
            ? $this->leagueReportUnderlyingPerspectiveSlug($leagueReportPerspective, $requestedColumnGroup)
            : null;
        $isLeagueScoringPerspective = ! $request->filled('perspectiveId')
            && $leaguePerspectiveSlug !== null
            && $leagueReportUnderlyingPerspective === null
            && ($requestedPerspective === '' || $requestedPerspective === $leaguePerspectiveSlug);
        $isFantraxLeaguePerspective = ! $request->filled('perspectiveId')
            && $fantraxPerspectiveSlug !== null
            && $leagueReportUnderlyingPerspective === null
            && ($requestedPerspective === '' || $requestedPerspective === $fantraxPerspectiveSlug);
        $mark('request_context_ms');

        if ($syntheticDraftPerspective === 'nhl-current-or-last-season') {
            $basePerspective = $this->defaultSavedStatsPerspective($user);
            $settings = is_array($basePerspective->settings)
                ? $basePerspective->settings
                : (json_decode($basePerspective->settings ?? '[]', true) ?: []);
            $settings['filters'] = is_array($settings['filters'] ?? null) ? $settings['filters'] : [];
            unset($settings['filters']['is_goalie'], $settings['filters']['pos'], $settings['filters']['pos_type']);

            $perspective = (object) [
                'slug' => 'nhl-current-or-last-season',
                'name' => 'NHL Current/Last Season',
                'is_slicable' => (bool) ($basePerspective->is_slicable ?? true),
            ];
            $canSlice = (bool) ($basePerspective->is_slicable ?? true);
        } elseif ($leagueReportUnderlyingPerspective !== null) {
            $perspective = $this->resolveStatsPerspective($request, $user, $leagueReportUnderlyingPerspective);
            $settings = is_array($perspective->settings)
                ? $perspective->settings
                : (json_decode($perspective->settings ?? '[]', true) ?: []);
            $canSlice = (bool) ($perspective->is_slicable ?? true);

            if ($this->isProspectsPerspective($perspective, $settings['filters'] ?? [])) {
                $period = 'season';
            }
        } elseif ($isLeagueScoringPerspective) {
            $settings = $leaguePerspectiveFactory->leagueScoringPerspectiveSettings($league);

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
            $settings = $leaguePerspectiveFactory->leagueScoringPerspectiveSettings($league);
            $basePerspective = null;

            if ($settings === null) {
                $basePerspective = $this->defaultSavedStatsPerspective($user);
                $settings = is_array($basePerspective->settings)
                    ? $basePerspective->settings
                    : (json_decode($basePerspective->settings ?? '[]', true) ?: []);
            }
            if ($requestedColumnGroup === 'goalie') {
                $settings = $leaguePerspectiveFactory->withFantraxGoalieSettings($settings, $league);
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
        $mark('perspective_settings_ms');

        $buildTimings = [];
        if ($period === 'season') {
            [$payload, , , $buildTimings] = $this->buildAndFormatPlayersPayloadFromSettings(
                $user,
                $perspective,
                $settings,
                $canSlice,
                is_string($season) ? $season : null,
                $canSlice && in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
                in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
                $request,
                $filterSet,
            );
        } else {
            [$fromDate, $toDate] = $this->resolveDates($period, $request->query('from'), $request->query('to'));

            [$payload, , , $buildTimings] = $this->buildAndFormatPlayersPayloadRangeFromSettings(
                $user,
                $settings,
                $canSlice,
                $canSlice && in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
                in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
                $fromDate,
                $toDate,
                $request,
                $filterSet,
            );
        }
        $mark('stats_payload_build_ms');

        $payload['settings'] = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $payload['settings']['ownerColumn'] = true;
        $payload['settings']['leaguePlatform'] = (string) ($league->platform ?? '');
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        if ($leagueReportPerspective !== null) {
            $payload['meta']['positionButtons'] = ['F', 'C', 'LW', 'RW', 'D', 'G'];
        }
        $selectedPerspectiveSlug = strtolower((string) ($perspective->slug ?? $perspective->name ?? ''));
        if (in_array($selectedPerspectiveSlug, ['prospects', 'prospects-goalies'], true)) {
            $payload['meta']['leagueProspectMode'] = $selectedPerspectiveSlug === 'prospects-goalies'
                ? 'goalies'
                : 'skaters';
        }
        if ($isFantraxLeaguePerspective) {
            $payload = $leaguePerspectiveFactory->withFantraxGoalieColumnGroup($payload, $league);
            $payload = $leaguePerspectiveFactory->withActiveFantraxColumnGroupPayload(
                $payload,
                $league,
                $requestedColumnGroup !== '' ? $requestedColumnGroup : null,
            );
        }
        $mark('payload_settings_ms');

        if (! in_array($syntheticDraftPerspective, [
            'entry-draft',
            'entry-draft-goalies',
            'my-queue',
            'nhl-current-or-last-season',
        ], true)) {
            $payload = app(LeagueStatsPlayerUniverseFilter::class)->filter($payload, $league);
        }
        $mark('platform_filter_ms');

        $ownershipTimings = $logStatsTiming ? [] : null;
        $payload = app(LeagueStatsOwnershipHydrator::class)->hydrate(
            $payload,
            $league,
            $user?->id,
            $ownershipTimings,
            $viewerFantraxScope,
        );
        $payload = $this->dedupeLeagueProspectRows($payload);
        $payload = app(NhleProspectLens::class)->apply($payload, $request->boolean('nhle'));
        $mark('ownership_ms');

        if ($isFantraxLeaguePerspective || $isLeagueScoringPerspective) {
            $payload = app(PlatformLeaguePlayerStatService::class)->overlayStatsPayload(
                $payload,
                $league,
                is_string($season) ? $season : null,
            );
        }
        if (is_array($queuePlayerIds)) {
            $payload = $this->filterLeaguePayloadToPlayerIds($payload, $queuePlayerIds);
        }
        if (is_array($nhlCurrentOrLastSeasonPlayerIds)) {
            $payload = $this->filterLeaguePayloadToPlayerIds(
                $payload,
                $nhlCurrentOrLastSeasonPlayerIds,
                'nhl_current_or_last_season_player_ids',
                true,
            );
        }
        $mark('provider_stats_overlay_ms');

        $payload['connectedLeagues'] = $this->connectedLeaguesForUser($user);
        $payload['perspectives'] = $leaguePerspectiveFactory->perspectives($user, $league);
        $payload['selectedPerspective'] = $syntheticDraftPerspective
            ?? $leagueReportPerspective
            ?? $perspective->slug
            ?? $perspective->name
            ?? $defaultPerspectiveSlug;
        $mark('metadata_ms');

        if ($isLeagueScoringPerspective || $isFantraxLeaguePerspective) {
            $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $payload['meta']['positionButtons'] = ['F', 'C', 'LW', 'RW', 'D', 'G'];
            if ($requestedColumnGroup === 'goalie') {
                $payload['meta']['pos'] = ['G'];
                $payload['meta']['pos_type'] = ['G'];
            }
        }
        $mark('meta_filters_ms');

        if ($resource === 'teams') {
            $payload = $this->teamAggregateLeaguePayload($payload, $request->boolean('starters'));
        }
        $mark('resource_transform_ms');

        if ($logStatsTiming && $profileStart !== null) {
            $totalMs = round((hrtime(true) - $profileStart) / 1_000_000, 2);
            $responseBytes = strlen((string) json_encode($payload));

            \Log::warning('league_stats_payload_timing', [
                'league_id' => (string) $league->id,
                'platform' => (string) ($league->platform ?? ''),
                'user_id' => $user?->id,
                'perspective' => $payload['selectedPerspective'] ?? null,
                'period' => $period,
                'season' => is_string($season) ? $season : null,
                'game_type' => $gameType,
                'slice' => $slice,
                'column_group' => $requestedColumnGroup !== '' ? $requestedColumnGroup : null,
                'row_count' => is_countable($payload['data'] ?? null) ? count($payload['data']) : null,
                'heading_count' => is_countable($payload['headings'] ?? null) ? count($payload['headings']) : null,
                'response_bytes' => $responseBytes,
                'total_ms' => $totalMs,
                'timings' => $timings,
                'build_timings' => $buildTimings,
                'ownership_timings' => $ownershipTimings,
            ]);
        }

        return response()->json($payload);
    }

    /**
     * Return a stats payload for community league management draft views.
     */
    public function communityLeaguePayload(Request $request, int $cId, int $lId)
    {
        $user = $request->user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with('leagues')
            ->findOrFail($cId);

        $league = $community->leagues()->findOrFail($lId);
        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();

        abort_unless($platformLeague instanceof PlatformLeague, 404);

        $leaguePerspectiveFactory = app(LeagueStatsPerspectiveFactory::class);
        $syntheticDraftPerspective = $this->applySyntheticDraftPerspectiveRequest($request, $leaguePerspectiveFactory);
        $request->query->set('availability', (string) $platformLeague->id);

        if ($syntheticDraftPerspective === 'nhl-current-or-last-season') {
            $basePerspective = null;
            $settings = $leaguePerspectiveFactory->leagueScoringPerspectiveSettings($platformLeague);

            if ($settings === null) {
                $basePerspective = $this->defaultSavedStatsPerspective($user);
                $settings = is_array($basePerspective->settings)
                    ? $basePerspective->settings
                    : (json_decode($basePerspective->settings ?? '[]', true) ?: []);
            }
            $settings['filters'] = is_array($settings['filters'] ?? null) ? $settings['filters'] : [];
            unset($settings['filters']['is_goalie'], $settings['filters']['pos'], $settings['filters']['pos_type']);

            $season = $request->input('season_id', $request->input('season'));
            $slice = (string) $request->input('slice', 'total');
            $gameType = (int) $request->input('game_type', 2);

            [$payload] = $this->buildAndFormatPlayersPayloadFromSettings(
                $user,
                (object) [
                    'slug' => 'nhl-current-or-last-season',
                    'name' => 'NHL Current/Last Season',
                    'is_slicable' => (bool) ($basePerspective?->is_slicable ?? true),
                ],
                $settings,
                (bool) ($basePerspective?->is_slicable ?? true),
                is_string($season) ? $season : null,
                in_array($slice, ['total', 'pgp', 'p60'], true) ? $slice : 'total',
                in_array($gameType, [1, 2, 3], true) ? $gameType : 2,
                $request,
                StatsFilterSet::fromRequest($request),
            );

            $payload = app(PlatformLeaguePlayerStatService::class)->overlayStatsPayload(
                $payload,
                $platformLeague,
                is_string($season) ? $season : null,
            );
            $payload = $this->filterLeaguePayloadToPlayerIds(
                $payload,
                $this->withoutFantasyRosteredPlayers(
                    $this->nhlCurrentOrLastSeasonPlayerIds(),
                    $platformLeague,
                ),
                'nhl_current_or_last_season_player_ids',
                true,
            );
            $payload['selectedPerspective'] = 'nhl-current-or-last-season';

            return response()->json($payload);
        }

        return $this->payload($request);
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
            'resource'      => 'nullable|in:players,units,teams',
            'period'        => 'nullable|in:season,range,lastWeek,thisWeek,past30days',
            'game_type'     => 'nullable|in:1,2,3',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'availability'  => 'nullable|integer',
            'draft_context' => 'nullable|boolean',
            'entry_draft_year' => 'nullable|integer',
            'entry_draft_year_min' => 'nullable|integer',
            'entry_draft_year_max' => 'nullable|integer',
            'nhle'          => 'nullable|boolean',
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
            $payload = app(NhleProspectLens::class)->apply($payload, $request->boolean('nhle'));

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
        $payload = app(NhleProspectLens::class)->apply($payload, $request->boolean('nhle'));

        \Log::info('updated payload: ', ['payload' => $payload]);

        return response()->json($payload);
    }

    /** ─────────────── Build + format PLAYERS payload (SEASON) ─────────────── */
    private function buildAndFormatPlayersPayload(
        $user,
        int $perspectiveId,
        ?string $seasonFilter,
        string $slice = 'total',
        ?int $gameType = 2,
        ?Request $request = null,
        ?StatsFilterSet $filterSet = null
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
            $filterSet,
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
        ?Request $request = null,
        ?StatsFilterSet $filterSet = null
    ): array {
        return app(StatsPayloadBuilder::class)->buildSeasonPayload(new SeasonStatsPayloadRequest(
            $user,
            $perspective,
            $settings,
            $canSlice,
            $seasonFilter,
            $slice,
            $gameType,
            $request,
            $filterSet ?? StatsFilterSet::fromRequest($request),
        ));
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
                StatsFilterSet::fromRequest($request),
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
        Request $request,
        ?StatsFilterSet $filterSet = null
    ): array {
        return app(StatsPayloadBuilder::class)->buildRangePayload(new RangeStatsPayloadRequest(
            $user,
            $settings,
            $canSlice,
            $slice,
            $gameType,
            $from,
            $to,
            $request,
            $filterSet ?? StatsFilterSet::fromRequest($request),
        ));
    }

    /** ───────────────────────────── Small helpers ───────────────────────────── */
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

    private function leagueReportPerspectiveSlug(string $requestedPerspective): ?string
    {
        $slug = strtolower(trim($requestedPerspective));

        return in_array($slug, ['basic', 'advanced', 'prospects'], true) ? $slug : null;
    }

    private function leagueReportUnderlyingPerspectiveSlug(string $reportPerspective, string $columnGroup): string
    {
        $isGoalie = $columnGroup === 'goalie';

        return match ($reportPerspective) {
            'advanced' => $isGoalie ? 'goalies-adv' : 'skaters-adv',
            'prospects' => $isGoalie ? 'prospects-goalies' : 'prospects',
            default => $isGoalie ? 'goalies' : 'skaters',
        };
    }

    /**
     * Translate league-only draft perspective slugs into the existing stats payload request contract.
     */
    private function applySyntheticDraftPerspectiveRequest(
        Request $request,
        LeagueStatsPerspectiveFactory $perspectiveFactory
    ): ?string {
        $requestedPerspective = strtolower(trim((string) $request->query('perspective', '')));

        if (! in_array($requestedPerspective, [
            'entry-draft',
            'entry-draft-goalies',
            'my-queue',
            'nhl-current-or-last-season',
        ], true)) {
            return null;
        }

        $request->query->set('period', 'season');
        $request->query->set('availability', '0');

        if ($requestedPerspective !== 'nhl-current-or-last-season') {
            $request->query->set('draft_context', '1');
        }

        if ($requestedPerspective === 'entry-draft') {
            $request->query->set('perspective', 'prospects');
        }

        if ($requestedPerspective === 'entry-draft-goalies') {
            $request->query->set('perspective', 'prospects-goalies');
        }

        if ($requestedPerspective === 'my-queue') {
            $request->query->set('perspective', 'prospects');
        }

        $latestEntryDraftYear = $perspectiveFactory->latestEntryDraftYear();
        if (in_array($requestedPerspective, ['entry-draft', 'entry-draft-goalies'], true) && $latestEntryDraftYear) {
            $request->query->set('entry_draft_year', (string) $latestEntryDraftYear);
        }

        return $requestedPerspective;
    }

    /**
     * Return players currently in the NHL or represented in the newest completed NHL regular season.
     *
     * @return array<int,int>
     */
    private function nhlCurrentOrLastSeasonPlayerIds(): array
    {
        $latestSeason = DB::table('nhl_season_stats')
            ->where('game_type', 2)
            ->max('season_id');

        $currentNhlPlayerIds = DB::table('players')
            ->where('current_league_abbrev', 'NHL')
            ->pluck('id')
            ->map(static fn (mixed $playerId): int => (int) $playerId);

        $lastSeasonPlayerIds = $latestSeason
            ? DB::table('nhl_season_stats as nss')
                ->join('players as p', 'p.nhl_id', '=', 'nss.nhl_player_id')
                ->where('nss.season_id', (string) $latestSeason)
                ->where('nss.game_type', 2)
                ->pluck('p.id')
                ->map(static fn (mixed $playerId): int => (int) $playerId)
            : collect();

        return $currentNhlPlayerIds
            ->concat($lastSeasonPlayerIds)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Remove players already on an active fantasy roster in the selected league.
     *
     * @param array<int,int> $playerIds
     * @return array<int,int>
     */
    private function withoutFantasyRosteredPlayers(array $playerIds, PlatformLeague $league): array
    {
        if ($playerIds === []) {
            return [];
        }

        $rosteredPlayerIds = DB::table('platform_roster_memberships as prm')
            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
            ->where('pt.platform_league_id', $league->id)
            ->whereNull('prm.ends_at')
            ->whereIn('prm.player_id', $playerIds)
            ->pluck('prm.player_id')
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->all();

        if ($rosteredPlayerIds === []) {
            return $playerIds;
        }

        $rosteredLookup = array_flip($rosteredPlayerIds);

        return collect($playerIds)
            ->filter(static fn (int $playerId): bool => ! isset($rosteredLookup[$playerId]))
            ->values()
            ->all();
    }

    /**
     * Return queued player ids for the user's latest league draft, excluding drafted players.
     *
     * @return array<int,int>
     */
    private function draftQueuePlayerIdsForLeague(PlatformLeague $league, int $userId): array
    {
        $draft = $this->latestDraftForLeague($league);

        if (! $draft instanceof Draft) {
            return [];
        }

        $draftedPlayerIds = DraftPick::query()
            ->where('draft_id', $draft->id)
            ->whereNotNull('player_id')
            ->pluck('player_id')
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->all();

        return DraftQueueItem::query()
            ->where('draft_id', $draft->id)
            ->where('user_id', $userId)
            ->when($draftedPlayerIds !== [], static function ($query) use ($draftedPlayerIds): void {
                $query->whereNotIn('player_id', $draftedPlayerIds);
            })
            ->orderBy('rank')
            ->pluck('player_id')
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->values()
            ->all();
    }

    private function latestDraftForLeague(PlatformLeague $league): ?Draft
    {
        return $league->drafts()
            ->where('source_type', 'platform_mirror')
            ->latest('updated_at')
            ->first()
            ?? $league->drafts()
                ->latest('updated_at')
                ->first();
    }

    /**
     * Keep only stats rows matching a set of player ids.
     *
     * @param array<string,mixed> $payload
     * @param array<int,int> $playerIds
     * @return array<string,mixed>
     */
    private function filterLeaguePayloadToPlayerIds(
        array $payload,
        array $playerIds,
        string $metaKey = 'queue_player_ids',
        bool $appendMissingRows = false,
    ): array
    {
        $lookup = array_flip($playerIds);
        $payload['data'] = collect($payload['data'] ?? [])
            ->filter(static function (mixed $row) use ($lookup): bool {
                $playerId = (int) data_get($row, 'player_id', data_get($row, 'id', 0));

                return $playerId > 0 && isset($lookup[$playerId]);
            })
            ->values()
            ->all();

        if ($appendMissingRows) {
            $existingPlayerIds = collect($payload['data'])
                ->map(static fn (mixed $row): int => (int) data_get($row, 'player_id', data_get($row, 'id', 0)))
                ->filter(static fn (int $playerId): bool => $playerId > 0)
                ->all();
            $missingPlayerIds = array_values(array_diff($playerIds, $existingPlayerIds));

            if ($missingPlayerIds !== []) {
                $payload['data'] = collect($payload['data'])
                    ->concat($this->emptyStatsRowsForPlayers($missingPlayerIds, $payload['headings'] ?? []))
                    ->values()
                    ->all();
            }
        }

        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta'][$metaKey] = $playerIds;

        return $payload;
    }

    /**
     * Build zero-stat rows for players that belong in a synthetic player universe but lack selected-season stats.
     *
     * @param array<int,int> $playerIds
     * @param array<int,array<string,mixed>> $headings
     * @return array<int,array<string,mixed>>
     */
    private function emptyStatsRowsForPlayers(array $playerIds, array $headings): array
    {
        $sortOrder = array_flip($playerIds);

        return Player::query()
            ->whereIn('id', $playerIds)
            ->get()
            ->sortBy(static fn (Player $player): int => (int) ($sortOrder[(int) $player->id] ?? PHP_INT_MAX))
            ->map(function (Player $player) use ($headings): array {
                $row = [
                    'name' => (string) ($player->full_name ?? trim(($player->first_name ?? '') . ' ' . ($player->last_name ?? ''))),
                    'player_id' => (int) $player->id,
                    'avatar_url' => $player->head_shot_url,
                    'age' => $player->age(),
                    'team' => $player->team_abbrev,
                    'league' => null,
                    'pos' => (bool) $player->is_goalie ? 'G' : $player->position,
                    'pos_type' => (bool) $player->is_goalie ? 'G' : $player->pos_type,
                    'is_goalie' => (bool) $player->is_goalie,
                    'contract_value' => '$0.00m',
                    'contract_value_num' => 0,
                    'contract_last_year' => null,
                    'contract_last_year_num' => null,
                    'drafted_overall_pick' => $player->draft_oa,
                    'drafted_year' => $player->draft_year,
                    'drafted_label' => null,
                    'gp' => 0,
                    'nhl_player_id' => $player->nhl_id,
                    'toi_seconds' => 0,
                    'toi' => '0:00',
                ];

                foreach ($headings as $heading) {
                    $key = trim((string) ($heading['key'] ?? ''));

                    if ($key !== '' && ! array_key_exists($key, $row)) {
                        $row[$key] = 0;
                    }
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * League prospect payloads can contain multiple legacy stat rows for one player.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function dedupeLeagueProspectRows(array $payload): array
    {
        if (! in_array((string) data_get($payload, 'meta.leagueProspectMode', ''), ['skaters', 'goalies'], true)) {
            return $payload;
        }

        $bestRows = [];
        $passthroughRows = [];

        foreach (($payload['data'] ?? []) as $row) {
            if (! is_array($row)) {
                $passthroughRows[] = $row;
                continue;
            }

            if ((bool) ($row['league_roster_placeholder'] ?? false)) {
                $passthroughRows[] = $row;
                continue;
            }

            $key = $this->leagueStatsRowIdentityKey($row);

            if ($key === null) {
                $passthroughRows[] = $row;
                continue;
            }

            if (
                ! isset($bestRows[$key]) ||
                $this->leagueStatsRowScore($row) > $this->leagueStatsRowScore($bestRows[$key])
            ) {
                $bestRows[$key] = $row;
            }
        }

        $payload['data'] = array_values(array_merge($bestRows, $passthroughRows));

        return $payload;
    }

    /**
     * Convert league player rows into fantasy-team aggregate rows.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function teamAggregateLeaguePayload(array $payload, bool $startersOnly = false): array
    {
        $baseHeadings = collect($payload['headings'] ?? [])
            ->filter(fn (mixed $heading): bool => is_array($heading))
            ->map(function (array $heading): array {
                $key = strtolower((string) ($heading['key'] ?? ''));

                if (in_array($key, ['aav', 'cap_hit', 'contract_value', 'contract_value_num'], true)) {
                    $heading['label'] = 'Cap';
                }

                if (in_array($key, ['name', 'player'], true)) {
                    $heading['label'] = 'Team';
                }

                return $heading;
            })
            ->values();

        $columnGroups = data_get($payload, 'settings.columnGroups', []);
        $groupHeadings = collect([
            ...(is_array($columnGroups['skater'] ?? null) ? $columnGroups['skater'] : []),
            ...(is_array($columnGroups['goalie'] ?? null) ? $columnGroups['goalie'] : []),
        ])->filter(fn (mixed $heading): bool => is_array($heading));

        $headings = $baseHeadings
            ->merge($groupHeadings)
            ->unique(static fn (array $heading): string => strtolower((string) ($heading['key'] ?? '')))
            ->filter(function (array $heading): bool {
                $key = strtolower((string) ($heading['key'] ?? ''));

                return ! in_array($key, [
                    'team',
                    'league',
                    'pos',
                    'pos_type',
                    'type',
                    'age',
                    'contract_last_year',
                    'contract_last_year_num',
                    'contract_type',
                    'roster_slot',
                    'slot',
                ], true);
            })
            ->values();

        $statHeadings = $headings
            ->filter(function (array $heading): bool {
                $key = strtolower((string) ($heading['key'] ?? ''));

                return ! in_array($key, ['__rk', 'name', 'player'], true);
            })
            ->values();

        $teams = [];
        foreach (($payload['data'] ?? []) as $row) {
            if (! is_array($row) || (bool) ($row['league_roster_placeholder'] ?? false)) {
                continue;
            }

            if (strtolower((string) ($row['roster_group'] ?? '')) === 'minor') {
                continue;
            }

            if (
                $startersOnly
                && (
                    strtolower((string) ($row['roster_group'] ?? '')) !== 'active'
                    || strtolower((string) ($row['roster_status'] ?? '')) !== 'active'
                )
            ) {
                continue;
            }

            $teamId = trim((string) ($row['fantasy_team_id'] ?? ''));
            $teamName = trim((string) ($row['fantasy_team_name'] ?? ''));
            if ($teamId === '' || $teamName === '') {
                continue;
            }

            $groupKey = $teamId !== '' ? $teamId : $teamName;
            $teams[$groupKey] ??= [
                'rows' => [],
                'name' => $teamName,
                'fantasy_team_id' => $teamId,
                'fantasy_team_name' => $teamName,
                'fantasy_team_avatar_url' => (string) ($row['fantasy_team_avatar_url'] ?? ''),
                'fantasy_team_is_user_team' => (bool) ($row['fantasy_team_is_user_team'] ?? false),
                'league' => (string) ($row['league'] ?? ''),
            ];
            $teams[$groupKey]['rows'][] = $row;
        }

        $teamRows = [];
        foreach ($teams as $team) {
            $rows = $team['rows'];
            $teamRow = [
                'name' => $team['name'],
                'avatar_url' => $team['fantasy_team_avatar_url'],
                'fantasy_team_id' => $team['fantasy_team_id'],
                'fantasy_team_name' => $team['fantasy_team_name'],
                'fantasy_team_avatar_url' => $team['fantasy_team_avatar_url'],
                'fantasy_team_is_user_team' => $team['fantasy_team_is_user_team'],
                'league' => $team['league'],
                'player_count' => count($rows),
                'league_team_aggregate' => true,
                '__team_average' => [],
                'stats' => [],
            ];

            foreach ($statHeadings as $heading) {
                $key = (string) ($heading['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $values = collect($rows)
                    ->map(fn (array $row): mixed => $row[$key] ?? data_get($row, "stats.$key"))
                    ->filter(fn (mixed $value): bool => is_numeric($value))
                    ->map(fn (mixed $value): float => (float) $value)
                    ->values();
                $sum = round((float) $values->sum(), 3);
                $average = $values->isNotEmpty() ? round($sum / $values->count(), 2) : 0.0;
                $value = $this->isTeamAverageOnlyStat((string) $key, (string) ($heading['label'] ?? ''))
                    ? $average
                    : $sum;

                $teamRow[$key] = $value;
                $teamRow['stats'][$key] = $value;
                $teamRow['__team_average'][$key] = $average;
            }

            $teamRows[] = $teamRow;
        }

        usort($teamRows, static function (array $a, array $b): int {
            if (($a['fantasy_team_is_user_team'] ?? false) !== ($b['fantasy_team_is_user_team'] ?? false)) {
                return ($a['fantasy_team_is_user_team'] ?? false) ? -1 : 1;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $payload['headings'] = $headings->all();
        $payload['data'] = $teamRows;
        $payload['settings'] = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $payload['settings']['resource'] = 'teams';
        $payload['settings']['teamAggregate'] = true;
        $payload['settings']['teamAggregateStartersOnly'] = $startersOnly;
        $payload['settings']['ownerColumn'] = true;
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['resource'] = 'teams';
        $payload['meta']['teamAggregateStartersOnly'] = $startersOnly;

        return $payload;
    }

    private function isTeamAverageOnlyStat(string $key, string $label): bool
    {
        $normalizedKey = strtolower($key);
        $normalizedLabel = strtolower($label);

        return str_contains($normalizedKey, '_per_')
            || str_contains($normalizedKey, 'per_gp')
            || str_contains($normalizedKey, 'per_game')
            || str_contains($normalizedLabel, '/g')
            || str_contains($normalizedLabel, '/gp')
            || str_contains($normalizedLabel, 'per game')
            || in_array($normalizedKey, [
                'gaa',
                'sv_pct',
                'save_pct',
                'save_pctg',
                'shooting_pct',
                'shooting_pctg',
                'ev_sv_pct',
                'pp_sv_pct',
                'pk_sv_pct',
            ], true);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function leagueStatsRowIdentityKey(array $row): ?string
    {
        foreach (['player_id', 'id', 'nhl_player_id'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));

            if ($value !== '') {
                return $field . ':' . $value;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function leagueStatsRowScore(array $row): float
    {
        $score = 0.0;

        if (filled($row['fantasy_team_id'] ?? null)) {
            $score += 1_000_000;
        }

        if (! (bool) ($row['league_roster_only'] ?? false)) {
            $score += 100_000;
        }

        $score += ((float) ($row['gp'] ?? 0)) * 1_000;
        $score += collect($row)
            ->filter(static fn (mixed $value, mixed $key): bool => is_numeric($value)
                && ! in_array((string) $key, ['id', 'player_id', 'nhl_player_id'], true)
                && (float) $value !== 0.0)
            ->count();

        return $score;
    }

    private function isProspectsPerspective(object $p, array $filters): bool
    {
        $slug = strtolower((string) ($p->slug ?? ''));

        return in_array($slug, ['prospects', 'prospects-goalies'], true);
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

}
