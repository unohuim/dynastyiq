<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->makeSuperAdmin = function (): User {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'level' => 99,
            'scope' => 'global',
            'is_active' => true,
        ]);

        $user->roles()->attach($role->id, ['organization_id' => null]);

        return $user;
    };
});

it('blocks guests from api key management', function () {
    $this->getJson(route('admin.api-keys.index'))
        ->assertUnauthorized();
});

it('blocks non super admins from api key management', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.api-keys.index'))
        ->assertForbidden();
});

it('lists api keys without exposing plaintext tokens', function () {
    $client = ApiClient::query()->create([
        'name' => 'gner8',
        'slug' => 'gner8',
        'token_prefix' => 'diq_gner8_example',
        'token_hash' => ApiClient::hashToken('plain-token'),
        'scopes' => ['nhl-reference:read', 'nhl-stats:read'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->getJson(route('admin.api-keys.index'))
        ->assertOk()
        ->assertJsonPath('api_keys.0.id', $client->id)
        ->assertJsonPath('api_keys.0.slug', 'gner8')
        ->assertJsonMissing(['token' => 'plain-token'])
        ->assertJsonMissing(['token_hash' => $client->token_hash]);
});

it('creates a scoped api key and returns the plaintext token once', function () {
    $response = $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.api-keys.store'), [
            'name' => 'gner8',
            'scopes' => ['nhl-stats:read', 'nhl-reference:read'],
        ])
        ->assertCreated()
        ->assertJsonPath('api_key.slug', 'gner8')
        ->assertJsonPath('api_key.scopes.0', 'nhl-stats:read')
        ->assertJsonPath('api_key.scopes.1', 'nhl-reference:read');

    $token = $response->json('token');
    expect($token)->toStartWith('diq_gner8_');

    $client = ApiClient::query()->where('slug', 'gner8')->firstOrFail();

    expect($client->token_prefix)->toBe(substr($token, 0, 24))
        ->and(hash_equals($client->token_hash, ApiClient::hashToken($token)))->toBeTrue()
        ->and($client->scopes)->toBe(['nhl-stats:read', 'nhl-reference:read']);
});

it('rejects duplicate api key slugs', function () {
    ApiClient::query()->create([
        'name' => 'gner8',
        'slug' => 'gner8',
        'token_prefix' => 'diq_gner8_example',
        'token_hash' => ApiClient::hashToken('plain-token'),
        'scopes' => ['nhl-stats:read'],
    ]);

    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.api-keys.store'), [
            'name' => 'gner8',
            'scopes' => ['nhl-stats:read'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('rejects unknown api key scopes', function () {
    $this->actingAs(($this->makeSuperAdmin)())
        ->postJson(route('admin.api-keys.store'), [
            'name' => 'gner8',
            'scopes' => ['admin:everything'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scopes.0');
});
