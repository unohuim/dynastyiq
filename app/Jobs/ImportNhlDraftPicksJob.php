<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ImportStreamEvent;
use App\Models\ImportRun;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\ImportNHLPlayer;
use App\Services\NhlPlayerIdentityLookup;
use App\Services\NhlTeamReference;
use App\Services\PlayerIdentityNormalizer;
use App\Services\PlayerIdentityResolver;
use App\Traits\HasAPITrait;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Discovers recent NHL draft picks once per NHL player import run.
 */
class ImportNhlDraftPicksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;
    use HasAPITrait;
    use Batchable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        protected string $importRunId,
        private ?int $adminImportRunId = null,
    ) {
    }

    public function handle(
        PlayerIdentityResolver $resolver,
        PlayerIdentityNormalizer $normalizer,
        NhlTeamReference $teams,
    ): void {
        foreach ($this->draftYears() as $year) {
            $payload = $this->getAPIData('nhl', 'draft_picks', [
                'year' => (string) $year,
            ]);

            foreach ($this->draftPickRecords($payload) as $pick) {
                if ($this->draftPickSeenInRun($pick, $normalizer)) {
                    continue;
                }

                $this->incrementProgressTotal();

                try {
                    $draftPlayer = $this->upsertDraftPlayer($pick, $year, $resolver, $normalizer, $teams);

                    if ($draftPlayer === null) {
                        $this->recordProcessedRecord('skipped');
                        continue;
                    }

                    $this->refreshLandingStatsWhenResolvable($pick, $draftPlayer['player'], $draftPlayer['identity']);
                    $this->recordProcessedRecord('successful');
                } catch (Throwable $throwable) {
                    $this->recordDraftPickFailure($pick, $year, $throwable);
                    continue;
                }
            }
        }

        $this->adminImportRun()?->markCompleted();
        ImportStreamEvent::dispatch('nhl', 'NHL player import completed', 'finished');
    }

    public function failed(Throwable $throwable): void
    {
        $this->adminImportRun()?->markFailed($throwable);
    }

    /**
     * @return array<int,int>
     */
    private function draftYears(): array
    {
        $yearsBack = max(1, (int) config('apiImportNhl.draft_years_back', 8));
        $currentYear = (int) Carbon::now()->year;

        return range($currentYear, $currentYear - $yearsBack + 1);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function draftPickRecords(array $payload): array
    {
        $records = [];

        foreach ($this->candidateRows($payload) as $row) {
            if ($this->looksLikeDraftPick($row)) {
                $records[] = $row;
            }
        }

        return $records;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function candidateRows(array $payload): array
    {
        $directRows = $payload['picks']
            ?? $payload['draftPicks']
            ?? $payload['data']
            ?? null;

        if (is_array($directRows) && array_is_list($directRows)) {
            return $this->flattenRows($directRows);
        }

        return $this->flattenRows([$payload]);
    }

    /**
     * @param array<int|string,mixed> $rows
     * @return array<int,array<string,mixed>>
     */
    private function flattenRows(array $rows): array
    {
        $flattened = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($this->looksLikeDraftPick($row)) {
                $flattened[] = $row;
            }

            foreach ($row as $value) {
                if (is_array($value)) {
                    $flattened = array_merge($flattened, $this->flattenRows($value));
                }
            }
        }

        return $flattened;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function looksLikeDraftPick(array $row): bool
    {
        return $this->draftDisplayName($row) !== null
            && (
                $this->overallPick($row) !== null
                || $this->round($row) !== null
                || $this->pickInRound($row) !== null
            );
    }

    /**
     * @param array<string,mixed> $pick
     *
     * @return array{player:Player,identity:PlayerExternalIdentity}|null
     */
    private function upsertDraftPlayer(
        array $pick,
        int $year,
        PlayerIdentityResolver $resolver,
        PlayerIdentityNormalizer $normalizer,
        NhlTeamReference $teams,
    ): ?array {
        $providerPlayerId = $this->draftProviderPlayerId($pick, $year, $normalizer);
        $identity = $resolver->upsertNhlDraftIdentity($providerPlayerId, $this->normalizedDraftPayload($pick, $year));

        if ($identity->player_id !== null) {
            $this->applyDraftFields($identity->player, $pick, $year);

            return $identity->player === null ? null : ['player' => $identity->player, 'identity' => $identity];
        }

        $identity = $resolver->resolveNonAuthorityIdentity($identity);

        if ($identity->player_id !== null) {
            $this->applyDraftFields($identity->player, $pick, $year);

            return $identity->player === null ? null : ['player' => $identity->player, 'identity' => $identity];
        }

        if ($identity->match_status !== PlayerExternalIdentity::STATUS_UNMATCHED) {
            return null;
        }

        $displayName = (string) $identity->display_name;
        [$firstName, $lastName] = $this->nameParts($displayName);
        $position = $identity->position;

        $player = Player::create([
            'nhl_id' => null,
            'nhl_team_id' => $this->teamId($pick) ?? $teams->idForAbbrev($identity->team),
            'team_abbrev' => $identity->team,
            'first_name' => $identity->first_name ?: $firstName,
            'last_name' => $identity->last_name ?: $lastName,
            'full_name' => $displayName,
            'dob' => $identity->birthdate?->toDateString(),
            'country_code' => $this->countryCode($pick),
            'position' => $position,
            'pos_type' => $this->positionType($position),
            'current_league_abbrev' => $this->currentLeague($pick),
            ...$this->draftFields($pick, $year),
            'is_prospect' => true,
            'is_goalie' => $this->positionType($position) === 'G',
        ]);

        $resolver->linkIdentityToPlayer($identity, $player);

        return ['player' => $player, 'identity' => $identity->refresh()];
    }

    /**
     * @param array<string,mixed> $pick
     * @param Player $player
     * @param PlayerExternalIdentity $draftIdentity
     */
    private function refreshLandingStatsWhenResolvable(
        array $pick,
        Player $player,
        PlayerExternalIdentity $draftIdentity,
    ): void
    {
        $nhlPlayerId = $this->draftNhlPlayerId($pick)
            ?? ($player->nhl_id === null || $player->nhl_id === '' ? null : (int) $player->nhl_id);

        if ($nhlPlayerId === null) {
            app(NhlPlayerIdentityLookup::class)->enrich($player, $draftIdentity);
            return;
        }

        $refreshedPlayer = app(ImportNHLPlayer::class)->importForPlayer($player, (string) $nhlPlayerId, true);

        if ($refreshedPlayer->id !== $player->id) {
            app(PlayerIdentityResolver::class)->linkIdentityToPlayer($draftIdentity, $refreshedPlayer);
        }

        $this->applyDraftFields($refreshedPlayer, $pick, (int) ($draftIdentity->raw_payload['draft_year'] ?? 0));
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function applyDraftFields(?Player $player, array $pick, int $year): void
    {
        if ($player === null) {
            return;
        }

        $player->forceFill($this->draftFields($pick, $year))->save();
    }

    /**
     * @param array<string,mixed> $pick
     * @return array{draft_year:int|null,draft_round:int|null,draft_round_pick:int|null,draft_oa:int|null}
     */
    private function draftFields(array $pick, int $year): array
    {
        return [
            'draft_year' => $year > 0 ? $year : null,
            'draft_round' => $this->round($pick),
            'draft_round_pick' => $this->pickInRound($pick),
            'draft_oa' => $this->overallPick($pick),
        ];
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function draftPickSeenInRun(array $pick, PlayerIdentityNormalizer $normalizer): bool
    {
        $normalizedName = $normalizer->normalizeName($this->draftDisplayName($pick));
        $positionType = $this->positionType($this->position($pick));

        if ($normalizedName === null || $positionType === null) {
            return false;
        }

        return ! Cache::add($this->fingerprintKey($normalizedName, $positionType), true, 3500);
    }

    private function fingerprintKey(string $normalizedName, string $positionType): string
    {
        return 'nhl-import:'
            . $this->importRunId
            . ':fingerprint:'
            . sha1($normalizedName . '|' . $positionType);
    }

    private function adminImportRun(): ?ImportRun
    {
        if ($this->adminImportRunId === null) {
            return null;
        }

        return ImportRun::query()->find($this->adminImportRunId);
    }

    private function incrementProgressTotal(): void
    {
        $this->adminImportRun()?->incrementProgressTotal(label: 'NHL player records');
        $this->broadcastProgress();
    }

    private function recordProcessedRecord(string $result): void
    {
        $this->adminImportRun()?->recordProcessed($result);
        $this->broadcastProgress();
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function recordDraftPickFailure(array $pick, int $year, Throwable $throwable): void
    {
        $this->recordProcessedRecord('failed');

        $entry = [
            'draft_year' => $year,
            'display_name' => $this->draftDisplayName($pick),
            'nhl_player_id' => $this->draftNhlPlayerId($pick),
            'provider_player_id' => $this->draftProviderPlayerId($pick, $year, app(PlayerIdentityNormalizer::class)),
            'error' => $throwable->getMessage(),
        ];

        Log::warning('NHL draft pick import failure; skipping pick', $entry);
        $this->appendImportRunMeta('draft_pick_failures', $entry);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function appendImportRunMeta(string $key, array $entry): void
    {
        $importRun = $this->adminImportRun();

        if ($importRun === null) {
            return;
        }

        $meta = $importRun->meta ?? [];
        $items = is_array($meta[$key] ?? null) ? $meta[$key] : [];
        $items[] = $entry;
        $meta[$key] = $items;

        $importRun->forceFill(['meta' => $meta])->save();
    }

    private function broadcastProgress(): void
    {
        ImportStreamEvent::dispatch(
            'nhl',
            'Processed NHL draft player records',
            'progress'
        );
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function draftProviderPlayerId(array $pick, int $year, PlayerIdentityNormalizer $normalizer): string
    {
        $overallPick = $this->overallPick($pick);

        if ($overallPick !== null) {
            return "{$year}:{$overallPick}";
        }

        $team = (string) ($this->team($pick) ?? 'unknown');
        $round = (string) ($this->round($pick) ?? 'unknown');
        $pickInRound = (string) ($this->pickInRound($pick) ?? 'unknown');
        $name = $normalizer->normalizeName($this->draftDisplayName($pick)) ?? 'unknown';

        return "{$year}:{$team}:{$round}:{$pickInRound}:{$name}";
    }

    /**
     * @param array<string,mixed> $pick
     * @return array<string,mixed>
     */
    private function normalizedDraftPayload(array $pick, int $year): array
    {
        return array_merge($pick, [
            'draft_year' => $year,
            'display_name' => $this->draftDisplayName($pick),
            'position' => $this->position($pick),
            'team' => $this->team($pick),
            'team_id' => $this->teamId($pick),
            'country_code' => $this->countryCode($pick),
            'birthdate' => $pick['birthDate'] ?? $pick['dateOfBirth'] ?? null,
        ]);
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function draftDisplayName(array $pick): ?string
    {
        $name = $pick['name'] ?? $pick['playerName'] ?? $pick['fullName'] ?? null;

        if (is_array($name)) {
            $name = $name['default'] ?? null;
        }

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $firstName = $this->localizedString($pick['firstName'] ?? null);
        $lastName = $this->localizedString($pick['lastName'] ?? null);
        $displayName = trim("{$firstName} {$lastName}");

        return $displayName === '' ? null : $displayName;
    }

    private function localizedString(mixed $value): string
    {
        if (is_array($value)) {
            return trim((string) ($value['default'] ?? ''));
        }

        return trim((string) $value);
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

    /**
     * @param array<string,mixed> $pick
     */
    private function position(array $pick): ?string
    {
        $position = $pick['position'] ?? $pick['positionCode'] ?? $pick['pos'] ?? null;

        if (is_array($position)) {
            $position = $position['code'] ?? $position['abbrev'] ?? $position['default'] ?? null;
        }

        return is_string($position) && trim($position) !== '' ? trim($position) : null;
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function team(array $pick): ?string
    {
        $team = $pick['teamAbbrev']
            ?? $pick['triCode']
            ?? $pick['team']
            ?? $pick['club']
            ?? null;

        if (is_array($team)) {
            $team = $team['abbrev'] ?? $team['triCode'] ?? $team['default'] ?? null;
        }

        return is_string($team) && trim($team) !== '' ? trim($team) : null;
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function teamId(array $pick): ?int
    {
        return $this->integerValue($pick['teamId'] ?? $pick['draftTeamId'] ?? null);
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function countryCode(array $pick): ?string
    {
        $countryCode = $pick['countryCode'] ?? $pick['country'] ?? null;

        if (is_array($countryCode)) {
            $countryCode = $countryCode['default'] ?? null;
        }

        return is_string($countryCode) && trim($countryCode) !== '' ? trim($countryCode) : null;
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function currentLeague(array $pick): ?string
    {
        $league = $pick['leagueAbbrev'] ?? $pick['amateurLeague'] ?? $pick['league'] ?? null;

        if (is_array($league)) {
            $league = $league['abbrev'] ?? $league['default'] ?? null;
        }

        return is_string($league) && trim($league) !== '' ? trim($league) : null;
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function draftNhlPlayerId(array $pick): ?int
    {
        return $this->integerValue($pick['playerId'] ?? $pick['nhlId'] ?? null);
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
     * @param array<string,mixed> $pick
     */
    private function overallPick(array $pick): ?int
    {
        return $this->integerValue($pick['overallPick'] ?? $pick['pickOverall'] ?? $pick['overall'] ?? null);
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function round(array $pick): ?int
    {
        return $this->integerValue($pick['round'] ?? $pick['roundNumber'] ?? null);
    }

    /**
     * @param array<string,mixed> $pick
     */
    private function pickInRound(array $pick): ?int
    {
        return $this->integerValue($pick['pickInRound'] ?? $pick['roundPick'] ?? null);
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
