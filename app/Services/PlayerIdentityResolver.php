<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\PlayerIdentityMatchResult;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use Illuminate\Support\Carbon;
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
}
