<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\FantraxPlayer;
use App\Models\Player;
use App\Models\User;

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
        return $this->initialized();
    }
}
