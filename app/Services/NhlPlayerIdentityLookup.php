<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Traits\HasAPITrait;
use Illuminate\Support\Carbon;

/**
 * Resolves provisional canonical players to NHL API identities.
 */
class NhlPlayerIdentityLookup
{
    use HasAPITrait;

    public function __construct(
        private readonly PlayerIdentityNormalizer $normalizer,
        private readonly PlayerIdentityResolver $resolver,
        private readonly ImportNHLPlayer $playerImporter,
    ) {
    }

    /**
     * Attempt to enrich an existing canonical player with an NHL identity.
     */
    public function enrich(Player $player, ?PlayerExternalIdentity $sourceIdentity = null): void
    {
        if ($player->nhl_id !== null) {
            return;
        }

        $landing = $this->resolveLandingForPlayer($player, $sourceIdentity);

        if ($landing === null) {
            return;
        }

        if ($this->landingPlayerIdAlreadyOwned($landing, $player)) {
            $this->markSourceIdentityConflict($sourceIdentity);
            return;
        }

        $identity = $this->resolver->upsertNhlIdentity($landing);
        $this->resolver->linkIdentityToPlayer($identity, $player);
        $this->playerImporter->import((string) $landing['playerId'], (bool) $player->is_prospect);
    }

    /**
     * Resolve the NHL player id for a canonical player without mutating the player.
     */
    public function resolveForPlayer(Player $player, ?PlayerExternalIdentity $sourceIdentity = null): ?int
    {
        if ($player->nhl_id !== null) {
            return (int) $player->nhl_id;
        }

        $landing = $this->resolveLandingForPlayer($player, $sourceIdentity);
        $playerId = $landing['playerId'] ?? null;

        return $playerId === null || $playerId === '' ? null : (int) $playerId;
    }

    /**
     * Resolve the NHL player id for first/last name plus optional position evidence.
     */
    public function resolveForName(string $firstName, string $lastName, ?string $positionType = null): ?int
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $positionType = $this->positionType($positionType);

        if ($firstName === '' || $lastName === '') {
            return null;
        }

        $fullName = $this->normalizer->displayNameFromParts($firstName, $lastName) ?? trim("{$firstName} {$lastName}");
        $candidates = $this->playerCandidates($firstName, $lastName, $fullName)
            ->when($positionType !== null, fn ($items) => $items
                ->filter(fn (array $candidate): bool => $this->positionType($candidate['positionCode'] ?? null) === $positionType)
                ->values());
        $candidates = $this->preferSingleCurrentTeamCandidate($candidates);

        if ($candidates->count() !== 1) {
            return null;
        }

        $landing = $this->landingPayload((string) $candidates->first()['id']);

        if (! $this->landingMatchesName($landing, $fullName, $positionType)) {
            return null;
        }

        $playerId = $landing['playerId'] ?? null;

        return $playerId === null || $playerId === '' ? null : (int) $playerId;
    }

    /**
     * Determine whether a player has enough local evidence for an NHL lookup.
     */
    public function hasLookupEvidence(Player $player, ?PlayerExternalIdentity $sourceIdentity = null): bool
    {
        return $this->nameParts($player, $sourceIdentity) !== null
            && $this->evidencePositionType($player, $sourceIdentity) !== null;
    }

    /**
     * Resolve one validated NHL landing payload for a canonical player.
     *
     * @return array<string,mixed>|null
     */
    private function resolveLandingForPlayer(Player $player, ?PlayerExternalIdentity $sourceIdentity = null): ?array
    {
        $name = $this->nameParts($player, $sourceIdentity);
        $positionType = $this->evidencePositionType($player, $sourceIdentity);

        if ($name === null || $positionType === null) {
            return null;
        }

        $candidates = $this->playerCandidates($name['first'], $name['last'], $name['full'])
            ->filter(fn (array $candidate): bool => $this->positionType($candidate['positionCode'] ?? null) === $positionType)
            ->values();
        $candidates = $this->preferSingleCurrentTeamCandidate($candidates);

        if ($candidates->count() === 1) {
            $landing = $this->landingPayload((string) $candidates->first()['id']);

            if (! $this->landingMatches($landing, $player, $sourceIdentity, $positionType)) {
                $this->upsertCandidateIdentities($candidates, PlayerExternalIdentity::STATUS_CANDIDATE);
                return null;
            }

            return $landing;
        }

        if ($candidates->count() > 1) {
            $this->upsertCandidateIdentities($candidates, PlayerExternalIdentity::STATUS_CONFLICT);
        }

        return null;
    }

    /**
     * @return array{first:string,last:string,full:string}|null
     */
    private function nameParts(Player $player, ?PlayerExternalIdentity $identity): ?array
    {
        $firstName = trim((string) (($identity?->first_name) ?: $player->first_name));
        $lastName = trim((string) (($identity?->last_name) ?: $player->last_name));

        if ($firstName !== '' && $lastName !== '') {
            return [
                'first' => $firstName,
                'last' => $lastName,
                'full' => $this->normalizer->displayNameFromParts($firstName, $lastName) ?? trim("{$firstName} {$lastName}"),
            ];
        }

        $displayName = trim((string) (($identity?->display_name) ?: $player->full_name));
        $parts = preg_split('/\s+/', $displayName) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        $firstName = array_shift($parts);
        $lastName = implode(' ', $parts);

        return $firstName !== '' && $lastName !== ''
            ? ['first' => $firstName, 'last' => $lastName, 'full' => trim("{$firstName} {$lastName}")]
            : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function playerCandidates(string $firstName, string $lastName, string $fullName)
    {
        $payload = $this->getAPIData('nhl_stats', 'players', [], [
            'limit' => 100,
            'cayenneExp' => sprintf(
                'firstName="%s" and lastName="%s"',
                $this->cayenneString($firstName),
                $this->cayenneString($lastName),
            ),
        ]);

        return collect(is_array($payload['data'] ?? null) ? $payload['data'] : [])
            ->filter(static fn (mixed $candidate): bool => is_array($candidate) && isset($candidate['id']))
            ->filter(fn (array $candidate): bool => $this->candidateNameMatches(
                $candidate,
                $firstName,
                $lastName,
                $fullName,
            ))
            ->values();
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function candidateNameMatches(array $candidate, string $firstName, string $lastName, string $fullName): bool
    {
        $candidateFullName = $this->normalizer->normalizeName($candidate['fullName'] ?? null);
        $candidateFirstName = $this->normalizer->normalizeName($candidate['firstName'] ?? null);
        $candidateLastName = $this->normalizer->normalizeName($candidate['lastName'] ?? null);
        $normalizedFullName = $this->normalizer->normalizeName($fullName);

        if ($candidateFullName !== null && $candidateFullName === $normalizedFullName) {
            return true;
        }

        return $candidateFirstName === $this->normalizer->normalizeName($firstName)
            && $candidateLastName === $this->normalizer->normalizeName($lastName);
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $candidates
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function preferSingleCurrentTeamCandidate($candidates)
    {
        if ($candidates->count() <= 1) {
            return $candidates;
        }

        $currentTeamCandidates = $candidates
            ->filter(static fn (array $candidate): bool => ($candidate['currentTeamId'] ?? null) !== null)
            ->values();

        return $currentTeamCandidates->count() === 1 ? $currentTeamCandidates : $candidates;
    }

    /**
     * @return array<string,mixed>
     */
    private function landingPayload(string $playerId): array
    {
        return $this->getAPIData('nhl', 'player_landing', [
            'playerId' => $playerId,
        ]);
    }

    /**
     * @param array<string,mixed> $landing
     */
    private function landingMatches(
        array $landing,
        Player $player,
        ?PlayerExternalIdentity $sourceIdentity,
        string $positionType,
    ): bool {
        if (! isset($landing['playerId'])) {
            return false;
        }

        $landingName = $this->normalizer->displayNameFromParts(
            $this->normalizer->nhlLocalizedDefault($landing, 'firstName'),
            $this->normalizer->nhlLocalizedDefault($landing, 'lastName'),
        );
        $sourceName = ($sourceIdentity?->display_name) ?: $player->full_name;

        return $this->normalizer->normalizeName($landingName) === $this->normalizer->normalizeName($sourceName)
            && $this->positionType($landing['position'] ?? null) === $positionType;
    }

    /**
     * @param array<string,mixed> $landing
     */
    private function landingMatchesName(array $landing, string $fullName, ?string $positionType): bool
    {
        if (! isset($landing['playerId'])) {
            return false;
        }

        $landingName = $this->normalizer->displayNameFromParts(
            $this->normalizer->nhlLocalizedDefault($landing, 'firstName'),
            $this->normalizer->nhlLocalizedDefault($landing, 'lastName'),
        );

        if ($this->normalizer->normalizeName($landingName) !== $this->normalizer->normalizeName($fullName)) {
            return false;
        }

        return $positionType === null || $this->positionType($landing['position'] ?? null) === $positionType;
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $candidates
     */
    private function upsertCandidateIdentities($candidates, string $status): void
    {
        foreach ($candidates as $candidate) {
            $identity = PlayerExternalIdentity::firstOrNew([
                'provider' => PlayerExternalIdentity::PROVIDER_NHL,
                'provider_player_id' => (string) $candidate['id'],
            ]);

            if (! $identity->exists) {
                $identity->first_seen_at = Carbon::now();
            }

            $identity->fill([
                'provider_slug' => (string) $candidate['id'],
                'display_name' => trim((string) ($candidate['fullName'] ?? '')) ?: null,
                'normalized_name' => $this->normalizer->normalizeName($candidate['fullName'] ?? null),
                'first_name' => trim((string) ($candidate['firstName'] ?? '')) ?: null,
                'last_name' => trim((string) ($candidate['lastName'] ?? '')) ?: null,
                'position' => $candidate['positionCode'] ?? null,
                'team' => null,
                'raw_payload' => $candidate,
                'match_status' => $status,
                'match_confidence' => null,
                'unmatched_reason' => $status === PlayerExternalIdentity::STATUS_CONFLICT
                    ? PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES
                    : PlayerExternalIdentity::REASON_INSUFFICIENT_IDENTITY_DATA,
                'last_seen_at' => Carbon::now(),
            ]);

            $identity->save();
        }
    }

    /**
     * @param array<string,mixed> $landing
     */
    private function landingPlayerIdAlreadyOwned(array $landing, Player $player): bool
    {
        $playerId = $landing['playerId'] ?? null;

        if ($playerId === null || $playerId === '') {
            return false;
        }

        return Player::query()
            ->where('nhl_id', (int) $playerId)
            ->whereKeyNot($player->id)
            ->exists();
    }

    private function markSourceIdentityConflict(?PlayerExternalIdentity $sourceIdentity): void
    {
        if ($sourceIdentity === null) {
            return;
        }

        $sourceIdentity->fill([
            'player_id' => null,
            'match_status' => PlayerExternalIdentity::STATUS_CONFLICT,
            'match_confidence' => null,
            'unmatched_reason' => PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES,
            'last_seen_at' => Carbon::now(),
        ]);
        $sourceIdentity->save();
    }

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

    private function evidencePositionType(Player $player, ?PlayerExternalIdentity $sourceIdentity): ?string
    {
        return $this->positionType($sourceIdentity?->position)
            ?? $this->positionType($player->position)
            ?? $this->positionType($player->pos_type);
    }

    private function cayenneString(string $value): string
    {
        return str_replace('"', '\"', $value);
    }
}
