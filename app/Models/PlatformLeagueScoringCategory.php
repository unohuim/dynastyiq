<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformLeagueScoringCategory extends Model
{
    protected $fillable = [
        'platform_league_id',
        'platform',
        'provider_identity_key',
        'provider_category_id',
        'provider_group',
        'provider_code',
        'provider_short_label',
        'provider_label',
        'normalized_group',
        'normalized_short_label',
        'normalized_label',
        'value',
        'position_values',
        'dictionary_mapping_id',
        'auto_mapping_key',
        'manual_mapping_key',
        'selected_mapping_key',
        'stat_key',
        'auto_stat_key',
        'mapping_source',
        'alignment_status',
        'formula',
        'required_schema_columns',
        'is_supported',
        'support_message',
        'raw_payload',
        'sort_order',
    ];

    protected $casts = [
        'value' => 'float',
        'position_values' => 'array',
        'required_schema_columns' => 'array',
        'is_supported' => 'boolean',
        'raw_payload' => 'array',
    ];

    public function platformLeague(): BelongsTo
    {
        return $this->belongsTo(PlatformLeague::class, 'platform_league_id');
    }

    public function dictionaryMapping(): BelongsTo
    {
        return $this->belongsTo(FantasyScoringCategoryMapping::class, 'dictionary_mapping_id');
    }
}
