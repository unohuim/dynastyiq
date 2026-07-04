<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Traits\HasAPITrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        $owner = $this->landingPlayerOwner($landing, $player);

        if ($owner instanceof Player) {
            $this->mergeDuplicatePlayerIntoOwner($player, $owner);
            $this->playerImporter->importForPlayer(
                $owner->refresh(),
                (string) $landing['playerId'],
                (bool) $owner->is_prospect,
            );
            return;
        }

        $identity = $this->resolver->upsertNhlIdentity($landing);
        $this->resolver->linkIdentityToPlayer($identity, $player);
        $this->playerImporter->importForPlayer($player, (string) $landing['playerId'], (bool) $player->is_prospect);
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
        $firstNameVariants = collect($this->normalizer->firstNameVariants($firstName))
            ->map(fn (string $name): string => $this->displayNameVariant($firstName, $name))
            ->unique()
            ->values();

        return $firstNameVariants
            ->flatMap(function (string $firstNameVariant) use ($lastName): array {
                $payload = $this->getAPIData('nhl_stats', 'players', [], [
                    'limit' => 100,
                    'cayenneExp' => sprintf(
                        'firstName="%s" and lastName="%s"',
                        $this->cayenneString($firstNameVariant),
                        $this->cayenneString($lastName),
                    ),
                ]);

                return is_array($payload['data'] ?? null) ? $payload['data'] : [];
            })
            ->filter(static fn (mixed $candidate): bool => is_array($candidate) && isset($candidate['id']))
            ->filter(fn (array $candidate): bool => $this->candidateNameMatches(
                $candidate,
                $firstName,
                $lastName,
                $fullName,
            ))
            ->unique(static fn (array $candidate): string => (string) $candidate['id'])
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

        return $this->normalizer->firstNamesAreCompatible($candidateFirstName, $firstName)
            && $candidateLastName === $this->normalizer->normalizeName($lastName);
    }

    private function displayNameVariant(string $sourceName, string $normalizedVariant): string
    {
        foreach ((array) config('name_variants.first_name_variants', []) as $canonical => $aliases) {
            foreach ([$canonical, ...((array) $aliases)] as $alias) {
                if ($this->normalizer->normalizeName(is_string($alias) ? $alias : null) === $normalizedVariant) {
                    return (string) $alias;
                }
            }
        }

        return $this->normalizer->normalizeName($sourceName) === $normalizedVariant
            ? $sourceName
            : $normalizedVariant;
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

        return $this->namesAreCompatible($landingName, $sourceName)
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

        if (! $this->namesAreCompatible($landingName, $fullName)) {
            return false;
        }

        return $positionType === null || $this->positionType($landing['position'] ?? null) === $positionType;
    }

    private function namesAreCompatible(?string $name, ?string $otherName): bool
    {
        $normalizedName = $this->normalizer->normalizeName($name);
        $normalizedOtherName = $this->normalizer->normalizeName($otherName);

        if ($normalizedName !== null && $normalizedName === $normalizedOtherName) {
            return true;
        }

        $nameParts = $this->splitName($name);
        $otherNameParts = $this->splitName($otherName);

        if ($nameParts === null || $otherNameParts === null) {
            return false;
        }

        return $nameParts['last'] === $otherNameParts['last']
            && $this->normalizer->firstNamesAreCompatible($nameParts['first'], $otherNameParts['first']);
    }

    /**
     * @return array{first:string,last:string}|null
     */
    private function splitName(?string $name): ?array
    {
        $normalizedName = $this->normalizer->normalizeName($name);

        if ($normalizedName === null) {
            return null;
        }

        $parts = preg_split('/\s+/', $normalizedName) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        $firstName = array_shift($parts);
        $lastName = implode(' ', $parts);

        return $firstName !== '' && $lastName !== ''
            ? ['first' => $firstName, 'last' => $lastName]
            : null;
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
    private function landingPlayerOwner(array $landing, Player $player): ?Player
    {
        $playerId = $landing['playerId'] ?? null;

        if ($playerId === null || $playerId === '') {
            return null;
        }

        return Player::query()
            ->where('nhl_id', (int) $playerId)
            ->whereKeyNot($player->id)
            ->first();
    }

    private function mergeDuplicatePlayerIntoOwner(Player $duplicate, Player $owner): void
    {
        if ((int) $duplicate->id === (int) $owner->id) {
            return;
        }

        DB::transaction(function () use ($duplicate, $owner): void {
            $duplicate = Player::query()->whereKey($duplicate->id)->lockForUpdate()->first();
            $owner = Player::query()->whereKey($owner->id)->lockForUpdate()->first();

            if (! $duplicate || ! $owner || $duplicate->nhl_id !== null) {
                return;
            }

            PlayerExternalIdentity::query()
                ->where('player_id', $duplicate->id)
                ->update(['player_id' => $owner->id]);

            $this->reassignProviderMirrorRows($duplicate, $owner);
            $this->reassignConflictSafePlayerReferences($duplicate, $owner);
            $this->reassignSimplePlayerReferences($duplicate, $owner);
            $this->discardDerivedDuplicateRows($duplicate);

            $duplicate->delete();
        });
    }

    private function reassignProviderMirrorRows(Player $duplicate, Player $owner): void
    {
        DB::table('capwages_players')
            ->where('player_id', $duplicate->id)
            ->update(['player_id' => $owner->id]);

        DB::table('yahoo_players')
            ->where('player_id', $duplicate->id)
            ->update(['player_id' => $owner->id]);

        $ownerHasFantraxRow = DB::table('fantrax_players')
            ->where('player_id', $owner->id)
            ->exists();

        DB::table('fantrax_players')
            ->where('player_id', $duplicate->id)
            ->update(['player_id' => $ownerHasFantraxRow ? null : $owner->id]);
    }

    private function reassignSimplePlayerReferences(Player $duplicate, Player $owner): void
    {
        foreach (['contracts', 'nhl_player_transactions'] as $table) {
            DB::table($table)
                ->where('player_id', $duplicate->id)
                ->update(['player_id' => $owner->id]);
        }
    }

    private function reassignConflictSafePlayerReferences(Player $duplicate, Player $owner): void
    {
        $this->reassignUniquePlayerReference(
            'platform_player_ids',
            $duplicate,
            $owner,
            ['platform'],
        );
        $this->reassignUniquePlayerReference(
            'platform_roster_memberships',
            $duplicate,
            $owner,
            ['platform_team_id', 'starts_at'],
        );
        $this->reassignUniquePlayerReference(
            'player_rankings',
            $duplicate,
            $owner,
            ['ranking_profile_id'],
        );
        $this->reassignUniquePlayerReference(
            'nhl_unit_players',
            $duplicate,
            $owner,
            ['unit_id'],
        );
    }

    /**
     * @param array<int,string> $conflictColumns
     */
    private function reassignUniquePlayerReference(
        string $table,
        Player $duplicate,
        Player $owner,
        array $conflictColumns,
    ): void {
        DB::table($table)
            ->where('player_id', $duplicate->id)
            ->whereExists(function ($query) use ($table, $owner, $conflictColumns): void {
                $query->selectRaw('1')
                    ->from($table . ' as owner_reference')
                    ->where('owner_reference.player_id', $owner->id);

                foreach ($conflictColumns as $column) {
                    $query->whereColumn("owner_reference.{$column}", "{$table}.{$column}");
                }
            })
            ->delete();

        DB::table($table)
            ->where('player_id', $duplicate->id)
            ->update(['player_id' => $owner->id]);
    }

    private function discardDerivedDuplicateRows(Player $duplicate): void
    {
        foreach (['stats', 'season_stats', 'nhl_player_game_strength_summaries'] as $table) {
            DB::table($table)
                ->where('player_id', $duplicate->id)
                ->delete();
        }
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
