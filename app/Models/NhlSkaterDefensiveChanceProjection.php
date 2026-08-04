<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versioned skater defensive chance projection rollup.
 */
class NhlSkaterDefensiveChanceProjection extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'source_games' => 'decimal:2',
        'source_toi_seconds' => 'integer',
        'source_sat_against_on_ice' => 'integer',
        'source_sog_against_on_ice' => 'integer',
        'source_goals_against_on_ice' => 'integer',
        'source_xga_on_ice' => 'decimal:4',
        'source_xsoga_on_ice' => 'decimal:4',
        'projected_games' => 'decimal:2',
        'projected_toi_hours' => 'decimal:4',
        'projected_sata' => 'decimal:2',
        'projected_soga' => 'decimal:2',
        'projected_xga' => 'decimal:4',
        'projected_xsoga' => 'decimal:4',
        'confidence_score' => 'decimal:4',
        'projection_inputs' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'projected_at' => 'datetime',
    ];

    /**
     * Bucket rows that explain this defensive projection.
     *
     * @return HasMany<NhlSkaterDefensiveChanceProjectionBucket>
     */
    public function buckets(): HasMany
    {
        return $this->hasMany(NhlSkaterDefensiveChanceProjectionBucket::class, 'skater_defensive_chance_projection_id');
    }
}
