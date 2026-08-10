<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;
use App\Models\NhlGameOfficial;
use App\Models\NhlGameTeamStaff;
use App\Models\NhlOfficial;
use App\Models\NhlStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists NHL right-rail game context such as officials and head coaches.
 */
class NhlGameContextImporter
{
    public function __construct(private readonly NhlRightRailService $rightRail)
    {
    }

    /**
     * Fetch and persist right-rail context for one NHL game.
     */
    public function import(int $gameId): int
    {
        $payload = $this->rightRail->payload($gameId);

        if ($payload === null) {
            return 0;
        }

        return $this->importFromPayload($gameId, $payload);
    }

    /**
     * Persist right-rail context from an already fetched payload.
     *
     * @param array<string,mixed> $payload
     */
    public function importFromPayload(int $gameId, array $payload): int
    {
        $gameInfo = $payload['gameInfo'] ?? [];

        if (! is_array($gameInfo) || $gameInfo === []) {
            return 0;
        }

        $game = NhlGame::query()->find($gameId);

        return DB::transaction(function () use ($gameId, $gameInfo, $game): int {
            NhlGameOfficial::query()
                ->where('nhl_game_id', $gameId)
                ->where('source', NhlGameOfficial::SOURCE_RIGHT_RAIL)
                ->delete();

            NhlGameTeamStaff::query()
                ->where('nhl_game_id', $gameId)
                ->where('source', NhlGameTeamStaff::SOURCE_RIGHT_RAIL)
                ->delete();

            $count = 0;
            $count += $this->persistOfficialRows(
                $gameId,
                NhlGameOfficial::ROLE_REFEREE,
                is_array($gameInfo['referees'] ?? null) ? $gameInfo['referees'] : []
            );
            $count += $this->persistOfficialRows(
                $gameId,
                NhlGameOfficial::ROLE_LINESMAN,
                is_array($gameInfo['linesmen'] ?? null) ? $gameInfo['linesmen'] : []
            );
            $count += $this->persistHeadCoach($gameId, NhlGameTeamStaff::TEAM_SIDE_AWAY, $gameInfo['awayTeam'] ?? [], $game);
            $count += $this->persistHeadCoach($gameId, NhlGameTeamStaff::TEAM_SIDE_HOME, $gameInfo['homeTeam'] ?? [], $game);

            return $count;
        });
    }

    /**
     * @param array<int,mixed> $rows
     */
    private function persistOfficialRows(int $gameId, string $role, array $rows): int
    {
        $count = 0;

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->localizedName($row);

            if ($name === null) {
                continue;
            }

            $official = NhlOfficial::query()->updateOrCreate(
                ['normalized_name' => $this->normalizeName($name)],
                ['display_name' => $name]
            );

            NhlGameOfficial::query()->create([
                'nhl_game_id' => $gameId,
                'nhl_official_id' => $official->id,
                'role' => $role,
                'sequence' => $index + 1,
                'provider_name' => $name,
                'source' => NhlGameOfficial::SOURCE_RIGHT_RAIL,
                'raw_payload' => $row,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @param mixed $teamPayload
     */
    private function persistHeadCoach(int $gameId, string $teamSide, mixed $teamPayload, ?NhlGame $game): int
    {
        if (! is_array($teamPayload)) {
            return 0;
        }

        $headCoachPayload = $teamPayload['headCoach'] ?? null;

        if (! is_array($headCoachPayload)) {
            return 0;
        }

        $name = $this->localizedName($headCoachPayload);

        if ($name === null) {
            return 0;
        }

        $staff = NhlStaff::query()->updateOrCreate(
            ['normalized_name' => $this->normalizeName($name)],
            ['display_name' => $name]
        );

        NhlGameTeamStaff::query()->create([
            'nhl_game_id' => $gameId,
            'nhl_staff_id' => $staff->id,
            'nhl_team_id' => $teamSide === NhlGameTeamStaff::TEAM_SIDE_HOME
                ? $game?->home_team_id
                : $game?->away_team_id,
            'team_side' => $teamSide,
            'role' => NhlGameTeamStaff::ROLE_HEAD_COACH,
            'provider_name' => $name,
            'source' => NhlGameTeamStaff::SOURCE_RIGHT_RAIL,
            'raw_payload' => $headCoachPayload,
        ]);

        return 1;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function localizedName(array $payload): ?string
    {
        $name = trim((string) ($payload['default'] ?? ''));

        return $name !== '' ? $name : null;
    }

    private function normalizeName(string $name): string
    {
        return (string) Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }
}
