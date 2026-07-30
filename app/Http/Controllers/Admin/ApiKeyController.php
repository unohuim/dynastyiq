<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Manage scoped server-to-server API keys for partner apps.
 */
class ApiKeyController extends Controller
{
    /**
     * @var array<int,string>
     */
    private const ALLOWED_SCOPES = [
        'nhl-reference:read',
        'nhl-stats:read',
    ];

    /**
     * List existing API clients and scopes available for new keys.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'api_keys' => ApiClient::query()
                ->latest('created_at')
                ->get()
                ->map(fn (ApiClient $client): array => $this->payload($client))
                ->values(),
            'available_scopes' => collect(self::ALLOWED_SCOPES)
                ->map(fn (string $scope): array => [
                    'value' => $scope,
                    'label' => $this->scopeLabel($scope),
                ])
                ->values(),
        ]);
    }

    /**
     * Create a scoped API client token and return the plaintext token once.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(self::ALLOWED_SCOPES)],
        ]);

        $name = trim((string) $data['name']);
        $slug = Str::slug($name);

        if ($slug === '') {
            return response()->json([
                'message' => 'API key name must produce a non-empty slug.',
                'errors' => [
                    'name' => ['API key name must produce a non-empty slug.'],
                ],
            ], 422);
        }

        if (ApiClient::query()->where('slug', $slug)->exists()) {
            return response()->json([
                'message' => "API key [{$slug}] already exists.",
                'errors' => [
                    'name' => ["API key [{$slug}] already exists."],
                ],
            ], 422);
        }

        $scopes = collect($data['scopes'])
            ->map(fn (mixed $scope): string => trim((string) $scope))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $token = 'diq_' . str_replace('-', '_', $slug) . '_' . Str::random(64);

        $client = ApiClient::query()->create([
            'name' => $name,
            'slug' => $slug,
            'token_prefix' => substr($token, 0, 24),
            'token_hash' => ApiClient::hashToken($token),
            'scopes' => $scopes,
        ]);

        return response()->json([
            'api_key' => $this->payload($client),
            'token' => $token,
        ], 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(ApiClient $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'token_prefix' => $client->token_prefix,
            'scopes' => $client->scopes ?? [],
            'created_at' => $client->created_at?->toIso8601String(),
            'last_used_at' => $client->last_used_at?->toIso8601String(),
            'revoked_at' => $client->revoked_at?->toIso8601String(),
            'status' => $client->revoked_at ? 'revoked' : 'active',
        ];
    }

    private function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'nhl-reference:read' => 'NHL Reference Read',
            'nhl-stats:read' => 'NHL Stats Read',
            default => $scope,
        };
    }
}
