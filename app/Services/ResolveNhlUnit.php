<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlUnit;
use App\Models\NhlUnitPlayer;
use App\Models\Player;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Resolves stable NHL units by unit type, team, and sorted player composition.
 */
class ResolveNhlUnit
{
    /**
     * Resolve or create a unit for the supplied NHL player ids.
     *
     * @param array<int,int> $playerNhlIds
     */
    public function resolve(string $unitType, array $playerNhlIds, ?string $teamAbbrev): NhlUnit
    {
        $playerNhlIds = $this->normalizePlayerIds($playerNhlIds);
        $hash = $this->compositionHash($unitType, $playerNhlIds);

        $unit = NhlUnit::firstOrCreate(
            [
                'team_abbrev' => $teamAbbrev,
                'unit_type' => $unitType,
                'composition_hash' => $hash,
            ],
            [
                'composition_player_ids' => $playerNhlIds,
            ]
        );

        if ($unit->composition_player_ids !== $playerNhlIds) {
            $unit->forceFill(['composition_player_ids' => $playerNhlIds])->save();
        }

        $this->syncPlayers($unit, $playerNhlIds);

        return $unit;
    }

    /**
     * Build the deterministic composition hash used by storage and tests.
     *
     * @param array<int,int> $playerNhlIds
     */
    public function compositionHash(string $unitType, array $playerNhlIds): string
    {
        return hash('sha256', $unitType . ':' . implode(',', $this->normalizePlayerIds($playerNhlIds)));
    }

    /**
     * @param array<int,int> $playerNhlIds
     * @return array<int,int>
     */
    private function normalizePlayerIds(array $playerNhlIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $playerNhlIds)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param array<int,int> $playerNhlIds
     */
    private function syncPlayers(NhlUnit $unit, array $playerNhlIds): void
    {
        $players = Player::whereIn('nhl_id', $playerNhlIds)->get()->keyBy('nhl_id');

        foreach ($playerNhlIds as $nhlId) {
            $player = $players->get($nhlId);

            if (! $player && class_exists(ImportNHLPlayer::class)) {
                try {
                    app(ImportNHLPlayer::class)->import((string) $nhlId);
                } catch (RequestException $exception) {
                    if ($exception->response?->status() !== 404) {
                        throw $exception;
                    }

                    Log::info('Skipping NHL unit player link for player with unavailable landing payload.', [
                        'nhl_player_id' => $nhlId,
                        'unit_id' => $unit->id,
                        'unit_type' => $unit->unit_type,
                        'team_abbrev' => $unit->team_abbrev,
                        'status' => 404,
                    ]);

                    continue;
                }

                $player = Player::where('nhl_id', $nhlId)->first();
            }

            if ($player) {
                NhlUnitPlayer::firstOrCreate([
                    'unit_id' => $unit->id,
                    'player_id' => $player->id,
                ]);
            }
        }
    }
}
