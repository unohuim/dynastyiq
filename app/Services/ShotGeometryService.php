<?php

namespace App\Services;

use App\Models\NhlGame;
use App\Models\PlayByPlay;

class ShotGeometryService
{
    private const RINK_HALF_LENGTH = 89; // feet from center ice to goal line

    private const SHOT_EVENT_KEYS = [
        'goal',
        'shot-on-goal',
        'missed-shot',
        'blocked-shot',
        'shootout-goal',
        'shootout-shot',
        'penalty-shot',
    ];

    public function isShotAttempt(?string $typeDescKey, ?string $shotType): bool
    {
        $normalizedType = $typeDescKey ? strtolower($typeDescKey) : null;

        if ($normalizedType) {
            foreach (self::SHOT_EVENT_KEYS as $shotKey) {
                if (str_contains($normalizedType, $shotKey)) {
                    return true;
                }
            }
        }

        return !empty($shotType);
    }

    public function computeFromPlay(PlayByPlay $play, ?NhlGame $game): ?array
    {
        if (!$game || !$game->home_team_id || !$game->away_team_id) {
            return null;
        }

        return $this->computeFromContext(
            $play->x_coord,
            $play->y_coord,
            $play->event_owner_team_id,
            $play->home_team_defending_side,
            $play->period,
            (int)$game->home_team_id,
            (int)$game->away_team_id,
        );
    }

    public function computeFromContext(
        ?int $xCoord,
        ?int $yCoord,
        ?int $eventOwnerTeamId,
        ?string $homeTeamDefendingSide,
        ?int $period,
        ?int $homeTeamId,
        ?int $awayTeamId,
    ): ?array {
        if ($xCoord === null || $yCoord === null || $eventOwnerTeamId === null || !$homeTeamId || !$awayTeamId) {
            return null;
        }

        $homeDefendingSide = $this->normalizeSide($homeTeamDefendingSide, $period ?? 1);

        if (!$homeDefendingSide) {
            return null;
        }

        $targetSide = $this->resolveTargetNetSide(
            $eventOwnerTeamId,
            $homeTeamId,
            $awayTeamId,
            $homeDefendingSide,
        );

        if (!$targetSide) {
            return null;
        }

        $netX = $targetSide === 'left' ? -self::RINK_HALF_LENGTH : self::RINK_HALF_LENGTH;
        $netY = 0.0;

        $dx = $netX - $xCoord;
        $dy = $netY - $yCoord;

        return [
            'distance' => round(hypot($dx, $dy), 2),
            'angle' => round(rad2deg(atan2($dy, $dx)), 3),
        ];
    }

    private function normalizeSide(?string $side, int $period): ?string
    {
        $trimmed = strtolower(trim((string)$side));

        if (str_starts_with($trimmed, 'l')) {
            return 'left';
        }

        if (str_starts_with($trimmed, 'r')) {
            return 'right';
        }

        return $period % 2 === 0 ? 'right' : 'left';
    }

    private function resolveTargetNetSide(int $eventOwnerTeamId, int $homeTeamId, int $awayTeamId, string $homeDefendingSide): ?string
    {
        if ($eventOwnerTeamId === $homeTeamId) {
            return $this->flipSide($homeDefendingSide);
        }

        if ($eventOwnerTeamId === $awayTeamId) {
            return $homeDefendingSide;
        }

        return null;
    }

    private function flipSide(string $side): string
    {
        return $side === 'left' ? 'right' : 'left';
    }
}
