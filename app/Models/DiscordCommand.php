<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscordCommand extends Model
{
    protected $table = 'discord_commands';

    /**
     * Primary key is a string slug.
     */
    protected $primaryKey = 'command_slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'command_slug',
        'name',
        'parent_slug',
        'description',
        'handler_kind',
        'handler_ref',
        'http_method',
        'has_defaults',
        'defaults',
        'allowed_overrides',
        'max_sorts',
        'auth_scope',
        'enabled',
        'version',
        'metadata',
    ];

    protected $casts = [
        'has_defaults' => 'bool',
        'enabled' => 'bool',
        'version' => 'int',
        'max_sorts' => 'int',
        'defaults' => 'array',
        'allowed_overrides' => 'array',
        'metadata' => 'array',
    ];

    // Relationships

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_slug', 'command_slug');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_slug', 'command_slug');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(DiscordCommandAlias::class, 'command_slug', 'command_slug');
    }

    public function userOverrides(): HasMany
    {
        return $this->hasMany(DiscordUserCommandOverride::class, 'command_slug', 'command_slug');
    }

    // Scopes

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_slug');
    }

    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where('command_slug', $slug);
    }

    // Helpers

    public function canAutoDefault(): bool
    {
        return (bool) $this->has_defaults;
    }

    public function allowsOverride(string $param): bool
    {
        $allowed = $this->allowed_overrides ?? [];

        return in_array($param, $allowed, true);
    }

    public function pathLabel(): string
    {
        $labels = [$this->name];
        $node = $this->parent;

        while ($node) {
            array_unshift($labels, $node->name);
            $node = $node->parent;
        }

        return implode(' › ', $labels);
    }
}
