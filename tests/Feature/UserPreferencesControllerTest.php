<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserPreferencesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_removes_preference_when_value_is_null(): void
    {
        $user = User::factory()->create();

        DB::table('user_preferences')->insert([
            'user_id' => $user->id,
            'key' => 'notifications.discord.dm',
            'value' => json_encode(true),
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
    }

    public function test_upsert_persists_encoded_value_when_provided(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson(route('user.preferences.update'), [
                'key' => 'notifications.discord.channel',
                'value' => '12345',
            ]);

        $response->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'key' => 'notifications.discord.channel',
            'value' => json_encode('12345'),
        ]);
    }
}
