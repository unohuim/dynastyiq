<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Perspective;
use App\Services\FantasyLeagueAccess;
use App\Services\PlatformLeaguePlayerStatService;
use App\Support\Stats\LeagueStatsOwnershipHydrator;
use App\Support\Stats\LeagueStatsPerspectiveFactory;
use App\Support\Stats\LeagueStatsPlayerUniverseFilter;
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
            'resource'      => 'nullable|in:players,units',
            'period'        => 'nullable|in:season,range,lastWeek,thisWeek,past30days',
            'game_type'     => 'nullable|in:1,2,3',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'availability'  => 'nullable|integer',
            'draft_context' => 'nullable|boolean',
            'column_group'  => 'nullable|in:goalie',
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
        $mark('league_lookup_ms');

        $leaguePerspectiveFactory = app(LeagueStatsPerspectiveFactory::class);
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
        $period = $context->period;
        $requestedColumnGroup = (string) ($context->columnGroup ?? '');
        $requestedPerspective = $context->requestedPerspective;
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

        if ($leagueReportUnderlyingPerspective !== null) {
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
        }
        $mark('payload_settings_ms');

        $payload = app(LeagueStatsPlayerUniverseFilter::class)->filter($payload, $league);
        $mark('platform_filter_ms');

        $ownershipTimings = $logStatsTiming ? [] : null;
        $payload = app(LeagueStatsOwnershipHydrator::class)->hydrate($payload, $league, $user?->id, $ownershipTimings);
        $payload = $this->dedupeLeagueProspectRows($payload);
        $mark('ownership_ms');

        if ($isFantraxLeaguePerspective || $isLeagueScoringPerspective) {
            $payload = app(PlatformLeaguePlayerStatService::class)->overlayStatsPayload(
                $payload,
                $league,
                is_string($season) ? $season : null,
            );
        }
        $mark('provider_stats_overlay_ms');

        $payload['connectedLeagues'] = $this->connectedLeaguesForUser($user);
        $payload['perspectives'] = $leaguePerspectiveFactory->perspectives($user, $league);
        $payload['selectedPerspective'] = $leagueReportPerspective
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
