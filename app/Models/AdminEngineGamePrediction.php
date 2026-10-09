<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Private user-owned diagnostic snapshots; not tenant or production prediction data. */
class AdminEngineGamePrediction extends Model
{
    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'autosave_slot' => 'integer'];
    }
}
