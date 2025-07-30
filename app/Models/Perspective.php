<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use App\Models\User;


class Perspective extends Model
{
    /** @use HasFactory<\Database\Factories\PerspectiveFactory> */
    use HasFactory;


    protected $casts = [
        'settings' => 'array',
    ];


    /**
     * Scope a query to the perspectives visible to a given user (or guest).
     *
     * @param  Builder     $query
     * @param  User|null   $user
     * @return Builder
     */
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        // Wrap visibility logic in a single closure
        $query->where(function (Builder $q) use ($user) {
            // Always allow public_guest
            $q->where('visibility', 'public_guest');

            if ($user) {
                // Also allow public_authenticated and owner/tenant
                $q->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('visibility', 'public_authenticated')
                       ->orWhere(function (Builder $q3) use ($user) {
                           $q3->where('author_id', $user->id)
                              ->orWhere('tenant_id', $user->tenant_id);
                       });
                });
            }
        });

        return $query;
    }


}
