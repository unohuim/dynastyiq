<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CapContractProjection extends Model
{
    protected $table = 'cap_contract_projections';

    /**
     * @var array<int,string>
     */
    protected $fillable = [
        'platform_league_id',
        'platform_team_id',
        'user_id',
        'player_id',
        'season_key',
        'projected_aav',
        'source',
        'basis',
    ];

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'platform_league_id' => 'integer',
        'platform_team_id' => 'integer',
        'user_id' => 'integer',
        'player_id' => 'integer',
        'season_key' => 'integer',
        'projected_aav' => 'integer',
    ];

    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }

    public function platformTeam(): BelongsTo
    {
        return $this->belongsTo(PlatformTeam::class, 'platform_team_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
