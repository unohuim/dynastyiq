<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Game/team-scoped staff assignment captured from NHL right-rail context.
 */
class NhlGameTeamStaff extends Model
{
    public const ROLE_HEAD_COACH = 'head_coach';

    public const TEAM_SIDE_AWAY = 'away';
    public const TEAM_SIDE_HOME = 'home';

    public const SOURCE_RIGHT_RAIL = 'right-rail';

    protected $table = 'nhl_game_team_staff';

    protected $guarded = [];

    protected $casts = [
        'raw_payload' => 'array',
    ];

    /**
     * Game for this staff assignment.
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(NhlGame::class, 'nhl_game_id', 'nhl_game_id');
    }

    /**
     * Name-normalized staff identity.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(NhlStaff::class, 'nhl_staff_id');
    }
}
