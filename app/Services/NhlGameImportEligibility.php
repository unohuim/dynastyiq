<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlGame;

/**
 * Centralizes which NHL game types are eligible for the game import pipeline.
 */
class NhlGameImportEligibility
{
    /**
     * @var array<int,int>
     */
    public const ALLOWED_GAME_TYPES = [1, 2, 3];

    /**
     * Determine whether a provider game type can be processed by the pipeline.
     */
    public function allowsGameType(int|string|null $gameType): bool
    {
        if ($gameType === null || $gameType === '') {
            return false;
        }

        return in_array((int) $gameType, self::ALLOWED_GAME_TYPES, true);
    }

    /**
     * Determine whether the already-imported game record can advance past PBP.
     */
    public function allowsStoredGame(int $gameId): bool
    {
        $gameType = NhlGame::where('nhl_game_id', $gameId)->value('game_type');

        return $this->allowsGameType($gameType);
    }

    /**
     * Return the human-readable allowed game type list for errors.
     */
    public function allowedGameTypeList(): string
    {
        return implode(', ', self::ALLOWED_GAME_TYPES);
    }
}
