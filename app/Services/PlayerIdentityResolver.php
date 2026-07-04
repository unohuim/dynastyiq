<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\PlayerIdentityMatchResult;
use App\Events\PlayerExternalIdentityLinked;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\YahooPlayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Upserts provider identities and records canonical player matches.
 */
class PlayerIdentityResolver
{
    private const SCORE_NAME_ONLY = 75;
    private const SCORE_NAME_PLUS = 85;
    private const SCORE_NAME_PLUS_PLUS = 95;

    /**
     * Minimum score required for automatic linking by provider.
     *
     * @var array<string,int>
     */
    private const PROVIDER_AUTO_LINK_THRESHOLDS = [
        PlayerExternalIdentity::PROVIDER_NHL => self::SCORE_NAME_PLUS,
        PlayerExternalIdentity::PROVIDER_NHL_DRAFT => self::SCORE_NAME_PLUS,
        PlayerExternalIdentity::PROVIDER_FANTRAX => self::SCORE_NAME_PLUS,
        PlayerExternalIdentity::PROVIDER_YAHOO => self::SCORE_NAME_PLUS,
        PlayerExternalIdentity::PROVIDER_CAPWAGES => self::SCORE_NAME_ONLY,
    ];

    public function __construct(
        private readonly PlayerIdentityNormalizer $normalizer,
        private readonly NhlTeamReference $teams,
    ) {
    }

    /**
     * Upsert an NHL authority identity from the player landing payload.
     *
     * @param array<string,mixed> $payload
     */
    public function upsertNhlIdentity(array $payload): PlayerExternalIdentity
    {
        $providerPlayerId = (string)($payload['playerId'] ?? '');

        if ($providerPlayerId === '') {
            throw new InvalidArgumentException('NHL identity payload is missing playerId.');
        }

        $firstName = $this->normalizer->nhlLocalizedDefault($payload, 'firstName');
        $lastName = $this->normalizer->nhlLocalizedDefault($payload, 'lastName');
        $displayName = $this->normalizer->displayNameFromParts($firstName, $lastName);
        $now = Carbon::now();

        $identity = PlayerExternalIdentity::firstOrNew([
            'provider' => PlayerExternalIdentity::PROVIDER_NHL,
            'provider_player_id' => $providerPlayerId,
        ]);

        if (! $identity->exists) {
            $identity->first_seen_at = $now;
            $identity->match_status = PlayerExternalIdentity::STATUS_UNMATCHED;
        }

        $identity->fill([
            'provider_slug' => $providerPlayerId,
            'display_name' => $displayName,
            'normalized_name' => $this->normalizer->normalizeName($displayName),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $payload['birthDate'] ?? null,
            'position' => $payload['position'] ?? null,
            'team' => $payload['currentTeamAbbrev'] ?? null,
            'raw_payload' => $payload,
            'last_seen_at' => $now,
        ]);

        $identity->save();

        return $identity;
    }

    /**
     * Upsert an NHL draft identity for a drafted player without an NHL player id.
     *
     * @param array<string,mixed> $payload
     */
    public function upsertNhlDraftIdentity(string $providerPlayerId, array $payload): PlayerExternalIdentity
    {
        $displayName = trim((string)($payload['display_name'] ?? $payload['name'] ?? $payload['playerName'] ?? ''));

        if ($displayName === '') {
            throw new InvalidArgumentException('NHL draft identity payload is missing a player name.');
        }

        $firstName = $this->draftNamePart($payload['first_name'] ?? $payload['firstName'] ?? null);
        $lastName = $this->draftNamePart($payload['last_name'] ?? $payload['lastName'] ?? null);

        return $this->upsertProviderIdentity(
            PlayerExternalIdentity::PROVIDER_NHL_DRAFT,
            $providerPlayerId,
            [
                'provider_slug' => $providerPlayerId,
                'display_name' => $displayName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birthdate' => $payload['birthdate'] ?? $payload['birthDate'] ?? null,
                'position' => $payload['position'] ?? $payload['positionCode'] ?? null,
                'team' => $this->teams->normalizeToAbbrev(
                    $payload['team'] ?? $payload['teamAbbrev'] ?? $payload['triCode'] ?? null,
                ),
                'raw_payload' => $payload,
            ],
        );
    }

    /**
     * Upsert a Fantrax provider identity from a player entry.
     *
     * @param array<string,mixed> $entry
     */
    public function upsertFantraxIdentity(array $entry): PlayerExternalIdentity
    {
        $providerPlayerId = trim((string)($entry['fantraxId'] ?? ''));

        if ($providerPlayerId === '') {
            throw new InvalidArgumentException('Fantrax identity payload is missing fantraxId.');
        }

        $nameParts = $this->namePartsFromFantraxName((string)($entry['name'] ?? ''));
        $displayName = $nameParts === null
            ? trim((string)($entry['name'] ?? '')) ?: null
            : $this->normalizer->displayNameFromParts($nameParts['first'], $nameParts['last']);

        return $this->upsertProviderIdentity(
            PlayerExternalIdentity::PROVIDER_FANTRAX,
            $providerPlayerId,
            [
                'provider_slug' => $providerPlayerId,
                'display_name' => $displayName,
                'first_name' => $nameParts['first'] ?? null,
                'last_name' => $nameParts['last'] ?? null,
                'birthdate' => $entry['birthDate'] ?? $entry['dob'] ?? null,
                'position' => $entry['position'] ?? null,
                'team' => $entry['team'] ?? null,
                'raw_payload' => $entry,
            ],
        );
    }

    /**
     * Upsert a CapWages provider identity from a player detail payload.
     *
     * @param array<string,mixed> $payload
     */
    public function upsertCapWagesIdentity(string $slug, array $payload): PlayerExternalIdentity
    {
        $providerPlayerId = trim((string)($payload['nhlId'] ?? $slug));

        if ($providerPlayerId === '') {
            throw new InvalidArgumentException('CapWages identity payload is missing a durable provider player id.');
        }

        $displayName = trim((string)($payload['name'] ?? $payload['fullName'] ?? '')) ?: null;
        $firstName = trim((string)($payload['firstName'] ?? '')) ?: null;
        $lastName = trim((string)($payload['lastName'] ?? '')) ?: null;

        if ($displayName === null) {
            $displayName = $this->normalizer->displayNameFromParts($firstName, $lastName);
        }

        return $this->upsertProviderIdentity(
            PlayerExternalIdentity::PROVIDER_CAPWAGES,
            $providerPlayerId,
            [
                'provider_slug' => $slug,
                'display_name' => $displayName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birthdate' => $this->capWagesBirthdate($payload),
                'position' => $payload['position'] ?? null,
                'team' => $this->teams->normalizeToAbbrev(
                    $payload['team'] ?? $payload['currentTeamAbbrev'] ?? null,
                ),
                'raw_payload' => $payload,
            ],
        );
    }

    /**
     * Upsert a Yahoo Fantasy provider identity from a staged Yahoo player row.
     */
    public function upsertYahooIdentity(YahooPlayer $player): PlayerExternalIdentity
    {
        $providerPlayerId = trim((string) $player->yahoo_player_id);

        if ($providerPlayerId === '') {
            throw new InvalidArgumentException('Yahoo identity payload is missing yahoo_player_id.');
        }

        $displayName = trim((string) $player->full_name) ?: null;
        $position = collect((array) $player->eligible_positions)
            ->map(static fn (mixed $position): string => trim((string) $position))
            ->first(static fn (string $position): bool => $position !== '');

        return $this->upsertProviderIdentity(
            PlayerExternalIdentity::PROVIDER_YAHOO,
            $providerPlayerId,
            [
                'provider_slug' => $player->player_key,
                'display_name' => $displayName,
                'first_name' => $player->first_name,
                'last_name' => $player->last_name,
                'birthdate' => null,
                'position' => $position ?: $player->display_position,
                'team' => $this->teams->normalizeToAbbrev($player->editorial_team_abbr),
                'raw_payload' => $player->raw_payload,
            ],
        );
    }

    /**
     * Resolve a non-authority identity without creating canonical players.
     */
    public function resolveNonAuthorityIdentity(
        PlayerExternalIdentity $identity,
        ?Player $knownPlayer = null,
    ): PlayerExternalIdentity {
        if ($knownPlayer !== null) {
            return $this->linkIdentityToPlayer($identity, $knownPlayer);
        }

        return $this->applyMatchResult($identity, $this->previewNonAuthorityIdentity($identity));
    }

    /**
     * Preview the current resolver decision without mutating the identity row.
     */
    public function previewNonAuthorityIdentity(PlayerExternalIdentity $identity): PlayerIdentityMatchResult
    {
        if ($identity->player_id !== null) {
            return PlayerIdentityMatchResult::matched((int)$identity->player_id);
        }

        if ($identity->normalized_name === null) {
            return PlayerIdentityMatchResult::unmatched(PlayerExternalIdentity::REASON_PROVIDER_PAYLOAD_MISSING_NAME);
        }

        $candidateScores = $this->scoredCandidatesForIdentity($identity);

        if ($candidateScores->isEmpty()) {
            return PlayerIdentityMatchResult::unmatched(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER);
        }

        $threshold = $this->autoLinkThresholdForProvider($identity->provider);
        $qualifiedCandidates = $candidateScores
            ->filter(static fn (array $candidate) => $candidate['score'] >= $threshold)
            ->values();

        if ($qualifiedCandidates->count() === 1) {
            $qualifiedCandidate = $qualifiedCandidates->first();

            return PlayerIdentityMatchResult::matched(
                (int)$qualifiedCandidate['player']->id,
                $qualifiedCandidate['score'],
            );
        }

        if ($qualifiedCandidates->count() > 1) {
            return new PlayerIdentityMatchResult(
                PlayerExternalIdentity::STATUS_CONFLICT,
                null,
                $qualifiedCandidates->max('score'),
                PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
            );
        }

        $bestCandidate = $candidateScores->first();

        if ($candidateScores->count() > 1) {
            return PlayerIdentityMatchResult::candidate(
                PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
                $bestCandidate['score'],
            );
        }

        return PlayerIdentityMatchResult::candidate(
            PlayerExternalIdentity::REASON_INSUFFICIENT_IDENTITY_DATA,
            $bestCandidate['score'],
        );
    }

    /**
     * Link an external identity to a canonical player as a matched identity.
     */
    public function linkIdentityToPlayer(
        PlayerExternalIdentity $identity,
        Player $player,
        int $confidence = 100,
    ): PlayerExternalIdentity {
        $previousPlayerId = $identity->player_id === null ? null : (int)$identity->player_id;
        $playerId = (int)$player->id;

        $this->applyMatchResult(
            $identity,
            PlayerIdentityMatchResult::matched($playerId, $confidence),
        );

        if ($previousPlayerId !== $playerId) {
            PlayerExternalIdentityLinked::dispatch($identity, $previousPlayerId, $playerId);
        }

        return $identity;
    }

    /**
     * Apply a resolver result to an identity.
     */
    public function applyMatchResult(
        PlayerExternalIdentity $identity,
        PlayerIdentityMatchResult $result,
    ): PlayerExternalIdentity {
        $identity->player_id = $result->playerId;
        $identity->match_status = $result->status;
        $identity->match_confidence = $result->confidence;
        $identity->unmatched_reason = $result->reason;
        $identity->save();

        return $identity;
    }

    /**
     * Count identities by provider and match status.
     *
     * @return array<string,array<string,int>>
     */
    public function statusCountsByProvider(?string $provider = null): array
    {
        $rows = PlayerExternalIdentity::query()
            ->when($provider !== null, static fn ($query) => $query->where('provider', $provider))
            ->selectRaw('provider, match_status, count(*) as aggregate')
            ->groupBy('provider', 'match_status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->provider][$row->match_status] = (int)$row->aggregate;
        }

        return $counts;
    }

    /**
     * Upsert a generic provider identity row.
     *
     * @param array<string,mixed> $attributes
     */
    private function upsertProviderIdentity(
        string $provider,
        string $providerPlayerId,
        array $attributes,
    ): PlayerExternalIdentity {
        $now = Carbon::now();

        $identity = PlayerExternalIdentity::firstOrNew([
            'provider' => $provider,
            'provider_player_id' => $providerPlayerId,
        ]);

        if (! $identity->exists) {
            $identity->first_seen_at = $now;
            $identity->match_status = PlayerExternalIdentity::STATUS_UNMATCHED;
        }

        $displayName = $attributes['display_name'] ?? null;

        $identity->fill([
            'provider_slug' => $attributes['provider_slug'] ?? null,
            'display_name' => $displayName,
            'normalized_name' => $this->normalizer->normalizeName(is_string($displayName) ? $displayName : null),
            'first_name' => $attributes['first_name'] ?? null,
            'last_name' => $attributes['last_name'] ?? null,
            'birthdate' => $attributes['birthdate'] ?? null,
            'position' => $attributes['position'] ?? null,
            'team' => $attributes['team'] ?? null,
            'raw_payload' => $attributes['raw_payload'] ?? null,
            'last_seen_at' => $now,
        ]);

        $identity->save();

        return $identity;
    }

    /**
     * Parse Fantrax names commonly returned as "Last, First".
     *
     * @return array{first:string,last:string}|null
     */
    private function namePartsFromFantraxName(string $name): ?array
    {
        [$last, $first] = array_map(
            static fn ($part) => trim((string)$part),
            explode(',', $name) + [1 => ''],
        );

        if ($first === '' || $last === '') {
            return null;
        }

        return [
            'first' => $first,
            'last' => $last,
        ];
    }

    /**
     * Score canonical players that match identity name.
     *
     * @return Collection<int,array{player:Player,score:int}>
     */
    private function scoredCandidatesForIdentity(PlayerExternalIdentity $identity): Collection
    {
        return $this->candidatePlayerQuery($identity)
            ->get()
            ->filter(fn (Player $player) => $this->identityNameMatchesPlayer($identity, $player))
            ->filter(fn (Player $player) => $this->positionTypesAreCompatible($identity, $player))
            ->map(fn (Player $player) => [
                'player' => $player,
                'score' => $this->scoreCandidate($identity, $player),
            ])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Build a bounded canonical player query for one provider identity.
     */
    private function candidatePlayerQuery(PlayerExternalIdentity $identity): Builder
    {
        $name = $this->candidateNameEvidence($identity);

        return Player::query()
            ->select(['id', 'full_name', 'first_name', 'last_name', 'dob', 'position', 'team_abbrev'])
            ->when(
                $name['full'] === null && $name['last'] === null,
                static fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->when(
                $name['full'] !== null || $name['last'] !== null,
                function (Builder $query) use ($name): void {
                    $query->where(function (Builder $inner) use ($name): void {
                        if ($name['full'] !== null) {
                            $inner->orWhereRaw('LOWER(full_name) = ?', [$name['full']]);
                        }

                        if ($name['last'] !== null) {
                            $inner->orWhereRaw('LOWER(last_name) = ?', [$name['last']]);

                            $lastInitial = mb_substr($name['last'], 0, 1);

                            if ($lastInitial !== '') {
                                $inner->orWhereRaw('LOWER(last_name) LIKE ?', ["{$lastInitial}%"]);
                            }
                        }
                    });
                },
            );
    }

    /**
     * @return array{first:string|null,last:string|null,full:string|null}
     */
    private function candidateNameEvidence(PlayerExternalIdentity $identity): array
    {
        $firstName = $this->normalizer->normalizeName($identity->first_name);
        $lastName = $this->normalizer->normalizeName($identity->last_name);
        $fullName = $this->normalizer->normalizeName($identity->display_name)
            ?? (trim((string) $identity->normalized_name) ?: null);

        if (($firstName === null || $lastName === null) && $fullName !== null) {
            $parts = preg_split('/\s+/', $fullName) ?: [];

            if (count($parts) >= 2) {
                $lastName ??= array_pop($parts);
                $firstName ??= implode(' ', $parts);
            }
        }

        return [
            'first' => $firstName,
            'last' => $lastName,
            'full' => $fullName,
        ];
    }

    /**
     * Determine whether a canonical player name is compatible with a provider identity.
     */
    private function identityNameMatchesPlayer(PlayerExternalIdentity $identity, Player $player): bool
    {
        if ($this->normalizer->normalizeName($player->full_name) === $identity->normalized_name) {
            return true;
        }

        $identityFirstName = $this->normalizer->normalizeName($identity->first_name);
        $identityLastName = $this->normalizer->normalizeName($identity->last_name);
        $playerFirstName = $this->normalizer->normalizeName($player->first_name);
        $playerLastName = $this->normalizer->normalizeName($player->last_name);

        if ($identityLastName === null || $playerLastName === null || $identityLastName !== $playerLastName) {
            return false;
        }

        return $this->firstNamesAreCompatible($identityFirstName, $playerFirstName);
    }

    /**
     * Treat common transliteration endings as compatible first names.
     */
    private function firstNamesAreCompatible(?string $identityFirstName, ?string $playerFirstName): bool
    {
        if ($identityFirstName === null || $playerFirstName === null) {
            return false;
        }

        if ($identityFirstName === $playerFirstName) {
            return true;
        }

        return $this->yiEndingVariant($identityFirstName) === $this->yiEndingVariant($playerFirstName);
    }

    /**
     * Normalize final y/i variants without changing broader name matching rules.
     */
    private function yiEndingVariant(string $name): string
    {
        return preg_replace('/[yi]$/', '#', $name) ?? $name;
    }

    /**
     * Score matching evidence for one canonical player candidate.
     */
    private function scoreCandidate(PlayerExternalIdentity $identity, Player $player): int
    {
        if ($this->birthdateMatches($identity, $player)) {
            return self::SCORE_NAME_PLUS_PLUS;
        }

        $contextMatches = 0;

        if ($this->positionMatches($identity, $player)) {
            $contextMatches++;
        }

        if ($this->teamMatches($identity, $player)) {
            $contextMatches++;
        }

        if ($contextMatches >= 2) {
            return self::SCORE_NAME_PLUS_PLUS;
        }

        if ($contextMatches === 1) {
            return self::SCORE_NAME_PLUS;
        }

        return self::SCORE_NAME_ONLY;
    }

    /**
     * Resolve the minimum automatic-link confidence for a provider.
     */
    private function autoLinkThresholdForProvider(string $provider): int
    {
        return self::PROVIDER_AUTO_LINK_THRESHOLDS[$provider] ?? 100;
    }

    /**
     * Determine whether identity and canonical player birthdates match.
     */
    private function birthdateMatches(PlayerExternalIdentity $identity, Player $player): bool
    {
        if ($identity->birthdate === null || $player->dob === null) {
            return false;
        }

        return $identity->birthdate->toDateString() === Carbon::parse($player->dob)->toDateString();
    }

    /**
     * Determine whether identity and canonical player position types match.
     */
    private function positionMatches(PlayerExternalIdentity $identity, Player $player): bool
    {
        $identityPositionType = $this->positionType($identity->position);
        $playerPositionType = $this->positionType($player->position);

        if ($identityPositionType === null || $playerPositionType === null) {
            return false;
        }

        return $identityPositionType === $playerPositionType;
    }

    /**
     * Determine whether a canonical player is viable for this identity by position type.
     */
    private function positionTypesAreCompatible(PlayerExternalIdentity $identity, Player $player): bool
    {
        $identityPositionType = $this->positionType($identity->position);
        $playerPositionType = $this->positionType($player->position);

        if ($identityPositionType === null || $playerPositionType === null) {
            return true;
        }

        return $identityPositionType === $playerPositionType;
    }

    /**
     * Normalize detailed hockey positions to matching position type.
     */
    private function positionType(?string $position): ?string
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
     * Determine whether identity and canonical player teams match.
     */
    private function teamMatches(PlayerExternalIdentity $identity, Player $player): bool
    {
        if ($identity->team === null || $player->team_abbrev === null) {
            return false;
        }

        return mb_strtoupper(trim($identity->team)) === mb_strtoupper(trim($player->team_abbrev));
    }

    /**
     * Extract CapWages birthdate from current and legacy payload shapes.
     *
     * @param array<string,mixed> $payload
     */
    private function capWagesBirthdate(array $payload): mixed
    {
        $personalInfo = is_array($payload['personalInfo'] ?? null) ? $payload['personalInfo'] : [];

        return $personalInfo['birthDate'] ?? $payload['birthDate'] ?? $payload['dob'] ?? null;
    }

    private function draftNamePart(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['default'] ?? null;
        }

        $name = trim((string) $value);

        return $name === '' ? null : $name;
    }
}
