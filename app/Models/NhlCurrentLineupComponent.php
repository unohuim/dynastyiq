<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Supporting observation for one group in a current composite NHL lineup. */
class NhlCurrentLineupComponent extends Model
{
    public const TYPE_FORWARDS = 'forwards';
    public const TYPE_DEFENSE = 'defense';

    /** @var array<int,string> */
    protected $guarded = [];

    /** @var array<string,string> */
    protected $casts = [
        'is_representative' => 'boolean',
    ];

    /** Return the composite current lineup owning this component. */
    public function currentLineup(): BelongsTo
    {
        return $this->belongsTo(NhlCurrentLineup::class);
    }

    /** Return the immutable evidence observation supporting this component. */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(NhlLineupObservation::class, 'nhl_lineup_observation_id');
    }
}
