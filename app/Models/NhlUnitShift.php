<?php

namespace App\Models;
use App\Models\PlayByPlay;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NhlUnitShift extends Model
{
    protected $fillable = [
        'unit_id',
        'nhl_game_id',
        'period',
        'start_time',
        'end_time',
        'start_game_seconds',
        'end_game_seconds',
        'seconds',
        'team_id',
        'team_abbrev'
    ];

    /**
     * Persist an import-owned shift without creating a savepoint per inserted row.
     *
     * The existing natural-key constraint handles concurrent writes. The caller's
     * game transaction still owns rollback and cancellation of the derived graph.
     *
     * @param array{unit_id:int,nhl_game_id:int,start_game_seconds:int} $identity
     * @param array<string,mixed> $values
     */
    public static function upsertForImport(array $identity, array $values): self
    {
        static::query()->upsert(
            [array_merge($values, $identity)],
            ['unit_id', 'nhl_game_id', 'start_game_seconds'],
            array_keys($values)
        );

        return static::query()->where($identity)->firstOrFail();
    }

    /**
     * Get the unit this shift belongs to.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(NhlUnit::class, 'unit_id');
    }


    /**
     * The events associated with this shift.
     */
    public function events()
    {
        return $this->belongsToMany(
            PlayByPlay::class,
            'event_unit_shifts',    // Pivot table name
            'unit_shift_id',        // Foreign key on pivot for this model
            'event_id'              // Foreign key on pivot for related model
        )->orderBy('seconds_in_game')
            ->withTimestamps();
    }

    /**
     * The contextual player positions for this unit shift.
     */
    public function players(): HasMany
    {
        return $this->hasMany(NhlUnitShiftPlayer::class, 'unit_shift_id');
    }

}
