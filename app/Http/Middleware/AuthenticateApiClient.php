<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate scoped server-to-server API clients via bearer token.
 */
class AuthenticateApiClient
{
    /**
     * @param Closure(Request):Response $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || trim($token) === '') {
            return response()->json(['message' => 'API client token is required.'], Response::HTTP_UNAUTHORIZED);
        }

        $client = ApiClient::query()
            ->where('token_hash', ApiClient::hashToken($token))
            ->whereNull('revoked_at')
            ->first();

        if (! $client instanceof ApiClient || ! $client->hasScope($scope)) {
            return response()->json(['message' => 'API client token is invalid for this scope.'], Response::HTTP_FORBIDDEN);
        }

        $client->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('api_client', $client);

        return $next($request);
    }
}
