<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FantasyScoringCategoryMapping extends Model
{
    protected $fillable = [
        'platform',
        'provider_label',
        'definition',
        'alignment_status',
        'formula',
        'required_schema_columns',
        'unavailable_reason',
        'notes',
    ];

    protected $casts = [
        'required_schema_columns' => 'array',
    ];
}
