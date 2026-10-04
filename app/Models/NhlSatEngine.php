<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** A saved set of prediction settings, independent of model training. */
class NhlSatEngine extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** The discovery run whose candidate settings were applied to this engine. */
    public function discovery(): BelongsTo
    {
        return $this->belongsTo(NhlSatEngineRun::class, 'discovery_run_id');
    }

    /** Saved stack memberships that retain this engine as an explicit member. */
    public function stackMembers(): HasMany
    {
        return $this->hasMany(NhlSatEngineStackMember::class, 'engine_id');
    }

    /** Serialize saved definition changes across concurrent requests.
     * @param array<string, mixed> $attributes
     */
    public function saveDefinition(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            DB::statement('LOCK TABLE nhl_sat_engines IN SHARE ROW EXCLUSIVE MODE');
            $this->fill($attributes);
            $this->save();

            return $this;
        });
    }

    /** Delete an inactive engine after all saved stack memberships are removed. */
    public function deleteDefinition(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE nhl_sat_engines IN SHARE ROW EXCLUSIVE MODE');
            $engine = self::query()->findOrFail($this->id);
            $engine->delete();
        });
    }

}
