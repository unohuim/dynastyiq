<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CapWagesPlayerNotFoundException;
use App\Exceptions\PlayerNotFoundException;
use App\Jobs\ImportNHLPlayerJob;
use App\Models\CapWagesPlayer;
use App\Models\Contract;
use App\Models\NhlPlayerTransaction;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Traits\HasAPITrait;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles import of a single player's contract details from CapWages API.
 */
class ImportCapWagesPlayer
{
    use HasAPITrait;

    public function __construct(
        private readonly ?PlayerIdentityResolver $identityResolver = null,
        private readonly ?PlayerIdentityNormalizer $normalizer = null,
        private readonly ?NhlTeamReference $teams = null,
    ) {
    }

    /**
     * Import contract data (including seasons) for a given player slug.
     *
     * @param string $slug
     * @param bool   $all  When true, preserve existing behavior (dispatch ImportNHLPlayerJob and throw if Player missing).
     *                    When false, skip importing if local Player is missing (no dispatch, no exception).
     */
    public function syncBySlug(string $slug, bool $all = true): bool
    {
        $data = $this->cachedPlayerDetail($slug) ?? $this->fetchPlayerDetail($slug);
        $nhlId = $data['nhlId'] ?? null;

        if (! $this->hasImportableContractSeason($data)) {
            Log::info('Skipping CapWages player without an importable contract season', [
                'slug' => $slug,
                'nhl_id' => $nhlId,
            ]);

            return false;
        }

        $resolver = $this->identityResolver ?? app(PlayerIdentityResolver::class);
        $identity = $resolver->upsertCapWagesIdentity($slug, $data);
        $this->upsertCapWagesPlayer($identity, $slug, $data);
        $knownPlayer = $nhlId ? Player::where('nhl_id', $nhlId)->first() : null;
        $identity = $resolver->resolveNonAuthorityIdentity($identity, $knownPlayer);
        $this->upsertCapWagesPlayer($identity, $slug, $data);
        $player = $identity->player;

        if (
            ! $player
            && ! $nhlId
            && $identity->unmatched_reason === PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER
        ) {
            $player = $this->createCanonicalPlayerFromCapWages($identity, $data);
            $identity = $resolver->linkIdentityToPlayer($identity, $player);
            $this->upsertCapWagesPlayer($identity, $slug, $data);
        }

        if (! $nhlId && ! $player) {
            Log::warning("CapWages player slug {$slug} missing nhl_id");
            return false;
        }

        if (! $player) {
            Log::warning("No local Player for NHL ID {$nhlId}", ['slug' => $slug, 'all' => $all]);

            if ($all) {
                ImportNHLPlayerJob::dispatch($nhlId);
                throw new PlayerNotFoundException("from service: Player with NHL ID {$nhlId} not found in DB.");
            }

            // all=false: do not import the contract; just exit quietly.
            return false;
        }

        $this->writeContractsForPlayer($player, $data, $slug, $nhlId);

        return true;
    }

    /**
     * Create a canonical non-prospect player from an eligible CapWages contract payload.
     *
     * @param array<string,mixed> $payload
     */
    private function createCanonicalPlayerFromCapWages(PlayerExternalIdentity $identity, array $payload): Player
    {
        $displayName = trim((string) ($payload['name'] ?? $identity->display_name ?? ''));
        [$firstName, $lastName] = $this->nameParts($displayName);
        $position = $identity->position ?: ($payload['position'] ?? null);
        $positionType = $this->positionType($position);
        $teams = $this->teams ?? app(NhlTeamReference::class);

        return Player::create([
            'nhl_id' => null,
            'nhl_team_id' => $teams->idForAbbrev($identity->team),
            'team_abbrev' => $identity->team,
            'first_name' => $identity->first_name ?: $firstName,
            'last_name' => $identity->last_name ?: $lastName,
            'full_name' => $displayName,
            'dob' => $identity->birthdate?->toDateString(),
            'country_code' => $this->capWagesNationality($payload),
            'is_prospect' => false,
            'is_goalie' => $positionType === 'G',
            'position' => $position,
            'pos_type' => $positionType,
            'current_league_abbrev' => $payload['leagueStatus'] ?? null,
            'status' => 'active',
            'height' => $this->capWagesHeight($payload),
            'weight' => $this->capWagesWeight($payload),
            'shoots' => $this->capWagesShoots($payload),
            'meta' => [
                'created_from_external_identity' => [
                    'provider' => $identity->provider,
                    'provider_player_id' => $identity->provider_player_id,
                ],
            ],
        ]);
    }

    /**
     * Materialize contracts from cached CapWages profile data for a linked identity.
     */
    public function materializeCachedContractsForLinkedIdentity(PlayerExternalIdentity $identity): bool
    {
        $identity->refresh();

        if ($identity->provider !== PlayerExternalIdentity::PROVIDER_CAPWAGES || $identity->player_id === null) {
            return false;
        }

        $capWagesPlayer = CapWagesPlayer::query()
            ->where('player_external_identity_id', $identity->id)
            ->whereNotNull('raw_payload')
            ->first();

        if (! $capWagesPlayer || ! is_array($capWagesPlayer->raw_payload)) {
            return false;
        }

        $player = Player::find($identity->player_id);
        if (! $player) {
            return false;
        }

        if ((int) $capWagesPlayer->player_id !== (int) $identity->player_id) {
            $capWagesPlayer->player_id = $identity->player_id;
            $capWagesPlayer->save();
        }

        $slug = $capWagesPlayer->slug ?: ($identity->provider_slug ?: $identity->provider_player_id);
        $nhlId = $capWagesPlayer->raw_payload['nhlId'] ?? null;

        $this->writeContractsForPlayer($player, $capWagesPlayer->raw_payload, (string) $slug, $nhlId);

        return true;
    }

    /**
     * Re-fetch CapWages detail and materialize contracts for an already linked identity.
     */
    public function refreshContractsForLinkedIdentity(PlayerExternalIdentity $identity): void
    {
        $identity->refresh();

        if ($identity->provider !== PlayerExternalIdentity::PROVIDER_CAPWAGES || $identity->player_id === null) {
            return;
        }

        $slug = $identity->provider_slug ?: $identity->provider_player_id;
        if ($slug === null || $slug === '') {
            Log::warning('Cannot refresh CapWages contracts for identity without slug', [
                'identity_id' => $identity->id,
            ]);

            return;
        }

        $data = $this->fetchPlayerDetail($slug);
        $nhlId = $data['nhlId'] ?? null;

        $this->refreshIdentityPayload($identity, $slug, $data);
        $this->upsertCapWagesPlayer($identity->refresh(), $slug, $data);

        if (! $this->hasImportableContractSeason($data)) {
            Log::info('Skipping CapWages contract refresh without an importable contract season', [
                'identity_id' => $identity->id,
                'slug' => $slug,
                'nhl_id' => $nhlId,
            ]);

            return;
        }

        $player = Player::find($identity->player_id);
        if (! $player) {
            return;
        }

        $this->writeContractsForPlayer($player, $data, $slug, $nhlId);
    }

    /**
     * Fetch one CapWages player detail payload.
     *
     * @return array<string,mixed>
     */
    private function fetchPlayerDetail(string $slug): array
    {
        try {
            $response = $this->getAPIData('capwages', 'player_detail', ['slug' => $slug]);
        } catch (RequestException $e) {
            $status = $e->response->status();
            $body = mb_substr($e->response->body(), 0, 500);

            Log::error('CapWages player detail request failed', [
                'slug' => $slug,
                'status' => $status,
                'body' => $body,
            ]);

            if ($status === 404) {
                throw new CapWagesPlayerNotFoundException("Player with slug {$slug} not found at CapWages.", 0, $e);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('CapWages player detail request failed unexpectedly', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (isset($response['meta'])) {
            $data['_meta'] = $response['meta'];
        }

        return $data;
    }

    /**
     * Get previously fetched CapWages detail payload for a slug.
     *
     * @return array<string,mixed>|null
     */
    private function cachedPlayerDetail(string $slug): ?array
    {
        $capWagesPlayer = CapWagesPlayer::query()
            ->where('slug', $slug)
            ->whereNotNull('raw_payload')
            ->first();

        if (! $capWagesPlayer || ! is_array($capWagesPlayer->raw_payload) || $capWagesPlayer->raw_payload === []) {
            return null;
        }

        if (! $capWagesPlayer->api_last_updated?->isToday()) {
            return null;
        }

        return $capWagesPlayer->raw_payload;
    }

    /**
     * Write CapWages contracts and seasons to the linked player.
     *
     * @param array<string,mixed> $data
     * @param int|string|null $nhlId
     */
    private function writeContractsForPlayer(Player $player, array $data, string $slug, int|string|null $nhlId): void
    {
        foreach ($data['contracts'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $seasonRows = array_values(array_filter(
                $entry['seasons'] ?? [],
                fn (mixed $seasonData): bool => is_array($seasonData) && $this->isPayableContractSeason($seasonData),
            ));

            if ($seasonRows === []) {
                continue;
            }

            $signingDate = isset($entry['signingDate'])
                ? Carbon::parse((string) $entry['signingDate'])->toDateString()
                : null;

            $contractQuery = Contract::query()
                ->where('player_id', $player->id)
                ->where('contract_type', $entry['contractType'] ?? 'Unknown');

            $contract = $contractQuery->get()
                ->first(static function (Contract $contract) use ($signingDate): bool {
                    if ($signingDate === null) {
                        return $contract->signing_date === null;
                    }

                    return $contract->signing_date?->toDateString() === $signingDate;
                }) ?? new Contract([
                    'player_id' => $player->id,
                    'contract_type' => $entry['contractType'] ?? 'Unknown',
                    'signing_date' => $signingDate,
                ]);

            $contract->fill([
                'contract_length' => $entry['contractLength'] ?? null,
                'contract_value'  => $entry['contractValue']  ?? null,
                'expiry_status'   => $entry['expiryStatus']   ?? null,
                'signing_team'    => $entry['signingTeam']    ?? null,
                'signing_date'    => $signingDate,
                'signed_by'       => $entry['signedBy']       ?? null,
            ]);
            $contract->save();

            foreach ($seasonRows as $seasonData) {
                $rawSeason = (string) ($seasonData['season'] ?? '');

                $parsed = $this->parseSeason($rawSeason);
                if ($parsed === null) {
                    Log::warning('Skipping season row; unparseable season string', [
                        'slug'      => $slug,
                        'nhl_id'    => $nhlId,
                        'rawSeason' => $rawSeason,
                    ]);
                    continue; // never insert season_key=0
                }

                [$seasonKey, $label, $suffix] = $parsed;

                $contract->seasons()->updateOrCreate(
                    ['season_key' => $seasonKey],
                    [
                        'label'               => $label,
                        'clause'              => $this->mergeClause($seasonData['clause'] ?? null, $suffix),
                        'cap_hit'             => $seasonData['capHit']              ?? null,
                        'aav'                 => $seasonData['aav']                 ?? null,
                        'performance_bonuses' => $seasonData['performanceBonuses'] ?? 0,
                        'signing_bonuses'     => $seasonData['signingBonuses']     ?? 0,
                        'base_salary'         => $seasonData['baseSalary']         ?? null,
                        'total_salary'        => $seasonData['totalSalary']        ?? null,
                        'minors_salary'       => $seasonData['minorsSalary']       ?? null,
                    ]
                );
            }
        }
    }

    /**
     * Refresh identity display fields and raw payload from CapWages detail.
     *
     * @param array<string,mixed> $payload
     */
    private function refreshIdentityPayload(PlayerExternalIdentity $identity, string $slug, array $payload): void
    {
        $normalizer = $this->normalizer ?? app(PlayerIdentityNormalizer::class);
        $teams = $this->teams ?? app(NhlTeamReference::class);
        $displayName = trim((string) ($payload['name'] ?? $payload['fullName'] ?? '')) ?: null;
        $firstName = trim((string) ($payload['firstName'] ?? '')) ?: null;
        $lastName = trim((string) ($payload['lastName'] ?? '')) ?: null;
        $personalInfo = is_array($payload['personalInfo'] ?? null) ? $payload['personalInfo'] : [];

        if ($displayName === null) {
            $displayName = $normalizer->displayNameFromParts($firstName, $lastName);
        }

        $identity->fill([
            'provider_slug' => $slug,
            'display_name' => $displayName,
            'normalized_name' => $normalizer->normalizeName(is_string($displayName) ? $displayName : null),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $personalInfo['birthDate'] ?? $payload['birthDate'] ?? $payload['dob'] ?? null,
            'position' => $payload['position'] ?? null,
            'team' => $teams->normalizeToAbbrev($payload['team'] ?? $payload['currentTeamAbbrev'] ?? null),
            'raw_payload' => $payload,
            'last_seen_at' => Carbon::now(),
        ]);
        $identity->save();
    }

    /**
     * Upsert provider-owned CapWages player profile data.
     *
     * @param array<string,mixed> $payload
     */
    private function upsertCapWagesPlayer(PlayerExternalIdentity $identity, string $slug, array $payload): CapWagesPlayer
    {
        $personalInfo = is_array($payload['personalInfo'] ?? null) ? $payload['personalInfo'] : [];
        $physicalAttributes = is_array($payload['physicalAttributes'] ?? null) ? $payload['physicalAttributes'] : [];
        $height = is_array($physicalAttributes['height'] ?? null) ? $physicalAttributes['height'] : [];
        $weight = is_array($physicalAttributes['weight'] ?? null) ? $physicalAttributes['weight'] : [];
        $acquisition = is_array($payload['acquisition'] ?? null) ? $payload['acquisition'] : [];
        $ageLimits = is_array($payload['ageLimits'] ?? null) ? $payload['ageLimits'] : [];

        $capWagesPlayer = CapWagesPlayer::updateOrCreate(
            ['slug' => $slug],
            [
                'player_external_identity_id' => $identity->id,
                'player_id' => $identity->player_id,
                'name' => $payload['name'] ?? null,
                'team' => $payload['team'] ?? null,
                'position' => $payload['position'] ?? null,
                'league_status' => $payload['leagueStatus'] ?? null,
                'nhl_id' => $this->nullableInt($payload['nhlId'] ?? null),
                'jersey_number' => $this->nullableInt($payload['jerseyNumber'] ?? null),
                'birth_date' => $personalInfo['birthDate'] ?? null,
                'birth_place' => $personalInfo['birthPlace'] ?? null,
                'nationality' => $personalInfo['nationality'] ?? null,
                'hand' => $physicalAttributes['hand'] ?? null,
                'height_imperial' => $height['imperial'] ?? null,
                'height_cm' => $this->nullableInt($height['metric'] ?? null),
                'weight_imperial' => $weight['imperial'] ?? null,
                'weight_kg' => $this->nullableInt($weight['metric'] ?? null),
                'acquisition_method' => $acquisition['method'] ?? null,
                'acquisition_details' => $acquisition['details'] ?? null,
                'acquisition_year' => $this->nullableInt($acquisition['year'] ?? null),
                'acquisition_round' => $this->nullableInt($acquisition['round'] ?? null),
                'acquisition_overall_pick' => $this->nullableInt($acquisition['overallPick'] ?? null),
                'acquisition_draft_team' => $acquisition['draftTeam'] ?? null,
                'elc_signing_age' => $this->nullableInt($ageLimits['entryLevelContractSigningAge'] ?? null),
                'waivers_eligibility_age' => $this->nullableInt($ageLimits['waiversEligibilityAge'] ?? null),
                'api_last_updated' => $this->apiLastUpdatedFromPayload($payload),
                'raw_payload' => $payload,
            ],
        );

        $this->upsertCapWagesAcquisitionTransaction($identity, $slug, $payload);

        return $capWagesPlayer;
    }

    /**
     * Persist CapWages acquisition details as real hockey transaction history.
     *
     * @param array<string,mixed> $payload
     */
    private function upsertCapWagesAcquisitionTransaction(
        PlayerExternalIdentity $identity,
        string $slug,
        array $payload,
    ): void {
        $acquisition = is_array($payload['acquisition'] ?? null) ? $payload['acquisition'] : [];

        if ($acquisition === []) {
            return;
        }

        $description = trim((string) ($acquisition['details'] ?? ''));
        $method = trim((string) ($acquisition['method'] ?? ''));

        if ($description === '' && $method === '') {
            return;
        }

        NhlPlayerTransaction::updateOrCreate(
            [
                'source_key' => $this->capWagesAcquisitionSourceKey($slug, $acquisition),
            ],
            [
                'player_id' => $identity->player_id,
                'player_external_identity_id' => $identity->id,
                'source' => NhlPlayerTransaction::SOURCE_CAPWAGES,
                'source_transaction_id' => null,
                'transaction_date' => $this->capWagesAcquisitionTransactionDate($method, $description),
                'transaction_type' => $method !== '' ? mb_strtolower($method) : null,
                'description' => $description !== '' ? $description : null,
                'from_team' => null,
                'to_team' => $identity->team,
                'raw_payload' => [
                    'slug' => $slug,
                    'acquisition' => $acquisition,
                ],
            ],
        );
    }

    /**
     * Build a deterministic key for provider acquisition rows.
     *
     * @param array<string,mixed> $acquisition
     */
    private function capWagesAcquisitionSourceKey(string $slug, array $acquisition): string
    {
        return 'capwages:acquisition:'
            . sha1(json_encode([
                'slug' => $slug,
                'method' => $acquisition['method'] ?? null,
                'details' => $acquisition['details'] ?? null,
                'year' => $acquisition['year'] ?? null,
                'round' => $acquisition['round'] ?? null,
                'overallPick' => $acquisition['overallPick'] ?? null,
                'draftTeam' => $acquisition['draftTeam'] ?? null,
            ], JSON_THROW_ON_ERROR));
    }

    /**
     * Extract an explicit transaction date from CapWages acquisition prose.
     */
    private function capWagesAcquisitionTransactionDate(string $method, string $description): ?string
    {
        if (mb_strtolower(trim($method)) === 'draft' || trim($description) === '') {
            return null;
        }

        return $this->dateFromMonthNameText($description)
            ?? $this->dateFromNumericText($description);
    }

    /**
     * Parse dates such as "July 1, 2024", "Jan. 30, 2023", or "Mar. 07 2025".
     */
    private function dateFromMonthNameText(string $description): ?string
    {
        $monthMap = $this->capWagesMonthMap();
        $monthPattern = implode('|', array_keys($monthMap));

        if (! preg_match_all(
            '/\b(' . $monthPattern . ')\.?\s+(\d{1,2})(?:,)?\s+(\d{4})\b/i',
            $description,
            $matches,
            PREG_SET_ORDER,
        )) {
            return null;
        }

        foreach ($matches as $match) {
            $month = $monthMap[mb_strtolower($match[1])] ?? null;
            $day = (int) $match[2];
            $year = (int) $match[3];

            if ($month !== null && checkdate($month, $day, $year)) {
                return Carbon::createFromDate($year, $month, $day)->toDateString();
            }
        }

        return null;
    }

    /**
     * Parse numeric dates such as "8/14/19" or "08/14/2019".
     */
    private function dateFromNumericText(string $description): ?string
    {
        if (! preg_match_all(
            '/\b(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})\b/',
            $description,
            $matches,
            PREG_SET_ORDER,
        )) {
            return null;
        }

        foreach ($matches as $match) {
            $month = (int) $match[1];
            $day = (int) $match[2];
            $year = $this->capWagesTransactionYear((string) $match[3]);

            if (checkdate($month, $day, $year)) {
                return Carbon::createFromDate($year, $month, $day)->toDateString();
            }
        }

        return null;
    }

    /**
     * Expand CapWages two-digit years into modern hockey transaction years.
     */
    private function capWagesTransactionYear(string $year): int
    {
        if (strlen($year) === 4) {
            return (int) $year;
        }

        $twoDigitYear = (int) $year;

        return $twoDigitYear >= 70 ? 1900 + $twoDigitYear : 2000 + $twoDigitYear;
    }

    /**
     * Map accepted CapWages month labels to month numbers.
     *
     * @return array<string,int>
     */
    private function capWagesMonthMap(): array
    {
        return [
            'jan' => 1,
            'january' => 1,
            'feb' => 2,
            'february' => 2,
            'mar' => 3,
            'march' => 3,
            'apr' => 4,
            'april' => 4,
            'may' => 5,
            'jun' => 6,
            'june' => 6,
            'jul' => 7,
            'july' => 7,
            'aug' => 8,
            'august' => 8,
            'sep' => 9,
            'sept' => 9,
            'september' => 9,
            'oct' => 10,
            'october' => 10,
            'nov' => 11,
            'november' => 11,
            'dec' => 12,
            'december' => 12,
        ];
    }

    /**
     * Extract API last-updated metadata from response data when present.
     *
     * @param array<string,mixed> $payload
     */
    private function apiLastUpdatedFromPayload(array $payload): ?Carbon
    {
        $lastUpdated = $payload['meta']['lastUpdated'] ?? $payload['_meta']['lastUpdated'] ?? null;

        return $lastUpdated ? Carbon::parse((string) $lastUpdated) : null;
    }

    /**
     * Convert provider numeric fields to integers, preserving invalid provider text only in raw_payload.
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{string,string}
     */
    private function nameParts(string $displayName): array
    {
        $parts = preg_split('/\s+/', trim($displayName)) ?: [];
        $firstName = array_shift($parts) ?: $displayName;
        $lastName = trim(implode(' ', $parts));

        return [$firstName, $lastName === '' ? $firstName : $lastName];
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

    /**
     * @param array<string,mixed> $payload
     */
    private function capWagesNationality(array $payload): ?string
    {
        $personalInfo = is_array($payload['personalInfo'] ?? null) ? $payload['personalInfo'] : [];
        $nationality = $personalInfo['nationality'] ?? null;

        return is_string($nationality) && trim($nationality) !== '' ? trim($nationality) : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function capWagesHeight(array $payload): ?string
    {
        $physicalAttributes = is_array($payload['physicalAttributes'] ?? null) ? $payload['physicalAttributes'] : [];
        $height = is_array($physicalAttributes['height'] ?? null) ? $physicalAttributes['height'] : [];
        $value = $height['imperial'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function capWagesWeight(array $payload): ?int
    {
        $physicalAttributes = is_array($payload['physicalAttributes'] ?? null) ? $payload['physicalAttributes'] : [];
        $weight = is_array($physicalAttributes['weight'] ?? null) ? $physicalAttributes['weight'] : [];
        $value = $weight['imperial'] ?? null;

        if (is_string($value) && preg_match('/(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function capWagesShoots(array $payload): ?string
    {
        $physicalAttributes = is_array($payload['physicalAttributes'] ?? null) ? $payload['physicalAttributes'] : [];
        $hand = mb_strtoupper(trim((string) ($physicalAttributes['hand'] ?? '')));

        return match ($hand) {
            'LEFT', 'L' => 'L',
            'RIGHT', 'R' => 'R',
            default => null,
        };
    }

    /**
     * Parse season strings like "2028–29 LY", "2015-16", "2015-2016".
     * Returns [seasonKey(int), label("YYYY-YY"), suffix|null] or null if unparseable.
     */
    private function parseSeason(string $raw): ?array
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }

        // Normalize dashes to ASCII hyphen
        $sNorm = str_replace(["\u{2013}", "\u{2014}", "\u{2212}", "\u{2010}", '–', '—', '−', '-'], '-', $s);

        // Capture "YYYY-YY" or "YYYY-YYYY" at the start, allow trailing text (e.g., " LY", "(retained)")
        if (!preg_match('/^\s*(\d{4})\s*-\s*(\d{2}|\d{4})\b(.*)$/u', $sNorm, $m)) {
            return null;
        }

        $startYear = (int) $m[1];
        $endPart   = $m[2];
        $trailing  = trim($m[3]);

        // End year: if 2 digits, assume start+1; if 4 digits, trust it.
        $endYear = (strlen($endPart) === 2) ? ($startYear + 1) : (int) $endPart;

        // Canonical label "YYYY-YY" (exactly 7 chars)
        $label = sprintf('%04d-%02d', $startYear, $endYear % 100);

        // Derive suffix from trailing text, stripping wrappers
        $suffix = trim($trailing, " \t\n\r\0\x0B()[]");
        if ($suffix === '') {
            $suffix = null;
        }

        $seasonKey = ($startYear * 10000) + $endYear;

        return [$seasonKey, $label, $suffix];
    }

    /**
     * Determine whether a CapWages payload has any payable contract season relevant to the current calendar year.
     *
     * @param array<string,mixed> $payload
     */
    private function hasImportableContractSeason(array $payload): bool
    {
        $latestSeasonKey = $this->latestPayableContractSeasonKey($payload);

        return $latestSeasonKey !== null && $latestSeasonKey >= $this->minimumImportableSeasonKey();
    }

    /**
     * Return the latest season key from real payable contract seasons only.
     *
     * @param array<string,mixed> $payload
     */
    private function latestPayableContractSeasonKey(array $payload): ?int
    {
        $maxSeasonKey = null;

        foreach ($payload['contracts'] ?? [] as $contract) {
            if (! is_array($contract)) {
                continue;
            }

            foreach ($contract['seasons'] ?? [] as $seasonData) {
                if (! is_array($seasonData) || ! $this->isPayableContractSeason($seasonData)) {
                    continue;
                }

                $parsed = $this->parseSeason((string) ($seasonData['season'] ?? ''));

                if ($parsed === null) {
                    continue;
                }

                $maxSeasonKey = max($maxSeasonKey ?? 0, (int) $parsed[0]);
            }
        }

        return $maxSeasonKey;
    }

    /**
     * CapWages includes buyout/dead-cap rows in old contracts; those must not qualify imports.
     *
     * @param array<string,mixed> $seasonData
     */
    private function isPayableContractSeason(array $seasonData): bool
    {
        if (is_array($seasonData['buyout'] ?? null)) {
            return false;
        }

        foreach (['capHit', 'aav', 'baseSalary', 'totalSalary', 'minorsSalary'] as $field) {
            if ((int) ($seasonData[$field] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the minimum season key from the current calendar year.
     */
    private function minimumImportableSeasonKey(): int
    {
        $year = Carbon::now()->year;

        return (($year - 1) * 10000) + $year;
    }

    /**
     * Merge API-provided clause with parsed suffix, truncating to fit varchar(255).
     */
    private function mergeClause(?string $apiClause, ?string $suffix): ?string
    {
        $parts = array_values(array_filter([trim((string) $apiClause) ?: null, $suffix], fn ($v) => $v !== null));
        if (empty($parts)) {
            return null;
        }
        $merged = implode(' | ', $parts);
        return mb_substr($merged, 0, 255);
    }
}
