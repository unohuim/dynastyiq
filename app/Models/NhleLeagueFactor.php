<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned NHLe translation factor for a source league.
 */
final class NhleLeagueFactor extends Model
{
    protected $fillable = [
        'source',
        'source_version',
        'model_name',
        'model_window',
        'source_league_name',
        'mapped_league_codes',
        'points_factor',
        'win_shares_factor',
        'source_url',
        'notes',
    ];

    protected $casts = [
        'mapped_league_codes' => 'array',
        'points_factor' => 'decimal:2',
        'win_shares_factor' => 'decimal:2',
    ];
}
