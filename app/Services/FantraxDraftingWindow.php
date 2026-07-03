<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

final class FantraxDraftingWindow
{
    /**
     * Build a stable community league drafting payload from Fantrax responses.
     *
     * @param array<string,mixed> $leagueInfo
     * @param array<string,mixed> $draftResults
     * @param array<string,array<string,mixed>> $playerNamesByFantraxId
     * @param array<string,array<string,mixed>> $teamMetaByFantraxId
     * @param array<string,mixed> $draftPickInfo
     *
     * @return array<string,mixed>
     */
    public function normalize(
        array $leagueInfo,
        array $draftResults,
        ?Throwable $error = null,
        ?CarbonInterface $now = null,
        array $playerNamesByFantraxId = [],
        array $teamMetaByFantraxId = [],
        array $draftPickInfo = []
    ): array {
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $draftAt = $this->draftAt($leagueInfo, $draftResults);
        $rows = $this->draftedRows(
            $draftResults,
            $this->teamNamesById($leagueInfo),
            $playerNamesByFantraxId,
            $teamMetaByFantraxId
        );
        $rounds = $this->rounds($rows);
        $status = $this->status($draftAt, $now, $draftPickInfo, $error);

        return [
            'available' => $error === null,
            'title' => $draftAt?->format('F j, Y') ?? 'Draft',
            'draft_at' => $draftAt?->toIso8601String(),
            'is_live' => $status['text'] === 'Live',
            'status_text' => $status['text'],
            'status_tone' => $status['tone'],
            'rows' => $rows,
            'rounds' => $rounds,
            'active_round_index' => $this->activeRoundIndex($rounds),
            'empty_text' => 'No drafted players yet.',
            'error_text' => $error ? 'Draft results are unavailable right now.' : null,
        ];
    }

    /**
     * Extract Fantrax player IDs from a draft-results payload.
     *
     * @param array<string,mixed> $draftResults
     *
     * @return array<int,string>
     */
    public function fantraxPlayerIds(array $draftResults): array
    {
        return collect($this->resultItems($draftResults))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): string => $this->stringValue($this->firstValue($item, [
                'fantraxPlayerId',
                'fantrax_player_id',
                'playerId',
                'player_id',
                'id',
                'player.id',
            ])))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $leagueInfo
     */
    private function draftAt(array $leagueInfo, array $draftResults): ?CarbonImmutable
    {
        $raw = $this->firstValue($draftResults, [
            'draftDate',
            'draft_date',
            'draftTime',
            'draft_time',
            'draftAt',
            'draft_at',
            'draftStartTime',
            'draft_start_time',
            'draftStartDate',
            'draft_start_date',
        ]) ?? $this->firstValue($leagueInfo, [
            'draftDate',
            'draft_date',
            'draftTime',
            'draft_time',
            'draftAt',
            'draft_at',
            'draftStartTime',
            'draft_start_time',
            'draftStartDate',
            'draft_start_date',
            'league.draftDate',
            'league.draftTime',
            'league.draftAt',
            'draft.date',
            'draft.time',
            'draft.startsAt',
            'draft.startTime',
            'draft.startDate',
            'settings.draftDate',
            'settings.draftTime',
            'settings.draftAt',
        ]);

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $leagueInfo
     * @param array<string,string> $teamNamesById
     * @param array<string,array<string,mixed>> $playerNamesByFantraxId
     * @param array<string,array<string,mixed>> $teamMetaByFantraxId
     *
     * @return array<int,array<string,mixed>>
     */
    private function draftedRows(
        array $draftResults,
        array $teamNamesById,
        array $playerNamesByFantraxId,
        array $teamMetaByFantraxId
    ): array {
        $nextPickMarked = false;

        return collect($this->resultItems($draftResults))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $item) use ($teamNamesById, $playerNamesByFantraxId, $teamMetaByFantraxId): array {
                $teamId = $this->stringValue($this->firstValue($item, [
                    'teamId',
                    'team_id',
                    'fantasyTeamId',
                    'fantasy_team_id',
                    'franchiseId',
                    'franchise_id',
                    'team.id',
                    'fantasyTeam.id',
                ]));
                $teamName = $this->stringValue($this->firstValue($item, [
                    'teamName',
                    'team_name',
                    'franchiseName',
                    'franchise_name',
                    'team.name',
                    'fantasyTeam.name',
                ]));
                $playerId = $this->stringValue($this->firstValue($item, [
                    'fantraxPlayerId',
                    'fantrax_player_id',
                    'playerId',
                    'player_id',
                    'id',
                    'player.id',
                ]));
                $playerName = $this->stringValue($this->firstValue($item, [
                    'playerName',
                    'player_name',
                    'name',
                    'player.name',
                    'player.fullName',
                ]));
                $mappedPlayer = $playerNamesByFantraxId[$playerId] ?? [];
                $mappedTeam = $teamMetaByFantraxId[$teamId] ?? [];
                $pick = $this->integerValue($this->firstValue($item, ['pick', 'pickNum', 'pick_number']));
                $pickInRound = $this->integerValue($this->firstValue($item, [
                    'pickInRound',
                    'pick_in_round',
                    'roundPick',
                    'round_pick',
                ]));
                $overallPick = $this->integerValue($this->firstValue($item, [
                    'overallPick',
                    'overall_pick',
                    'pickOverall',
                    'overall',
                ])) ?? ($pickInRound !== null ? $pick : null);
                $hasPlayer = $playerId !== '';

                return [
                    'player_name' => $hasPlayer
                        ? ($playerName ?: ((string) ($mappedPlayer['name'] ?? '') ?: 'Unknown player'))
                        : '',
                    'fantrax_player_id' => $playerId,
                    'player_id' => $mappedPlayer['player_id'] ?? null,
                    'nhl_id' => $mappedPlayer['nhl_id'] ?? null,
                    'position' => $mappedPlayer['position'] ?? null,
                    'league_abbrev' => $mappedPlayer['league_abbrev'] ?? null,
                    'team_abbrev' => $mappedPlayer['team_abbrev'] ?? null,
                    'avatar_url' => $mappedPlayer['avatar_url'] ?? null,
                    'stats' => $mappedPlayer['stats'] ?? [
                        'gp' => null,
                        'g' => null,
                        'a' => null,
                        'pts' => null,
                    ],
                    'team_id' => $teamId,
                    'team_name' => $teamName ?: ($teamNamesById[$teamId] ?? 'Unknown team'),
                    'team_avatar_url' => $mappedTeam['owner_avatar_url'] ?? null,
                    'round' => $this->integerValue($this->firstValue($item, ['round', 'roundNum', 'round_number'])),
                    'pick' => $pick,
                    'pick_in_round' => $pickInRound,
                    'overall_pick' => $overallPick,
                ];
            })
            ->filter(static fn (array $row): bool => $row['fantrax_player_id'] !== ''
                || $row['team_name'] !== 'Unknown team'
                || $row['round'] !== null
                || $row['pick'] !== null)
            ->sortBy([
                ['overall_pick', 'asc'],
                ['round', 'asc'],
                ['pick', 'asc'],
                ['player_name', 'asc'],
            ])
            ->values()
            ->map(function (array $row) use (&$nextPickMarked): array {
                $row['is_next_pick'] = false;

                if (! $nextPickMarked && $row['fantrax_player_id'] === '') {
                    $row['is_next_pick'] = true;
                    $nextPickMarked = true;
                }

                return $row;
            })
            ->all();
    }

    /**
     * Segment drafted rows into round pages.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array{round:int|null,label:string,count:int,rows:array<int,array<string,mixed>>}>
     */
    private function rounds(array $rows): array
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
     * Choose the initial round page for the draft window.
     *
     * @param array<int,array{round:int|null,label:string,count:int,rows:array<int,array<string,mixed>>}> $rounds
     */
    private function activeRoundIndex(array $rounds): int
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
     * @param array<string,mixed> $draftResults
     *
     * @return array<int,mixed>
     */
    private function resultItems(array $draftResults): array
    {
        $items = $draftResults['results']
            ?? $draftResults['draftResults']
            ?? $draftResults['draft_results']
            ?? $draftResults['picks']
            ?? $draftResults['draftPicks']
            ?? $draftResults['draft_picks']
            ?? $draftResults;

        if (! is_array($items)) {
            return [];
        }

        return array_values($items);
    }

    /**
     * @param array<string,mixed> $leagueInfo
     *
     * @return array<string,string>
     */
    private function teamNamesById(array $leagueInfo): array
    {
        $teamInfo = $leagueInfo['teamInfo']
            ?? $leagueInfo['team_info']
            ?? data_get($leagueInfo, 'league.teamInfo')
            ?? [];

        if (! is_array($teamInfo)) {
            return [];
        }

        $teams = [];

        foreach ($teamInfo as $key => $team) {
            if (! is_array($team)) {
                continue;
            }

            $id = $this->stringValue($team['id'] ?? $team['teamId'] ?? $team['team_id'] ?? $key);
            $name = $this->stringValue($team['name'] ?? $team['teamName'] ?? $team['team_name'] ?? null);

            if ($id !== '' && $name !== '') {
                $teams[$id] = $name;
            }
        }

        return $teams;
    }

    /**
     * Build the status label and tone from draft date and current draft-pick state.
     *
     * @param array<string,mixed> $draftPickInfo
     *
     * @return array{text:string,tone:string}
     */
    private function status(
        ?CarbonImmutable $draftAt,
        CarbonImmutable $now,
        array $draftPickInfo,
        ?Throwable $error
    ): array {
        if ($error !== null) {
            return ['text' => 'Unavailable', 'tone' => 'slate'];
        }

        if ($draftAt === null) {
            return ['text' => 'Draft', 'tone' => 'slate'];
        }

        if ($draftAt->greaterThan($now)) {
            return ['text' => 'Scheduled', 'tone' => 'blue'];
        }

        return $this->currentDraftPickCount($draftPickInfo) > 0
            ? ['text' => 'Live', 'tone' => 'green']
            : ['text' => 'Complete', 'tone' => 'slate'];
    }

    /**
     * @param array<string,mixed> $draftPickInfo
     */
    private function currentDraftPickCount(array $draftPickInfo): int
    {
        $currentDraftPicks = $draftPickInfo['currentDraftPicks']
            ?? $draftPickInfo['current_draft_picks']
            ?? data_get($draftPickInfo, 'draft.currentDraftPicks')
            ?? [];

        return is_array($currentDraftPicks) ? count($currentDraftPicks) : 0;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $keys
     */
    private function firstValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
