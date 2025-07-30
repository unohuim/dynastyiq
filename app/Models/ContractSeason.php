<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\ContractSeason
 *
 * Represents a single season within a player's contract.
 *
 * @property int                             $id
 * @property int                             $contract_id
 * @property string                          $season
 * @property string|null                     $clause
 * @property int                             $cap_hit
 * @property int                             $aav
 * @property int|null                        $performance_bonuses
 * @property int|null                        $signing_bonuses
 * @property int                             $base_salary
 * @property int|null                        $total_salary
 * @property int|null                        $minors_salary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|ContractSeason newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractSeason newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractSeason query()
 *
 * @mixin \Eloquent
 */
class ContractSeason extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contract_seasons';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        'contract_id',
        'season',
        'clause',
        'cap_hit',
        'aav',
        'performance_bonuses',
        'signing_bonuses',
        'base_salary',
        'total_salary',
        'minors_salary',
        'season_key',
        'label',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'contract_id'          => 'integer',
        'season'               => 'string',
        'clause'               => 'string',
        'cap_hit'              => 'integer',
        'aav'                  => 'integer',
        'performance_bonuses'  => 'integer',
        'signing_bonuses'      => 'integer',
        'base_salary'          => 'integer',
        'total_salary'         => 'integer',
        'minors_salary'        => 'integer',
    ];

    /**
     * Get the contract that owns this season.
     *
     * @return BelongsTo
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
