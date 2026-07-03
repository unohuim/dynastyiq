<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\FantraxDraftPickMade;
use App\Models\FantraxDraftPick;
use App\Models\FantraxDraftState;
use App\Models\PlatformLeague;
use App\Traits\HasAPITrait;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncFantraxDraftState
{
    use HasAPITrait;

    /**
     * Fetch Fantrax draft payloads and persist the latest draft state.
     *
     * @return array{state:FantraxDraftState|null,new_picks:array<int,FantraxDraftPick>}
     */
    public function sync(int $platformLeagueId, ?CarbonInterface $now = null): array
    {
        $league = PlatformLeague::query()->find($platformLeagueId);

        if (! $league instanceof PlatformLeague || $league->platform !== 'fantrax') {
            return ['state' => null, 'new_picks' => []];
        }

        $draftResults = $this->getAPIData('fantrax', 'draft_results', [
            'leagueId' => (string) $league->platform_league_id,
        ]);
        $draftPickInfo = $this->getAPIData('fantrax', 'draft_picks', [
            'leagueId' => (string) $league->platform_league_id,
        ]);

        return $this->syncPayloads(
            $league,
            is_array($draftResults) ? $draftResults : [],
            is_array($draftPickInfo) ? $draftPickInfo : [],
            $now
        );
    }

    /**
     * Persist supplied draft payloads and return picks that transitioned from unmade to made.
     *
     * @return array{state:FantraxDraftState,new_picks:array<int,FantraxDraftPick>}
     */
    public function syncPayloads(
        PlatformLeague $league,
        array $draftResults,
        array $draftPickInfo = [],
        ?CarbonInterface $now = null
    ): array {
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $rows = $this->draftRows($draftResults);
        $newPicks = [];

        $state = DB::transaction(function () use ($league, $draftResults, $draftPickInfo, $rows, $now, &$newPicks): FantraxDraftState {
            $state = FantraxDraftState::query()->updateOrCreate(
                ['platform_league_id' => $league->id],
                [
                    'draft_at' => $this->draftAt($draftResults),
                    'status' => $this->status($draftResults, $draftPickInfo, $now),
                    'current_draft_pick_count' => $this->currentDraftPickCount($draftPickInfo),
                    'draft_results_hash' => $this->payloadHash($draftResults),
                    'draft_picks_hash' => $this->payloadHash($draftPickInfo),
                    'raw_draft_results' => $draftResults,
                    'raw_draft_pick_info' => $draftPickInfo,
                    'last_checked_at' => $now,
                ]
            );

            foreach ($rows as $row) {
                $existing = FantraxDraftPick::query()
                    ->where('platform_league_id', $league->id)
                    ->where('provider_pick_key', $row['provider_pick_key'])
                    ->first();
                $oldPlayerId = trim((string) ($existing?->fantrax_player_id ?? ''));
                $newPlayerId = trim((string) ($row['fantrax_player_id'] ?? ''));

                $draftPick = FantraxDraftPick::query()->updateOrCreate(
                    [
                        'platform_league_id' => $league->id,
                        'provider_pick_key' => $row['provider_pick_key'],
                    ],
                    [
                        'overall_pick' => $row['overall_pick'],
                        'round' => $row['round'],
                        'pick' => $row['pick'],
                        'pick_in_round' => $row['pick_in_round'],
                        'fantrax_team_id' => $row['fantrax_team_id'],
                        'fantrax_player_id' => $newPlayerId !== '' ? $newPlayerId : null,
                        'drafted_at' => $row['drafted_at'],
                        'detected_at' => $newPlayerId !== '' ? ($existing?->detected_at ?? $now) : null,
                        'payload_hash' => $row['payload_hash'],
                        'raw_payload' => $row['raw_payload'],
                    ]
                );

                if ($existing instanceof FantraxDraftPick && $oldPlayerId === '' && $newPlayerId !== '') {
                    $newPicks[] = $draftPick;
                }
            }

            if ($newPicks !== []) {
                $state->forceFill(['last_detected_pick_at' => $now])->save();
            }

            return $state;
        });

        foreach ($newPicks as $newPick) {
            event(FantraxDraftPickMade::fromDraftPick($newPick));
        }

        return ['state' => $state, 'new_picks' => $newPicks];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function draftRows(array $draftResults): array
    {
        return collect($this->resultItems($draftResults))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
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
                $round = $this->integerValue($this->firstValue($item, ['round', 'roundNum', 'round_number']));

                return [
                    'provider_pick_key' => $this->providerPickKey($overallPick, $round, $pickInRound, $pick, $item),
                    'overall_pick' => $overallPick,
                    'round' => $round,
                    'pick' => $pick,
                    'pick_in_round' => $pickInRound,
                    'fantrax_team_id' => $this->stringValue($this->firstValue($item, [
                        'teamId',
                        'team_id',
                        'fantasyTeamId',
                        'fantasy_team_id',
                        'franchiseId',
                        'franchise_id',
                        'team.id',
                    ])) ?: null,
                    'fantrax_player_id' => $this->stringValue($this->firstValue($item, [
                        'fantraxPlayerId',
                        'fantrax_player_id',
                        'playerId',
                        'player_id',
                        'id',
                        'player.id',
                    ])),
                    'drafted_at' => $this->dateValue($this->firstValue($item, ['time', 'draftedAt', 'drafted_at'])),
                    'payload_hash' => $this->payloadHash($item),
                    'raw_payload' => $item,
                ];
            })
            ->filter(static fn (array $row): bool => $row['provider_pick_key'] !== '')
            ->sortBy([
                ['overall_pick', 'asc'],
                ['round', 'asc'],
                ['pick', 'asc'],
                ['provider_pick_key', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
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

        return is_array($items) ? array_values($items) : [];
    }

    private function providerPickKey(?int $overallPick, ?int $round, ?int $pickInRound, ?int $pick, array $item): string
    {
        if ($overallPick !== null) {
            return 'overall:' . $overallPick;
        }

        if ($round !== null && $pickInRound !== null) {
            return 'round:' . $round . ':pick-in-round:' . $pickInRound;
        }

        if ($round !== null && $pick !== null) {
            return 'round:' . $round . ':pick:' . $pick;
        }

        return 'hash:' . $this->payloadHash($item);
    }

    private function draftAt(array $draftResults): ?CarbonImmutable
    {
        return $this->dateValue($this->firstValue($draftResults, [
            'draftDate',
            'draft_date',
            'draftTime',
            'draft_time',
            'draftAt',
            'draft_at',
            'draftStartTime',
            'draft_start_time',
        ]));
    }

    private function status(array $draftResults, array $draftPickInfo, CarbonImmutable $now): string
    {
        $draftAt = $this->draftAt($draftResults);

        if (! $draftAt instanceof CarbonImmutable) {
            return 'unknown';
        }

        if ($draftAt->greaterThan($now)) {
            return 'scheduled';
        }

        return $this->currentDraftPickCount($draftPickInfo) > 0 ? 'live' : 'complete';
    }

    private function currentDraftPickCount(array $draftPickInfo): int
    {
        $currentDraftPicks = $draftPickInfo['currentDraftPicks']
            ?? $draftPickInfo['current_draft_picks']
            ?? data_get($draftPickInfo, 'draft.currentDraftPicks')
            ?? [];

        return is_array($currentDraftPicks) ? count($currentDraftPicks) : 0;
    }

    /**
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
        return is_array($value) ? '' : trim((string) $value);
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function dateValue(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
