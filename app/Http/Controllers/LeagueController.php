<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SyncFantraxLeagueJob;
use App\Events\TeamLogosSynced;
use App\Models\Draft;
use App\Models\DraftNotificationSetting;
use App\Models\DraftPick;
use App\Models\DraftQueueItem;
use App\Models\FantraxPlayer;
use App\Models\FantasyScoringCategoryMapping;
use App\Models\IntegrationSecret;
use App\Models\CapContractProjection;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\Stat;
use App\Support\Stats\LeagueStatsPerspectiveFactory;
use App\Support\FantraxViewerScope;
use App\Services\ConnectFantraxUser;
use App\Services\FantraxDraftingWindow;
use App\Services\FantasyLeagueAccess;
use App\Services\FantraxLogoSyncService;
use App\Services\PlatformLeagueSettingsResolver;
use App\Services\PlatformLeaguePlayerStatService;
use App\Services\PlatformLeagueScoringCategoryService;
use App\Services\SyncFantraxDraftState;
use App\Services\YahooFantasyLeagueService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        ['key' => 'overtime_wins', 'label' => 'Overtime Wins'],
        ['key' => 'shootout_wins', 'label' => 'Shootout Wins'],
        ['key' => 'shootout_losses', 'label' => 'Shootout Losses'],
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

        $leagues = $leagueAccess->visibleLeaguesForUser($user)
            ->addSelect([
                'user_team_logo_url' => PlatformTeam::query()
                    ->select('platform_teams.logo_url')
                    ->join('league_user_teams as logo_lut', 'logo_lut.team_id', '=', 'platform_teams.id')
                    ->whereColumn('logo_lut.platform_league_id', 'platform_leagues.id')
                    ->where('logo_lut.user_id', $user->id)
                    ->where('logo_lut.is_active', true)
                    ->limit(1),
            ])
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->get();
        $leagueOptions = $leagueAccess->activeLeaguesForUser($user)
            ->addSelect([
                'user_team_logo_url' => PlatformTeam::query()
                    ->select('platform_teams.logo_url')
                    ->join('league_user_teams as logo_lut', 'logo_lut.team_id', '=', 'platform_teams.id')
                    ->whereColumn('logo_lut.platform_league_id', 'platform_leagues.id')
                    ->where('logo_lut.user_id', $user->id)
                    ->where('logo_lut.is_active', true)
                    ->limit(1),
            ])
            ->get();

        $activeLeagueId = $request->integer('active') ?? ($leagues->first()->id ?? null);
        $activeLeague = $activeLeagueId
            ? $leagues->firstWhere('id', $activeLeagueId)
            : $leagues->first();

        $activeLeagueScope = $activeLeague ? $this->viewerFantraxScope($activeLeague, $user) : null;
        $teams = $activeLeague ? $this->teamsMetaPayload($activeLeague, $activeLeagueScope) : [];
        $drafting = $activeLeague
            ? $this->draftingPayload($activeLeague, $user, $activeLeagueScope)
            : app(FantraxDraftingWindow::class)->normalize([], []);
        $leagueStatsControls = $activeLeague
            ? $this->leagueStatsPerspectiveControls($user, $activeLeague)
            : ['perspectives' => [], 'selected' => ''];
        $leagueSettings = $activeLeague
            ? $this->resolvedLeagueSettings($activeLeague, $user)
            : $this->emptyLeagueSettings();

        return view('leagues', [
            'leagues' => $leagues,
            'leagueOptions' => $leagueOptions,
            'activeLeagueId' => $activeLeague?->id,
            'activeLeague' => $activeLeague,
            'teams' => $teams,
            'drafting' => $drafting,
            'scoringCategories' => $activeLeague ? $this->scoringCategoriesPayload($activeLeague) : [],
            'scoringAlignmentCategories' => $activeLeague ? $this->scoringAlignmentCategoriesPayload($activeLeague) : [],
            'manualScoringMappings' => $activeLeague ? $this->manualScoringMappingsPayload($activeLeague) : [],
            'availableStatFields' => $this->availableStatFieldsPayload(),
            'scoringMappingOptions' => $this->scoringMappingOptionsPayload(),
            'searchPlayers' => [],
            'scoringSettingsUpdateUrl' => $activeLeague ? route('leagues.scoring-settings.update', $activeLeague->id) : '',
            'capSettingsUpdateUrl' => $activeLeague ? route('leagues.cap-settings.update', $activeLeague->id) : '',
            'capProjectionsUpdateUrl' => $activeLeague ? route('leagues.cap-projections.update', $activeLeague->id) : '',
            'leagueStatsPayloadUrl' => $activeLeague ? route('leagues.stats.payload', $activeLeague->id) : '',
            'leagueStatsPerspectives' => $leagueStatsControls['perspectives'],
            'selectedLeagueStatsPerspective' => $leagueStatsControls['selected'],
            'playersPayloadUrl' => $activeLeague ? route('leagues.players.payload', $activeLeague->id) : '',
            'playersFreeAgentsPayloadUrl' => $activeLeague ? route('leagues.players.free-agents.payload', $activeLeague->id) : '',
            'teamLogoSyncUrl' => $activeLeague ? route('leagues.team-logos.sync', $activeLeague->id) : '',
            'leagueShape' => $activeLeague ? $this->leagueShapePayload($activeLeague) : [],
            'customCap' => (bool) data_get($leagueSettings, 'settings.custom_cap', false),
            'salaryCap' => data_get($leagueSettings, 'settings.salary_cap'),
            'capLimitsBySeason' => data_get($leagueSettings, 'settings.cap_limits_by_season', []),
            'capAdjustmentsByTeam' => data_get($leagueSettings, 'settings.cap_adjustments_by_team', []),
            'maxActiveBuyouts' => data_get($leagueSettings, 'settings.max_active_buyouts'),
            'maxActiveRetentions' => data_get($leagueSettings, 'settings.max_active_retentions'),
            'buyoutExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.buyout_extra_payout_year', false),
            'retentionExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.retention_extra_payout_year', false),
            'leagueSettingsSource' => $leagueSettings['source'],
            'canEditLeagueSettings' => $leagueSettings['can_edit'],
            'fantraxContractCodes' => $activeLeague ? $this->fantraxContractCodesPayload(
                $activeLeague,
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ) : [],
            'fantraxContractCodeDefinitions' => $this->fantraxContractCodeDefinitionsFromSettings(
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
            'isScoringFullyMapped' => $activeLeague ? $this->isScoringFullyMapped($activeLeague) : false,
            'canShowLeagueStats' => $activeLeague ? $this->canShowLeagueStats($activeLeague) : false,
            'canManageLeague' => $activeLeague ? $this->canManageLeague($activeLeague, $user) : false,
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
        $leagueStatsControls = $this->leagueStatsPerspectiveControls($user, $league);
        $leagueSettings = $this->resolvedLeagueSettings($league, $user);

        return view('leagues._panel', [
            'league' => $league,
            'teams'  => $this->teamsMetaPayload($league, $this->viewerFantraxScope($league, $user)),
            'drafting' => $this->draftingPayload($league, $user),
            'scoringCategories' => $this->scoringCategoriesPayload($league),
            'scoringAlignmentCategories' => $this->scoringAlignmentCategoriesPayload($league),
            'manualScoringMappings' => $this->manualScoringMappingsPayload($league),
            'availableStatFields' => $this->availableStatFieldsPayload(),
            'scoringMappingOptions' => $this->scoringMappingOptionsPayload(),
            'searchPlayers' => [],
            'scoringSettingsUpdateUrl' => route('leagues.scoring-settings.update', $league->id),
            'capSettingsUpdateUrl' => route('leagues.cap-settings.update', $league->id),
            'capProjectionsUpdateUrl' => route('leagues.cap-projections.update', $league->id),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $league->id),
            'leagueStatsPerspectives' => $leagueStatsControls['perspectives'],
            'selectedLeagueStatsPerspective' => $leagueStatsControls['selected'],
            'playersPayloadUrl' => route('leagues.players.payload', $league->id),
            'playersFreeAgentsPayloadUrl' => route('leagues.players.free-agents.payload', $league->id),
            'teamLogoSyncUrl' => route('leagues.team-logos.sync', $league->id),
            'leagueShape' => $this->leagueShapePayload($league),
            'customCap' => (bool) data_get($leagueSettings, 'settings.custom_cap', false),
            'salaryCap' => data_get($leagueSettings, 'settings.salary_cap'),
            'capLimitsBySeason' => data_get($leagueSettings, 'settings.cap_limits_by_season', []),
            'capAdjustmentsByTeam' => data_get($leagueSettings, 'settings.cap_adjustments_by_team', []),
            'maxActiveBuyouts' => data_get($leagueSettings, 'settings.max_active_buyouts'),
            'maxActiveRetentions' => data_get($leagueSettings, 'settings.max_active_retentions'),
            'buyoutExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.buyout_extra_payout_year', false),
            'retentionExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.retention_extra_payout_year', false),
            'leagueSettingsSource' => $leagueSettings['source'],
            'canEditLeagueSettings' => $leagueSettings['can_edit'],
            'fantraxContractCodes' => $this->fantraxContractCodesPayload(
                $league,
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
            'fantraxContractCodeDefinitions' => $this->fantraxContractCodeDefinitionsFromSettings(
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($league),
            'canShowLeagueStats' => $this->canShowLeagueStats($league),
            'canManageLeague' => $this->canManageLeague($league, $user),
            'initialLeagueTab' => $request->query('initial_tab'),
        ]);
    }

    /**
     * Return heavy player/team data for the selected league on demand.
     */
    public function playersPayload(Request $request, string $leagueId): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider to view league players.',
            ], 409);
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->firstOrFail();
        $leagueStatsControls = $this->leagueStatsPerspectiveControls($user, $league);
        $leagueSettings = $this->resolvedLeagueSettings($league, $user);

        return response()->json([
            'teams' => $this->teamsPayload($league, true, $user, $this->viewerFantraxScope($league, $user)),
            'canShowLeagueStats' => $this->canShowLeagueStats($league),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $league->id),
            'leagueStatsPerspectives' => $leagueStatsControls['perspectives'],
            'selectedLeagueStatsPerspective' => $leagueStatsControls['selected'],
            'playersFreeAgentsPayloadUrl' => route('leagues.players.free-agents.payload', $league->id),
            'leagueShape' => $this->leagueShapePayload($league),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($league),
            'customCap' => (bool) data_get($leagueSettings, 'settings.custom_cap', false),
            'salaryCap' => data_get($leagueSettings, 'settings.salary_cap'),
            'capLimitsBySeason' => data_get($leagueSettings, 'settings.cap_limits_by_season', []),
            'capAdjustmentsByTeam' => data_get($leagueSettings, 'settings.cap_adjustments_by_team', []),
            'maxActiveBuyouts' => data_get($leagueSettings, 'settings.max_active_buyouts'),
            'maxActiveRetentions' => data_get($leagueSettings, 'settings.max_active_retentions'),
            'buyoutExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.buyout_extra_payout_year', false),
            'retentionExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.retention_extra_payout_year', false),
            'leagueSettingsSource' => $leagueSettings['source'],
            'canEditLeagueSettings' => $leagueSettings['can_edit'],
            'fantraxContractCodes' => $this->fantraxContractCodesPayload(
                $league,
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
            'fantraxContractCodeDefinitions' => $this->fantraxContractCodeDefinitionsFromSettings(
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
        ]);
    }

    /**
     * Return league-scoped stats controls without loading stat rows.
     *
     * @return array{perspectives:array<int,array{id:int|string|null,slug:string,name:string,is_slicable:bool}>,selected:string}
     */
    private function leagueStatsPerspectiveControls($user, $league): array
    {
        $perspectives = app(LeagueStatsPerspectiveFactory::class)->perspectives($user, $league);

        return [
            'perspectives' => $perspectives,
            'selected' => $this->selectedLeagueStatsPerspectiveFromList($perspectives),
        ];
    }

    /**
     * Compact Fantrax league-shape payload for page and AJAX consumers.
     *
     * @return array<string,mixed>
     */
    private function leagueShapePayload($league): array
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
        ];
    }

    /**
     * Resolve the viewer's Fantrax player-pool scope for user-facing league reads.
     *
     * @return array{scope:string,division:string|null}|null
     */
    private function viewerFantraxScope($league, $user): ?array
    {
        return app(FantraxViewerScope::class)->resolve($league, $user);
    }

    private function fantraxScopeDivisionFromTeam(PlatformTeam $team): ?string
    {
        return app(FantraxViewerScope::class)->divisionFromTeam($team);
    }

    /**
     * @param array{scope:string,division:string|null}|null $scope
     */
    private function teamMatchesViewerFantraxScope(PlatformTeam $team, ?array $scope): bool
    {
        return app(FantraxViewerScope::class)->teamMatches($team, $scope);
    }

    /**
     * @param array{scope:string,division:string|null}|null $scope
     */
    private function draftPickMatchesViewerFantraxScope(DraftPick $pick, ?array $scope): bool
    {
        if (($scope['scope'] ?? null) !== 'division') {
            return true;
        }

        $rawPayload = is_array($pick->raw_payload) ? $pick->raw_payload : [];
        $division = trim((string) (
            $rawPayload['division']
            ?? $rawPayload['divisionName']
            ?? $rawPayload['division_name']
            ?? $rawPayload['pool']
            ?? $rawPayload['poolName']
            ?? $rawPayload['pool_name']
            ?? data_get($pick->platformTeam?->extras, 'fantrax.division')
            ?? data_get($pick->platformTeam?->extras, 'fantrax.pool')
            ?? ''
        ));

        return $division !== ''
            && strcasecmp($division, (string) ($scope['division'] ?? '')) === 0;
    }

    /**
     * Choose the first selected Players-tab perspective from the available options.
     *
     * @param array<int,array<string,mixed>> $perspectives
     */
    private function selectedLeagueStatsPerspectiveFromList(array $perspectives): string
    {
        $synthetic = collect($perspectives)
            ->first(static fn (array $perspective): bool => str_contains((string) ($perspective['slug'] ?? ''), '-league-'));

        if (is_array($synthetic) && filled($synthetic['slug'] ?? null)) {
            return (string) $synthetic['slug'];
        }

        $skaters = collect($perspectives)
            ->first(static fn (array $perspective): bool => ($perspective['slug'] ?? null) === 'skaters'
                || ($perspective['name'] ?? null) === 'Skaters');

        if (is_array($skaters) && filled($skaters['slug'] ?? null)) {
            return (string) $skaters['slug'];
        }

        return (string) ($perspectives[0]['slug'] ?? '');
    }

    /**
     * Return deferred free-agent and search player data for the selected league.
     */
    public function playersFreeAgentsPayload(Request $request, string $leagueId): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider to view league players.',
            ], 409);
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        $scope = $this->viewerFantraxScope($league, $user);
        $freeAgents = $this->freeAgentsPayload($league, $scope);
        $searchPlayers = $this->searchPlayersPayloadForScope($league, $user, $scope, $freeAgents);

        return response()->json([
            'freeAgents' => $freeAgents,
            'searchPlayers' => $searchPlayers,
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
        $mappingOptions = $this->scoringMappingOptionsPayload();
        $mappingOptionKeys = collect($mappingOptions)->pluck('key')->all();
        $legacyStatKeys = collect($this->availableStatFieldsPayload())->pluck('key')->all();
        $validated = $request->validate([
            'mappings' => ['nullable', 'array'],
            'mappings.*' => ['nullable', 'string', Rule::in(array_merge($mappingOptionKeys, $legacyStatKeys))],
        ]);
        $manualMappings = collect($validated['mappings'] ?? [])
            ->mapWithKeys(fn (mixed $value, mixed $key): array => [
                (string) $key => $this->normalizeScoringMappingKey((string) $value),
            ])
            ->filter(static fn (string $value): bool => $value !== '')
            ->all();
        $scoringSettings = is_array($league->scoring_settings) ? $league->scoring_settings : [];
        $scoringCategoryService = app(PlatformLeagueScoringCategoryService::class);
        $updatedRows = $scoringCategoryService->updateManualMappings(
            $league,
            $manualMappings,
            collect($mappingOptions)->keyBy('key')->all(),
        );

        $scoringSettings['manual_mappings'] = $manualMappings;

        if (! $updatedRows) {
            $scoringSettings['categories'] = $this->applyManualScoringMappingsToCategories(
                is_array($scoringSettings['categories'] ?? null) ? $scoringSettings['categories'] : [],
                $manualMappings,
            );
        }

        $league->forceFill([
            'scoring_settings' => $scoringSettings,
        ])->save();

        $league->refresh();

        return response()->json([
            'message' => 'Scoring category alignment saved.',
            'scoringCategories' => $this->scoringCategoriesPayload($league),
            'scoringAlignmentCategories' => $this->scoringAlignmentCategoriesPayload($league),
            'manualScoringMappings' => $this->manualScoringMappingsPayload($league),
            'scoringMappingOptions' => $this->scoringMappingOptionsPayload(),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($league),
            'canShowLeagueStats' => $this->canShowLeagueStats($league),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $league->id),
        ]);
    }

    /**
     * Persist cap display options for the selected league.
     */
    public function updateCapSettings(Request $request, string $leagueId): JsonResponse
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
        $settingsResolver = app(PlatformLeagueSettingsResolver::class);

        $validated = $request->validate([
            'custom_cap' => ['required', 'boolean'],
            'salary_cap' => ['nullable', 'string', 'max:32'],
            'cap_limits_by_season' => ['nullable', 'array'],
            'cap_adjustments_by_team' => ['nullable', 'array'],
            'max_active_buyouts' => ['nullable', 'integer', 'min:0', 'max:99'],
            'max_active_retentions' => ['nullable', 'integer', 'min:0', 'max:99'],
            'buyout_extra_payout_year' => ['nullable', 'boolean'],
            'retention_extra_payout_year' => ['nullable', 'boolean'],
            'contract_code_definitions' => ['nullable', 'array'],
            'contract_code_definitions.*.label' => ['nullable', 'string', 'max:80'],
            'contract_code_definitions.*.type' => ['nullable', 'string', 'max:80'],
            'contract_code_definitions.*.suffix_years_remaining' => ['nullable', 'boolean'],
        ]);
        $resolved = $settingsResolver->resolve($league, $user);

        if (! $resolved['can_edit']) {
            abort_unless(
                array_key_exists('cap_adjustments_by_team', $validated)
                    || array_key_exists('cap_limits_by_season', $validated),
                403,
            );

            $planningSettings = [];

            if (array_key_exists('cap_adjustments_by_team', $validated)) {
                $planningSettings['cap_adjustments_by_team'] = $this->normalizeCapAdjustmentsByTeam(
                    $validated['cap_adjustments_by_team'] ?? [],
                );
            }

            if (array_key_exists('cap_limits_by_season', $validated)) {
                $planningSettings['cap_limits_by_season'] = $this->normalizeCapLimitsBySeason(
                    $validated['cap_limits_by_season'] ?? [],
                );
            }

            $resolved = $settingsResolver->saveManagerPlanning($league, $user, $planningSettings);
            $settings = $resolved['settings'];

            return response()->json([
                'message' => 'Personal cap planning saved.',
                'customCap' => $settings['custom_cap'],
                'salaryCap' => $settings['salary_cap'] ?? null,
                'capLimitsBySeason' => $settings['cap_limits_by_season'] ?? [],
                'capAdjustmentsByTeam' => $settings['cap_adjustments_by_team'] ?? [],
                'maxActiveBuyouts' => $settings['max_active_buyouts'] ?? null,
                'maxActiveRetentions' => $settings['max_active_retentions'] ?? null,
                'buyoutExtraPayoutYear' => (bool) ($settings['buyout_extra_payout_year'] ?? false),
                'retentionExtraPayoutYear' => (bool) ($settings['retention_extra_payout_year'] ?? false),
                'leagueSettingsSource' => $resolved['source'],
                'canEditLeagueSettings' => $resolved['can_edit'],
                'fantraxContractCodes' => $this->fantraxContractCodesPayload(
                    $league,
                    $settings['fantrax_contract_code_definitions'] ?? [],
                ),
                'fantraxContractCodeDefinitions' => $this->fantraxContractCodeDefinitionsFromSettings(
                    $settings['fantrax_contract_code_definitions'] ?? [],
                ),
            ]);
        }

        $settings = $settingsResolver->mergeDefaults($resolved['settings']);
        $settings['custom_cap'] = (bool) $validated['custom_cap'];

        if (array_key_exists('salary_cap', $validated)) {
            $settings['salary_cap'] = $this->normalizeSalaryCap($validated['salary_cap']);
        }

        if (array_key_exists('cap_limits_by_season', $validated)) {
            $settings['cap_limits_by_season'] = $this->normalizeCapLimitsBySeason(
                $validated['cap_limits_by_season'] ?? [],
            );
        }

        if (array_key_exists('cap_adjustments_by_team', $validated)) {
            $settings['cap_adjustments_by_team'] = $this->normalizeCapAdjustmentsByTeam(
                $validated['cap_adjustments_by_team'] ?? [],
            );
        }

        if (array_key_exists('max_active_buyouts', $validated)) {
            $settings['max_active_buyouts'] = $validated['max_active_buyouts'] !== null
                ? (int) $validated['max_active_buyouts']
                : null;
        }

        if (array_key_exists('max_active_retentions', $validated)) {
            $settings['max_active_retentions'] = $validated['max_active_retentions'] !== null
                ? (int) $validated['max_active_retentions']
                : null;
        }

        if (array_key_exists('buyout_extra_payout_year', $validated)) {
            $settings['buyout_extra_payout_year'] = (bool) $validated['buyout_extra_payout_year'];
        }

        if (array_key_exists('retention_extra_payout_year', $validated)) {
            $settings['retention_extra_payout_year'] = (bool) $validated['retention_extra_payout_year'];
        }

        if (array_key_exists('contract_code_definitions', $validated)) {
            $settings['fantrax_contract_code_definitions'] = $this->normalizeFantraxContractCodeDefinitions(
                $validated['contract_code_definitions'] ?? [],
            );
        }

        $resolved = $settingsResolver->save($league, $user, $settings);
        $settings = $resolved['settings'];

        return response()->json([
            'message' => $resolved['source'] === 'manager_local'
                ? 'Personal league settings saved.'
                : ($settings['custom_cap'] ? 'Custom Fantrax cap enabled.' : 'Custom Fantrax cap disabled.'),
            'customCap' => $settings['custom_cap'],
            'salaryCap' => $settings['salary_cap'] ?? null,
            'capLimitsBySeason' => $settings['cap_limits_by_season'] ?? [],
            'capAdjustmentsByTeam' => $settings['cap_adjustments_by_team'] ?? [],
            'maxActiveBuyouts' => $settings['max_active_buyouts'] ?? null,
            'maxActiveRetentions' => $settings['max_active_retentions'] ?? null,
            'buyoutExtraPayoutYear' => (bool) ($settings['buyout_extra_payout_year'] ?? false),
            'retentionExtraPayoutYear' => (bool) ($settings['retention_extra_payout_year'] ?? false),
            'leagueSettingsSource' => $resolved['source'],
            'canEditLeagueSettings' => $resolved['can_edit'],
            'fantraxContractCodes' => $this->fantraxContractCodesPayload(
                $league,
                $settings['fantrax_contract_code_definitions'] ?? [],
            ),
            'fantraxContractCodeDefinitions' => $this->fantraxContractCodeDefinitionsFromSettings(
                $settings['fantrax_contract_code_definitions'] ?? [],
            ),
        ]);
    }

    /**
     * Persist user-owned projected cap assumptions for expired-contract players.
     */
    public function updateCapProjections(Request $request, string $leagueId): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before updating cap projections.',
            ], 409);
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        $validated = $request->validate([
            'platform_team_id' => ['required', 'integer', Rule::exists('platform_teams', 'id')],
            'projections' => ['required', 'array'],
            'projections.*.player_id' => ['required', 'integer', Rule::exists('players', 'id')],
            'projections.*.season_key' => ['required', 'integer', 'min:20002001', 'max:21002101'],
            'projections.*.projected_aav' => ['nullable', 'string', 'max:32'],
        ]);

        $team = $league->teams()
            ->whereKey((int) $validated['platform_team_id'])
            ->firstOrFail();
        $players = $team->roster()
            ->with(['contracts.seasons'])
            ->get()
            ->keyBy('id');
        $currentSeasonKey = self::currentNhlSeasonKey();

        foreach ($validated['projections'] as $projection) {
            $playerId = (int) $projection['player_id'];
            $seasonKey = (int) $projection['season_key'];
            $player = $players->get($playerId);

            if (! $player || ! self::playerCanProjectExpiredContract($player, $seasonKey, $currentSeasonKey)) {
                continue;
            }

            $amount = $this->normalizeCapLimitAmount($projection['projected_aav'] ?? null);
            $projectionSeasonKeys = self::projectionSeasonKeys()
                ->filter(
                    static fn (int $projectionSeasonKey): bool => self::playerCanProjectExpiredContract(
                        $player,
                        $projectionSeasonKey,
                        $currentSeasonKey,
                    ),
                );

            if ($amount === null) {
                CapContractProjection::query()
                    ->where('platform_league_id', (int) $league->id)
                    ->where('platform_team_id', (int) $team->id)
                    ->where('user_id', (int) $user->id)
                    ->where('player_id', $playerId)
                    ->whereIn('season_key', $projectionSeasonKeys->all())
                    ->delete();
                continue;
            }

            foreach ($projectionSeasonKeys as $projectionSeasonKey) {
                CapContractProjection::query()->updateOrCreate(
                    [
                        'platform_league_id' => (int) $league->id,
                        'platform_team_id' => (int) $team->id,
                        'user_id' => (int) $user->id,
                        'player_id' => $playerId,
                        'season_key' => $projectionSeasonKey,
                    ],
                    [
                        'projected_aav' => $amount,
                        'source' => 'user',
                        'basis' => 'manual',
                    ],
                );
            }
        }

        $team->load(['roster.contracts.seasons']);

        return response()->json([
            'message' => 'Cap projections saved.',
            'platformTeamId' => (int) $team->id,
            'projections' => self::capProjectionPayloadForTeam($league, $team, $user),
        ]);
    }

    /**
     * Create the canonical draft record for the selected league.
     */
    public function storeDraft(Request $request, string $leagueId, SyncFantraxDraftState $draftSync): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);
        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        abort_unless($this->canManageLeague($league, $user), 403);

        if ($league->drafts()->exists()) {
            $draft = $league->drafts()->latest('updated_at')->first();

            if ($draft instanceof Draft) {
                $this->ensureDraftNotificationSettings($draft, $league);
            }

            return response()->json([
                'message' => 'Draft already exists for this league.',
                'html' => $this->draftPanelHtml($league, $user),
            ]);
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['fantrax', 'manual'])],
            'pick_clock_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'pick_clock_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'pause_between_picks_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'auto_pick_enabled' => ['nullable', 'boolean'],
        ]);

        abort_if(
            $validated['mode'] === 'fantrax' && (string) $league->platform !== 'fantrax',
            422,
            'This league cannot connect a Fantrax draft.'
        );

        if ($validated['mode'] === 'fantrax') {
            $draftSync->sync((int) $league->id);
            $draft = $league->drafts()
                ->where('source_type', 'platform_mirror')
                ->latest('updated_at')
                ->first();

            abort_unless($draft instanceof Draft, 422, 'Fantrax did not return draft data for this league.');
        } else {
            $draft = Draft::query()->create([
                'platform_league_id' => (int) $league->id,
                'source_type' => 'hybrid',
                'platform' => (string) $league->platform,
                'external_draft_id' => 'dynastyiq:' . $league->id . ':' . Str::uuid()->toString(),
                'name' => $league->name . ' Draft',
                'status' => 'scheduled',
                'pick_clock_seconds' => array_key_exists('pick_clock_seconds', $validated)
                    ? max(0, (int) $validated['pick_clock_seconds'])
                    : $this->minutesToSeconds($validated['pick_clock_minutes'] ?? 5),
                'pause_between_picks_seconds' => (int) ($validated['pause_between_picks_seconds'] ?? 0),
                'auto_pick_enabled' => (bool) ($validated['auto_pick_enabled'] ?? false),
                'settings' => [
                    'created_from' => 'draft_central',
                    'mode' => 'manual',
                ],
            ]);
        }

        if ($validated['mode'] === 'fantrax') {
            $this->applyDraftSettings($draft, $validated);
        }

        $this->ensureDraftNotificationSettings($draft, $league);

        return response()->json([
            'message' => $validated['mode'] === 'fantrax'
                ? 'Fantrax draft connected.'
                : 'Manual draft created.',
            'html' => $this->draftPanelHtml($league, $user),
        ]);
    }

    /**
     * Update timer and automation options for the selected canonical draft.
     */
    public function updateDraftSettings(Request $request, string $leagueId, Draft $draft): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);
        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        abort_unless($this->canManageLeague($league, $user), 403);
        abort_unless((int) $draft->platform_league_id === (int) $league->id, 404);
        abort_if(
            $this->draftTimerIsCommunityManaged($league, $draft),
            409,
            'Draft timer settings are managed from the community league.'
        );

        $validated = $request->validate([
            'pick_clock_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'pick_clock_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'pause_between_picks_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'auto_pick_enabled' => ['nullable', 'boolean'],
        ]);

        $this->applyDraftSettings($draft, $validated);

        return response()->json([
            'message' => 'Draft settings saved.',
            'html' => $this->draftPanelHtml($league, $user),
        ]);
    }

    /**
     * Add a player to the authenticated user's draft queue.
     */
    public function storeDraftQueueItem(Request $request, string $leagueId, Draft $draft): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);
        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        abort_unless((int) $draft->platform_league_id === (int) $league->id, 404);

        $validated = $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
        ]);
        $playerId = (int) $validated['player_id'];

        abort_if(
            DraftPick::query()
                ->where('draft_id', $draft->id)
                ->where('player_id', $playerId)
                ->exists(),
            422,
            'This player has already been drafted.'
        );

        $rank = ((int) DraftQueueItem::query()
            ->where('draft_id', $draft->id)
            ->where('user_id', $user->id)
            ->max('rank')) + 1;

        $queueItem = DraftQueueItem::query()->firstOrCreate(
            [
                'draft_id' => $draft->id,
                'user_id' => $user->id,
                'player_id' => $playerId,
            ],
            [
                'rank' => $rank,
            ],
        );

        $queueItem->load('player');

        return response()->json([
            'item' => $this->draftQueueItemPayload($queueItem, (int) $league->id),
        ]);
    }

    /**
     * Return the authenticated user's enriched draft queue payload.
     */
    public function draftQueuePayload(Request $request, string $leagueId, Draft $draft): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);
        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        abort_unless((int) $draft->platform_league_id === (int) $league->id, 404);

        $items = $this->draftQueueItemsForUser($draft, (int) $user->id);

        return response()->json([
            'items' => $this->draftQueueItemsPayload($items, (int) $league->id),
        ]);
    }

    /**
     * Remove a player from the authenticated user's draft queue.
     */
    public function destroyDraftQueueItem(Request $request, string $leagueId, Draft $draft, DraftQueueItem $queueItem): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);
        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        abort_unless((int) $draft->platform_league_id === (int) $league->id, 404);
        abort_unless((int) $queueItem->draft_id === (int) $draft->id, 404);
        abort_unless((int) $queueItem->user_id === (int) $user->id, 403);

        $queueItem->delete();

        return response()->json([
            'deleted' => true,
            'item_id' => $queueItem->id,
            'player_id' => $queueItem->player_id,
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
    public function resync(
        Request $request,
        YahooFantasyLeagueService $leagueService,
        ConnectFantraxUser $fantraxConnector,
    ): JsonResponse
    {
        Gate::authorize('refresh-leagues');

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
                'api' => null,
            ],
        ];

        $connection = $user
            ->yahooFantasyConnection()
            ->where('status', 'connected')
            ->first();

        if ($connection) {
            $summary['yahoo'] = $leagueService->syncForConnection($connection, $user->id);
        }

        $fantraxSecret = IntegrationSecret::query()
            ->where('user_id', $user->id)
            ->where('provider', 'fantrax')
            ->where('status', 'connected')
            ->first();

        if ($fantraxSecret !== null) {
            try {
                $summary['fantrax']['api'] = $fantraxConnector->syncLeagues($user, $fantraxSecret->secret);
            } catch (\Throwable $e) {
                $summary['fantrax']['api'] = [
                    'error' => $e->getMessage(),
                ];
            }
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

    /**
     * Update whether the selected platform league appears in the current user's Leagues list.
     */
    public function updateVisibility(Request $request, string $leagueId): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            if (! $request->expectsJson()) {
                return redirect()
                    ->route('dashboard')
                    ->with('status', 'Connect a fantasy provider before updating league visibility.');
            }

            return response()->json([
                'message' => 'Connect a fantasy provider before updating league visibility.',
            ], 409);
        }

        $validated = $request->validate([
            'is_visible' => ['required', 'boolean'],
        ]);

        $updated = DB::table('league_user_teams')
            ->where('user_id', $user->id)
            ->where('platform_league_id', $leagueId)
            ->where('is_active', true)
            ->update([
                'is_visible' => (bool) $validated['is_visible'],
                'updated_at' => now(),
            ]);

        abort_if($updated === 0, 404);

        $message = (bool) $validated['is_visible']
            ? 'League shown in your list.'
            : 'League hidden from your list.';

        if (! $request->expectsJson()) {
            return redirect()
                ->route('leagues.index')
                ->with('status', $message);
        }

        return response()->json([
            'message' => $message,
            'league_id' => (int) $leagueId,
            'is_visible' => (bool) $validated['is_visible'],
        ]);
    }

    /**
     * Sync team logos for a commissioner-managed fantasy league.
     */
    public function syncTeamLogos(
        Request $request,
        string $leagueId,
        FantraxLogoSyncService $fantraxLogoSync,
        YahooFantasyLeagueService $yahooLeagueService,
    ): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before syncing team logos.',
            ], 409);
        }

        $league = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->firstOrFail();

        abort_unless($this->canManageLeague($league, $user), 403);

        if ($league->platform === 'fantrax') {
            $summary = $fantraxLogoSync->syncForPlatformLeagueIds([(int) $league->id]);

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
        } elseif ($league->platform === 'yahoo') {
            $connection = $user->yahooFantasyConnection()
                ->where('status', 'connected')
                ->first();

            if ($connection === null) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Connect Yahoo before syncing team logos.',
                ], 409);
            }

            $summary = $yahooLeagueService->syncLogosForLeague($connection, $league);

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

        $logoUrl = $this->leagueDisplayLogoUrl($league, $user);

        TeamLogosSynced::dispatch(
            (int) $user->id,
            (int) $league->id,
            (string) $league->platform,
            $logoUrl,
        );

        return response()->json([
            'ok' => true,
            'message' => $summary['updated_team_count'] > 0
                ? 'Team logos synced.'
                : 'Logo sync ran, but no team logos changed.',
            'platform_league_id' => (int) $league->id,
            'platform' => (string) $league->platform,
            'logo_url' => $logoUrl,
            'summary' => $summary,
        ]);
    }

    /**
     * Persist the current user's preferred Leagues list order.
     */
    public function updateOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        $leagueAccess = app(FantasyLeagueAccess::class);

        if (! $leagueAccess->canViewLeagues($user)) {
            return response()->json([
                'message' => 'Connect a fantasy provider before updating league order.',
            ], 409);
        }

        $validated = $request->validate([
            'league_ids' => ['required', 'array', 'min:1'],
            'league_ids.*' => ['integer', 'distinct'],
        ]);
        $leagueIds = collect($validated['league_ids'])
            ->map(static fn (int|string $leagueId): int => (int) $leagueId)
            ->values();
        $activeLeagueIds = DB::table('league_user_teams')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('platform_league_id', $leagueIds)
            ->pluck('platform_league_id')
            ->map(static fn (int|string $leagueId): int => (int) $leagueId)
            ->all();

        abort_if(count($activeLeagueIds) !== $leagueIds->count(), 404);

        DB::transaction(function () use ($leagueIds, $user): void {
            foreach ($leagueIds as $index => $leagueId) {
                DB::table('league_user_teams')
                    ->where('user_id', $user->id)
                    ->where('platform_league_id', $leagueId)
                    ->where('is_active', true)
                    ->update([
                        'sort_order' => $index + 1,
                        'updated_at' => now(),
                    ]);
            }
        });

        return response()->json([
            'message' => 'League order saved.',
            'league_ids' => $leagueIds->all(),
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

        $leagues = $leagueAccess->visibleLeaguesForUser($user)
            ->addSelect([
                'user_team_logo_url' => PlatformTeam::query()
                    ->select('platform_teams.logo_url')
                    ->join('league_user_teams as logo_lut', 'logo_lut.team_id', '=', 'platform_teams.id')
                    ->whereColumn('logo_lut.platform_league_id', 'platform_leagues.id')
                    ->where('logo_lut.user_id', $user->id)
                    ->where('logo_lut.is_active', true)
                    ->limit(1),
            ])
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->get();
        $leagueOptions = $leagueAccess->activeLeaguesForUser($user)
            ->addSelect([
                'user_team_logo_url' => PlatformTeam::query()
                    ->select('platform_teams.logo_url')
                    ->join('league_user_teams as logo_lut', 'logo_lut.team_id', '=', 'platform_teams.id')
                    ->whereColumn('logo_lut.platform_league_id', 'platform_leagues.id')
                    ->where('logo_lut.user_id', $user->id)
                    ->where('logo_lut.is_active', true)
                    ->limit(1),
            ])
            ->get();

        $activeLeague = $leagueAccess->activeLeaguesForUser($user)
            ->where('platform_leagues.id', $leagueId)
            ->with(['teams' => static fn ($q) => $q->orderBy('name')])
            ->first();
        abort_if($activeLeague === null, 404);
        $leagueSettings = $this->resolvedLeagueSettings($activeLeague, $user);
        $activeLeagueScope = $this->viewerFantraxScope($activeLeague, $user);

        return view('leagues', [
            'leagues' => $leagues,
            'leagueOptions' => $leagueOptions,
            'activeLeagueId' => $activeLeague->id,
            'activeLeague' => $activeLeague,
            'teams' => $this->teamsMetaPayload($activeLeague, $activeLeagueScope),
            'drafting' => $this->draftingPayload($activeLeague, $user, $activeLeagueScope),
            'scoringCategories' => $this->scoringCategoriesPayload($activeLeague),
            'scoringAlignmentCategories' => $this->scoringAlignmentCategoriesPayload($activeLeague),
            'manualScoringMappings' => $this->manualScoringMappingsPayload($activeLeague),
            'availableStatFields' => $this->availableStatFieldsPayload(),
            'scoringMappingOptions' => $this->scoringMappingOptionsPayload(),
            'searchPlayers' => [],
            'scoringSettingsUpdateUrl' => route('leagues.scoring-settings.update', $activeLeague->id),
            'capSettingsUpdateUrl' => route('leagues.cap-settings.update', $activeLeague->id),
            'capProjectionsUpdateUrl' => route('leagues.cap-projections.update', $activeLeague->id),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $activeLeague->id),
            'playersPayloadUrl' => route('leagues.players.payload', $activeLeague->id),
            'playersFreeAgentsPayloadUrl' => route('leagues.players.free-agents.payload', $activeLeague->id),
            'teamLogoSyncUrl' => route('leagues.team-logos.sync', $activeLeague->id),
            'customCap' => (bool) data_get($leagueSettings, 'settings.custom_cap', false),
            'salaryCap' => data_get($leagueSettings, 'settings.salary_cap'),
            'capLimitsBySeason' => data_get($leagueSettings, 'settings.cap_limits_by_season', []),
            'capAdjustmentsByTeam' => data_get($leagueSettings, 'settings.cap_adjustments_by_team', []),
            'maxActiveBuyouts' => data_get($leagueSettings, 'settings.max_active_buyouts'),
            'maxActiveRetentions' => data_get($leagueSettings, 'settings.max_active_retentions'),
            'buyoutExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.buyout_extra_payout_year', false),
            'retentionExtraPayoutYear' => (bool) data_get($leagueSettings, 'settings.retention_extra_payout_year', false),
            'leagueSettingsSource' => $leagueSettings['source'],
            'canEditLeagueSettings' => $leagueSettings['can_edit'],
            'fantraxContractCodes' => $this->fantraxContractCodesPayload(
                $activeLeague,
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
            'fantraxContractCodeDefinitions' => $this->fantraxContractCodeDefinitionsFromSettings(
                data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
            ),
            'isScoringFullyMapped' => $this->isScoringFullyMapped($activeLeague),
            'canShowLeagueStats' => $this->canShowLeagueStats($activeLeague),
            'canManageLeague' => $this->canManageLeague($activeLeague, $user),
        ]);
    }

    /**
     * Determine whether the current user can manage commissioner-only league settings.
     */
    private function canManageLeague($league, $user): bool
    {
        if (! $league || ! $user) {
            return false;
        }

        return app(PlatformLeagueSettingsResolver::class)->canManage($league, $user);
    }

    /**
     * Resolve current settings and edit authority for a league.
     *
     * @return array{settings:array<string,mixed>,source:string,can_edit:bool,has_league_admin:bool}
     */
    private function resolvedLeagueSettings($league, $user): array
    {
        return app(PlatformLeagueSettingsResolver::class)->resolve($league, $user);
    }

    /**
     * Return an empty league settings resolution for views without an active league.
     *
     * @return array{settings:array<string,mixed>,source:string,can_edit:bool,has_league_admin:bool}
     */
    private function emptyLeagueSettings(): array
    {
        return [
            'settings' => app(PlatformLeagueSettingsResolver::class)->defaults(),
            'source' => 'league_default',
            'can_edit' => false,
            'has_league_admin' => false,
        ];
    }

    /**
     * Determine whether this league should use provider custom salaries in Cap views.
     */
    private function customCapEnabled($league): bool
    {
        return (bool) data_get($league, 'settings.custom_cap', false);
    }

    /**
     * Return the saved league salary cap in whole dollars.
     */
    private function salaryCap($league): ?int
    {
        $value = data_get($league, 'settings.salary_cap');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Normalize a commissioner-entered salary-cap value into whole dollars.
     */
    private function normalizeSalaryCap(mixed $value): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $isMillions = str_ends_with(strtolower($raw), 'm');
        $normalized = preg_replace('/[^0-9.]/', '', $raw);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized;

        if ($isMillions) {
            $amount *= 1000000;
        }

        return $amount > 0 ? (int) round($amount) : null;
    }

    /**
     * Normalize a season cap limit; plain small numbers are entered as millions.
     */
    private function normalizeCapLimitAmount(mixed $value): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9.]/', '', $raw);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized;
        $usesMillionsSuffix = str_ends_with(strtolower($raw), 'm');

        if ($usesMillionsSuffix || $amount < 10000) {
            $amount *= 1000000;
        }

        return $amount > 0 ? (int) round($amount) : null;
    }

    /**
     * Current NHL cap season key, turning over on July 1.
     */
    private static function currentNhlSeasonKey(): int
    {
        $now = now();
        $startYear = (int) $now->month >= 7 ? (int) $now->year : (int) $now->year - 1;

        return ($startYear * 10000) + $startYear + 1;
    }

    /**
     * Projection-generated season keys, capped to three current/future seasons.
     *
     * @return Collection<int,int>
     */
    private static function projectionSeasonKeys(): Collection
    {
        $currentSeasonKey = self::currentNhlSeasonKey();

        return collect(range(0, 2))
            ->map(static function (int $offset) use ($currentSeasonKey): int {
                $startYear = intdiv($currentSeasonKey, 10000) + $offset;

                return ($startYear * 10000) + $startYear + 1;
            });
    }

    /**
     * Determine whether a player is eligible for projected cap planning.
     */
    private static function playerCanProjectExpiredContract(Player $player, int $seasonKey, int $currentSeasonKey): bool
    {
        $summary = self::expiredContractProjectionBasis($player, $currentSeasonKey);

        if ($summary === null) {
            return false;
        }

        return $seasonKey >= $currentSeasonKey && $seasonKey > (int) $summary['last_season_key'];
    }

    /**
     * Find the last expired contract cap-hit basis for a player with no active/future seasons.
     *
     * @return array{last_season_key:int,last_cap_hit:int}|null
     */
    private static function expiredContractProjectionBasis(Player $player, int $currentSeasonKey): ?array
    {
        $seasons = $player->contracts
            ->flatMap(static fn ($contract) => $contract->seasons)
            ->filter(static fn ($season): bool => is_numeric($season->season_key))
            ->sortBy('season_key')
            ->values();

        if ($seasons->isEmpty()) {
            return null;
        }

        $hasCurrentOrFutureSeason = $seasons->contains(
            static fn ($season): bool => (int) $season->season_key >= $currentSeasonKey,
        );

        if ($hasCurrentOrFutureSeason) {
            return null;
        }

        $lastSeason = $seasons->last();
        $lastCapHit = is_numeric($lastSeason->cap_hit) ? (int) $lastSeason->cap_hit : 0;

        if ((int) $lastSeason->season_key <= 0 || $lastCapHit <= 0) {
            return null;
        }

        return [
            'last_season_key' => (int) $lastSeason->season_key,
            'last_cap_hit' => $lastCapHit,
        ];
    }

    /**
     * Build user-owned projection values for a platform team.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function capProjectionPayloadForTeam($league, PlatformTeam $team, $user): array
    {
        $currentSeasonKey = self::currentNhlSeasonKey();
        $seasonKeys = self::projectionSeasonKeys();
        $saved = CapContractProjection::query()
            ->where('platform_league_id', (int) $league->id)
            ->where('platform_team_id', (int) $team->id)
            ->where('user_id', (int) $user->id)
            ->get()
            ->groupBy('player_id');
        $payload = [];

        foreach ($team->roster as $player) {
            if (! $player instanceof Player) {
                continue;
            }

            $basis = self::expiredContractProjectionBasis($player, $currentSeasonKey);

            if ($basis === null) {
                continue;
            }

            $playerSaved = $saved->get((int) $player->id, collect())->keyBy('season_key');
            $seasons = [];

            foreach ($seasonKeys as $seasonKey) {
                if ($seasonKey <= (int) $basis['last_season_key']) {
                    continue;
                }

                $row = $playerSaved->get($seasonKey);

                if (! $row) {
                    $row = CapContractProjection::query()->create([
                        'platform_league_id' => (int) $league->id,
                        'platform_team_id' => (int) $team->id,
                        'user_id' => (int) $user->id,
                        'player_id' => (int) $player->id,
                        'season_key' => $seasonKey,
                        'projected_aav' => (int) $basis['last_cap_hit'],
                        'source' => 'system',
                        'basis' => 'last_cap_hit',
                    ]);
                } elseif (
                    (string) $row->source === 'system'
                    && (
                        (int) $row->projected_aav !== (int) $basis['last_cap_hit']
                        || (string) $row->basis !== 'last_cap_hit'
                    )
                ) {
                    $row->forceFill([
                        'projected_aav' => (int) $basis['last_cap_hit'],
                        'basis' => 'last_cap_hit',
                    ])->save();
                }

                $projectedCapHit = (int) $row->projected_aav;
                $source = (string) $row->source;
                $basisKey = (string) $row->basis;

                $seasons[(string) $seasonKey] = [
                    'season_key' => $seasonKey,
                    'label' => self::seasonKeyLabel($seasonKey),
                    'projected_cap_hit' => $projectedCapHit,
                    'projected_cap_hit_label' => self::compactMoneyLabel($projectedCapHit),
                    'projected_aav' => $projectedCapHit,
                    'projected_aav_label' => self::compactMoneyLabel($projectedCapHit),
                    'source' => $source,
                    'basis' => $basisKey,
                ];
            }

            if ($seasons !== []) {
                $payload[(string) $player->id] = [
                    'player_id' => (int) $player->id,
                    'last_season_key' => (int) $basis['last_season_key'],
                    'last_cap_hit' => (int) $basis['last_cap_hit'],
                    'last_cap_hit_label' => self::compactMoneyLabel((int) $basis['last_cap_hit']),
                    'seasons' => $seasons,
                ];
            }
        }

        return $payload;
    }

    /**
     * Format an NHL season key as a short season label.
     */
    private static function seasonKeyLabel(int $seasonKey): string
    {
        $value = (string) $seasonKey;

        if (strlen($value) < 8) {
            return $value;
        }

        return substr($value, 0, 4) . '-' . substr($value, 6, 2);
    }

    /**
     * Normalize season-specific cap floors and ceilings.
     *
     * @param array<mixed,mixed> $limits
     * @return array<string,array{floor:int|null,ceiling:int|null}>
     */
    private function normalizeCapLimitsBySeason(array $limits): array
    {
        $normalized = [];

        foreach ($limits as $seasonKey => $values) {
            $seasonKey = $this->normalizeSeasonKey($seasonKey);

            if ($seasonKey === null || ! is_array($values)) {
                continue;
            }

            $floor = $this->normalizeCapLimitAmount($values['floor'] ?? null);
            $ceiling = $this->normalizeCapLimitAmount($values['ceiling'] ?? null);

            if ($floor === null && $ceiling === null) {
                continue;
            }

            $normalized[$seasonKey] = [
                'floor' => $floor,
                'ceiling' => $ceiling,
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Normalize team-scoped cap adjustments for buyouts and retentions.
     *
     * @param array<mixed,mixed> $adjustmentsByTeam
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function normalizeCapAdjustmentsByTeam(array $adjustmentsByTeam): array
    {
        $normalized = [];

        foreach ($adjustmentsByTeam as $teamId => $adjustments) {
            $teamId = trim((string) $teamId);

            if ($teamId === '' || ! is_array($adjustments)) {
                continue;
            }

            foreach ($adjustments as $adjustment) {
                if (! is_array($adjustment)) {
                    continue;
                }

                $type = strtolower(trim((string) ($adjustment['type'] ?? '')));

                if (! in_array($type, ['buyout', 'retention'], true)) {
                    continue;
                }

                $values = $this->normalizeAdjustmentValuesBySeason(
                    is_array($adjustment['values_by_season'] ?? null)
                        ? $adjustment['values_by_season']
                        : [],
                    $type,
                );

                if ($values === []) {
                    continue;
                }

                $id = trim((string) ($adjustment['id'] ?? ''));
                $playerId = $adjustment['player_id'] ?? null;

                $normalized[$teamId][] = [
                    'id' => $id !== '' ? $id : (string) Str::uuid(),
                    'type' => $type,
                    'player_id' => is_numeric($playerId) ? (int) $playerId : null,
                    'player_name' => trim((string) ($adjustment['player_name'] ?? '')),
                    'player_position' => trim((string) ($adjustment['player_position'] ?? '')),
                    'avatar_url' => trim((string) ($adjustment['avatar_url'] ?? '')),
                    'team_abbrev' => trim((string) ($adjustment['team_abbrev'] ?? '')),
                    'percent' => $type === 'retention'
                        ? max(-100, min(100, (float) ($adjustment['percent'] ?? 0)))
                        : max(0, min(100, abs((float) ($adjustment['percent'] ?? 0)))),
                    'start_season' => $this->normalizeSeasonKey($adjustment['start_season'] ?? null),
                    'end_season' => $this->normalizeSeasonKey($adjustment['end_season'] ?? null),
                    'payout_years' => max(1, (int) ($adjustment['payout_years'] ?? 1)),
                    'values_by_season' => $values,
                ];
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<mixed,mixed> $values
     * @return array<string,int>
     */
    private function normalizeAdjustmentValuesBySeason(array $values, string $type): array
    {
        $normalized = [];

        foreach ($values as $seasonKey => $value) {
            $seasonKey = $this->normalizeSeasonKey($seasonKey);
            $amount = $this->normalizeCapAdjustmentAmount($value);

            if ($seasonKey === null || $amount === null) {
                continue;
            }

            $normalized[$seasonKey] = $type === 'retention' ? $amount : abs($amount);
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Normalize a cap adjustment amount while preserving retention direction.
     */
    private function normalizeCapAdjustmentAmount(mixed $value): ?int
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $sign = str_starts_with($raw, '-') ? -1 : 1;
        $usesMillionsSuffix = str_ends_with(strtolower($raw), 'm');
        $normalized = preg_replace('/[^0-9.]/', '', $raw);

        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized;

        if ($usesMillionsSuffix || $amount < 10000) {
            $amount *= 1000000;
        }

        return $amount > 0 ? $sign * (int) round($amount) : null;
    }

    /**
     * Normalize a season key like 20262027.
     */
    private function normalizeSeasonKey(mixed $value): ?string
    {
        $raw = preg_replace('/[^0-9]/', '', (string) $value);

        if ($raw === null || strlen($raw) !== 8) {
            return null;
        }

        return $raw;
    }

    /**
     * Return saved Fantrax contract-code prefix meanings for this league.
     *
     * @return array<string,array{label:string,type:string,suffix_years_remaining:bool}>
     */
    private function fantraxContractCodeDefinitions($league): array
    {
        if (! $league) {
            return [];
        }

        return $this->fantraxContractCodeDefinitionsFromSettings(
            data_get($league, 'settings.fantrax_contract_code_definitions', []),
        );
    }

    /**
     * Return normalized Fantrax contract-code definitions from a settings value.
     *
     * @param mixed $definitions
     * @return array<string,array{label:string,type:string,suffix_years_remaining:bool}>
     */
    private function fantraxContractCodeDefinitionsFromSettings(mixed $definitions): array
    {
        return $this->normalizeFantraxContractCodeDefinitions(is_array($definitions) ? $definitions : []);
    }

    /**
     * Normalize user-defined Fantrax contract-code meanings keyed by prefix.
     *
     * @param array<mixed,mixed> $definitions
     * @return array<string,array{label:string,type:string,suffix_years_remaining:bool}>
     */
    private function normalizeFantraxContractCodeDefinitions(array $definitions): array
    {
        $normalized = [];

        foreach ($definitions as $prefix => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $prefix = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $prefix) ?? '');

            if ($prefix === '') {
                continue;
            }

            $label = trim((string) ($definition['label'] ?? ''));
            $type = trim((string) ($definition['type'] ?? ''));

            if ($label === '' && $type === '') {
                continue;
            }

            if ($type === '') {
                $type = Str::snake($label);
            }

            $type = Str::snake($type);

            $normalized[$prefix] = [
                'label' => $label !== '' ? $label : Str::headline(strtolower($type)),
                'type' => $type,
                'suffix_years_remaining' => (bool) ($definition['suffix_years_remaining'] ?? true),
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Return detected Fantrax contract-code prefixes from current roster metadata.
     *
     * @return array<int,array{prefix:string,count:int,examples:string,codes:array<int,array{code:string,count:int,years_remaining:int|null}>,definition:array<string,mixed>|null}>
     */
    private function fantraxContractCodesPayload($league, mixed $definitions = null): array
    {
        if (! $league || (string) ($league->platform ?? '') !== 'fantrax') {
            return [];
        }

        $definitions = $definitions === null
            ? $this->fantraxContractCodeDefinitions($league)
            : $this->fantraxContractCodeDefinitionsFromSettings($definitions);
        $codes = DB::table('platform_roster_memberships as prm')
            ->join('platform_teams as pt', 'pt.id', '=', 'prm.platform_team_id')
            ->where('pt.platform_league_id', (int) $league->id)
            ->whereNull('prm.ends_at')
            ->pluck('prm.metadata')
            ->map(static function (mixed $metadata): ?string {
                if (is_string($metadata)) {
                    $decoded = json_decode($metadata, true);
                    $metadata = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
                }

                $name = is_array($metadata) ? data_get($metadata, 'fantrax_contract.name') : null;

                return is_string($name) && trim($name) !== '' ? strtoupper(trim($name)) : null;
            })
            ->filter()
            ->countBy()
            ->map(static function (int $count, string $code): array {
                $prefix = $code;
                $yearsRemaining = null;

                if (preg_match('/^([A-Z]+)(\d+)$/', $code, $matches)) {
                    $prefix = $matches[1];
                    $yearsRemaining = (int) $matches[2];
                }

                return [
                    'code' => $code,
                    'prefix' => $prefix,
                    'count' => $count,
                    'years_remaining' => $yearsRemaining,
                ];
            })
            ->values()
            ->groupBy('prefix')
            ->map(function (Collection $rows, string $prefix) use ($definitions): array {
                $codeRows = $rows
                    ->sortBy('code')
                    ->values()
                    ->all();

                return [
                    'prefix' => $prefix,
                    'count' => (int) $rows->sum('count'),
                    'examples' => collect($codeRows)->pluck('code')->take(4)->implode(', '),
                    'codes' => $codeRows,
                    'definition' => $definitions[$prefix] ?? null,
                ];
            })
            ->sortBy('prefix')
            ->values()
            ->all();

        return $codes;
    }

    private function leagueDisplayLogoUrl($league, $user): ?string
    {
        if (! $league || ! $user) {
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
     * Build the team, avatar, ownership, and roster payload for a league.
     */
    private function teamsMetaPayload($league, ?array $scope = null): array
    {
        $authId = auth()->id();

        return $league->teams()
            ->select('id', 'platform_team_id', 'name', 'extras')
            ->with([
                'users' => static function ($q): void {
                    $q->wherePivot('is_active', true)
                        ->select('users.id')
                        ->with(['socialAccounts' => static function ($query): void {
                            $query->select('id', 'user_id', 'avatar')
                                ->where('provider', 'discord');
                        }]);
                },
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (PlatformTeam $team): bool => $this->teamMatchesViewerFantraxScope($team, $scope))
            ->map(static function ($team) use ($authId): array {
                $defaultAvatar = config('ui.default_team_avatar')
                    ?: 'https://ui-avatars.com/api/?name=' . urlencode($team->name) . '&background=E5E7EB&color=111827&size=64';
                $ownerAvatars = self::ownerAvatarUrls($team->users);
                $ownerAvatar = $ownerAvatars[0] ?? $defaultAvatar;

                $ownerIds = $team->users->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

                return [
                    'id' => (string) $team->platform_team_id,
                    'name' => (string) $team->name,
                    'owner_avatar_url' => $ownerAvatar,
                    'owner_avatar_urls' => $ownerAvatars,
                    'owned_by_me' => $authId ? in_array((int) $authId, $ownerIds, true) : false,
                    'owner_user_ids' => $ownerIds,
                    'fantrax_division' => data_get($team->extras, 'fantrax.division'),
                    'fantrax_pool' => data_get($team->extras, 'fantrax.pool'),
                    'players' => [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build ordered Discord avatar URLs for active team owners.
     *
     * @param iterable<int,\App\Models\User> $users
     * @return array<int,string>
     */
    private static function ownerAvatarUrls(iterable $users): array
    {
        $avatars = [];

        foreach ($users as $user) {
            $avatar = optional($user->socialAccounts->first())->avatar;

            if (filled($avatar)) {
                $avatars[] = (string) $avatar;
            }
        }

        return array_values(array_unique($avatars));
    }

    /**
     * Build the team, avatar, ownership, and roster payload for a league.
     */
    private function teamsPayload($league, bool $includeFreeAgents = true, $user = null, ?array $scope = null): array
    {
        $authId = auth()->id();
        $rosterSlots = $league->rosterSlots()
            ->get(['slot', 'count', 'sort_order']);
        $slotOrder = $rosterSlots
            ->pluck('sort_order', 'slot')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        $teams = $league->teams()
            ->select('id', 'platform_team_id', 'name', 'extras')
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
                    )
                        ->with([
                            'contracts' => static function ($query): void {
                                $query->with(['seasons' => static function ($query): void {
                                    $query->orderBy('season_key');
                                }]);
                            },
                        ])
                        ->withPivot(['platform', 'platform_player_id', 'slot', 'status', 'eligibility', 'metadata', 'starts_at', 'ends_at']);
                },
                'users' => static function ($q): void {
                    $q->wherePivot('is_active', true)
                        ->select('users.id')
                        ->with(['socialAccounts' => static function ($query): void {
                            $query->select('id', 'user_id', 'avatar')
                                ->where('provider', 'discord');
                        }]);
                },
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (PlatformTeam $team): bool => $this->teamMatchesViewerFantraxScope($team, $scope));

        $fantraxEligibility = $league->platform === 'fantrax'
            ? self::fantraxEligibilityByPlatformPlayerId($teams->pluck('roster')->flatten())
            : [];
        $leagueSettings = $user
            ? $this->resolvedLeagueSettings($league, $user)
            : ['settings' => is_array($league->settings) ? $league->settings : []];
        $fantraxContractDefinitions = $this->fantraxContractCodeDefinitionsFromSettings(
            data_get($leagueSettings, 'settings.fantrax_contract_code_definitions', []),
        );
        $providerStatsByPlayerId = app(PlatformLeaguePlayerStatService::class)->statsForLeagueByPlayerId($league);

        $teamRows = $teams
            ->map(static function ($t) use ($authId, $slotOrder, $rosterSlots, $fantraxEligibility, $fantraxContractDefinitions, $providerStatsByPlayerId, $league, $user): array {
                // default avatar per TEAM name
                $defaultAvatar = config('ui.default_team_avatar')
                    ?: 'https://ui-avatars.com/api/?name=' . urlencode($t->name) . '&background=E5E7EB&color=111827&size=64';

                $ownerAvatars = self::ownerAvatarUrls($t->users);
                $ownerAvatar = $ownerAvatars[0] ?? $defaultAvatar;

                $ownerIds = $t->users->pluck('id')->map(static fn ($v) => (int) $v)->values()->all();
                $ownedByMe = $authId ? in_array((int) $authId, $ownerIds, true) : false;

                $players = $t->roster
                    ->map(static function ($p) use ($slotOrder, $fantraxEligibility, $fantraxContractDefinitions, $providerStatsByPlayerId, $league, $t, $ownerAvatar): array {
                        $slot = (string) ($p->pivot->slot ?? '');
                        $rosterStatus = (string) ($p->pivot->status ?? '');
                        $eligibility = self::normalizeEligibility($p->pivot->eligibility);
                        $membershipMetadata = self::normalizeMembershipMetadata($p->pivot->metadata ?? null);
                        $fantraxSalary = self::fantraxSalaryPayload($membershipMetadata);
                        $fantraxContract = self::fantraxContractPayload($membershipMetadata, $fantraxContractDefinitions);
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
                        $rosterGroup = self::isMinorRosterRow($slot, $rosterStatus)
                            ? 'minor'
                            : 'active';
                        $rosterSortOrder = $rosterGroup === 'minor'
                            ? self::minorRosterPositionSortOrder($platformEligibility)
                            : ($slotOrder[$slot] ?? self::fallbackRosterSlotOrder($displaySlot));
                        $providerStats = $providerStatsByPlayerId[(int) $p->id] ?? [];

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
                            'fantasy_salary' => $fantraxSalary,
                            'fantasy_contract' => $membershipMetadata['fantrax_contract'] ?? null,
                            'fantasy_contract_code' => $fantraxContract,
                            'contract' => self::playerContractPayload($p),
                            'stats' => $providerStats,
                            'stats_source' => $providerStats !== [] ? 'provider' : null,
                        ];
                    });
                $slotCounts = $players
                    ->where('roster_group', 'active')
                    ->where('roster_status', 'active')
                    ->countBy('roster_slot');
                $placeholders = $rosterSlots
                    ->map(static function ($slotSetting) use ($slotCounts): array {
                        $slot = strtoupper(trim((string) $slotSetting->slot));

                        if (self::normalizedFallbackSlot($slot) === 'MIN') {
                            return [];
                        }

                        $missingCount = max(0, (int) $slotSetting->count - (int) ($slotCounts[$slot] ?? 0));

                        if ($missingCount === 0) {
                            return [];
                        }

                        return collect(range(1, $missingCount))
                            ->map(static fn (int $index): array => self::emptyRosterSlotPlayerPayload(
                                $slot,
                                (int) $slotSetting->sort_order,
                                $index,
                            ))
                            ->all();
                    })
                    ->flatten(1);
                $players = $players
                    ->concat($placeholders)
                    ->sortBy(static fn (array $player): string => sprintf(
                        '%03d-%03d-%03d-%s',
                        $player['roster_group_sort_order'],
                        $player['roster_sort_order'],
                        $player['roster_status_sort_order'],
                        $player['name'],
                    ))
                    ->values()
                    ->all();

                return [
                    'platform_team_record_id' => (int) $t->id,
                    'id'                => (string) $t->platform_team_id,
                    'name'              => (string) $t->name,
                    'owner_avatar_url'  => $ownerAvatar,
                    'owner_avatar_urls' => $ownerAvatars,
                    'owned_by_me'       => $ownedByMe,
                    'owner_user_ids'    => $ownerIds,
                    'fantrax_division'  => data_get($t->extras, 'fantrax.division'),
                    'fantrax_pool'      => data_get($t->extras, 'fantrax.pool'),
                    'cap_contract_projections' => $user
                        ? self::capProjectionPayloadForTeam($league, $t, $user)
                        : [],
                    'players'           => $players,
                ];
            })
            ->values()
            ->all();

        if (! $includeFreeAgents) {
            return $teamRows;
        }

        $freeAgents = $this->freeAgentsPayload($league, $scope);
        $allPlayers = collect($teamRows)
            ->pluck('players')
            ->flatten(1)
            ->reject(static fn (array $player): bool => (bool) ($player['league_roster_placeholder'] ?? false))
            ->concat($freeAgents)
            ->unique(static fn (array $player): int => (int) $player['id'])
            ->sortBy(static fn (array $player): string => (string) $player['name'])
            ->values()
            ->all();

        $teams = [[
            'platform_team_record_id' => null,
            'id' => '__all_players__',
            'name' => 'All Players',
            'owner_avatar_url' => null,
            'owner_avatar_urls' => [],
            'owned_by_me' => false,
            'owner_user_ids' => [],
            'cap_contract_projections' => [],
            'players' => $allPlayers,
        ], ...$teamRows];

        $teams[] = [
            'platform_team_record_id' => null,
            'id' => '__free_agents__',
            'name' => 'Free Agents',
            'owner_avatar_url' => null,
            'owner_avatar_urls' => [],
            'owned_by_me' => false,
            'owner_user_ids' => [],
            'cap_contract_projections' => [],
            'players' => $freeAgents,
        ];

        return $teams;
    }

    /**
     * Build the selected league draft panel from canonical draft state.
     */
    private function draftingPayload($league, $user = null, ?array $scope = null): array
    {
        $scope ??= $this->viewerFantraxScope($league, $user ?? auth()->user());
        $draftQuery = $league->drafts()
            ->with(['picks' => static fn ($query) => $query
                ->with(['player', 'platformTeam'])
                ->orderBy('overall_pick')
                ->orderBy('round')
                ->orderBy('pick_in_round')
                ->orderBy('provider_pick_key')]);
        $draft = (string) ($league->platform ?? '') === 'fantrax'
            ? (clone $draftQuery)->where('source_type', 'platform_mirror')->latest('updated_at')->first()
            : null;
        $draft ??= $draftQuery
            ->latest('updated_at')
            ->first();

        if (! $draft instanceof Draft) {
            $payload = app(FantraxDraftingWindow::class)->normalize([], []);
            $payload['empty_text'] = 'No draft has been configured for this league.';

            return $this->withDraftCentralMeta(
                $payload,
                $league,
                null,
            );
        }

        return $this->withDraftCentralMeta(
            $this->canonicalDraftingPayload($draft, (int) $league->id, $scope),
            $league,
            $draft,
        );
    }

    /**
     * Build the Draft Central payload exclusively from canonical draft tables.
     *
     * @return array<string,mixed>
     */
    private function canonicalDraftingPayload(Draft $draft, int $leagueId, ?array $scope = null): array
    {
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
            ->filter(fn (DraftPick $pick): bool => $this->draftPickMatchesViewerFantraxScope($pick, $scope))
            ->values();
        $providerPlayerIds = $picks
            ->pluck('provider_player_id')
            ->filter()
            ->map(static fn (mixed $playerId): string => (string) $playerId)
            ->unique()
            ->values()
            ->all();
        $canonicalPlayerIds = $picks
            ->pluck('player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values()
            ->all();
        $providerPlayerMap = $this->fantraxDraftPlayerMap($providerPlayerIds);
        $canonicalPlayerMap = $this->canonicalDraftPlayerMap($canonicalPlayerIds);
        $teamMap = $this->fantraxDraftTeamMap($leagueId, $scope);

        $rows = $picks
            ->map(function (DraftPick $pick) use ($providerPlayerMap, $canonicalPlayerMap, $teamMap): array {
                $providerPlayerId = $pick->provider_player_id ? (string) $pick->provider_player_id : '';
                $playerId = $pick->player_id ? (int) $pick->player_id : null;
                $mappedPlayer = $providerPlayerId !== ''
                    ? ($providerPlayerMap[$providerPlayerId] ?? [])
                    : [];
                $mappedPlayer = $mappedPlayer !== []
                    ? $mappedPlayer
                    : ($playerId ? ($canonicalPlayerMap[$playerId] ?? []) : []);
                $providerTeamId = $pick->provider_team_id ? (string) $pick->provider_team_id : '';
                $mappedTeam = $providerTeamId !== '' ? ($teamMap[$providerTeamId] ?? []) : [];
                $hasPlayer = $providerPlayerId !== '' || $playerId !== null;
                $rawPayload = is_array($pick->raw_payload) ? $pick->raw_payload : [];

                return [
                    'player_name' => $hasPlayer
                        ? ((string) ($mappedPlayer['name'] ?? '') ?: (string) ($pick->player?->full_name ?? 'Unknown player'))
                        : '',
                    'fantrax_player_id' => $providerPlayerId,
                    'is_picked' => $hasPlayer,
                    'player_id' => $playerId,
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
                    'team_avatar_url' => $mappedTeam['owner_avatar_url'] ?? null,
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
            ->values()
            ->all();
        $rounds = $this->draftRounds($rows);

        return [
            'available' => true,
            'title' => $draft->starts_at?->format('F j, Y') ?? $draft->name,
            'draft_at' => $draft->starts_at?->toIso8601String(),
            'last_pick_at' => $this->lastCanonicalDraftPickTimestamp($draft),
            'is_live' => (string) $draft->status === 'live',
            'status_text' => ucfirst((string) $draft->status),
            'status_tone' => match ((string) $draft->status) {
                'live' => 'green',
                'scheduled' => 'blue',
                default => 'slate',
            },
            'rows' => $rows,
            'rounds' => $rounds,
            'active_round_index' => $this->activeDraftRoundIndex($rounds),
            'empty_text' => 'No drafted players yet.',
            'error_text' => null,
        ];
    }

    /**
     * Build draft round groups from canonical draft rows.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array{round:int|null,label:string,count:int,rows:array<int,array<string,mixed>>}>
     */
    private function draftRounds(array $rows): array
    {
        return collect($rows)
            ->groupBy(static fn (array $row): string => $row['round'] === null ? 'unknown' : (string) $row['round'])
            ->map(static function ($roundRows, string $round): array {
                $roundNumber = $round === 'unknown' ? null : (int) $round;
                $rows = $roundRows->values()->all();

                return [
                    'round' => $roundNumber,
                    'label' => $roundNumber === null ? 'Round' : 'Round ' . $roundNumber,
                    'count' => count($rows),
                    'rows' => $rows,
                ];
            })
            ->sortBy(static fn (array $round): int => $round['round'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * Build a display-oriented contract payload for a roster player.
     *
     * @return array<string,mixed>|null
     */
    private static function playerContractPayload(Player $player): ?array
    {
        $contracts = $player->contracts
            ->filter(static fn ($contract): bool => $contract->seasons->isNotEmpty())
            ->sortBy(static function ($contract): int {
                return (int) ($contract->seasons->max('season_key') ?? 0);
            });

        if ($contracts->isEmpty()) {
            return null;
        }

        $seasonsByKey = collect();

        foreach ($contracts as $contract) {
            foreach ($contract->seasons->sortBy('season_key') as $season) {
                $seasonKey = $season->season_key !== null ? (int) $season->season_key : null;

                if ($seasonKey === null) {
                    continue;
                }

                $aav = is_numeric($season->aav) ? (int) $season->aav : null;
                $capHit = is_numeric($season->cap_hit) ? (int) $season->cap_hit : null;

                $seasonsByKey->put($seasonKey, [
                    'season_key' => $seasonKey,
                    'label' => (string) ($season->label ?? ''),
                    'aav' => $aav,
                    'aav_label' => self::compactMoneyLabel($aav),
                    'cap_hit' => $capHit,
                    'cap_hit_label' => self::compactMoneyLabel($capHit),
                ]);
            }
        }

        $seasons = $seasonsByKey
            ->sortKeys()
            ->values();

        if ($seasons->isEmpty()) {
            return null;
        }

        $contract = $contracts->last();
        $currentSeason = $seasons->first(static fn (array $season): bool => ($season['cap_hit'] ?? 0) > 0)
            ?? $seasons->first();
        $lastSeason = $seasons->last();

        return [
            'type' => (string) $contract->contract_type,
            'length' => (string) ($contract->contract_length ?? ''),
            'value' => $contract->contract_value !== null ? (int) $contract->contract_value : null,
            'value_label' => self::compactMoneyLabel($contract->contract_value !== null ? (int) $contract->contract_value : null),
            'expiry_status' => (string) ($contract->expiry_status ?? ''),
            'current_cap_hit' => $currentSeason['cap_hit'] ?? null,
            'current_cap_hit_label' => $currentSeason['cap_hit_label'] ?? '',
            'last_year' => $lastSeason['label'] ?? '',
            'last_season_key' => $lastSeason['season_key'] ?? null,
            'seasons' => $seasons->all(),
        ];
    }

    /**
     * Normalize provider roster membership metadata from the pivot payload.
     *
     * @return array<string,mixed>
     */
    private static function normalizeMembershipMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Build Fantrax custom salary display payload from membership metadata.
     *
     * @param array<string,mixed> $metadata
     *
     * @return array{raw:int,cap_hit:int,label:string}|null
     */
    private static function fantraxSalaryPayload(array $metadata): ?array
    {
        $salary = $metadata['fantrax_salary'] ?? null;

        if (! is_numeric($salary)) {
            return null;
        }

        $raw = (int) $salary;
        $capHit = $raw * 1000;

        return [
            'raw' => $raw,
            'cap_hit' => $capHit,
            'label' => self::compactMoneyLabel($capHit),
        ];
    }

    /**
     * Build normalized Fantrax contract-code display payload from membership metadata.
     *
     * @param array<string,mixed> $metadata
     * @param array<string,array<string,mixed>> $definitions
     *
     * @return array{code:string,prefix:string,years_remaining:int|null,label:string|null,type:string|null,suffix_years_remaining:bool}|null
     */
    private static function fantraxContractPayload(array $metadata, array $definitions): ?array
    {
        $code = data_get($metadata, 'fantrax_contract.name');

        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $code = strtoupper(trim($code));
        $prefix = $code;
        $yearsRemaining = null;

        if (preg_match('/^([A-Z]+)(\d+)$/', $code, $matches)) {
            $prefix = $matches[1];
            $yearsRemaining = (int) $matches[2];
        }

        $definition = $definitions[$prefix] ?? null;

        return [
            'code' => $code,
            'prefix' => $prefix,
            'years_remaining' => $yearsRemaining,
            'label' => $definition['label'] ?? null,
            'type' => $definition['type'] ?? null,
            'suffix_years_remaining' => (bool) ($definition['suffix_years_remaining'] ?? false),
        ];
    }

    /**
     * Build an empty roster-slot placeholder row for roster-shaped league views.
     *
     * @return array<string,mixed>
     */
    private static function emptyRosterSlotPlayerPayload(string $slot, int $sortOrder, int $index): array
    {
        $slot = strtoupper(trim($slot));
        $status = match ($slot) {
            'BN' => 'bench',
            'IR', 'IR+' => 'ir',
            'NA' => 'na',
            default => 'active',
        };

        return [
            'id' => sprintf('__empty_%s_%d', $slot, $index),
            'first_name' => '',
            'last_name' => '',
            'name' => 'Open slot',
            'position' => '',
            'age' => null,
            'pos_type' => '',
            'team_abbrev' => '',
            'avatar_url' => '',
            'is_goalie' => false,
            'status' => '',
            'fantasy_team_id' => '',
            'fantasy_team_name' => '',
            'fantasy_team_avatar_url' => '',
            'roster_slot' => $slot,
            'roster_status' => $status,
            'roster_group' => 'active',
            'eligibility' => [],
            'starts_at' => '',
            'ends_at' => '',
            'roster_sort_order' => $sortOrder,
            'roster_group_sort_order' => 0,
            'roster_status_sort_order' => match ($status) {
                'active' => 10,
                'bench' => 20,
                'ir' => 30,
                'na' => 40,
                default => 90,
            },
            'fantasy_salary' => null,
            'fantasy_contract' => null,
            'fantasy_contract_code' => null,
            'contract' => null,
            'stats' => [],
            'stats_source' => null,
            'league_roster_placeholder' => true,
        ];
    }

    /**
     * Format contract money in compact cap-table style.
     */
    private static function compactMoneyLabel(?int $value): string
    {
        if ($value === null || $value <= 0) {
            return '-';
        }

        return '$' . number_format($value / 1_000_000, 2) . 'M';
    }

    /**
     * Pick the active round from the canonical current pick marker.
     *
     * @param array<int,array{round:int|null,label:string,count:int,rows:array<int,array<string,mixed>>}> $rounds
     */
    private function activeDraftRoundIndex(array $rounds): int
    {
        foreach ($rounds as $index => $round) {
            foreach ($round['rows'] as $row) {
                if (! empty($row['is_next_pick'])) {
                    return $index;
                }
            }
        }

        return max(0, count($rounds) - 1);
    }

    /**
     * Build player display metadata directly from canonical players.
     *
     * @param array<int,int> $playerIds
     *
     * @return array<int,array<string,mixed>>
     */
    private function canonicalDraftPlayerMap(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $players = Player::query()
            ->whereIn('id', $playerIds)
            ->get(['id', 'full_name', 'nhl_id', 'position', 'head_shot_url', 'dob']);
        $latestStatsByPlayerId = $this->latestStatsByPlayerId($playerIds);
        $nextSeasonStatsByPlayerId = $this->seasonStatsByPlayerId($playerIds, $this->draftCommitSeasonId());

        return $players
            ->mapWithKeys(function (Player $player) use ($latestStatsByPlayerId, $nextSeasonStatsByPlayerId): array {
                $playerId = (int) $player->id;
                $latestStats = $latestStatsByPlayerId[$playerId] ?? null;
                $nextSeasonStats = $nextSeasonStatsByPlayerId[$playerId] ?? null;

                return [
                    $playerId => [
                        'name' => $player->full_name,
                        'player_id' => $playerId,
                        'nhl_id' => $player->nhl_id ? (int) $player->nhl_id : null,
                        'position' => $player->position,
                        'age' => $player->age(),
                        'league_abbrev' => $latestStats?->league_abbrev,
                        'team_abbrev' => $latestStats?->nhl_team_abbrev,
                        'avatar_url' => $player->head_shot_url,
                        'next_season' => $nextSeasonStats ? [
                            'season_id' => (string) $nextSeasonStats->season_id,
                            'label' => $this->seasonLabel((string) $nextSeasonStats->season_id),
                            'team_name' => (string) ($nextSeasonStats->team_name ?? ''),
                        ] : null,
                        'stats' => [
                            'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                            'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                            'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                            'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                            'wins' => $latestStats?->wins !== null ? (int) $latestStats->wins : null,
                            'sv_pct' => $latestStats?->sv_pct !== null ? (float) $latestStats->sv_pct : null,
                        ],
                    ],
                ];
            })
            ->all();
    }

    /**
     * Add Draft Central metadata used by the league Draft tab.
     */
    private function withDraftCentralMeta(array $payload, $league, ?Draft $draft): array
    {
        $isCompletedDraft = $draft instanceof Draft && (string) $draft->status === 'complete';
        $communityTimer = $draft instanceof Draft
            ? $this->communityDraftTimerSettings($league)
            : [];
        $communityPickClockSeconds = $communityTimer['pick_clock_seconds'] ?? null;
        $pickClockSeconds = $communityPickClockSeconds !== null
            ? (int) $communityPickClockSeconds
            : ($draft instanceof Draft
            ? (int) ($draft->pick_clock_seconds ?? 300)
            : 0);
        $lastPickAt = $payload['last_pick_at'] ?? null;
        $lastPickAt = is_string($lastPickAt) && $lastPickAt !== ''
            ? $lastPickAt
            : $this->lastCanonicalDraftPickTimestamp($draft);
        $expiresAt = null;

        if (! $isCompletedDraft && $draft instanceof Draft && $pickClockSeconds > 0 && is_string($lastPickAt) && $lastPickAt !== '') {
            try {
                $expiresAt = \Carbon\CarbonImmutable::parse($lastPickAt)
                    ->addSeconds($pickClockSeconds)
                    ->toIso8601String();
            } catch (\Throwable) {
                $expiresAt = null;
            }
        }

        $payload['has_canonical_draft'] = $draft instanceof Draft;
        $payload['draft_id'] = $draft?->id;
        $payload['source_type'] = $draft?->source_type;
        $currentPick = $isCompletedDraft
            ? null
            : ($draft instanceof Draft ? $draft->currentPick()->first() : null);

        if (! $this->payloadHasNextPickMarker($payload)) {
            $payload = $this->markCurrentDraftPick($payload, $currentPick);
        }

        if ($isCompletedDraft) {
            $payload = $this->clearCurrentDraftPick($payload);
        }

        $payload['pick_clock_seconds'] = $pickClockSeconds;
        $payload['pick_clock_hours'] = intdiv($pickClockSeconds, 3600);
        $payload['pick_clock_minutes'] = $pickClockSeconds > 0
            ? intdiv($pickClockSeconds % 3600, 60)
            : 5;
        $payload['pick_clock_seconds_remainder'] = $pickClockSeconds % 60;
        $payload['pause_between_picks_seconds'] = (int) (
            $communityTimer['pause_between_picks_seconds']
            ?? ($draft?->pause_between_picks_seconds ?? 0)
        );
        $payload['auto_pick_enabled'] = (bool) (
            $communityTimer['auto_pick_enabled']
            ?? ($draft?->auto_pick_enabled ?? false)
        );
        $payload['timer_read_only'] = $draft instanceof Draft
            ? $this->draftTimerIsCommunityManaged($league, $draft)
            : false;
        $payload['timer_read_only_message'] = $payload['timer_read_only']
            ? 'Timer settings are managed from the community league.'
            : '';
        $payload['countdown_started_at'] = $lastPickAt;
        $payload['countdown_expires_at'] = $expiresAt;
        $payload['draft_commit_season_label'] = $this->seasonLabel($this->draftCommitSeasonId());
        $payload['create_url'] = route('leagues.drafts.store', $league->id);
        $payload['settings_url'] = $draft instanceof Draft
            ? route('leagues.drafts.settings.update', [$league->id, $draft->id])
            : null;
        $payload['queue_store_url'] = $draft instanceof Draft
            ? route('leagues.drafts.queue.store', [$league->id, $draft->id])
            : null;
        $payload['queue_payload_url'] = $draft instanceof Draft
            ? route('leagues.drafts.queue.payload', [$league->id, $draft->id])
            : null;
        $payload['queue_items'] = $draft instanceof Draft
            ? $this->draftQueueItemsForUser($draft, (int) auth()->id())
                ->pipe(fn ($items): array => $this->draftQueueItemsPayload($items, (int) $league->id))
            : [];

        return $payload;
    }

    /**
     * Remove current-pick markers from completed draft payloads.
     */
    private function clearCurrentDraftPick(array $payload): array
    {
        unset($payload['current_pick']);

        $payload['rows'] = collect($payload['rows'] ?? [])
            ->map(static function (array $row): array {
                $row['is_next_pick'] = false;

                return $row;
            })
            ->values()
            ->all();

        $payload['rounds'] = collect($payload['rounds'] ?? [])
            ->map(static function (array $round): array {
                $round['rows'] = collect($round['rows'] ?? [])
                    ->map(static function (array $row): array {
                        $row['is_next_pick'] = false;

                        return $row;
                    })
                    ->values()
                    ->all();

                return $round;
            })
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Return the latest canonical picked-row timestamp usable for the draft clock.
     */
    private function lastCanonicalDraftPickTimestamp(?Draft $draft): ?string
    {
        if (! $draft instanceof Draft) {
            return null;
        }

        $pick = DraftPick::query()
            ->where('draft_id', $draft->id)
            ->whereNotNull('provider_player_id')
            ->orderByRaw('COALESCE(picked_at, detected_at) desc')
            ->first(['picked_at', 'detected_at']);

        $timestamp = $pick?->picked_at ?? $pick?->detected_at;

        return $timestamp?->toIso8601String();
    }

    /**
     * Determine whether a draft payload already marks the current pick.
     */
    private function payloadHasNextPickMarker(array $payload): bool
    {
        if (collect($payload['rows'] ?? [])->contains(static fn (array $row): bool => ! empty($row['is_next_pick']))) {
            return true;
        }

        return collect($payload['rounds'] ?? [])
            ->flatMap(static fn (array $round): array => $round['rows'] ?? [])
            ->contains(static fn (array $row): bool => ! empty($row['is_next_pick']));
    }

    /**
     * Mark the actual canonical current pick in the draft payload.
     */
    private function markCurrentDraftPick(array $payload, ?DraftPick $currentPick): array
    {
        if (! $currentPick) {
            return $payload;
        }

        $matched = false;
        $payload['current_pick'] = [
            'id' => (int) $currentPick->id,
            'provider_pick_key' => (string) $currentPick->provider_pick_key,
            'overall_pick' => $currentPick->overall_pick !== null ? (int) $currentPick->overall_pick : null,
            'round' => $currentPick->round !== null ? (int) $currentPick->round : null,
            'pick_in_round' => $currentPick->pick_in_round !== null ? (int) $currentPick->pick_in_round : null,
        ];

        $payload['rows'] = collect($payload['rows'] ?? [])
            ->map(function (array $row) use ($currentPick, &$matched): array {
                $isCurrent = $this->draftPayloadRowMatchesPick($row, $currentPick);

                $row['is_next_pick'] = $isCurrent;
                $matched = $matched || $isCurrent;

                return $row;
            })
            ->values()
            ->all();

        $payload['rounds'] = collect($payload['rounds'] ?? [])
            ->map(function (array $round) use ($currentPick): array {
                $round['rows'] = collect($round['rows'] ?? [])
                    ->map(function (array $row) use ($currentPick): array {
                        $row['is_next_pick'] = $this->draftPayloadRowMatchesPick($row, $currentPick);

                        return $row;
                    })
                    ->values()
                    ->all();

                return $round;
            })
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Determine whether a normalized draft row represents the canonical current pick.
     */
    private function draftPayloadRowMatchesPick(array $row, DraftPick $pick): bool
    {
        if (trim((string) ($row['provider_pick_key'] ?? '')) !== '') {
            return (string) $row['provider_pick_key'] === (string) $pick->provider_pick_key;
        }

        if ($pick->overall_pick !== null && (int) ($row['overall_pick'] ?? 0) === (int) $pick->overall_pick) {
            return true;
        }

        return $pick->round !== null
            && $pick->pick_in_round !== null
            && (int) ($row['round'] ?? 0) === (int) $pick->round
            && (int) ($row['pick_in_round'] ?? $row['pick'] ?? 0) === (int) $pick->pick_in_round;
    }

    /**
     * Format a queue item for Draft Central JSON.
     *
     * @param mixed $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function draftQueueItemsPayload($items, int $leagueId): array
    {
        $playerIds = collect($items)
            ->pluck('player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values()
            ->all();
        $latestStatsByPlayerId = $this->latestStatsByPlayerId($playerIds);

        return collect($items)
            ->map(fn (DraftQueueItem $item): array => $this->draftQueueItemPayload($item, $leagueId, $latestStatsByPlayerId))
            ->values()
            ->all();
    }

    /**
     * Return queue items for a user, excluding players already picked in this draft.
     *
     * @return Collection<int,DraftQueueItem>
     */
    private function draftQueueItemsForUser(Draft $draft, int $userId): Collection
    {
        $draftedPlayerIds = DraftPick::query()
            ->where('draft_id', $draft->id)
            ->whereNotNull('player_id')
            ->pluck('player_id')
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->all();

        return DraftQueueItem::query()
            ->with('player')
            ->where('draft_id', $draft->id)
            ->where('user_id', $userId)
            ->when($draftedPlayerIds !== [], static function ($query) use ($draftedPlayerIds): void {
                $query->whereNotIn('player_id', $draftedPlayerIds);
            })
            ->orderBy('rank')
            ->get();
    }

    /**
     * Format a queue item for Draft Central JSON.
     *
     * @return array<string,mixed>
     */
    private function draftQueueItemPayload(DraftQueueItem $item, int $leagueId, array $latestStatsByPlayerId = []): array
    {
        $player = $item->player;
        $latestStats = $latestStatsByPlayerId[(int) $item->player_id] ?? null;

        return [
            'id' => (int) $item->id,
            'draft_id' => (int) $item->draft_id,
            'player_id' => (int) $item->player_id,
            'rank' => (int) $item->rank,
            'name' => (string) ($player?->full_name ?? trim(($player?->first_name ?? '') . ' ' . ($player?->last_name ?? ''))),
            'position' => (string) ($player?->position ?? ''),
            'team_abbrev' => (string) ($latestStats?->nhl_team_abbrev ?? $player?->team_abbrev ?? ''),
            'league_abbrev' => (string) ($latestStats?->league_abbrev ?? ''),
            'age' => $player?->age(),
            'avatar_url' => (string) ($player?->head_shot_url ?? ''),
            'stats' => [
                'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                'wins' => $latestStats?->wins !== null ? (int) $latestStats->wins : null,
                'sv_pct' => $latestStats?->sv_pct !== null ? (float) $latestStats->sv_pct : null,
            ],
            'delete_url' => route('leagues.drafts.queue.destroy', [$leagueId, $item->draft_id, $item->id]),
        ];
    }

    /**
     * Render the Draft tab partial after an AJAX draft mutation.
     */
    private function draftPanelHtml($league, $user): string
    {
        $league = $league->fresh();
        $scope = $this->viewerFantraxScope($league, $user);

        return view('leagues._draft-panel', [
            'league' => $league,
            'teams' => $this->teamsMetaPayload($league, $scope),
            'drafting' => $this->draftingPayload($league, $user, $scope),
            'canManageLeague' => $this->canManageLeague($league, $user),
            'playersPayloadUrl' => route('leagues.players.payload', $league->id),
            'playersFreeAgentsPayloadUrl' => route('leagues.players.free-agents.payload', $league->id),
            'leagueStatsPayloadUrl' => route('leagues.stats.payload', $league->id),
            'canShowLeagueStats' => $this->canShowLeagueStats($league),
        ])->render();
    }

    /**
     * Ensure the neutral notification settings row exists for newly created drafts.
     */
    private function ensureDraftNotificationSettings(Draft $draft, $league): void
    {
        $channel = $this->legacyDraftNotificationChannel($league);
        $channelId = is_array($channel) ? trim((string) ($channel['id'] ?? '')) : '';
        $channelName = is_array($channel) ? trim((string) ($channel['name'] ?? '')) : '';

        $settings = DraftNotificationSetting::query()->firstOrNew(['draft_id' => $draft->id]);

        if ($settings->exists && $settings->discord_channel_id) {
            return;
        }

        if ($settings->exists && $channelId === '') {
            return;
        }

        $settings->fill([
            'discord_channel_id' => $channelId !== '' ? $channelId : null,
            'discord_channel_name' => $channelName !== '' ? $channelName : null,
            'enabled' => $channelId !== '',
            'settings' => [
                'source' => $channelId !== '' ? 'legacy_community_league_meta' : 'draft_central',
            ],
        ])->save();
    }

    /**
     * Find the legacy community-league draft Discord channel for this platform league.
     *
     * @return array<string,mixed>|null
     */
    private function legacyDraftNotificationChannel($league): ?array
    {
        $metaRows = DB::table('league_platform_league')
            ->join('organization_leagues', 'organization_leagues.league_id', '=', 'league_platform_league.league_id')
            ->where('league_platform_league.platform_league_id', (int) $league->id)
            ->where('league_platform_league.status', 'active')
            ->pluck('organization_leagues.meta');

        foreach ($metaRows as $meta) {
            $decoded = is_string($meta) && $meta !== '' ? json_decode($meta, true) : null;
            $channel = is_array($decoded) ? data_get($decoded, 'draft_notifications.discord_channel') : null;

            if (is_array($channel) && filled($channel['id'] ?? null)) {
                return $channel;
            }
        }

        return null;
    }

    private function draftTimerIsCommunityManaged($league, Draft $draft): bool
    {
        return array_key_exists('pick_clock_seconds', $this->communityDraftTimerSettings($league));
    }

    /**
     * Return community-level draft timer settings for a platform league.
     *
     * @return array<string,mixed>
     */
    private function communityDraftTimerSettings($league): array
    {
        $metaRows = DB::table('league_platform_league')
            ->join('organization_leagues', 'organization_leagues.league_id', '=', 'league_platform_league.league_id')
            ->where('league_platform_league.platform_league_id', (int) $league->id)
            ->where('league_platform_league.status', 'active')
            ->pluck('organization_leagues.meta');

        foreach ($metaRows as $meta) {
            $decoded = is_string($meta) && $meta !== '' ? json_decode($meta, true) : null;
            $timer = is_array($decoded) ? data_get($decoded, 'draft_timer') : null;

            if (is_array($timer) && array_key_exists('pick_clock_seconds', $timer)) {
                return $timer;
            }
        }

        return [];
    }

    /**
     * Persist draft timer settings to the canonical draft row.
     *
     * @param array<string,mixed> $settings
     */
    private function applyDraftSettings(Draft $draft, array $settings): void
    {
        $draft->forceFill([
            'pick_clock_seconds' => array_key_exists('pick_clock_seconds', $settings)
                ? max(0, (int) $settings['pick_clock_seconds'])
                : $this->minutesToSeconds($settings['pick_clock_minutes'] ?? 5),
            'pause_between_picks_seconds' => (int) ($settings['pause_between_picks_seconds'] ?? 0),
            'auto_pick_enabled' => (bool) ($settings['auto_pick_enabled'] ?? false),
        ])->save();
    }

    private function minutesToSeconds(mixed $minutes): int
    {
        return max(0, (int) $minutes) * 60;
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
            ->with('player:id,full_name,nhl_id,position,head_shot_url,dob')
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
        $nextSeasonStatsByPlayerId = $this->seasonStatsByPlayerId($playerIds, $this->draftCommitSeasonId());

        $fantraxPlayers->each(function (FantraxPlayer $fantraxPlayer) use (&$map, $latestStatsByPlayerId, $nextSeasonStatsByPlayerId): void {
            $playerId = $fantraxPlayer->player_id ? (int) $fantraxPlayer->player_id : null;
            $latestStats = $playerId ? ($latestStatsByPlayerId[$playerId] ?? null) : null;
            $nextSeasonStats = $playerId ? ($nextSeasonStatsByPlayerId[$playerId] ?? null) : null;

            $map[(string) $fantraxPlayer->fantrax_id] = [
                'name' => $fantraxPlayer->name ?: $fantraxPlayer->player?->full_name,
                'player_id' => $playerId,
                'nhl_id' => $fantraxPlayer->player?->nhl_id ? (int) $fantraxPlayer->player->nhl_id : null,
                'position' => $fantraxPlayer->player?->position ?: $fantraxPlayer->position,
                'age' => $fantraxPlayer->player?->age(),
                'league_abbrev' => $latestStats?->league_abbrev,
                'team_abbrev' => $latestStats?->nhl_team_abbrev,
                'avatar_url' => $fantraxPlayer->player?->head_shot_url,
                'next_season' => $nextSeasonStats ? [
                    'season_id' => (string) $nextSeasonStats->season_id,
                    'label' => $this->seasonLabel((string) $nextSeasonStats->season_id),
                    'team_name' => (string) ($nextSeasonStats->team_name ?? ''),
                ] : null,
                'stats' => [
                    'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                    'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                    'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                    'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                    'wins' => $latestStats?->wins !== null ? (int) $latestStats->wins : null,
                    'sv_pct' => $latestStats?->sv_pct !== null ? (float) $latestStats->sv_pct : null,
                ],
            ];
        });

        $externalIdentities = PlayerExternalIdentity::query()
            ->with('player:id,full_name,nhl_id,position,head_shot_url,dob')
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
        $identityNextSeasonStatsByPlayerId = $this->seasonStatsByPlayerId($identityPlayerIds, $this->draftCommitSeasonId());

        $externalIdentities->each(function (PlayerExternalIdentity $identity) use (&$map, $identityLatestStatsByPlayerId, $identityNextSeasonStatsByPlayerId): void {
            $fantraxId = (string) $identity->provider_player_id;
            $existing = $map[$fantraxId] ?? [];
            $playerId = $identity->player_id ? (int) $identity->player_id : null;
            $latestStats = $playerId ? ($identityLatestStatsByPlayerId[$playerId] ?? null) : null;
            $nextSeasonStats = $playerId ? ($identityNextSeasonStatsByPlayerId[$playerId] ?? null) : null;

            $map[$fantraxId] = [
                'name' => $existing['name'] ?? $identity->display_name ?? $identity->player?->full_name,
                'player_id' => $existing['player_id'] ?? $playerId,
                'nhl_id' => $existing['nhl_id'] ?? ($identity->player?->nhl_id ? (int) $identity->player->nhl_id : null),
                'position' => $existing['position'] ?? $identity->position ?? $identity->player?->position,
                'age' => $existing['age'] ?? $identity->player?->age(),
                'league_abbrev' => $existing['league_abbrev'] ?? $latestStats?->league_abbrev,
                'team_abbrev' => $existing['team_abbrev'] ?? $latestStats?->nhl_team_abbrev,
                'avatar_url' => $existing['avatar_url'] ?? $identity->player?->head_shot_url,
                'next_season' => $existing['next_season'] ?? ($nextSeasonStats ? [
                    'season_id' => (string) $nextSeasonStats->season_id,
                    'label' => $this->seasonLabel((string) $nextSeasonStats->season_id),
                    'team_name' => (string) ($nextSeasonStats->team_name ?? ''),
                ] : null),
                'stats' => $this->hasResolvedStats($existing['stats'] ?? null)
                    ? $existing['stats']
                    : [
                        'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                        'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                        'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                        'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                        'wins' => $latestStats?->wins !== null ? (int) $latestStats->wins : null,
                        'sv_pct' => $latestStats?->sv_pct !== null ? (float) $latestStats->sv_pct : null,
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

        return collect(['gp', 'g', 'a', 'pts', 'wins', 'sv_pct'])
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
            ->get([
                'player_id',
                'league_abbrev',
                'nhl_team_abbrev',
                'gp',
                'g',
                'a',
                'pts',
                'wins',
                'sv_pct',
                'season_id',
                'updated_at',
            ])
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
     * Return the most-used stat snapshot from a specific season.
     *
     * @param array<int,int> $playerIds
     *
     * @return array<int,Stat>
     */
    private function seasonStatsByPlayerId(array $playerIds, string $seasonId): array
    {
        if ($playerIds === [] || $seasonId === '') {
            return [];
        }

        return Stat::query()
            ->whereIn('player_id', $playerIds)
            ->where('season_id', $seasonId)
            ->orderByDesc('gp')
            ->orderByDesc('updated_at')
            ->get(['player_id', 'team_name', 'season_id', 'gp', 'updated_at'])
            ->groupBy(static fn (Stat $stat): int => (int) $stat->player_id)
            ->mapWithKeys(static function ($playerStats): array {
                $stat = $playerStats
                    ->sortByDesc(static fn (Stat $stat): int => (int) $stat->gp)
                    ->first();

                return $stat ? [(int) $stat->player_id => $stat] : [];
            })
            ->all();
    }

    /**
     * Return the season label/id used for drafted-player commitment display.
     */
    private function draftCommitSeasonId(): string
    {
        $now = now();
        $startYear = $now->month >= 6 ? $now->year : $now->year - 1;

        return (string) $startYear . (string) ($startYear + 1);
    }

    /**
     * Format an eight-digit season id as a display label.
     */
    private function seasonLabel(string $seasonId): string
    {
        if (preg_match('/^(\d{4})(\d{4})$/', $seasonId, $matches) !== 1) {
            return $seasonId;
        }

        return $matches[1] . '-' . substr($matches[2], -2);
    }

    /**
     * Build drafting team owner avatar metadata keyed by Fantrax platform team id.
     *
     * @return array<string,array{owner_avatar_url:string|null}>
     */
    private function fantraxDraftTeamMap(?int $platformLeagueId, ?array $scope = null): array
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
            ->get(['id', 'platform_team_id', 'name', 'extras'])
            ->filter(fn (PlatformTeam $team): bool => $this->teamMatchesViewerFantraxScope($team, $scope))
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
                        'team_name' => (string) $team->name,
                        'owner_avatar_url' => filled($avatar) ? (string) $avatar : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * Build free-agent player rows for the selected platform league.
     */
    private function freeAgentsPayload($league, ?array $scope = null): array
    {
        $teamIdsQuery = $league->teams()
            ->select('id', 'extras');
        $teamIds = $teamIdsQuery
            ->get()
            ->filter(fn (PlatformTeam $team): bool => $this->teamMatchesViewerFantraxScope($team, $scope))
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
            ->with(['contracts.seasons'])
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
                    'contract' => self::playerContractPayload($player),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Build searchable rows for the viewer's scoped league context.
     *
     * @param array{scope:string,division:string|null}|null $scope
     * @param array<int,array<string,mixed>> $freeAgents
     *
     * @return array<int,array<string,mixed>>
     */
    private function searchPlayersPayloadForScope($league, $user, ?array $scope, array $freeAgents): array
    {
        if (($scope['scope'] ?? null) !== 'division') {
            return $this->searchPlayersPayload();
        }

        $rosteredPlayers = collect($this->teamsPayload($league, false, $user, $scope))
            ->pluck('players')
            ->flatten(1)
            ->reject(static fn (array $player): bool => (bool) ($player['league_roster_placeholder'] ?? false));

        return $rosteredPlayers
            ->concat($freeAgents)
            ->unique(static fn (array $player): int => (int) $player['id'])
            ->sortBy(static fn (array $player): string => (string) $player['name'])
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
        $categories = app(PlatformLeagueScoringCategoryService::class)->payloadRows($league);

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
                    'dictionary_provider_label' => $category['dictionary_provider_label'] ?? null,
                    'alignment_status' => $category['alignment_status'] ?? null,
                    'formula' => $category['formula'] ?? null,
                    'required_schema_columns' => $category['required_schema_columns'] ?? [],
                    'is_supported' => $category['is_supported'] ?? filled($category['stat_key'] ?? null),
                    'support_message' => $category['support_message'] ?? null,
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
        $settings = app(PlatformLeagueScoringCategoryService::class)->payloadRows($league);

        return collect($settings)
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->map(function (array $category): array {
                return [
                    'id' => (string) ($category['id'] ?? $category['label'] ?? $category['name'] ?? ''),
                    'label' => (string) ($category['label'] ?? $category['short'] ?? $category['name'] ?? ''),
                    'name' => (string) ($category['name'] ?? ''),
                    'short' => (string) ($category['short'] ?? ''),
                    'value' => $category['points'] ?? $category['value'] ?? null,
                    'auto_stat_key' => $category['auto_stat_key'] ?? null,
                    'auto_mapping_key' => $category['auto_mapping_key'] ?? $this->autoMappingKey($category),
                    'selected_mapping_key' => $category['selected_mapping_key'] ?? null,
                    'stat_key' => $category['stat_key'] ?? null,
                    'is_mapped' => filled($category['stat_key'] ?? null),
                    'mapping_source' => $category['mapping_source'] ?? null,
                    'dictionary_provider_label' => $category['dictionary_provider_label'] ?? null,
                    'alignment_status' => $category['alignment_status'] ?? null,
                    'formula' => $category['formula'] ?? null,
                    'required_schema_columns' => $category['required_schema_columns'] ?? [],
                    'is_supported' => $category['is_supported'] ?? filled($category['stat_key'] ?? null),
                    'support_message' => $category['support_message'] ?? null,
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
        return app(PlatformLeagueScoringCategoryService::class)->manualMappings($league);
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
     * Build stat and dictionary scoring options exposed to the scoring alignment combobox.
     *
     * @return array<int,array<string,mixed>>
     */
    private function scoringMappingOptionsPayload(): array
    {
        $statOptions = collect($this->availableStatFieldsPayload())
            ->map(static fn (array $field): array => [
                'key' => 'stat:' . $field['key'],
                'type' => 'stat',
                'label' => $field['label'],
                'description' => $field['key'],
                'stat_key' => $field['key'],
                'formula' => $field['key'],
                'alignment_status' => 'direct',
                'required_schema_columns' => [$field['key']],
                'is_supported' => true,
                'support_message' => null,
            ]);

        if (! Schema::hasTable('fantasy_scoring_category_mappings')) {
            return $statOptions->values()->all();
        }

        $dictionaryOptions = FantasyScoringCategoryMapping::query()
            ->where('platform', 'fantrax')
            ->orderBy('provider_label')
            ->get()
            ->map(static function (FantasyScoringCategoryMapping $mapping): array {
                $columns = is_array($mapping->required_schema_columns)
                    ? array_values($mapping->required_schema_columns)
                    : [];

                return [
                    'key' => 'dictionary:fantrax:' . $mapping->provider_label,
                    'type' => 'dictionary',
                    'platform' => 'fantrax',
                    'label' => $mapping->provider_label,
                    'description' => $mapping->formula ?: implode(', ', $columns),
                    'stat_key' => $mapping->alignment_status === 'direct' ? ($columns[0] ?? null) : null,
                    'formula' => $mapping->formula,
                    'alignment_status' => $mapping->alignment_status,
                    'required_schema_columns' => $columns,
                    'is_supported' => in_array($mapping->alignment_status, ['direct', 'formula', 'ignored_deprecated'], true),
                    'support_message' => match ($mapping->alignment_status) {
                        'unsupported' => $mapping->unavailable_reason ?: 'This category is not currently supported.',
                        'planned_derivation' => 'This category needs a derived DynastyIQ stat before it is fully supported.',
                        default => null,
                    },
                ];
            });

        return $statOptions
            ->concat($dictionaryOptions)
            ->values()
            ->all();
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
        $options = collect($this->scoringMappingOptionsPayload())->keyBy('key');

        return collect($categories)
            ->map(function (array $category) use ($manualMappings, $options): array {
                $statId = (string) ($category['id'] ?? '');
                $manualMappingKey = $manualMappings[$statId] ?? null;
                $autoMappingKey = $category['auto_mapping_key'] ?? $this->autoMappingKey($category);
                $selectedMappingKey = $manualMappingKey ?: $autoMappingKey;
                $option = $selectedMappingKey ? $options->get($selectedMappingKey) : null;

                $category['auto_mapping_key'] = $autoMappingKey;
                $category['selected_mapping_key'] = $manualMappingKey ?: null;

                if (is_array($option)) {
                    $category['stat_key'] = $option['stat_key'] ?? null;
                    $category['is_mapped'] = filled($category['stat_key']) || ($option['type'] ?? null) === 'dictionary';
                    $category['mapping_source'] = $manualMappingKey
                        ? 'manual'
                        : (($option['type'] ?? null) === 'dictionary' ? 'dictionary' : 'auto');
                    $category['dictionary_provider_label'] = ($option['type'] ?? null) === 'dictionary'
                        ? ($option['label'] ?? null)
                        : ($category['dictionary_provider_label'] ?? null);
                    $category['alignment_status'] = $option['alignment_status'] ?? $category['alignment_status'] ?? null;
                    $category['formula'] = $option['formula'] ?? $category['formula'] ?? null;
                    $category['required_schema_columns'] = $option['required_schema_columns'] ?? $category['required_schema_columns'] ?? [];
                    $category['is_supported'] = (bool) ($option['is_supported'] ?? false);
                    $category['support_message'] = $option['support_message'] ?? null;
                }

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
        $categories = app(PlatformLeagueScoringCategoryService::class)->payloadRows($league);

        if ($categories === []) {
            return false;
        }

        return collect($categories)
            ->filter(static fn (mixed $category): bool => is_array($category))
            ->every(static fn (array $category): bool => (bool) ($category['is_supported'] ?? false)
                || filled($category['stat_key'] ?? null));
    }

    /**
     * Normalize old raw stat keys into the newer scoring mapping key format.
     */
    private function normalizeScoringMappingKey(string $value): string
    {
        $value = trim($value);

        if ($value === '' || str_contains($value, ':')) {
            return $value;
        }

        return 'stat:' . $value;
    }

    /**
     * Infer the automatic scoring mapping key for an imported league category.
     *
     * @param array<string,mixed> $category
     */
    private function autoMappingKey(array $category): ?string
    {
        if (! empty($category['dictionary_provider_label'])) {
            return 'dictionary:fantrax:' . $category['dictionary_provider_label'];
        }

        if (! empty($category['auto_stat_key'])) {
            return 'stat:' . $category['auto_stat_key'];
        }

        return null;
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
        if (self::isMinorRosterRow($slot, $status)) {
            return collect($eligibility)
                ->map(static fn (mixed $value): string => self::normalizedMinorPosition((string) $value))
                ->first(static fn (string $value): bool => in_array($value, ['C', 'LW', 'RW', 'D', 'G'], true))
                ?: self::normalizedMinorPosition($position)
                ?: 'MIN';
        }

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
