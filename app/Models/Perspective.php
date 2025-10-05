<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use App\Models\User;

class Perspective extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function (Perspective $p) {
            if (empty($p->slug)) {
                $p->slug = static::generateUniqueSlug($p->name);
            }
        });

        static::updating(function (Perspective $p) {
            if ($p->isDirty('name')) {
                $p->slug = static::generateUniqueSlug($p->name, $p->id);
            }
        });
    }

    /**
     * Scope a query to the perspectives visible to a given user (or guest).
     */
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('visibility', 'public_guest');

            if ($user) {
                $q->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('visibility', 'public_authenticated')
                    ->orWhere('author_id', $user->id)
                    ->orWhereIn('organization_id', $user->organizations()->select('organizations.id'));
                });
            }
        });
    }


    /**
     * Generate a unique slug from the given name.
     */
    protected static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
