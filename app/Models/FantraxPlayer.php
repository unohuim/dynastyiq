<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\FantraxPlayer
 *
 * @property int    $id
 * @property int    $player_id
 * @property string $fantrax_id
 * @property int   |null $statsinc_id
 * @property int   |null $rotowire_id
 * @property int   |null $sport_radar_id
 * @property string|null $team
 * @property string|null $name
 * @property string|null $position
 * @property array |null $raw_meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class FantraxPlayer extends Model
{
    // If your table name doesn’t follow the default plural:singular convention,
    // uncomment and set it here:
    // protected $table = 'fantrax_players';

    // Guarding nothing lets you mass‑assign all columns:
    protected $guarded = [];

    // Cast raw_meta to/from JSON automatically:
    protected $casts = [
        'statsinc_id'    => 'integer',
        'rotowire_id'    => 'integer',
        'sport_radar_id' => 'integer',
        'raw_meta'       => 'array',
    ];

    /**
     * Relationship to the core Player model.
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
