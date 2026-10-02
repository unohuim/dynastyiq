<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A saved set of prediction settings, independent of model training. */
class NhlSatEngine extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array', 'is_default' => 'boolean'];
    }

    /** The discovery run whose candidate settings were applied to this engine. */
    public function discovery(): BelongsTo
    {
        return $this->belongsTo(NhlSatEngineRun::class, 'discovery_run_id');
    }

    /** Serialize first creation and default selection across concurrent requests.
     * @param array<string, mixed> $attributes
     */
    public function saveDefinition(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            DB::statement('LOCK TABLE nhl_sat_engines IN SHARE ROW EXCLUSIVE MODE');
            $first = ! $this->exists && ! self::query()->exists();
            unset($attributes['is_default']);
            $this->fill($attributes);
            if ($first) {
                $this->is_default = true;
            }
            $this->save();

            return $this;
        });
    }

    /** Switch the sole default atomically without starting a prediction job. */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE nhl_sat_engines IN SHARE ROW EXCLUSIVE MODE');
            self::query()->findOrFail($this->id);
            self::query()->where('is_default', true)->update(['is_default' => false]);
            self::query()->whereKey($this->id)->update(['is_default' => true]);
        });
    }

    /** Delete an inactive engine while retaining a default when another engine exists. */
    public function deleteDefinition(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE nhl_sat_engines IN SHARE ROW EXCLUSIVE MODE');
            $engine = self::query()->findOrFail($this->id);
            $wasDefault = $engine->is_default;
            $engine->delete();
            if ($wasDefault) {
                $replacement = self::query()->latest('id')->first();
                if ($replacement !== null) {
                    $replacement->update(['is_default' => true]);
                }
            }
        });
    }

}
