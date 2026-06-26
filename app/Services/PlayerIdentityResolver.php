<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\PlayerIdentityMatchResult;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Upserts provider identities and records canonical player matches.
 */
class PlayerIdentityResolver
{
    public function __construct(
        private readonly PlayerIdentityNormalizer $normalizer,
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
                'birthdate' => $payload['birthDate'] ?? $payload['dob'] ?? null,
                'position' => $payload['position'] ?? null,
                'team' => $payload['team'] ?? $payload['currentTeamAbbrev'] ?? null,
                'raw_payload' => $payload,
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

        if ($identity->player_id !== null) {
            return $this->applyMatchResult(
                $identity,
                PlayerIdentityMatchResult::matched((int)$identity->player_id),
            );
        }

        if ($identity->normalized_name === null) {
            return $this->applyMatchResult(
                $identity,
                PlayerIdentityMatchResult::unmatched(PlayerExternalIdentity::REASON_PROVIDER_PAYLOAD_MISSING_NAME),
            );
        }

        $candidates = $this->candidatePlayersForIdentity($identity);

        if ($candidates->isEmpty()) {
            return $this->applyMatchResult(
                $identity,
                PlayerIdentityMatchResult::unmatched(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER),
            );
        }

        if ($candidates->count() > 1) {
            return $this->applyMatchResult(
                $identity,
                PlayerIdentityMatchResult::candidate(PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES, 50),
            );
        }

        $candidate = $candidates->first();

        if ($identity->birthdate === null) {
            return $this->applyMatchResult(
                $identity,
                PlayerIdentityMatchResult::candidate(PlayerExternalIdentity::REASON_INSUFFICIENT_IDENTITY_DATA, 75),
            );
        }

        return $this->linkIdentityToPlayer($identity, $candidate, 95);
    }

    /**
     * Link an external identity to a canonical player as a matched identity.
     */
    public function linkIdentityToPlayer(
        PlayerExternalIdentity $identity,
        Player $player,
        int $confidence = 100,
    ): PlayerExternalIdentity {
        $this->applyMatchResult(
            $identity,
            PlayerIdentityMatchResult::matched((int)$player->id, $confidence),
        );

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
     * Find canonical players that match identity name and optional birthdate.
     *
     * @return Collection<int,Player>
     */
    private function candidatePlayersForIdentity(PlayerExternalIdentity $identity): Collection
    {
        return Player::query()
            ->when(
                $identity->birthdate !== null,
                fn ($query) => $query->whereDate('dob', $identity->birthdate),
            )
            ->get()
            ->filter(fn (Player $player) => $this->normalizer->normalizeName($player->full_name) === $identity->normalized_name)
            ->values();
    }
}
