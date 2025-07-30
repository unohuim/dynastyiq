<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\Contract
 *
 * Represents a player's contract record from CapWages.
 *
 * @property int                             $id
 * @property int                             $player_id
 * @property string                          $contract_type
 * @property string|null                     $contract_length
 * @property int|null                        $contract_value
 * @property string|null                     $expiry_status
 * @property string|null                     $signing_team
 * @property \Illuminate\Support\Carbon|null $signing_date
 * @property string|null                     $signed_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Contract newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Contract newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Contract query()
 *
 * @mixin \Eloquent
 */
class Contract extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contracts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        'player_id',
        'contract_type',
        'contract_length',
        'contract_value',
        'expiry_status',
        'signing_team',
        'signing_date',
        'signed_by',
        
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'player_id'      => 'integer',
        'contract_length'=> 'string',
        'contract_value' => 'integer',
        'signing_date'   => 'date',
    ];

    /**
     * Get the player that owns this contract.
     *
     * @return BelongsTo
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Get the seasons associated with this contract.
     *
     * @return HasMany
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(ContractSeason::class);
    }
}
