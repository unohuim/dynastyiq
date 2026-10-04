<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** An ordered, saved collection of independently usable SAT engines. */
final class NhlSatEngineStack extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** Members always read in prediction precedence order. */
    public function members(): HasMany
    {
        return $this->hasMany(NhlSatEngineStackMember::class, 'stack_id')->orderBy('priority');
    }

    /** Atomically select this non-empty stack for ordinary predictions. */
    public function makeDefault(): void
    {
        DB::transaction(function (): void {
            if (! $this->members()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['stack' => 'An empty stack cannot be the default.']);
            }
            self::query()->where('is_default', true)->update(['is_default' => false]);
            $this->update(['is_default' => true]);
        });
    }
}
