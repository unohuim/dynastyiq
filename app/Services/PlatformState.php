<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\FantraxPlayer;
use App\Models\Player;
use App\Models\User;
use App\Models\NhlGame;
use Illuminate\Support\Carbon;

class PlatformState
{
    public function seeded(): bool
    {
        return User::query()->count() > 0;
    }

    public function initialized(): bool
    {
        return $this->seeded()
            && Player::query()->count() > 0
            && FantraxPlayer::query()->count() > 0
            && Contract::query()->count() > 0;
    }

    public function upToDate(): bool
    {
        if (! $this->initialized()) {
            return false;
        }

        try {
            $latest = NhlGame::query()
                ->whereHas('playByPlays')
                ->max('game_date');

            if ($latest === null) {
                return false;
            }

            $season = current_season_id();
            $postSeasonEnd = postseason_end_date($season);

            return Carbon::parse($latest)->greaterThanOrEqualTo($postSeasonEnd);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
