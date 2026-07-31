<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Models\PlayByPlay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds deterministic faceoff facts from already imported NHL play-by-play.
 */
class BuildNhlFaceoffFacts
{
    private const FACT_VERSION = 'faceoff_facts_v1';

    /**
     * Upsert faceoff facts for one NHL game.
     */
    public function buildForGame(int $gameId): int
    {
        $game = NhlGame::query()->find($gameId);

        if (! $game instanceof NhlGame) {
            return 0;
        }

        $plays = PlayByPlay::query()
            ->where('nhl_game_id', $gameId)
            ->orderBy('period')
            ->orderBy('seconds_in_game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($plays->isEmpty()) {
            return 0;
        }

        $unitContext = $this->unitContextForGame($gameId);
        $rows = [];

        foreach ($plays as $index => $play) {
            if ((string) $play->type_desc_key !== 'faceoff') {
                continue;
            }

            $nextPlay = $this->nextMeaningfulPlay($plays, $index);
            $rows[] = $this->rowForPlay($play, $game, $nextPlay, $unitContext[$play->id] ?? []);
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('nhl_faceoff_facts')->upsert(
            $rows,
            ['play_by_play_id'],
            array_values(array_diff(array_keys($rows[0]), ['play_by_play_id', 'created_at']))
        );

        return count($rows);
    }

    /**
     * @param array<int,array{unit_id:int,team_id:?int,team_abbrev:?string,player_ids:array<int,int>}> $unitContext
     * @return array<string,mixed>
     */
    private function rowForPlay(PlayByPlay $play, NhlGame $game, ?PlayByPlay $nextPlay, array $unitContext): array
    {
        $winningTeamId = $this->nullableInt($play->event_owner_team_id);
        $losingTeamId = $this->opponentTeamId($game, $winningTeamId);
        $winningContext = $winningTeamId ? ($unitContext[$winningTeamId] ?? null) : null;
        $losingContext = $losingTeamId ? ($unitContext[$losingTeamId] ?? null) : null;
        $winningZone = $this->normalizeZoneCode($play->zone_code);
        $losingZone = $this->oppositeZone($winningZone);
        $nextZone = $this->nextEventZoneForWinningTeam($nextPlay, $winningTeamId);
        $advancementValue = $this->advancementValue($winningZone, $nextZone);
        $now = now();

        return [
            'play_by_play_id' => $play->id,
            'next_play_by_play_id' => $nextPlay?->id,
            'nhl_game_id' => $play->nhl_game_id,
            'nhl_event_id' => $play->nhl_event_id,
            'season_id' => $game->season_id,
            'game_date' => $game->game_date?->toDateString(),
            'fact_version' => self::FACT_VERSION,
            'period' => $this->nullableInt($play->period),
            'period_type' => $play->period_type,
            'seconds_in_game' => $this->nullableInt($play->seconds_in_game),
            'seconds_since_last_event' => $this->nullableInt($play->seconds_since_last_event),
            'situation_code' => $play->situation_code,
            'strength' => $play->strength,
            'strength_bucket' => $this->strengthBucket($play),
            'winning_team_id' => $winningTeamId,
            'winning_team_abbrev' => $this->teamAbbrev($game, $winningTeamId),
            'losing_team_id' => $losingTeamId,
            'losing_team_abbrev' => $this->teamAbbrev($game, $losingTeamId),
            'winning_player_id' => $this->nullableInt($play->fo_winning_player_id),
            'losing_player_id' => $this->nullableInt($play->fo_losing_player_id),
            'zone_code' => $play->zone_code,
            'winning_team_zone' => $winningZone,
            'losing_team_zone' => $losingZone,
            'zone_bucket' => $this->zoneBucket($winningZone),
            'winning_team_zone_bucket' => $this->zoneBucket($winningZone),
            'losing_team_zone_bucket' => $this->zoneBucket($losingZone),
            'winning_unit_id' => $winningContext['unit_id'] ?? null,
            'losing_unit_id' => $losingContext['unit_id'] ?? null,
            'winning_on_ice_player_ids' => json_encode($winningContext['player_ids'] ?? []),
            'losing_on_ice_player_ids' => json_encode($losingContext['player_ids'] ?? []),
            'next_event_type' => $nextPlay?->type_desc_key,
            'next_event_team_id' => $this->nullableInt($nextPlay?->event_owner_team_id),
            'next_event_zone' => $nextZone,
            'next_event_zone_bucket' => $this->zoneBucket($nextZone),
            'next_event_seconds_delta' => $this->secondsDelta($play, $nextPlay),
            'advancement_bucket' => $this->advancementBucket($advancementValue),
            'advancement_value' => $advancementValue,
            'facts_payload' => json_encode([
                'source' => self::FACT_VERSION,
                'advance_definition' => 'next_meaningful_event_zone_from_winning_team_perspective',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<int,array<int,array{unit_id:int,team_id:?int,team_abbrev:?string,player_ids:array<int,int>}>>
     */
    private function unitContextForGame(int $gameId): array
    {
        $rows = DB::table('event_unit_shifts as eus')
            ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
            ->leftJoin('nhl_unit_shift_players as usp', 'usp.unit_shift_id', '=', 'us.id')
            ->where('us.nhl_game_id', $gameId)
            ->select([
                'eus.event_id',
                'us.unit_id',
                'us.team_id',
                'us.team_abbrev',
                'usp.player_id',
            ])
            ->orderBy('eus.event_id')
            ->orderBy('us.team_id')
            ->orderBy('usp.player_id')
            ->get();

        $context = [];

        foreach ($rows as $row) {
            $eventId = (int) $row->event_id;
            $teamId = $this->nullableInt($row->team_id);

            if ($teamId === null) {
                continue;
            }

            $context[$eventId][$teamId] ??= [
                'unit_id' => (int) $row->unit_id,
                'team_id' => $teamId,
                'team_abbrev' => $row->team_abbrev ? (string) $row->team_abbrev : null,
                'player_ids' => [],
            ];

            $playerId = $this->nullableInt($row->player_id);

            if ($playerId !== null) {
                $context[$eventId][$teamId]['player_ids'][] = $playerId;
            }
        }

        foreach ($context as $eventId => $teams) {
            foreach ($teams as $teamId => $teamContext) {
                $context[$eventId][$teamId]['player_ids'] = array_values(array_unique($teamContext['player_ids']));
            }
        }

        return $context;
    }

    /**
     * @param Collection<int,PlayByPlay> $plays
     */
    private function nextMeaningfulPlay(Collection $plays, int $index): ?PlayByPlay
    {
        for ($nextIndex = $index + 1; $nextIndex < $plays->count(); $nextIndex++) {
            $play = $plays->get($nextIndex);

            if ($play instanceof PlayByPlay && $this->isMeaningfulNextEvent($play)) {
                return $play;
            }
        }

        return null;
    }

    private function isMeaningfulNextEvent(PlayByPlay $play): bool
    {
        return ! in_array((string) $play->type_desc_key, [
            'period-start',
            'period-end',
            'game-end',
            'stoppage',
        ], true);
    }

    private function nextEventZoneForWinningTeam(?PlayByPlay $play, ?int $winningTeamId): ?string
    {
        if (! $play instanceof PlayByPlay || $winningTeamId === null) {
            return null;
        }

        $zone = $this->normalizeZoneCode($play->zone_code);
        $eventTeamId = $this->nullableInt($play->event_owner_team_id);

        if ($zone === null || $eventTeamId === null) {
            return null;
        }

        return $eventTeamId === $winningTeamId ? $zone : $this->oppositeZone($zone);
    }

    private function advancementValue(?string $startZone, ?string $nextZone): ?int
    {
        if ($startZone === null || $nextZone === null) {
            return null;
        }

        return $this->zoneRank($nextZone) - $this->zoneRank($startZone);
    }

    private function advancementBucket(?int $value): string
    {
        return match (true) {
            $value === null => 'unknown',
            $value > 0 => 'advanced',
            $value < 0 => 'retreated',
            default => 'held',
        };
    }

    private function zoneRank(string $zone): int
    {
        return match ($zone) {
            'D' => 0,
            'N' => 1,
            'O' => 2,
            default => 1,
        };
    }

    private function zoneBucket(?string $zone): string
    {
        return match ($zone) {
            'O' => 'offensive',
            'N' => 'neutral',
            'D' => 'defensive',
            default => 'unknown',
        };
    }

    private function normalizeZoneCode(?string $zoneCode): ?string
    {
        return match (strtoupper(trim((string) $zoneCode))) {
            'O', 'OZ' => 'O',
            'N', 'NZ' => 'N',
            'D', 'DZ' => 'D',
            default => null,
        };
    }

    private function oppositeZone(?string $zone): ?string
    {
        return match ($zone) {
            'O' => 'D',
            'D' => 'O',
            'N' => 'N',
            default => null,
        };
    }

    private function strengthBucket(PlayByPlay $play): string
    {
        if ((string) $play->period_type === 'SO') {
            return 'so';
        }

        return match (strtoupper((string) $play->strength)) {
            'EV' => 'ev',
            'PP' => 'pp',
            'PK' => 'pk',
            default => 'unknown',
        };
    }

    private function opponentTeamId(NhlGame $game, ?int $teamId): ?int
    {
        $homeTeamId = $this->nullableInt($game->home_team_id);
        $awayTeamId = $this->nullableInt($game->away_team_id);

        if ($teamId === $homeTeamId) {
            return $awayTeamId;
        }

        if ($teamId === $awayTeamId) {
            return $homeTeamId;
        }

        return null;
    }

    private function teamAbbrev(NhlGame $game, ?int $teamId): ?string
    {
        if ($teamId === $this->nullableInt($game->home_team_id)) {
            return $game->home_team_abbrev ? (string) $game->home_team_abbrev : null;
        }

        if ($teamId === $this->nullableInt($game->away_team_id)) {
            return $game->away_team_abbrev ? (string) $game->away_team_abbrev : null;
        }

        return null;
    }

    private function secondsDelta(PlayByPlay $play, ?PlayByPlay $nextPlay): ?int
    {
        $start = $this->nullableInt($play->seconds_in_game);
        $next = $this->nullableInt($nextPlay?->seconds_in_game);

        if ($start === null || $next === null) {
            return null;
        }

        return max(0, $next - $start);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
