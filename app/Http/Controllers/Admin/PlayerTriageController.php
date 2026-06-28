<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapWagesPlayer;
use App\Models\Contract;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\NhlTeamReference;
use App\Services\PlayerIdentityNormalizer;
use App\Services\PlayerIdentityResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

/**
 * Provides the admin inbox for manually triaging provider player identities.
 */
class PlayerTriageController extends Controller
{
    /**
     * Statuses shown in the default decision inbox.
     *
     * @var array<int,string>
     */
    private const DEFAULT_INBOX_STATUSES = [
        PlayerExternalIdentity::STATUS_CONFLICT,
        PlayerExternalIdentity::STATUS_CANDIDATE,
        PlayerExternalIdentity::STATUS_UNMATCHED,
    ];

    /**
     * All supported provider identity statuses.
     *
     * @var array<int,string>
     */
    private const ALL_STATUSES = [
        PlayerExternalIdentity::STATUS_CONFLICT,
        PlayerExternalIdentity::STATUS_CANDIDATE,
        PlayerExternalIdentity::STATUS_UNMATCHED,
        PlayerExternalIdentity::STATUS_MATCHED,
        PlayerExternalIdentity::STATUS_IGNORED,
    ];

    /**
     * All supported provider identity sources.
     *
     * @var array<int,string>
     */
    private const ALL_PROVIDERS = [
        PlayerExternalIdentity::PROVIDER_NHL,
        PlayerExternalIdentity::PROVIDER_FANTRAX,
        PlayerExternalIdentity::PROVIDER_CAPWAGES,
        PlayerExternalIdentity::PROVIDER_ELITEPROSPECTS,
    ];

    public function __construct(
        private readonly PlayerIdentityNormalizer $normalizer,
        private readonly PlayerIdentityResolver $resolver,
        private readonly NhlTeamReference $teams,
    ) {
    }

    /**
     * Render the manual triage inbox.
     */
    public function index(Request $request): View
    {
        return view('admin.player-triage-page', $this->viewData($request));
    }

    /**
     * Build the manual triage inbox view data.
     *
     * @return array<string, mixed>
     */
    public function viewData(Request $request): array
    {
        $providers = $this->providerOptions();
        $filters = $this->filtersFromRequest($request, $providers);
        $identityQuery = $this->identityQuery($filters);
        $inboxCount = (clone $identityQuery)->count();
        $identities = $identityQuery
            ->with('player')
            ->limit(250)
            ->get();

        $recommendations = $this->recommendationsForIdentities($identities, null);

        if ($filters['low_confidence_only']) {
            $identities = $this->lowConfidenceIdentities($identities, $recommendations);
            $inboxCount = $identities->count();
        } else {
            $identities = $identities->take(75)->values();
        }

        $selectedIdentity = $this->selectedIdentity($request, $identities);
        $recommendations = $this->recommendationsForIdentities($identities, $selectedIdentity);
        $candidatePlayers = $selectedIdentity
            ? $this->candidatePlayers($selectedIdentity)
            : collect();
        $playerSearchResults = $selectedIdentity
            ? $this->playerSearchResults($request, $selectedIdentity)
            : collect();
        $matchingSourceCandidates = $selectedIdentity
            ? $this->matchingSourceCandidates($selectedIdentity, $filters)
            : collect();
        $matchingSourceSearchResults = $selectedIdentity
            ? $this->matchingSourceSearchResults($request, $filters, $selectedIdentity)
            : collect();
        $selectedMatchingSourceIdentity = $selectedIdentity
            ? $this->selectedMatchingSourceIdentity($selectedIdentity, $filters)
            : null;
        $linkedSourceIdentities = $selectedIdentity
            ? $this->linkedSourceIdentities($selectedIdentity)
            : collect();
        $suggestedExternalMatches = $selectedIdentity
            ? $this->suggestedExternalMatches($selectedIdentity)
            : collect();
        $currentContract = $selectedIdentity?->player
            ? $this->currentContractForIdentity($selectedIdentity)
            : null;

        return [
            'candidatePlayers' => $candidatePlayers,
            'currentContract' => $currentContract,
            'filters' => $filters,
            'identities' => $identities,
            'inboxCount' => $inboxCount,
            'linkedSourceIdentities' => $linkedSourceIdentities,
            'matchingSourceCandidates' => $matchingSourceCandidates,
            'matchingSourceSearchResults' => $matchingSourceSearchResults,
            'playerSearchResults' => $playerSearchResults,
            'providers' => $providers,
            'recommendations' => $recommendations,
            'selectedIdentity' => $selectedIdentity,
            'selectedMatchingSourceIdentity' => $selectedMatchingSourceIdentity,
            'statusCounts' => $this->statusCounts(),
            'statuses' => self::ALL_STATUSES,
            'suggestedExternalMatches' => $suggestedExternalMatches,
        ];
    }

    /**
     * Link an external identity to the selected canonical player.
     */
    public function link(Request $request, PlayerExternalIdentity $identity): RedirectResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'integer', 'exists:players,id'],
        ]);

        $player = Player::findOrFail((int) $data['player_id']);
        $this->resolver->linkIdentityToPlayer($identity, $player);

        return $this->redirectToSelected($identity)->with('status', 'Identity linked');
    }

    /**
     * Link a matching-source identity to the selected identity's canonical player.
     */
    public function linkMatchingSource(Request $request, PlayerExternalIdentity $identity): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'matching_identity_id' => ['required', 'integer', 'exists:player_external_identities,id'],
        ]);

        if ($identity->player_id === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Source identity has no canonical player',
                ], 422);
            }

            return $this->redirectToSelected($identity)->with('status', 'Source identity has no canonical player');
        }

        $matchingIdentity = PlayerExternalIdentity::findOrFail((int) $data['matching_identity_id']);
        $player = Player::findOrFail((int) $identity->player_id);

        $this->resolver->linkIdentityToPlayer($matchingIdentity, $player);
        $matchingIdentity->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Matching source linked',
                'matched_identity' => $this->externalIdentityPayload($matchingIdentity),
                'linked_identities' => $this->linkedSourceIdentities($identity)
                    ->map(fn (PlayerExternalIdentity $linkedIdentity) => $this->externalIdentityPayload($linkedIdentity))
                    ->values(),
            ]);
        }

        return $this->redirectToSelected($identity)->with('status', 'Matching source linked');
    }

    /**
     * Link another external identity to the selected identity's canonical player.
     */
    public function linkExternalSource(Request $request, PlayerExternalIdentity $identity): RedirectResponse
    {
        $data = $request->validate([
            'external_identity_id' => ['required', 'integer', 'exists:player_external_identities,id'],
        ]);

        if ($identity->player_id === null) {
            return $this->redirectToSelected($identity)->with('status', 'Link the selected identity to a canonical player first');
        }

        $externalIdentity = PlayerExternalIdentity::findOrFail((int) $data['external_identity_id']);
        $player = Player::findOrFail((int) $identity->player_id);
        $allowedIds = $this->suggestedExternalMatches($identity)->pluck('id');

        if (! $allowedIds->contains($externalIdentity->id)) {
            return $this->redirectToSelected($identity)->with('status', 'External source is not a suggested match');
        }

        $this->resolver->linkIdentityToPlayer($externalIdentity, $player);

        return $this->redirectToSelected($identity)->with('status', 'External source linked');
    }

    /**
     * Apply the current resolver recommendation to one provider identity.
     */
    public function resolve(PlayerExternalIdentity $identity): RedirectResponse
    {
        $identity = $this->resolver->resolveNonAuthorityIdentity($identity);

        return $this->redirectToSelected($identity)->with(
            'status',
            "Resolver applied: {$identity->match_status}",
        );
    }

    /**
     * Mark an identity as ignored so it leaves the default triage inbox.
     */
    public function ignore(PlayerExternalIdentity $identity): RedirectResponse
    {
        $identity->update([
            'player_id' => null,
            'match_status' => PlayerExternalIdentity::STATUS_IGNORED,
            'match_confidence' => null,
            'unmatched_reason' => null,
        ]);

        return $this->redirectToSelected($identity)->with('status', 'Identity ignored');
    }

    /**
     * Keep an identity unresolved without changing its current match state.
     */
    public function defer(PlayerExternalIdentity $identity): RedirectResponse
    {
        return $this->redirectToSelected($identity)->with('status', 'Identity left in triage');
    }

    /**
     * Create a minimal canonical player from an external identity.
     */
    public function createCanonical(Request $request, PlayerExternalIdentity $identity): RedirectResponse
    {
        $data = $request->validate([
            'external_identity_ids' => ['array'],
            'external_identity_ids.*' => ['integer', 'exists:player_external_identities,id'],
        ]);

        if ($identity->player_id !== null) {
            return $this->redirectToSelected($identity)->with('status', 'Identity already linked');
        }

        if ($this->candidatePlayers($identity)->isNotEmpty()) {
            return $this->redirectToSelected($identity)->with('status', 'Review canonical candidates before creating a new player');
        }

        $allowedIds = $this->suggestedExternalMatches($identity)
            ->pluck('id')
            ->map(static fn (int|string $id) => (int) $id);

        $selectedIds = collect($data['external_identity_ids'] ?? [])
            ->map(static fn (int|string $id) => (int) $id)
            ->unique()
            ->values();

        $selectedExternalMatches = collect();
        if ($selectedIds->isNotEmpty()) {
            $selectedExternalMatches = PlayerExternalIdentity::query()
                ->whereIn('id', $selectedIds->intersect($allowedIds)->all())
                ->whereNull('player_id')
                ->get();
        }

        $player = Player::create($this->playerAttributesFromIdentity(
            $identity,
            collect([$identity])->merge($selectedExternalMatches),
        ));
        $this->resolver->linkIdentityToPlayer($identity, $player);

        if ($selectedIds->isNotEmpty()) {
            $selectedExternalMatches->each(
                fn (PlayerExternalIdentity $externalIdentity) => $this->resolver->linkIdentityToPlayer(
                    $externalIdentity,
                    $player,
                ),
            );
        }

        return $this->redirectToSelected($identity)->with('status', 'Canonical player created');
    }

    /**
     * Build normalized filters from query parameters.
     *
     * @return array{
     *     statuses: array<int,string>,
     *     provider: string|null,
     *     source: string|null,
     *     matching_source: string|null,
     *     matched: bool,
     *     reason: string|null,
     *     search: string|null,
     *     include_resolved: bool,
     *     low_confidence_only: bool
     * }
     */
    private function filtersFromRequest(Request $request, array $providers): array
    {
        $requestedStatuses = array_values(array_intersect(
            (array) $request->input('statuses', []),
            self::ALL_STATUSES,
        ));
        $includeResolved = $request->boolean('include_resolved');
        $source = in_array($request->query('source'), $providers, true)
            ? (string) $request->query('source')
            : null;
        $matchingSource = in_array($request->query('matching_source'), $providers, true)
            ? (string) $request->query('matching_source')
            : null;
        $usesSourceComparison = $source !== null && $matchingSource !== null;
        $usesSourceMatchedFilter = $source !== null && $matchingSource === null && $request->boolean('matched');
        $usesSourceFilter = $source !== null;

        return [
            'statuses' => $requestedStatuses !== []
                ? $requestedStatuses
                : ($includeResolved || $usesSourceFilter || $usesSourceComparison || $usesSourceMatchedFilter
                    ? self::ALL_STATUSES
                    : self::DEFAULT_INBOX_STATUSES),
            'provider' => in_array($request->query('provider'), self::ALL_PROVIDERS, true)
                ? (string) $request->query('provider')
                : null,
            'source' => $source,
            'matching_source' => $matchingSource,
            'matched' => $request->boolean('matched'),
            'reason' => trim((string) $request->query('reason')) ?: null,
            'search' => trim((string) $request->query('search')) ?: null,
            'include_resolved' => $includeResolved,
            'low_confidence_only' => $source === null && $requestedStatuses === [] && ! $includeResolved,
        ];
    }

    /**
     * Query provider identities for the inbox.
     *
     * @param array<string,mixed> $filters
     * @return Builder<PlayerExternalIdentity>
     */
    private function identityQuery(array $filters): Builder
    {
        return PlayerExternalIdentity::query()
            ->whereIn('match_status', $filters['statuses'])
            ->when(
                $filters['provider'],
                static fn (Builder $query, string $provider) => $query->where('provider', $provider),
            )
            ->when($filters['source'], static function (Builder $query, string $provider) use ($filters): void {
                $query->where('provider', $provider);

                if ($filters['matching_source'] === null) {
                    if ($filters['matched']) {
                        $query->whereNotNull('player_id');
                        return;
                    }

                    $query->whereNull('player_id');
                    return;
                }

                $query->whereNotNull('player_id');
                $matchingPlayerIds = PlayerExternalIdentity::query()
                    ->where('provider', $filters['matching_source'])
                    ->whereNotNull('player_id')
                    ->select('player_id');

                if ($filters['matched']) {
                    $query->whereIn('player_id', $matchingPlayerIds);
                    return;
                }

                $query->whereNotIn('player_id', $matchingPlayerIds);
            })
            ->when(
                $filters['reason'],
                static fn (Builder $query, string $reason) => $query->where('unmatched_reason', $reason),
            )
            ->when($filters['search'], static function (Builder $query, string $search): void {
                $term = mb_strtolower($search);

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->whereRaw('LOWER(display_name) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(provider_player_id) LIKE ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(provider_slug) LIKE ?', ["%{$term}%"]);
                });
            })
            ->orderByRaw(
                "CASE match_status WHEN 'conflict' THEN 0 WHEN 'candidate' THEN 1 WHEN 'unmatched' THEN 2 WHEN 'matched' THEN 3 ELSE 4 END",
            )
            ->latest('last_seen_at')
            ->latest('updated_at');
    }

    /**
     * List providers currently represented by external identity rows.
     *
     * @return array<int,string>
     */
    private function providerOptions(): array
    {
        return PlayerExternalIdentity::query()
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
    }

    /**
     * Resolve the selected identity from the current inbox result set.
     *
     * @param Collection<int,PlayerExternalIdentity> $identities
     */
    private function selectedIdentity(Request $request, Collection $identities): ?PlayerExternalIdentity
    {
        $requestedId = $request->integer('identity');

        if ($requestedId > 0) {
            $identity = PlayerExternalIdentity::with('player')->find($requestedId);

            if ($identity) {
                return $identity;
            }
        }

        return $identities->first();
    }

    /**
     * Find likely canonical players for a selected identity.
     *
     * @return Collection<int,Player>
     */
    private function candidatePlayers(PlayerExternalIdentity $identity): Collection
    {
        if ($identity->player_id !== null) {
            return collect();
        }

        if ($identity->normalized_name === null && $identity->display_name === null) {
            return collect();
        }

        return Player::query()
            ->select(['id', 'full_name', 'first_name', 'last_name', 'dob', 'position', 'team_abbrev', 'nhl_id'])
            ->when(
                $identity->birthdate !== null,
                static fn (Builder $query) => $query->orderByRaw(
                    'CASE WHEN dob = ? THEN 0 ELSE 1 END',
                    [$identity->birthdate->toDateString()],
                ),
            )
            ->get()
            ->filter(function (Player $player) use ($identity): bool {
                if ($identity->normalized_name !== null) {
                    return $this->normalizer->normalizeName($player->full_name) === $identity->normalized_name;
                }

                return str_contains(
                    mb_strtolower($player->full_name ?? ''),
                    mb_strtolower((string) $identity->display_name),
                );
            })
            ->take(8)
            ->values();
    }

    /**
     * Search canonical players for manual link decisions.
     *
     * @return Collection<int,Player>
     */
    private function playerSearchResults(Request $request, PlayerExternalIdentity $identity): Collection
    {
        $search = trim((string) $request->query('player_search'));

        if ($search === '') {
            return collect();
        }

        $term = mb_strtolower($search);
        $positionType = $this->identityPositionType($identity->position);

        $query = Player::query()
            ->select(['id', 'full_name', 'first_name', 'last_name', 'dob', 'position', 'team_abbrev', 'nhl_id'])
            ->whereDoesntHave(
                'externalIdentities',
                static fn (Builder $query) => $query->where('provider', $identity->provider),
            )
            ->where(function (Builder $query) use ($term, $search): void {
                $query->whereRaw('LOWER(full_name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$term}%"]);

                if (ctype_digit($search)) {
                    $query->orWhere('nhl_id', (int) $search);
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($positionType !== null) {
            $query->where(function (Builder $inner) use ($positionType): void {
                foreach ($this->positionsForType($positionType) as $position) {
                    $inner->orWhere('position', $position);
                }
            });
        }

        return $query->limit(10)->get();
    }

    /**
     * Suggest matching-source identities for a selected linked source identity.
     *
     * @param array<string,mixed> $filters
     * @return Collection<int,PlayerExternalIdentity>
     */
    private function matchingSourceCandidates(PlayerExternalIdentity $identity, array $filters): Collection
    {
        if ($identity->player_id === null || $filters['matching_source'] === null) {
            return collect();
        }

        return $this->matchingSourceIdentityQuery($filters['matching_source'], $identity)
            ->get()
            ->sortByDesc(fn (PlayerExternalIdentity $candidate) => $this->matchingSourceCandidateScore($identity, $candidate))
            ->take(8)
            ->values();
    }

    /**
     * Find the selected identity's linked identity from the active matching source.
     *
     * @param array<string,mixed> $filters
     */
    private function selectedMatchingSourceIdentity(PlayerExternalIdentity $identity, array $filters): ?PlayerExternalIdentity
    {
        if ($identity->player_id === null || $filters['matching_source'] === null) {
            return null;
        }

        return PlayerExternalIdentity::query()
            ->where('provider', $filters['matching_source'])
            ->where('player_id', $identity->player_id)
            ->orderBy('display_name')
            ->first();
    }

    /**
     * Return provider identities linked to the selected canonical player.
     * @return Collection<int,PlayerExternalIdentity>
     */
    private function linkedSourceIdentities(PlayerExternalIdentity $identity): Collection
    {
        if ($identity->player_id === null) {
            return collect();
        }

        return PlayerExternalIdentity::query()
            ->where('player_id', $identity->player_id)
            ->orderBy('provider')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Suggest unlinked external identities from other providers as supporting match evidence.
     *
     * @return Collection<int,PlayerExternalIdentity>
     */
    private function suggestedExternalMatches(PlayerExternalIdentity $identity): Collection
    {
        if ($identity->normalized_name === null) {
            return collect();
        }

        $sourcePositionType = $this->identityPositionType($identity->position);
        $compactName = str_replace(' ', '', $identity->normalized_name);

        return PlayerExternalIdentity::query()
            ->where('id', '!=', $identity->id)
            ->where('provider', '!=', $identity->provider)
            ->whereNull('player_id')
            ->whereNotNull('normalized_name')
            ->where(function (Builder $query) use ($identity, $compactName): void {
                $query->where('normalized_name', $identity->normalized_name)
                    ->orWhereRaw("REPLACE(normalized_name, ' ', '') = ?", [$compactName]);
            })
            ->when($sourcePositionType !== null, function (Builder $query) use ($sourcePositionType): void {
                $query->where(function (Builder $inner) use ($sourcePositionType): void {
                    foreach ($this->positionsForType($sourcePositionType) as $position) {
                        $inner->orWhere('position', $position);
                    }
                });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('display_name')
                    ->orWhereRaw('LOWER(display_name) != ?', ['team']);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('position')
                    ->orWhereRaw('LOWER(position) != ?', ['tm']);
            })
            ->orderBy('provider')
            ->orderBy('display_name')
            ->limit(12)
            ->get()
            ->filter(function (PlayerExternalIdentity $candidate) use ($identity): bool {
                if ($identity->display_name === null || $candidate->display_name === null) {
                    return true;
                }

                return $this->normalizer->normalizeName($candidate->display_name)
                    === $this->normalizer->normalizeName($identity->display_name);
            })
            ->values();
    }

    /**
     * Return the most recent contract linked to the selected identity's canonical player.
     */
    private function currentContractForIdentity(PlayerExternalIdentity $identity): ?Contract
    {
        return $identity->player?->contracts()
            ->with('seasons')
            ->latest('signing_date')
            ->latest('id')
            ->first();
    }

    /**
     * Build minimal canonical player attributes from one external identity.
     *
     * @param Collection<int,PlayerExternalIdentity>|null $linkedIdentities
     * @return array<string,mixed>
     */
    private function playerAttributesFromIdentity(PlayerExternalIdentity $identity, ?Collection $linkedIdentities = null): array
    {
        [$firstName, $lastName] = $this->namePartsForPlayer($identity);
        $positionType = $this->identityPositionType($identity->position);
        $birthdate = $identity->birthdate?->toDateString()
            ?? $this->capWagesBirthdateForIdentities($linkedIdentities ?? collect([$identity]));

        return [
            'nhl_id' => null,
            'nhl_team_id' => $this->teams->idForAbbrev($identity->team),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $identity->display_name ?: trim("{$firstName} {$lastName}"),
            'dob' => $birthdate,
            'is_prospect' => true,
            'is_goalie' => $positionType === 'G',
            'position' => $identity->position,
            'pos_type' => $positionType,
            'team_abbrev' => $identity->team,
            'current_league_abbrev' => null,
            'status' => 'active',
            'meta' => [
                'created_from_external_identity' => [
                    'provider' => $identity->provider,
                    'provider_player_id' => $identity->provider_player_id,
                ],
            ],
        ];
    }

    /**
     * Find a CapWages profile DOB for one of the identities being linked to a new player.
     *
     * @param Collection<int,PlayerExternalIdentity> $identities
     */
    private function capWagesBirthdateForIdentities(Collection $identities): ?string
    {
        $identityIds = $identities
            ->pluck('id')
            ->filter()
            ->values();

        if ($identityIds->isEmpty()) {
            return null;
        }

        $capWagesPlayer = CapWagesPlayer::query()
            ->whereIn('player_external_identity_id', $identityIds->all())
            ->whereNotNull('birth_date')
            ->orderBy('id')
            ->first(['birth_date']);

        return $capWagesPlayer?->birth_date?->toDateString();
    }

    /**
     * Resolve required first and last name values for canonical player creation.
     *
     * @return array{0:string,1:string}
     */
    private function namePartsForPlayer(PlayerExternalIdentity $identity): array
    {
        $firstName = trim((string) $identity->first_name);
        $lastName = trim((string) $identity->last_name);

        if ($firstName !== '' && $lastName !== '') {
            return [$firstName, $lastName];
        }

        $displayName = trim((string) $identity->display_name);

        if ($displayName === '') {
            return ['Unknown', 'Player'];
        }

        $parts = preg_split('/\s+/', $displayName) ?: [];

        if (count($parts) === 1) {
            return [$parts[0], 'Player'];
        }

        $lastName = array_pop($parts);

        return [implode(' ', $parts), $lastName ?: 'Player'];
    }

    /**
     * Build a compact identity payload for AJAX UI updates.
     *
     * @return array<string,string|null>
     */
    private function externalIdentityPayload(PlayerExternalIdentity $identity): array
    {
        return [
            'display_name' => $identity->display_name,
            'provider' => $identity->provider,
            'provider_player_id' => $identity->provider_player_id,
            'team' => $identity->team,
            'position' => $identity->position,
            'match_status' => $identity->match_status,
        ];
    }

    /**
     * Search matching-source identities for manual coverage linking.
     *
     * @param array<string,mixed> $filters
     * @return Collection<int,PlayerExternalIdentity>
     */
    private function matchingSourceSearchResults(
        Request $request,
        array $filters,
        PlayerExternalIdentity $selectedIdentity,
    ): Collection
    {
        $search = trim((string) $request->query('matching_identity_search'));

        if ($search === '' || $filters['matching_source'] === null) {
            return collect();
        }

        $term = mb_strtolower($search);
        $normalizedTerm = $this->normalizer->normalizeName($search);
        $compactNormalizedTerm = $normalizedTerm ? str_replace(' ', '', $normalizedTerm) : null;

        return PlayerExternalIdentity::query()
            ->where('provider', $filters['matching_source'])
            ->whereNull('player_id')
            ->where(fn (Builder $query) => $this->applyMatchingSourcePositionConstraint($query, $selectedIdentity))
            ->orderBy('display_name')
            ->get()
            ->filter(function (PlayerExternalIdentity $identity) use ($term, $normalizedTerm, $compactNormalizedTerm): bool {
                if (str_contains(mb_strtolower((string) $identity->display_name), $term)) {
                    return true;
                }

                if (str_contains(mb_strtolower((string) $identity->provider_player_id), $term)) {
                    return true;
                }

                if (str_contains(mb_strtolower((string) $identity->provider_slug), $term)) {
                    return true;
                }

                if ($normalizedTerm === null || $identity->normalized_name === null) {
                    return false;
                }

                if (str_contains($identity->normalized_name, $normalizedTerm)) {
                    return true;
                }

                return $compactNormalizedTerm !== null
                    && str_contains(str_replace(' ', '', $identity->normalized_name), $compactNormalizedTerm);
            })
            ->take(10)
            ->values();
    }

    /**
     * Build a base matching-source identity query.
     *
     * @return Builder<PlayerExternalIdentity>
     */
    private function matchingSourceIdentityQuery(string $provider, PlayerExternalIdentity $identity): Builder
    {
        return PlayerExternalIdentity::query()
            ->where('provider', $provider)
            ->whereNull('player_id')
            ->where(fn (Builder $query) => $this->applyMatchingSourceConstraints($query, $identity))
            ->orderBy('display_name');
    }

    /**
     * Constrain matching-source identities to plausible player-level matches.
     */
    private function applyMatchingSourceConstraints(Builder $query, PlayerExternalIdentity $identity): void
    {
        if ($identity->normalized_name !== null) {
            $query->where('normalized_name', $identity->normalized_name);
        } else {
            $query->whereRaw('1 = 0');
            return;
        }

        $this->applyMatchingSourcePositionConstraint($query, $identity);
    }

    /**
     * Constrain matching-source identities to compatible position types.
     */
    private function applyMatchingSourcePositionConstraint(Builder $query, PlayerExternalIdentity $identity): void
    {
        $sourcePositionType = $this->identityPositionType($identity->position);

        if ($sourcePositionType !== null) {
            $query->where(function (Builder $inner) use ($sourcePositionType): void {
                foreach ($this->positionsForType($sourcePositionType) as $position) {
                    $inner->orWhere('position', $position);
                }
            });
        }
    }

    /**
     * Normalize identity positions to coverage position type.
     */
    private function identityPositionType(?string $position): ?string
    {
        $position = mb_strtoupper(trim((string) $position));

        return match ($position) {
            'G' => 'G',
            'D', 'LD', 'RD' => 'D',
            'F', 'C', 'L', 'R', 'LW', 'RW' => 'F',
            default => null,
        };
    }

    /**
     * Return provider position values compatible with a position type.
     *
     * @return array<int,string>
     */
    private function positionsForType(string $positionType): array
    {
        return match ($positionType) {
            'G' => ['G'],
            'D' => ['D', 'LD', 'RD'],
            'F' => ['F', 'C', 'L', 'R', 'LW', 'RW'],
            default => [],
        };
    }

    /**
     * Score matching-source suggestions against the selected source identity.
     */
    private function matchingSourceCandidateScore(
        PlayerExternalIdentity $sourceIdentity,
        PlayerExternalIdentity $candidate,
    ): int {
        $score = 0;

        if ($sourceIdentity->normalized_name !== null && $sourceIdentity->normalized_name === $candidate->normalized_name) {
            $score += 3;
        }

        if ($sourceIdentity->position !== null && $sourceIdentity->position === $candidate->position) {
            $score++;
        }

        if ($sourceIdentity->team !== null && $sourceIdentity->team === $candidate->team) {
            $score++;
        }

        return $score;
    }

    /**
     * Build current resolver recommendations for visible identities.
     *
     * @param Collection<int,PlayerExternalIdentity> $identities
     * @return array<int,\App\DTO\PlayerIdentityMatchResult>
     */
    private function recommendationsForIdentities(Collection $identities, ?PlayerExternalIdentity $selectedIdentity): array
    {
        if ($selectedIdentity !== null && ! $identities->contains('id', $selectedIdentity->id)) {
            $identities = $identities->push($selectedIdentity);
        }

        return $identities
            ->mapWithKeys(fn (PlayerExternalIdentity $identity) => [
                $identity->id => $this->resolver->previewNonAuthorityIdentity($identity),
            ])
            ->all();
    }

    /**
     * Keep the default inbox focused on identities that still need manual judgment.
     *
     * @param Collection<int,PlayerExternalIdentity> $identities
     * @param array<int,\App\DTO\PlayerIdentityMatchResult> $recommendations
     * @return Collection<int,PlayerExternalIdentity>
     */
    private function lowConfidenceIdentities(Collection $identities, array $recommendations): Collection
    {
        return $identities
            ->filter(static function (PlayerExternalIdentity $identity) use ($recommendations): bool {
                $recommendation = $recommendations[$identity->id] ?? null;
                $confidence = $recommendation?->confidence;

                return $confidence === null || $confidence < 75;
            })
            ->take(75)
            ->values();
    }

    /**
     * Count identities by current status for inbox counters.
     *
     * @return array<string,int>
     */
    private function statusCounts(): array
    {
        $counts = array_fill_keys(self::ALL_STATUSES, 0);

        PlayerExternalIdentity::query()
            ->selectRaw('match_status, count(*) as aggregate')
            ->groupBy('match_status')
            ->get()
            ->each(static function (PlayerExternalIdentity $identity) use (&$counts): void {
                $counts[$identity->match_status] = (int) $identity->aggregate;
            });

        return $counts;
    }

    /**
     * Redirect back to the selected identity.
     */
    private function redirectToSelected(PlayerExternalIdentity $identity): RedirectResponse
    {
        if (request()->boolean('admin_panel')) {
            return Redirect::to(URL::route('admin.dashboard', ['identity' => $identity->id]));
        }

        return Redirect::to(URL::route('admin.player-triage', ['identity' => $identity->id]));
    }
}
