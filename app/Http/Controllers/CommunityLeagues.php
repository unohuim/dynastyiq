<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FantraxPlayer;
use App\Models\DiscordServer;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Events\TeamLogosSynced;
use App\Jobs\SyncFantraxDraftStateJob;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\PlayerExternalIdentity;
use App\Models\Stat;
use App\Services\FantraxDraftingWindow;
use App\Services\FantraxLogoSyncService;
use App\Services\LeagueProviderBindingService;
use App\Services\YahooFantasyLeagueService;
use App\Traits\HasAPITrait;
use App\ViewModels\LeagueShowViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CommunityLeagues extends Controller
{
    use HasAPITrait;

    public function show(int $cId, int $lId): View
    {
        $user = Auth::user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with(['discordServers', 'leagues'])
            ->findOrFail($cId);

        $league = $community->leagues()
            ->withPivot(['discord_server_id', 'meta'])
            ->findOrFail($lId);

        $communities = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->orderBy('organizations.name')
            ->get();

        $fantraxConnected = $user->fantraxSecret()->exists();
        $bindingService = app(LeagueProviderBindingService::class);

        $fantraxOptions = [];
        if ($fantraxConnected) {
            $fantraxOptions = PlatformLeague::query()
                ->select('platform_leagues.*')
                ->join('league_user_teams as lut', 'lut.platform_league_id', '=', 'platform_leagues.id')
                ->where('lut.user_id', $user->id)
                ->where('lut.is_active', true)
                ->where('platform_leagues.platform', 'fantrax')
                ->orderBy('platform_leagues.name')
                ->get()
                ->unique('platform_league_id')
                ->map(function (PlatformLeague $row) use ($bindingService): array {
                    return [
                        'name' => (string) $row->name,
                        'platform_league_id' => (string) $row->platform_league_id,
                        'sport' => (string) $row->sport,
                        'scope_options' => $bindingService->scopeOptions($row),
                    ];
                })
                ->values()
                ->all();
        }

        $activeProviderScope = $league->activePlatformScope();
        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();
        $platformLeagueId = $platformLeague?->platform_league_id;
        $isFantraxLeague = $platformLeague?->platform === 'fantrax' && filled($platformLeagueId);
        $communityProviderScope = $platformLeague instanceof PlatformLeague
            ? $this->communityProviderScope($platformLeague, $activeProviderScope)
            : [];
        $canSyncTeamLogos = $platformLeague instanceof PlatformLeague
            && in_array($platformLeague->platform, ['fantrax', 'yahoo'], true)
            && $this->canManageLeague($league, $user);

        $leagueInfo = [];
        $teams = [];
        if ($isFantraxLeague) {
            try {
                $resp = $this->getAPIData('fantrax', 'league_info', [
                    'leagueId' => (string) $platformLeagueId,
                ]);

                $leagueInfo = is_array($resp) ? $resp : [];
                $apiTeams = $leagueInfo['teamInfo'] ?? [];
                $teams = collect(is_array($apiTeams) ? $apiTeams : [])
                    ->filter(static fn (mixed $team): bool => is_array($team))
                    ->filter(fn (array $team): bool => $platformLeague instanceof PlatformLeague
                        ? $this->teamMatchesProviderScope($team, $platformLeague, $communityProviderScope)
                        : true)
                    ->map(function (array $team): array {
                        return [
                            'id' => (string) ($team['id'] ?? ''),
                            'name' => (string) ($team['name'] ?? ''),
                            'owner_avatar_url' => null,
                            'logo_url' => $this->teamLogoUrl($team),
                        ];
                    })
                    ->values()
                    ->all();
            } catch (RequestException $e) {
                $teams = [];
            }
        }

        $draftResults = [];
        $draftPickInfo = [];
        $draftError = null;

        if ($isFantraxLeague && $platformLeague instanceof PlatformLeague) {
            try {
                $resp = $this->getAPIData('fantrax', 'draft_results', [
                    'leagueId' => (string) $platformLeagueId,
                ]);
                $draftResults = is_array($resp) ? $resp : [];
            } catch (Throwable $e) {
                $draftError = $e;
            }

            try {
                $resp = $this->getAPIData('fantrax', 'draft_picks', [
                    'leagueId' => (string) $platformLeagueId,
                ]);
                $draftPickInfo = is_array($resp) ? $resp : [];
            } catch (Throwable $e) {
                $draftPickInfo = [];
            }

            if ($draftError === null && $draftResults !== []) {
                SyncFantraxDraftStateJob::dispatch((int) $platformLeague->id, $draftResults, $draftPickInfo);
            }
        }

        $draftingWindow = app(FantraxDraftingWindow::class);
        $playerNamesByFantraxId = $isFantraxLeague
            ? $this->fantraxDraftPlayerMap($draftingWindow->fantraxPlayerIds($draftResults))
            : [];
        $draftTeamMetaByFantraxId = $isFantraxLeague
            ? $this->fantraxDraftTeamMap((int) $platformLeague?->id)
            : [];
        $teams = collect($teams)
            ->map(static function (array $team) use ($draftTeamMetaByFantraxId): array {
                $teamMeta = $draftTeamMetaByFantraxId[(string) ($team['id'] ?? '')] ?? [];

                return array_merge($team, [
                    'owner_avatar_url' => $teamMeta['owner_avatar_url'] ?? null,
                    'logo_url' => $teamMeta['logo_url'] ?? ($team['logo_url'] ?? null),
                ]);
            })
            ->all();
        $drafting = $isFantraxLeague
            ? $draftingWindow->normalize(
                $leagueInfo,
                $draftResults,
                $draftError,
                null,
                $playerNamesByFantraxId,
                $draftTeamMetaByFantraxId,
                $draftPickInfo
            )
            : $draftingWindow->normalize([], []);
        $drafting = $platformLeague instanceof PlatformLeague
            ? $this->scopedCommunityDraftingPayload($drafting, $platformLeague, $communityProviderScope)
            : $drafting;
        $drafting['config'] = $this->draftingConfig($community, $league);

        $vm = new LeagueShowViewModel(
            community: $community,
            league: $league,
            communities: $communities,
            guilds: $community->discordServers,
            teams: $teams,
            drafting: $drafting,
            fantraxConnected: $fantraxConnected,
            fantraxOptions: $fantraxOptions,
            mobileBreakpoint: (int) config('viewports.mobile', 768)
        );

        $payload = $vm->toDto()->toArray();
        $payload['header']['can_export_fantrax_aav'] = $isFantraxLeague;
        $payload['header']['fantrax_aav_export_url'] = route('community.leagues.fantrax-aav-export', [
            'c_id' => $community->id,
            'l_id' => $league->id,
        ]);
        $payload['team_logo_sync'] = [
            'can_sync' => $canSyncTeamLogos,
            'action_url' => route('community.leagues.team-logos.sync', [
                'c_id' => $community->id,
                'l_id' => $league->id,
            ]),
        ];
        $payload['league_shape'] = $platformLeague instanceof PlatformLeague
            ? $this->leagueShapePayload($platformLeague, $communityProviderScope)
            : [];

        return view('communities.leagues.show', [
            'vm' => $payload,
        ]);
    }

    /**
     * Compact Fantrax league-shape payload for community page consumers.
     *
     * @return array<string,mixed>
     */
    private function leagueShapePayload(PlatformLeague $league, array $activeProviderScope = []): array
    {
        $shape = data_get($league, 'settings.league_shape', []);

        if (! is_array($shape)) {
            return [];
        }

        return [
            'duplicate_player_type' => $shape['duplicate_player_type'] ?? null,
            'player_pool_scope' => $shape['player_pool_scope'] ?? 'unknown',
            'team_count' => (int) ($shape['team_count'] ?? 0),
            'division_count' => (int) ($shape['division_count'] ?? 0),
            'divisions' => array_values(is_array($shape['divisions'] ?? null) ? $shape['divisions'] : []),
            'draft_shape' => $shape['draft_shape'] ?? 'unknown',
            'salary_source' => ($shape['custom_salary_detected'] ?? false) ? 'fantrax' : 'none',
            'active_scope' => [
                'type' => data_get($activeProviderScope, 'scope_type'),
                'key' => data_get($activeProviderScope, 'scope_key'),
                'label' => data_get($activeProviderScope, 'scope_label'),
            ],
        ];
    }

    /**
     * Return stored fantasy teams for a community league wrapper.
     */
    public function teams(int $cId, int $lId): JsonResponse
    {
        $user = Auth::user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with('leagues')
            ->findOrFail($cId);

        $league = $community->leagues()
            ->findOrFail($lId);

        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();

        if (! $platformLeague instanceof PlatformLeague) {
            return response()->json([
                'ok' => true,
                'teams' => [],
                'message' => 'No fantasy platform league is connected.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'teams' => $this->communityTeamRows(
                $platformLeague,
                $this->communityProviderScope($platformLeague, $league->activePlatformScope())
            ),
        ]);
    }

    /**
     * Return draft status cards for a community league wrapper.
     */
    public function draftSummary(int $cId, int $lId): JsonResponse
    {
        $user = Auth::user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with('leagues')
            ->findOrFail($cId);

        $league = $community->leagues()
            ->findOrFail($lId);

        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();

        if (! $platformLeague instanceof PlatformLeague || $platformLeague->platform !== 'fantrax') {
            $drafting = app(FantraxDraftingWindow::class)->normalize([], []);
            $drafting['empty_text'] = 'No Fantrax draft connected.';

            return response()->json([
                'ok' => true,
                'summary' => $this->emptyDraftSummary('No Fantrax draft connected.'),
                'live_html' => $this->communityDraftLiveHtml($drafting),
                'active_round_index' => 0,
            ]);
        }

        $draft = $platformLeague->drafts()
            ->with(['picks.player', 'picks.platformTeam'])
            ->where('source_type', 'platform_mirror')
            ->latest('updated_at')
            ->first()
            ?? $platformLeague->drafts()
                ->with(['picks.player', 'picks.platformTeam'])
                ->latest('updated_at')
                ->first();
        $communityProviderScope = $this->communityProviderScope($platformLeague, $league->activePlatformScope());

        if ($draft instanceof Draft) {
            $drafting = $this->canonicalCommunityDraftingPayload(
                $draft,
                $platformLeague,
                $communityProviderScope
            );

            return response()->json([
                'ok' => true,
                'summary' => $this->communityDraftSummaryPayload(
                    $drafting,
                    $platformLeague,
                    []
                ),
                'live_html' => $this->communityDraftLiveHtml($drafting),
                'active_round_index' => (int) ($drafting['active_round_index'] ?? 0),
            ]);
        }

        $platformLeagueId = (string) $platformLeague->platform_league_id;
        $draftingWindow = app(FantraxDraftingWindow::class);
        $draftResults = [];
        $draftPickInfo = [];
        $draftError = null;

        try {
            $resp = $this->getAPIData('fantrax', 'draft_results', [
                'leagueId' => $platformLeagueId,
            ]);
            $draftResults = is_array($resp) ? $resp : [];
        } catch (Throwable $e) {
            $draftError = $e;
        }

        try {
            $resp = $this->getAPIData('fantrax', 'draft_picks', [
                'leagueId' => $platformLeagueId,
            ]);
            $draftPickInfo = is_array($resp) ? $resp : [];
        } catch (Throwable) {
            $draftPickInfo = [];
        }

        $teamMap = $this->fantraxDraftTeamMap((int) $platformLeague->id);
        $drafting = $draftingWindow->normalize(
            [],
            $draftResults,
            $draftError,
            null,
            $this->fantraxDraftPlayerMap($draftingWindow->fantraxPlayerIds($draftResults)),
            $teamMap,
            $draftPickInfo
        );
        $drafting = $this->scopedCommunityDraftingPayload($drafting, $platformLeague, $communityProviderScope);

        return response()->json([
            'ok' => true,
            'summary' => $this->communityDraftSummaryPayload(
                $drafting,
                $platformLeague,
                []
            ),
            'live_html' => $this->communityDraftLiveHtml($drafting),
            'active_round_index' => (int) ($drafting['active_round_index'] ?? 0),
        ]);
    }

    /**
     * Apply a community provider scope only when the platform league has division-scoped player pools.
     *
     * @param array<string,mixed> $activeProviderScope
     * @return array<string,mixed>
     */
    private function communityProviderScope(PlatformLeague $platformLeague, array $activeProviderScope = []): array
    {
        $shape = data_get($platformLeague, 'settings.league_shape', []);
        $playerPoolScope = is_array($shape) ? (string) ($shape['player_pool_scope'] ?? '') : '';
        $duplicatePlayerType = is_array($shape) ? strtoupper((string) ($shape['duplicate_player_type'] ?? '')) : '';

        if ($playerPoolScope !== 'division' && $duplicatePlayerType !== 'ACROSS_DIVISIONS') {
            return [];
        }

        if (data_get($activeProviderScope, 'scope_type') !== 'division') {
            return [];
        }

        if ((string) data_get($activeProviderScope, 'scope_key', '') === '') {
            return [];
        }

        return $activeProviderScope;
    }

    /**
     * Filter and rebuild a community draft payload for the effective provider scope.
     *
     * @param array<string,mixed> $drafting
     * @param array<string,mixed> $activeProviderScope
     * @return array<string,mixed>
     */
    private function scopedCommunityDraftingPayload(
        array $drafting,
        PlatformLeague $platformLeague,
        array $activeProviderScope = []
    ): array {
        if (data_get($activeProviderScope, 'scope_type') !== 'division') {
            return $drafting;
        }

        $rows = collect($drafting['rows'] ?? [])
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => $this->draftRowMatchesProviderScope($row, $platformLeague, $activeProviderScope))
            ->values();

        if (! $rows->contains(static fn (array $row): bool => ! empty($row['is_next_pick']))) {
            $markedNextPick = false;
            $rows = $rows
                ->map(function (array $row) use (&$markedNextPick): array {
                    $row['is_next_pick'] = false;

                    if (! $markedNextPick && ! $this->communityDraftRowIsPicked($row)) {
                        $row['is_next_pick'] = true;
                        $markedNextPick = true;
                    }

                    return $row;
                })
                ->values();
        }

        $drafting['rows'] = $rows->all();
        $drafting['rounds'] = $this->communityDraftRounds($rows)
            ->map(function (array $round) use ($rows): array {
                $round['count'] = $rows
                    ->filter(static fn (array $row): bool => ($row['round'] ?? null) === $round['round'])
                    ->count();
                $round['rows'] = $rows
                    ->filter(static fn (array $row): bool => ($row['round'] ?? null) === $round['round'])
                    ->values()
                    ->all();

                return $round;
            })
            ->values()
            ->all();
        $drafting['active_round_index'] = $this->communityDraftActiveRoundIndex($drafting['rounds']);

        return $drafting;
    }

    /**
     * Build the community draft header payload from a normalized draft.
     *
     * @param array<string,mixed> $drafting
     * @param array<string,mixed> $activeProviderScope
     * @return array<string,mixed>
     */
    private function communityDraftSummaryPayload(
        array $drafting,
        PlatformLeague $platformLeague,
        array $activeProviderScope = []
    ): array
    {
        $rows = collect($drafting['rows'] ?? [])
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->filter(fn (array $row): bool => $this->draftRowMatchesProviderScope($row, $platformLeague, $activeProviderScope))
            ->values();
        $isCompleted = strtolower((string) ($drafting['status_text'] ?? '')) === 'complete';
        $pickedRows = $rows
            ->filter(fn (array $row): bool => $this->communityDraftRowIsPicked($row))
            ->values();
        $nextPick = $isCompleted
            ? null
            : ($rows->first(static fn (array $row): bool => ! empty($row['is_next_pick']))
                ?? $rows->first(fn (array $row): bool => ! $this->communityDraftRowIsPicked($row)));
        $upNextPick = $isCompleted
            ? null
            : $this->communityDraftRowAfter($rows, $nextPick);
        $rounds = $this->communityDraftRounds($rows);

        return [
            'available' => (bool) ($drafting['available'] ?? true),
            'status_text' => (string) ($drafting['status_text'] ?? 'Draft'),
            'status_tone' => (string) ($drafting['status_tone'] ?? 'slate'),
            'draft_at' => (string) ($drafting['draft_at'] ?? ''),
            'draft_at_label' => $this->communityDraftAtLabel((string) ($drafting['draft_at'] ?? '')),
            'is_completed' => $isCompleted,
            'time_remaining_label' => $isCompleted ? '-' : '--:--',
            'countdown_expires_at' => (string) ($drafting['countdown_expires_at'] ?? ''),
            'otc_team' => $this->communityDraftPickTeamPayload($nextPick, 'Awaiting pick'),
            'up_next_team' => $this->communityDraftPickTeamPayload($upNextPick, 'No upcoming pick'),
            'drafted_count' => $pickedRows->count(),
            'total_picks' => $rows->count(),
            'rounds' => $rounds
                ->all(),
            'active_round' => is_array($nextPick) ? ($nextPick['round'] ?? null) : null,
        ];
    }

    /**
     * Return the next unpicked normalized draft row after the current row.
     *
     * @param Collection<int,array<string,mixed>> $rows
     * @param array<string,mixed>|null $currentPick
     * @return array<string,mixed>|null
     */
    private function communityDraftRowAfter(Collection $rows, ?array $currentPick): ?array
    {
        if (! is_array($currentPick)) {
            return null;
        }

        $currentKey = (string) ($currentPick['provider_pick_key'] ?? '');
        $currentIndex = $rows
            ->values()
            ->search(function (array $row) use ($currentPick, $currentKey): bool {
                if ($currentKey !== '' && (string) ($row['provider_pick_key'] ?? '') === $currentKey) {
                    return true;
                }

                return $row === $currentPick;
            });

        if ($currentIndex === false) {
            return null;
        }

        return $rows
            ->values()
            ->slice(((int) $currentIndex) + 1)
            ->first(fn (array $row): bool => ! $this->communityDraftRowIsPicked($row));
    }

    /**
     * Determine whether a normalized draft row has a picked player.
     *
     * @param array<string,mixed> $row
     */
    private function communityDraftRowIsPicked(array $row): bool
    {
        return (bool) ($row['is_picked'] ?? ! empty($row['fantrax_player_id']) || ! empty($row['player_id']));
    }

    /**
     * Choose the initial round index for a scoped community draft payload.
     *
     * @param array<int,array<string,mixed>> $rounds
     */
    private function communityDraftActiveRoundIndex(array $rounds): int
    {
        foreach ($rounds as $index => $round) {
            foreach ($round['rows'] ?? [] as $row) {
                if (is_array($row) && ! empty($row['is_next_pick'])) {
                    return $index;
                }
            }
        }

        return max(0, count($rounds) - 1);
    }

    /**
     * Build compact round labels from scoped draft rows.
     *
     * @param Collection<int,array<string,mixed>> $rows
     * @return Collection<int,array{round:int|null,label:string}>
     */
    private function communityDraftRounds(Collection $rows): Collection
    {
        return $rows
            ->groupBy(static fn (array $row): string => ($row['round'] ?? null) === null ? 'unknown' : (string) $row['round'])
            ->map(static function ($roundRows, string $round): array {
                $roundNumber = $round === 'unknown' ? null : (int) $round;

                return [
                    'round' => $roundNumber,
                    'label' => $roundNumber === null ? 'Round' : 'Round ' . $roundNumber,
                ];
            })
            ->sortBy(static fn (array $round): int => $round['round'] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Render the shared Draft Central live pick list for the community selected-league panel.
     *
     * @param array<string,mixed> $drafting
     */
    private function communityDraftLiveHtml(array $drafting): string
    {
        return view('leagues._draft-live-panel', [
            'drafting' => $drafting,
            'draftRounds' => collect($drafting['rounds'] ?? []),
        ])->render();
    }

    /**
     * Build a community-safe Draft Central live payload from stored draft rows.
     *
     * @param array<string,mixed> $activeProviderScope
     * @return array<string,mixed>
     */
    private function canonicalCommunityDraftingPayload(
        Draft $draft,
        PlatformLeague $platformLeague,
        array $activeProviderScope = []
    ): array {
        $picks = $draft->picks instanceof Collection
            ? $draft->picks
            : $draft->picks()
                ->with(['player', 'platformTeam'])
                ->orderBy('overall_pick')
                ->orderBy('round')
                ->orderBy('pick_in_round')
                ->orderBy('provider_pick_key')
                ->get();
        $picks = $picks
            ->filter(fn (DraftPick $pick): bool => $this->draftPickMatchesProviderScope($pick, $platformLeague, $activeProviderScope))
            ->sortBy([
                ['overall_pick', 'asc'],
                ['round', 'asc'],
                ['pick_in_round', 'asc'],
                ['provider_pick_key', 'asc'],
            ])
            ->values();
        $providerPlayerIds = $picks
            ->pluck('provider_player_id')
            ->filter()
            ->map(static fn (mixed $playerId): string => (string) $playerId)
            ->unique()
            ->values()
            ->all();
        $currentPick = $picks->first(static fn (DraftPick $pick): bool => (string) $pick->status === 'on_clock')
            ?? $picks->first(static fn (DraftPick $pick): bool => blank($pick->provider_player_id) && blank($pick->player_id));
        $providerPlayerMap = $this->fantraxDraftPlayerMap($providerPlayerIds);
        $teamMap = $this->fantraxDraftTeamMap((int) $platformLeague->id);

        $rows = $picks
            ->map(function (DraftPick $pick) use ($providerPlayerMap, $teamMap): array {
                $providerPlayerId = $pick->provider_player_id ? (string) $pick->provider_player_id : '';
                $mappedPlayer = $providerPlayerId !== ''
                    ? ($providerPlayerMap[$providerPlayerId] ?? [])
                    : [];
                $providerTeamId = $pick->provider_team_id ? (string) $pick->provider_team_id : '';
                $mappedTeam = $providerTeamId !== '' ? ($teamMap[$providerTeamId] ?? []) : [];
                $hasPlayer = $providerPlayerId !== '' || filled($pick->player_id);
                $rawPayload = is_array($pick->raw_payload) ? $pick->raw_payload : [];

                return [
                    'player_name' => $hasPlayer
                        ? ((string) ($mappedPlayer['name'] ?? '') ?: (string) ($pick->player?->full_name ?? 'Unknown player'))
                        : '',
                    'fantrax_player_id' => $providerPlayerId,
                    'is_picked' => $hasPlayer,
                    'player_id' => $pick->player_id ? (int) $pick->player_id : null,
                    'nhl_id' => $mappedPlayer['nhl_id'] ?? ($pick->player?->nhl_id ? (int) $pick->player->nhl_id : null),
                    'position' => $mappedPlayer['position'] ?? $pick->player?->position,
                    'age' => $mappedPlayer['age'] ?? $pick->player?->age(),
                    'league_abbrev' => $mappedPlayer['league_abbrev'] ?? null,
                    'team_abbrev' => $mappedPlayer['team_abbrev'] ?? null,
                    'avatar_url' => $mappedPlayer['avatar_url'] ?? $pick->player?->head_shot_url,
                    'next_season' => $mappedPlayer['next_season'] ?? null,
                    'stats' => $mappedPlayer['stats'] ?? [
                        'gp' => null,
                        'g' => null,
                        'a' => null,
                        'pts' => null,
                        'wins' => null,
                        'sv_pct' => null,
                    ],
                    'team_id' => $providerTeamId,
                    'team_name' => (string) ($pick->platformTeam?->name ?: ($mappedTeam['team_name'] ?? ($providerTeamId ?: 'Unknown team'))),
                    'team_avatar_url' => $mappedTeam['owner_avatar_url'] ?? $pick->platformTeam?->logo_url,
                    'round' => $pick->round !== null ? (int) $pick->round : null,
                    'pick' => $pick->pick !== null ? (int) $pick->pick : null,
                    'pick_in_round' => $pick->pick_in_round !== null ? (int) $pick->pick_in_round : null,
                    'overall_pick' => $pick->overall_pick !== null ? (int) $pick->overall_pick : null,
                    'provider_pick_key' => (string) $pick->provider_pick_key,
                    'division' => (string) ($rawPayload['division'] ?? ''),
                    'picked_at' => $pick->picked_at?->toIso8601String(),
                    'is_next_pick' => (string) $pick->status === 'on_clock',
                ];
            })
            ->values();

        if (! $rows->contains(static fn (array $row): bool => ! empty($row['is_next_pick']))) {
            $markedNextPick = false;
            $rows = $rows
                ->map(function (array $row) use (&$markedNextPick): array {
                    $row['is_next_pick'] = false;

                    if (! $markedNextPick && ! $this->communityDraftRowIsPicked($row)) {
                        $row['is_next_pick'] = true;
                        $markedNextPick = true;
                    }

                    return $row;
                })
                ->values();
        }

        $rounds = $this->communityDraftRounds($rows)
            ->map(function (array $round) use ($rows): array {
                $round['count'] = $rows
                    ->filter(static fn (array $row): bool => ($row['round'] ?? null) === $round['round'])
                    ->count();
                $round['rows'] = $rows
                    ->filter(static fn (array $row): bool => ($row['round'] ?? null) === $round['round'])
                    ->values()
                    ->all();

                return $round;
            })
            ->values()
            ->all();

        return [
            'available' => true,
            'title' => $draft->starts_at?->format('F j, Y') ?? $draft->name,
            'draft_at' => $draft->starts_at?->toIso8601String(),
            'is_live' => (string) $draft->status === 'live',
            'status_text' => ucfirst((string) $draft->status),
            'status_tone' => match ((string) $draft->status) {
                'live' => 'green',
                'scheduled' => 'blue',
                default => 'slate',
            },
            'countdown_expires_at' => $this->canonicalDraftCountdownExpiresAt($draft, $currentPick),
            'rows' => $rows->all(),
            'rounds' => $rounds,
            'active_round_index' => $this->communityDraftActiveRoundIndex($rounds),
            'empty_text' => 'No drafted players yet.',
            'error_text' => null,
        ];
    }

    /**
     * Build the community draft header payload from stored draft tables.
     *
     * @return array<string,mixed>
     */
    private function canonicalCommunityDraftSummary(
        Draft $draft,
        PlatformLeague $platformLeague,
        array $activeProviderScope = []
    ): array {
        $picks = $draft->picks
            ->filter(fn (DraftPick $pick): bool => $this->draftPickMatchesProviderScope($pick, $platformLeague, $activeProviderScope))
            ->sortBy([
                ['overall_pick', 'asc'],
                ['round', 'asc'],
                ['pick_in_round', 'asc'],
                ['provider_pick_key', 'asc'],
            ])
            ->values();
        $isCompleted = (string) $draft->status === 'complete';
        $pickedRows = $picks
            ->filter(static fn (DraftPick $pick): bool => filled($pick->provider_player_id) || filled($pick->player_id))
            ->values();
        $draftCurrentPick = $draft->currentPick;
        $scopedCurrentPick = $draftCurrentPick instanceof DraftPick
            && $picks->contains(static fn (DraftPick $pick): bool => (int) $pick->id === (int) $draftCurrentPick->id)
                ? $draftCurrentPick
                : null;
        $currentPick = $isCompleted
            ? null
            : ($scopedCurrentPick
                ?? $picks->first(static fn (DraftPick $pick): bool => (string) $pick->status === 'on_clock')
                ?? $picks->first(static fn (DraftPick $pick): bool => blank($pick->provider_player_id) && blank($pick->player_id)));
        $upNextPick = $isCompleted || ! $currentPick instanceof DraftPick
            ? null
            : $this->communityDraftPickAfter($picks, $currentPick);
        $rounds = $picks
            ->groupBy(static fn (DraftPick $pick): string => $pick->round === null ? 'unknown' : (string) $pick->round)
            ->map(static function ($roundPicks, string $round): array {
                $roundNumber = $round === 'unknown' ? null : (int) $round;

                return [
                    'round' => $roundNumber,
                    'label' => $roundNumber === null ? 'Round' : 'Round ' . $roundNumber,
                ];
            })
            ->sortBy(static fn (array $round): int => $round['round'] ?? PHP_INT_MAX)
            ->values();

        return [
            'available' => true,
            'status_text' => ucfirst((string) $draft->status),
            'status_tone' => match ((string) $draft->status) {
                'live' => 'green',
                'scheduled' => 'blue',
                default => 'slate',
            },
            'draft_at' => $draft->starts_at?->toIso8601String() ?? '',
            'draft_at_label' => $this->communityDraftAtLabel($draft->starts_at?->toIso8601String() ?? ''),
            'is_completed' => $isCompleted,
            'time_remaining_label' => $isCompleted ? '-' : '--:--',
            'countdown_expires_at' => $this->canonicalDraftCountdownExpiresAt($draft, $currentPick),
            'pick_clock_seconds' => (int) ($draft->pick_clock_seconds ?? 0),
            'otc_team' => $this->canonicalDraftPickTeamPayload($currentPick, 'Awaiting pick'),
            'up_next_team' => $this->canonicalDraftPickTeamPayload($upNextPick, 'No upcoming pick'),
            'drafted_count' => $pickedRows->count(),
            'total_picks' => $picks->count(),
            'rounds' => $rounds->all(),
            'active_round' => $currentPick instanceof DraftPick ? $currentPick->round : null,
        ];
    }

    /**
     * Format a draft start date for compact community draft summaries.
     */
    private function communityDraftAtLabel(string $draftAt): string
    {
        if ($draftAt === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($draftAt)->format('M j, Y g:i A');
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Return the next unpicked canonical draft pick after the current pick.
     *
     * @param Collection<int,DraftPick> $picks
     */
    private function communityDraftPickAfter(Collection $picks, DraftPick $currentPick): ?DraftPick
    {
        $currentIndex = $picks
            ->values()
            ->search(static fn (DraftPick $pick): bool => (int) $pick->id === (int) $currentPick->id);

        if ($currentIndex === false) {
            return null;
        }

        return $picks
            ->values()
            ->slice(((int) $currentIndex) + 1)
            ->first(static fn (DraftPick $pick): bool => blank($pick->provider_player_id) && blank($pick->player_id));
    }

    private function canonicalDraftCountdownExpiresAt(Draft $draft, ?DraftPick $currentPick): string
    {
        if ((string) $draft->status === 'complete') {
            return '';
        }

        if ($currentPick instanceof DraftPick && $currentPick->expires_at) {
            return $currentPick->expires_at->toIso8601String();
        }

        $pickClockSeconds = (int) ($draft->pick_clock_seconds ?? 0);
        $lastPickedAt = $draft->picks
            ->filter(static fn (DraftPick $pick): bool => $pick->picked_at !== null)
            ->sortByDesc(static fn (DraftPick $pick): int => $pick->picked_at?->getTimestamp() ?? 0)
            ->first()
            ?->picked_at;

        if ($pickClockSeconds <= 0 || ! $lastPickedAt) {
            return '';
        }

        return $lastPickedAt->copy()
            ->addSeconds($pickClockSeconds)
            ->toIso8601String();
    }

    /**
     * Determine whether a stored draft pick belongs to the active provider scope.
     *
     * @param array<string,mixed> $activeProviderScope
     */
    private function draftPickMatchesProviderScope(
        DraftPick $pick,
        PlatformLeague $league,
        array $activeProviderScope
    ): bool {
        if (data_get($activeProviderScope, 'scope_type') !== 'division') {
            return true;
        }

        $scopeKey = (string) data_get($activeProviderScope, 'scope_key', '');

        if ($scopeKey === '') {
            return true;
        }

        $teamId = (string) ($pick->provider_team_id ?? $pick->platformTeam?->platform_team_id ?? '');
        $candidates = $this->providerScopeCandidates(
            $league,
            $teamId,
            is_array($pick->raw_payload) ? $pick->raw_payload : [],
            $pick->platformTeam instanceof PlatformTeam ? $pick->platformTeam : null,
            (string) $pick->provider_pick_key
        );

        return collect($candidates)
            ->contains(fn (string $candidate): bool => $this->providerScopeKey($candidate) === $scopeKey);
    }

    /**
     * Determine whether a normalized Fantrax draft row belongs to the active provider scope.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $activeProviderScope
     */
    private function draftRowMatchesProviderScope(
        array $row,
        PlatformLeague $league,
        array $activeProviderScope
    ): bool {
        if (data_get($activeProviderScope, 'scope_type') !== 'division') {
            return true;
        }

        $scopeKey = (string) data_get($activeProviderScope, 'scope_key', '');

        if ($scopeKey === '') {
            return true;
        }

        $teamId = (string) ($row['team_id'] ?? '');
        $candidates = $this->providerScopeCandidates($league, $teamId, $row);

        return collect($candidates)
            ->contains(fn (string $candidate): bool => $this->providerScopeKey($candidate) === $scopeKey);
    }

    /**
     * Return possible provider scope labels from a team, draft row, or pick key.
     *
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function providerScopeCandidates(
        PlatformLeague $league,
        string $providerTeamId = '',
        array $payload = [],
        ?PlatformTeam $team = null,
        string $providerPickKey = ''
    ): array {
        $candidates = [];

        if ($providerTeamId !== '') {
            $candidates[] = (string) data_get($league, 'settings.league_shape.team_divisions.' . $providerTeamId, '');
        }

        foreach ([
            'division',
            'divisionName',
            'division_name',
            'pool',
            'poolName',
            'pool_name',
        ] as $key) {
            $candidates[] = (string) data_get($payload, $key, '');
        }

        if ($team instanceof PlatformTeam) {
            $candidates[] = (string) data_get($team->extras, 'fantrax.division', '');
            $candidates[] = (string) data_get($team->extras, 'fantrax.pool', '');
        }

        if (preg_match('/(?:division|pool):([^:]+)/', $providerPickKey, $matches) === 1) {
            $candidates[] = (string) $matches[1];
        }

        return collect($candidates)
            ->map(static fn (string $candidate): string => trim($candidate))
            ->filter(static fn (string $candidate): bool => $candidate !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build a compact team payload from a stored draft pick.
     *
     * @return array<string,mixed>
     */
    private function canonicalDraftPickTeamPayload(?DraftPick $pick, string $fallbackName): array
    {
        return [
            'name' => (string) ($pick?->platformTeam?->name ?: $fallbackName),
            'avatar_url' => $pick?->platformTeam?->logo_url,
            'round' => $pick?->round,
            'pick' => $pick?->pick_in_round ?? $pick?->pick,
        ];
    }

    /**
     * Build a compact team payload for a draft card.
     *
     * @param array<string,mixed>|null $pick
     * @return array<string,mixed>
     */
    private function communityDraftPickTeamPayload(?array $pick, string $fallbackName): array
    {
        return [
            'name' => (string) data_get($pick, 'team_name', $fallbackName),
            'avatar_url' => data_get($pick, 'team_avatar_url'),
            'round' => data_get($pick, 'round'),
            'pick' => data_get($pick, 'pick_in_round', data_get($pick, 'pick')),
        ];
    }

    /**
     * Return an empty draft header payload.
     *
     * @return array<string,mixed>
     */
    private function emptyDraftSummary(string $message): array
    {
        return [
            'available' => false,
            'status_text' => $message,
            'status_tone' => 'slate',
            'is_completed' => false,
            'time_remaining_label' => '-',
            'countdown_expires_at' => '',
            'otc_team' => $this->communityDraftPickTeamPayload(null, '-'),
            'up_next_team' => $this->communityDraftPickTeamPayload(null, '-'),
            'drafted_count' => 0,
            'total_picks' => 0,
            'rounds' => [],
            'active_round' => null,
        ];
    }

    /**
     * Build community-facing team rows for a selected league wrapper.
     *
     * @param array<string,mixed> $activeProviderScope
     * @return array<int,array<string,mixed>>
     */
    private function communityTeamRows(PlatformLeague $platformLeague, array $activeProviderScope = []): array
    {
        return $platformLeague->teams()
            ->with(['users' => static function ($query): void {
                $query->wherePivot('is_active', true)
                    ->select('users.id', 'users.name')
                    ->with(['socialAccounts' => static function ($query): void {
                        $query->select('id', 'user_id', 'avatar')
                            ->where('provider', 'discord');
                    }]);
            }])
            ->orderBy('name')
            ->get(['id', 'platform_team_id', 'name', 'short_name', 'logo_url', 'extras'])
            ->filter(fn (PlatformTeam $team): bool => $this->platformTeamMatchesProviderScope($team, $platformLeague, $activeProviderScope))
            ->map(static function (PlatformTeam $team): array {
                $ownerNames = $team->users
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->all();
                $ownerAvatarUrls = $team->users
                    ->map(static fn ($user): ?string => $user->socialAccounts->first()?->avatar)
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => (string) $team->platform_team_id,
                    'name' => (string) $team->name,
                    'short_name' => (string) ($team->short_name ?: $team->name),
                    'logo_url' => filled($team->logo_url) ? (string) $team->logo_url : null,
                    'owner_names' => $ownerNames,
                    'owner_avatar_urls' => $ownerAvatarUrls,
                    'fantrax_division' => data_get($team->extras, 'fantrax.division'),
                    'fantrax_pool' => data_get($team->extras, 'fantrax.pool'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Determine whether a stored platform team belongs to the active provider scope.
     *
     * @param array<string,mixed> $activeProviderScope
     */
    private function platformTeamMatchesProviderScope(
        PlatformTeam $team,
        PlatformLeague $league,
        array $activeProviderScope
    ): bool {
        if (data_get($activeProviderScope, 'scope_type') !== 'division') {
            return true;
        }

        $scopeKey = (string) data_get($activeProviderScope, 'scope_key', '');

        if ($scopeKey === '') {
            return true;
        }

        $teamId = (string) $team->platform_team_id;
        $division = $teamId !== ''
            ? (string) data_get($league, 'settings.league_shape.team_divisions.' . $teamId, '')
            : '';

        if ($division === '') {
            $division = (string) data_get($team->extras, 'fantrax.division', '');
        }

        return $this->providerScopeKey($division) === $scopeKey;
    }

    /**
     * Determine whether a raw Fantrax team belongs to the active provider scope.
     *
     * @param array<string,mixed> $team
     * @param array<string,mixed> $activeProviderScope
     */
    private function teamMatchesProviderScope(array $team, PlatformLeague $league, array $activeProviderScope): bool
    {
        if (data_get($activeProviderScope, 'scope_type') !== 'division') {
            return true;
        }

        $scopeKey = (string) data_get($activeProviderScope, 'scope_key', '');

        if ($scopeKey === '') {
            return true;
        }

        $teamId = (string) ($team['id'] ?? '');
        $division = $teamId !== ''
            ? (string) data_get($league, 'settings.league_shape.team_divisions.' . $teamId, '')
            : '';

        if ($division === '') {
            $division = (string) ($team['division'] ?? $team['divisionName'] ?? $team['division_name'] ?? '');
        }

        return $this->providerScopeKey($division) === $scopeKey;
    }

    private function providerScopeKey(string $value): string
    {
        return str($value)->trim()->lower()->slug('-')->toString();
    }

    /**
     * Sync team logos for the connected fantasy league.
     */
    public function syncTeamLogos(
        int $cId,
        int $lId,
        FantraxLogoSyncService $fantraxLogoSync,
        YahooFantasyLeagueService $yahooLeagueService,
    ): JsonResponse
    {
        $user = Auth::user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with('leagues')
            ->findOrFail($cId);

        $league = $community->leagues()
            ->findOrFail($lId);

        abort_unless($this->canManageLeague($league, $user), 403);

        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();

        abort_unless($platformLeague instanceof PlatformLeague, 404);

        if ($platformLeague->platform === 'fantrax') {
            $summary = $fantraxLogoSync->syncForPlatformLeagueIds([(int) $platformLeague->id]);

            if (! $summary['ran']) {
                return response()->json([
                    'ok' => false,
                    'message' => match ($summary['skipped_reason']) {
                        'browser_profile_not_configured' => 'Fantrax logo sync is not configured.',
                        'browser_profile_not_ready' => 'Fantrax logo sync profile is not ready.',
                        default => 'Team logo sync was skipped.',
                    },
                    'summary' => $summary,
                ], 409);
            }
        } elseif ($platformLeague->platform === 'yahoo') {
            $connection = $user->yahooFantasyConnection()
                ->where('status', 'connected')
                ->first();

            if ($connection === null) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Connect Yahoo before syncing team logos.',
                ], 409);
            }

            $summary = $yahooLeagueService->syncLogosForLeague($connection, $platformLeague);

            if (! $summary['ran']) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Yahoo team logo sync was skipped.',
                    'summary' => $summary,
                ], 409);
            }
        } else {
            abort(404);
        }

        $logoUrl = $this->leagueDisplayLogoUrl($platformLeague, $user);

        TeamLogosSynced::dispatch(
            (int) $user->id,
            (int) $platformLeague->id,
            (string) $platformLeague->platform,
            $logoUrl,
        );

        return response()->json([
            'ok' => true,
            'message' => $summary['updated_team_count'] > 0
                ? 'Team logos synced.'
                : 'Logo sync ran, but no team logos changed.',
            'platform_league_id' => (int) $platformLeague->id,
            'platform' => (string) $platformLeague->platform,
            'logo_url' => $logoUrl,
            'summary' => $summary,
        ]);
    }

    /**
     * Stream a Fantrax salary upload file for commissioner-managed leagues.
     */
    public function exportFantraxAav(int $cId, int $lId): StreamedResponse
    {
        $user = Auth::user();
        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->findOrFail($cId);
        $league = $community->leagues()
            ->findOrFail($lId);
        $platformLeague = $league->activePlatformLeague() ?? $league->primaryPlatformLeague();

        abort_unless($platformLeague instanceof PlatformLeague && $platformLeague->platform === 'fantrax', 404);

        $filename = 'fantrax-caphit-' . str($league->name ?: 'league')->slug('-')->toString() . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            $rowNumber = 1;
            $players = FantraxPlayer::query()
                ->leftJoin('players', 'players.id', '=', 'fantrax_players.player_id')
                ->select([
                    'fantrax_players.fantrax_id',
                    'fantrax_players.name',
                    'fantrax_players.team',
                    'fantrax_players.position',
                    'players.team_abbrev as linked_team_abbrev',
                ])
                ->selectSub(function ($query): void {
                    $query->from('contracts')
                        ->join('contract_seasons', 'contract_seasons.contract_id', '=', 'contracts.id')
                        ->whereColumn('contracts.player_id', 'fantrax_players.player_id')
                        ->whereNotNull('contract_seasons.cap_hit')
                        ->orderByDesc('contract_seasons.season_key')
                        ->select('contract_seasons.cap_hit')
                        ->limit(1);
                }, 'current_cap_hit')
                ->orderBy('fantrax_players.name')
                ->orderBy('fantrax_players.fantrax_id')
                ->cursor();

            foreach ($players as $player) {
                fwrite($handle, $this->fantraxUploadCsvRow([
                    '*' . (string) $player->fantrax_id . '*',
                    (string) $rowNumber++,
                    $this->fantraxUploadName((string) ($player->name ?? '')),
                    $this->fantraxUploadTeam((string) ($player->team ?: $player->linked_team_abbrev ?: '')),
                    $this->fantraxUploadPosition((string) ($player->position ?? '')),
                    (string) (int) ($player->current_cap_hit ?: 750000),
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function updateDraftSettings(Request $request, int $cId, int $lId): JsonResponse
    {
        $user = Auth::user();
        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with('discordServers')
            ->findOrFail($cId);
        $league = $community->leagues()
            ->withPivot(['discord_server_id', 'meta'])
            ->findOrFail($lId);

        $data = $request->validate([
            'draft_channel_id' => ['nullable', 'string', 'max:64'],
            'draft_channel_name' => ['nullable', 'string', 'max:100'],
        ]);

        $channelId = trim((string) ($data['draft_channel_id'] ?? ''));
        $channelName = $this->normalizeDiscordChannelName($data['draft_channel_name'] ?? '');
        $discordServer = $this->selectedDiscordServer($community, $league);
        $meta = $this->pivotMeta($league);

        if ($channelId === '' && $channelName === '') {
            data_forget($meta, 'draft_notifications.discord_channel');
        } elseif ($discordServer) {
            $channels = $this->discordTextChannels($discordServer);
            $channel = collect($channels)->first(static fn (array $option): bool => $channelId !== ''
                ? (string) $option['id'] === $channelId
                : strtolower((string) $option['name']) === strtolower($channelName));

            if (! $channel && $channelName !== '') {
                $channel = $this->createDiscordTextChannel($discordServer, $channelName);
            }

            if ($channel) {
                data_set($meta, 'draft_notifications.discord_channel', [
                    'id' => (string) $channel['id'],
                    'name' => (string) $channel['name'],
                ]);
            } else {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not find or create that Discord channel.',
                ], 422);
            }
        } else {
            return response()->json([
                'ok' => false,
                'message' => 'Connect a Discord server before selecting a draft channel.',
            ], 422);
        }

        DB::table('organization_leagues')
            ->where('organization_id', $community->id)
            ->where('league_id', $league->id)
            ->update([
                'meta' => $meta === [] ? null : json_encode($meta),
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok' => true,
            'channel' => data_get($meta, 'draft_notifications.discord_channel'),
        ]);
    }

    /**
     * Determine whether the current user can manage this league.
     */
    private function canManageLeague(mixed $league, mixed $user): bool
    {
        if (! $league || ! $user) {
            return false;
        }

        return Gate::allows('refresh-leagues') || $user->isCommissionerForLeague((int) $league->id);
    }

    private function leagueDisplayLogoUrl(PlatformLeague $league, mixed $user): ?string
    {
        if (! $user) {
            return null;
        }

        $logoUrl = PlatformTeam::query()
            ->select('platform_teams.logo_url')
            ->join('league_user_teams as logo_lut', 'logo_lut.team_id', '=', 'platform_teams.id')
            ->where('logo_lut.platform_league_id', (int) $league->id)
            ->where('logo_lut.user_id', (int) $user->id)
            ->where('logo_lut.is_active', true)
            ->whereNotNull('platform_teams.logo_url')
            ->value('platform_teams.logo_url');

        if (is_string($logoUrl) && trim($logoUrl) !== '') {
            return trim($logoUrl);
        }

        $leagueLogoUrl = $league->getAttribute('logo_url');

        return is_string($leagueLogoUrl) && trim($leagueLogoUrl) !== ''
            ? trim($leagueLogoUrl)
            : null;
    }

    /**
     * Format a Fantrax salary-upload CSV row exactly like the blank Fantrax template.
     *
     * @param array<int, string> $fields
     */
    private function fantraxUploadCsvRow(array $fields): string
    {
        return collect($fields)
            ->map(static fn (string $field): string => '"' . str_replace('"', '""', $field) . '"')
            ->implode(',') . "\n";
    }

    /**
     * Normalize a stored Fantrax name for salary-upload display.
     */
    private function fantraxUploadName(string $name): string
    {
        $name = trim($name);

        if (! str_contains($name, ',')) {
            return $name;
        }

        [$last, $first] = array_map('trim', explode(',', $name, 2));

        return trim($first . ' ' . $last);
    }

    /**
     * Normalize a stored Fantrax team for salary-upload display.
     */
    private function fantraxUploadTeam(string $team): string
    {
        $team = trim($team);

        return in_array(strtoupper($team), ['', 'N/A', '(N/A)'], true) ? '' : $team;
    }

    /**
     * Normalize a stored Fantrax position into the Fantrax salary-upload position bucket.
     */
    private function fantraxUploadPosition(string $position): string
    {
        $positions = collect(preg_split('/[,\s\/]+/', strtoupper(trim($position))) ?: [])
            ->map(static fn (string $value): string => match ($value) {
                'C', 'L', 'LW', 'R', 'RW', 'W', 'FWD', 'FORWARD', 'SKT', 'SKATER' => 'F',
                'LD', 'RD' => 'D',
                default => $value,
            })
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($positions->isEmpty()) {
            return '';
        }

        if ($positions->contains('G')) {
            return 'G';
        }

        if ($positions->contains('F') && $positions->contains('D')) {
            return 'F,D';
        }

        if ($positions->contains('D')) {
            return 'D';
        }

        return 'F';
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

        $externalIdentities->each(
            function (PlayerExternalIdentity $identity) use (&$map, $identityLatestStatsByPlayerId): void {
                $fantraxId = (string) $identity->provider_player_id;
                $existing = $map[$fantraxId] ?? [];
                $playerId = $identity->player_id ? (int) $identity->player_id : null;
                $latestStats = $playerId ? ($identityLatestStatsByPlayerId[$playerId] ?? null) : null;

                $map[$fantraxId] = [
                    'name' => $existing['name']
                        ?? $identity->display_name
                        ?? $identity->player?->full_name,
                    'player_id' => $existing['player_id']
                        ?? $playerId,
                    'nhl_id' => $existing['nhl_id']
                        ?? ($identity->player?->nhl_id ? (int) $identity->player->nhl_id : null),
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
            }
        );

        return $map;
    }

    /**
     * Build settings data for the draft settings drawer.
     *
     * @return array<string,mixed>
     */
    private function draftingConfig(mixed $community, mixed $league): array
    {
        $discordServer = $this->selectedDiscordServer($community, $league);
        $selectedChannel = data_get($this->pivotMeta($league), 'draft_notifications.discord_channel');
        $channelOptions = $this->discordTextChannelOptions($discordServer);
        $channels = $channelOptions['channels'];

        if (is_array($selectedChannel)) {
            $selectedChannelId = (string) ($selectedChannel['id'] ?? '');
            $hasSelectedChannel = collect($channels)
                ->contains(static fn (array $channel): bool => (string) $channel['id'] === $selectedChannelId);

            if ($channelOptions['status'] === 'loaded' && ! $hasSelectedChannel) {
                $selectedChannel = null;
            }
        }

        return [
            'action_url' => route('community.leagues.draft-settings.update', [
                'c_id' => $community->id,
                'l_id' => $league->id,
            ]),
            'discord_connected' => $discordServer !== null,
            'channels' => collect($channels)->sortBy('name')->values()->all(),
            'channels_status' => $channelOptions['status'],
            'channels_message' => $channelOptions['message'],
            'selected_channel' => is_array($selectedChannel) ? $selectedChannel : null,
        ];
    }

    private function selectedDiscordServer(mixed $community, mixed $league): ?DiscordServer
    {
        $discordServerId = $league->pivot?->discord_server_id;

        return $community->discordServers
            ->first(static fn (DiscordServer $server): bool => (int) $server->id === (int) $discordServerId)
            ?? $community->discordServers->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function pivotMeta(mixed $league): array
    {
        $meta = $league->pivot?->meta ?? null;

        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<int,array{id:string,name:string}>
     */
    private function discordTextChannels(DiscordServer $discordServer): array
    {
        return $this->discordTextChannelOptions($discordServer)['channels'];
    }

    /**
     * @return array{channels:array<int,array{id:string,name:string}>,status:string,message:string|null}
     */
    private function discordTextChannelOptions(?DiscordServer $discordServer): array
    {
        $token = (string) config('apiurls.discord-bot.key');

        if (! $discordServer) {
            return [
                'channels' => [],
                'status' => 'not_connected',
                'message' => 'Connect a Discord server first.',
            ];
        }

        if ($token === '') {
            return [
                'channels' => [],
                'status' => 'missing_bot_token',
                'message' => 'Discord channels could not be loaded because the DIQ bot token is not configured.',
            ];
        }

        try {
            $response = Http::withHeaders($this->discordBotHeaders($token))
                ->acceptJson()
                ->get('https://discord.com/api/v10/guilds/' . $discordServer->discord_guild_id . '/channels');

            if (! $response->successful()) {
                return [
                    'channels' => [],
                    'status' => 'discord_error',
                    'message' => 'Discord returned ' . $response->status() . ' while loading channels for this server.',
                ];
            }

            $channels = collect($response->json())
                ->filter(static fn (mixed $channel): bool => is_array($channel) && (int) ($channel['type'] ?? -1) === 0)
                ->map(static fn (array $channel): array => [
                    'id' => (string) ($channel['id'] ?? ''),
                    'name' => (string) ($channel['name'] ?? ''),
                ])
                ->filter(static fn (array $channel): bool => $channel['id'] !== '' && $channel['name'] !== '')
                ->values()
                ->all();

            return [
                'channels' => $channels,
                'status' => $channels === [] ? 'empty' : 'loaded',
                'message' => $channels === [] ? 'No text channels were returned for this Discord server.' : null,
            ];
        } catch (Throwable) {
            return [
                'channels' => [],
                'status' => 'discord_error',
                'message' => 'Discord channels could not be loaded for this server.',
            ];
        }
    }

    /**
     * @return array{id:string,name:string}|null
     */
    private function createDiscordTextChannel(DiscordServer $discordServer, string $channelName): ?array
    {
        $token = (string) config('apiurls.discord-bot.key');
        $guildId = (string) $discordServer->discord_guild_id;

        if ($token === '' || $guildId === '' || $channelName === '') {
            return null;
        }

        try {
            $channels = $this->discordGuildChannels($discordServer);
            $placement = $this->draftChannelPlacement($channels);
            $payload = array_filter([
                'name' => $channelName,
                'type' => 0,
                'parent_id' => $placement['parent_id'],
                'position' => $placement['position'],
            ], static fn (mixed $value): bool => $value !== null);

            $response = Http::withHeaders($this->discordBotHeaders($token))
                ->acceptJson()
                ->post('https://discord.com/api/v10/guilds/' . $guildId . '/channels', $payload);

            if (! $response->successful()) {
                return null;
            }

            $channel = $response->json();

            return [
                'id' => (string) ($channel['id'] ?? ''),
                'name' => (string) ($channel['name'] ?? $channelName),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,string>
     */
    private function discordBotHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bot ' . $token,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function discordGuildChannels(DiscordServer $discordServer): array
    {
        $token = (string) config('apiurls.discord-bot.key');

        if ($token === '') {
            return [];
        }

        try {
            $response = Http::withHeaders($this->discordBotHeaders($token))
                ->acceptJson()
                ->get('https://discord.com/api/v10/guilds/' . $discordServer->discord_guild_id . '/channels');

            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $channels
     *
     * @return array{parent_id:string|null,position:int|null}
     */
    private function draftChannelPlacement(array $channels): array
    {
        $textChannels = collect($channels)
            ->filter(static fn (mixed $channel): bool => is_array($channel) && (int) ($channel['type'] ?? -1) === 0);
        $categories = collect($channels)
            ->filter(static fn (mixed $channel): bool => is_array($channel) && (int) ($channel['type'] ?? -1) === 4);

        $category = $categories->first(static function (array $channel): bool {
            $name = strtolower(trim((string) ($channel['name'] ?? '')));

            return in_array($name, ['text channels', 'text channel', 'text'], true);
        });
        $parentId = is_array($category) ? (string) ($category['id'] ?? '') : '';

        if ($parentId === '') {
            $firstTextChannel = $textChannels->first();
            $parentId = is_array($firstTextChannel) ? (string) ($firstTextChannel['parent_id'] ?? '') : '';
        }

        $position = null;

        if ($parentId !== '') {
            $position = $textChannels
                ->filter(static fn (array $channel): bool => (string) ($channel['parent_id'] ?? '') === $parentId)
                ->map(static fn (array $channel): int => (int) ($channel['position'] ?? 0))
                ->max();
            $position = is_int($position) ? $position + 1 : null;
        }

        return [
            'parent_id' => $parentId !== '' ? $parentId : null,
            'position' => $position,
        ];
    }

    private function normalizeDiscordChannelName(mixed $value): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9-_]+/', '-', ltrim((string) $value, '#')) ?? ''), '-');
    }

    /**
     * @param array<string,mixed> $team
     */
    private function teamLogoUrl(array $team): ?string
    {
        foreach (['logoUrl', 'logo_url', 'avatarUrl', 'avatar_url', 'imageUrl', 'image_url', 'iconUrl', 'icon_url'] as $key) {
            $value = data_get($team, $key);

            if (filled($value) && is_string($value)) {
                return $value;
            }
        }

        return null;
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
     * @return array<string,array{team_name:string,owner_avatar_url:string|null}>
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
            ->get(['id', 'platform_team_id', 'name'])
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
                        'team_name' => (string) $team->name,
                    ],
                ];
            })
            ->all();
    }
}
