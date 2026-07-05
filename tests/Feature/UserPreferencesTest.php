<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('fails validation for missing or disallowed keys', function () {
    $user = User::factory()->create();

    $missingKeyResponse = $this->actingAs($user)
        ->putJson(route('user.preferences.update'), [
            'value' => true,
        ]);

    $missingKeyResponse->assertStatus(422)
        ->assertJsonValidationErrors('key');

    $disallowedKeyResponse = $this->actingAs($user)
        ->putJson(route('user.preferences.update'), [
            'key' => 'notifications.discord.unknown',
            'value' => true,
        ]);

    $disallowedKeyResponse->assertStatus(422)
        ->assertJsonValidationErrors('key');
});

it('stores preferences as json and returns ok', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->putJson(route('user.preferences.update'), [
            'key' => 'notifications.discord.dm',
            'value' => true,
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
        ]);

    $preference = DB::table('user_preferences')
        ->where('user_id', $user->id)
        ->where('key', 'notifications.discord.dm')
        ->first();

    expect($preference)->not->toBeNull()
        ->and(json_decode((string) $preference->value, true))->toBeTrue();
});

it('deletes preferences when value is null or empty', function () {
    $user = User::factory()->create();

    DB::table('user_preferences')->insert([
        'user_id' => $user->id,
        'key' => 'notifications.discord.dm',
        'value' => json_encode('custom'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->putJson(route('user.preferences.update'), [
            'key' => 'notifications.discord.dm',
            'value' => null,
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'removed' => true,
        ]);

    $this->assertDatabaseMissing('user_preferences', [
        'user_id' => $user->id,
        'key' => 'notifications.discord.dm',
    ]);
});

it('only mutates preferences for the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    DB::table('user_preferences')->insert([
        'user_id' => $owner->id,
        'key' => 'notifications.discord.dm',
        'value' => json_encode(false),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($other)
        ->putJson(route('user.preferences.update'), [
            'key' => 'notifications.discord.dm',
            'value' => 'other-value',
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
        ]);

    $ownerPreference = DB::table('user_preferences')
        ->where('user_id', $owner->id)
        ->where('key', 'notifications.discord.dm')
        ->first();
    $otherPreference = DB::table('user_preferences')
        ->where('user_id', $other->id)
        ->where('key', 'notifications.discord.dm')
        ->first();

    expect($ownerPreference)->not->toBeNull()
        ->and(json_decode((string) $ownerPreference->value, true))->toBeFalse()
        ->and($otherPreference)->not->toBeNull()
        ->and(json_decode((string) $otherPreference->value, true))->toBe('other-value');
});
