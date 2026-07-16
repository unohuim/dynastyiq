<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\DraftPickMade;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\DraftQueueItem;
use App\Models\FantraxPlayer;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\PlayerExternalIdentity;
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
     * @return array{state:null,new_picks:array<int,DraftPick>}
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
     * Fetch and persist Fantrax draft state only when Fantrax exposes draft data.
     *
     * @return array{state:null,new_picks:array<int,DraftPick>}
     */
    public function syncIfAvailable(int $platformLeagueId, ?CarbonInterface $now = null): array
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
        $draftResults = is_array($draftResults) ? $draftResults : [];
        $draftPickInfo = is_array($draftPickInfo) ? $draftPickInfo : [];

        if (! $this->hasDraftPayload($draftResults)) {
            return ['state' => null, 'new_picks' => []];
        }

        return $this->syncPayloads($league, $draftResults, $draftPickInfo, $now);
    }

    /**
     * Persist supplied draft payloads and return picks that transitioned from unmade to made.
     *
     * @return array{state:null,new_picks:array<int,DraftPick>}
     */
    public function syncPayloads(
        PlatformLeague $league,
        array $draftResults,
        array $draftPickInfo = [],
        ?CarbonInterface $now = null
    ): array {
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $rows = $this->draftRows($draftResults);

        $newDraftPicks = DB::transaction(function () use ($league, $draftResults, $draftPickInfo, $rows, $now): array {
            return $this->mirrorNeutralDraft(
                $league,
                $draftResults,
                $draftPickInfo,
                $rows,
                $now,
            );
        });

        foreach ($newDraftPicks as $newPick) {
            event(DraftPickMade::fromDraftPick($newPick));
        }

        return ['state' => null, 'new_picks' => $newDraftPicks];
    }

    /**
     * Mirror provider-specific Fantrax draft data into the platform-neutral draft tables.
     *
     * @param array<string,mixed> $draftResults
     * @param array<string,mixed> $draftPickInfo
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,DraftPick>
     */
    private function mirrorNeutralDraft(
        PlatformLeague $league,
        array $draftResults,
        array $draftPickInfo,
        array $rows,
        CarbonImmutable $now,
    ): array
    {
        $newDraftPicks = [];
        $draftAt = $this->draftAt($draftResults);
        $externalDraftId = $this->neutralExternalDraftId($league, $draftResults, $draftAt);
        $draftShape = $this->draftShape($draftResults, $rows);
        $teamIds = collect($rows)
            ->pluck('fantrax_team_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $platformTeamIds = PlatformTeam::query()
            ->where('platform_league_id', $league->id)
            ->whereIn('platform_team_id', $teamIds)
            ->pluck('id', 'platform_team_id');

        $draft = Draft::query()->updateOrCreate(
            [
                'platform' => 'fantrax',
                'external_draft_id' => $externalDraftId,
            ],
            [
                'platform_league_id' => $league->id,
                'source_type' => 'platform_mirror',
                'name' => $league->name . ' Draft',
                'draft_type' => $this->stringValue($this->firstValue($draftResults, [
                    'draftType',
                    'draft_type',
                    'draftSettings.draftType',
                ])) ?: null,
                'status' => $this->status($draftResults, $draftPickInfo, $now),
                'starts_at' => $draftAt,
                'settings' => [
                    'provider' => 'fantrax',
                    'draft_shape' => $draftShape,
                    'provider_draft_order' => $this->providerDraftOrder($draftResults),
                    'provider_draft_state' => $this->stringValue($this->firstValue($draftResults, [
                        'draftState',
                        'draft_state',
                        'status',
                    ])) ?: null,
                ],
            ]
        );

        if ($draft->pick_clock_seconds === null) {
            $draft->forceFill(['pick_clock_seconds' => 300])->save();
        }

        foreach ($rows as $row) {
            $providerTeamId = $row['fantrax_team_id'] ?: null;
            $providerPlayerId = $row['fantrax_player_id'] ?: null;
            $playerId = $providerPlayerId ? $this->canonicalPlayerIdForFantraxPlayer($providerPlayerId) : null;
            $draftPick = DraftPick::query()->firstOrNew([
                'draft_id' => $draft->id,
                'provider_pick_key' => $row['provider_pick_key'],
            ]);
            $wasUnpicked = $draftPick->exists
                && trim((string) ($draftPick->provider_player_id ?? '')) === ''
                && $draftPick->player_id === null;
            $detectedAt = $providerPlayerId
                ? ($draftPick->detected_at ?? $row['drafted_at'] ?? $now)
                : null;

            $draftPick
                ->fill([
                    'overall_pick' => $row['overall_pick'],
                    'round' => $row['round'],
                    'pick' => $row['pick'],
                    'pick_in_round' => $row['pick_in_round'],
                    'platform_team_id' => $providerTeamId ? ($platformTeamIds[$providerTeamId] ?? null) : null,
                    'provider_team_id' => $providerTeamId,
                    'player_id' => $playerId,
                    'provider_player_id' => $providerPlayerId,
                    'source' => 'fantrax',
                    'status' => $providerPlayerId ? 'picked' : 'pending',
                    'picked_at' => $row['drafted_at'],
                    'detected_at' => $detectedAt,
                    'payload_hash' => $row['payload_hash'],
                    'raw_payload' => $row['raw_payload'],
                ])
                ->save();

            if ($playerId !== null) {
                $this->removeDraftedPlayerFromQueues($draft, $playerId);
            }

            if ($providerPlayerId && $wasUnpicked) {
                $newDraftPicks[] = $draftPick;
            }
        }

        $nextPick = DraftPick::query()
            ->where('draft_id', $draft->id)
            ->whereNull('provider_player_id')
            ->orderByRaw('overall_pick is null')
            ->orderBy('overall_pick')
            ->orderBy('round')
            ->orderBy('pick_in_round')
            ->orderBy('provider_pick_key')
            ->first();

        if ((string) $draft->status === 'complete') {
            DraftPick::query()
                ->where('draft_id', $draft->id)
                ->where('status', 'on_clock')
                ->update(['status' => 'pending']);

            $draft->forceFill(['current_draft_pick_id' => null])->save();

            return $newDraftPicks;
        }

        DraftPick::query()
            ->where('draft_id', $draft->id)
            ->whereIn('status', ['pending', 'on_clock'])
            ->update(['status' => 'pending']);

        if ($nextPick instanceof DraftPick) {
            $nextPick->forceFill(['status' => 'on_clock'])->save();
            $draft->forceFill(['current_draft_pick_id' => $nextPick->id])->save();

            return $newDraftPicks;
        }

        $draft->forceFill(['current_draft_pick_id' => null])->save();

        return $newDraftPicks;
    }

    /**
     * Resolve a Fantrax player id to the canonical player id used by draft queues.
     */
    private function canonicalPlayerIdForFantraxPlayer(string $fantraxPlayerId): ?int
    {
        $playerId = FantraxPlayer::query()
            ->where('fantrax_id', $fantraxPlayerId)
            ->value('player_id');

        if ($playerId !== null) {
            return (int) $playerId;
        }

        $playerId = PlayerExternalIdentity::query()
            ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
            ->where('provider_player_id', $fantraxPlayerId)
            ->value('player_id');

        return $playerId !== null ? (int) $playerId : null;
    }

    /**
     * Remove a player from all queues once the draft records them as picked.
     */
    private function removeDraftedPlayerFromQueues(Draft $draft, int $playerId): void
    {
        DraftQueueItem::query()
            ->where('draft_id', $draft->id)
            ->where('player_id', $playerId)
            ->delete();
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
                $division = $this->stringValue($this->firstValue($item, [
                    'division',
                    'divisionName',
                    'division_name',
                    'pool',
                    'poolName',
                    'pool_name',
                ])) ?: null;
                $providerPlayerId = $this->stringValue($this->firstValue($item, [
                    'fantraxPlayerId',
                    'fantrax_player_id',
                    'playerId',
                    'player_id',
                    'id',
                    'player.id',
                ]));

                if ($division !== null) {
                    $overallPick = null;
                }

                return [
                    'provider_pick_key' => $this->providerPickKey($division, $overallPick, $round, $pickInRound, $pick, $item),
                    'overall_pick' => $overallPick,
                    'round' => $round,
                    'pick' => $pick,
                    'pick_in_round' => $pickInRound,
                    'division' => $division,
                    'fantrax_team_id' => $this->stringValue($this->firstValue($item, [
                        'teamId',
                        'team_id',
                        'fantasyTeamId',
                        'fantasy_team_id',
                        'franchiseId',
                        'franchise_id',
                        'team.id',
                    ])) ?: null,
                    'fantrax_player_id' => $providerPlayerId,
                    'drafted_at' => $this->dateValue($this->firstValue($item, ['time', 'draftedAt', 'drafted_at'])),
                    'payload_hash' => $this->payloadHash($item),
                    'raw_payload' => $item,
                ];
            })
            ->filter(static fn (array $row): bool => $row['provider_pick_key'] !== '')
            ->sortBy([
                ['overall_pick', 'asc'],
                ['division', 'asc'],
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

    private function hasDraftPayload(array $draftResults): bool
    {
        return $this->draftAt($draftResults) instanceof CarbonImmutable
            || $this->resultItems($draftResults) !== [];
    }

    private function providerPickKey(
        ?string $division,
        ?int $overallPick,
        ?int $round,
        ?int $pickInRound,
        ?int $pick,
        array $item
    ): string
    {
        $prefix = $division !== null && trim($division) !== ''
            ? 'division:' . $this->providerKeyPart($division) . ':'
            : '';

        if ($overallPick !== null) {
            return $prefix . 'overall:' . $overallPick;
        }

        if ($round !== null && $pickInRound !== null) {
            return $prefix . 'round:' . $round . ':pick-in-round:' . $pickInRound;
        }

        if ($round !== null && $pick !== null) {
            return $prefix . 'round:' . $round . ':pick:' . $pick;
        }

        return $prefix . 'hash:' . $this->payloadHash($item);
    }

    /**
     * Keep provider key components stable and inside provider_pick_key length limits.
     */
    private function providerKeyPart(string $value): string
    {
        $original = trim($value);
        $value = strtolower($original);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
        $value = trim($value, '-');

        return $value !== '' ? substr($value, 0, 40) : substr(sha1($original), 0, 12);
    }

    /**
     * @param array<string,mixed> $draftResults
     * @param array<int,array<string,mixed>> $rows
     */
    private function draftShape(array $draftResults, array $rows): string
    {
        if (collect($rows)->contains(static fn (array $row): bool => trim((string) ($row['division'] ?? '')) !== '')) {
            return 'division_scoped';
        }

        return $this->providerDraftOrder($draftResults) !== null ? 'flat' : 'unknown';
    }

    /**
     * Preserve provider draft order exactly as Fantrax sends it.
     */
    private function providerDraftOrder(array $draftResults): mixed
    {
        return $draftResults['draftOrder']
            ?? $draftResults['draft_order']
            ?? data_get($draftResults, 'draft.draftOrder');
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
        $providerState = strtolower($this->stringValue($this->firstValue($draftResults, [
            'draftState',
            'draft_state',
            'status',
        ])));

        if (in_array($providerState, ['completed', 'complete'], true)) {
            return 'complete';
        }

        if (in_array($providerState, ['live', 'in_progress', 'in-progress', 'drafting'], true)) {
            return 'live';
        }

        if ($providerState === 'paused') {
            return 'paused';
        }

        if (in_array($providerState, ['scheduled', 'pending'], true)) {
            return 'scheduled';
        }

        $draftAt = $this->draftAt($draftResults);

        if (! $draftAt instanceof CarbonImmutable) {
            return 'unknown';
        }

        if ($draftAt->greaterThan($now)) {
            return 'scheduled';
        }

        return $this->currentDraftPickCount($draftPickInfo) > 0 ? 'live' : 'complete';
    }

    private function neutralExternalDraftId(
        PlatformLeague $league,
        array $draftResults,
        ?CarbonImmutable $draftAt
    ): string {
        $providerDraftId = $this->stringValue($this->firstValue($draftResults, [
            'draftId',
            'draft_id',
            'id',
        ]));

        if ($providerDraftId !== '') {
            return $providerDraftId;
        }

        $dateKey = $draftAt?->format('YmdHis') ?? 'current';

        return 'fantrax:' . $league->platform_league_id . ':' . $dateKey;
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

        if (is_numeric($value)) {
            $timestamp = (float) $value;

            if (abs($timestamp) > 9999999999) {
                $timestamp /= 1000;
            }

            return CarbonImmutable::createFromTimestampUTC($timestamp);
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
