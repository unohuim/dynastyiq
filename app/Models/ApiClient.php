<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Server-to-server API client allowed to call scoped partner endpoints.
 */
class ApiClient extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
