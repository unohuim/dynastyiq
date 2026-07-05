<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\FantraxUserConnected;
use App\Services\ConnectFantraxUser;
use App\Models\IntegrationSecret;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\User;
use App\Services\FantraxLeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class FantraxUserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_save_returns_json_response_and_triggers_side_effects_when_leagues_returned(): void
    {
        $user = User::factory()->create();
        $leagues = [
            ['id' => 'abc123', 'name' => 'League One'],
        ];

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->with('fantrax', 'user_leagues', ['userSecretId' => 'secret-key'])
            ->andReturn(['leagues' => $leagues]);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        $this->mock(FantraxLeagueService::class, function ($mock) use ($user, $leagues) {
            $mock->shouldReceive('upsertLeaguesForUser')
                ->once()
                ->withArgs(fn ($authUser, $payload) => $authUser->is($user) && $payload === $leagues);
        });

        Event::fake([FantraxUserConnected::class]);

        $response = $this->actingAs($user)->postJson(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'integration' => [
                    'provider' => 'fantrax',
                    'status' => 'connected',
                    'connected' => true,
                    'leagues_count' => 0,
                    'show_leagues' => false,
                ],
            ]);

        $secret = IntegrationSecret::where('user_id', $user->id)
            ->where('provider', 'fantrax')
            ->first();

        $this->assertNotNull($secret);
        $this->assertSame('secret-key', $secret->secret);
        $this->assertSame('connected', $secret->status);

        Event::assertDispatched(FantraxUserConnected::class, fn ($event) => $event->user->is($user));
        $this->assertTrue(session('fantrax.connected'));
    }

    public function test_save_redirects_and_sets_flash_when_leagues_returned(): void
    {
        $user = User::factory()->create();
        $leagues = [
            ['id' => 'abc123', 'name' => 'League One'],
        ];

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->with('fantrax', 'user_leagues', ['userSecretId' => 'secret-key'])
            ->andReturn(['leagues' => $leagues]);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        $this->mock(FantraxLeagueService::class, function ($mock) use ($user, $leagues) {
            $mock->shouldReceive('upsertLeaguesForUser')
                ->once()
                ->withArgs(fn ($authUser, $payload) => $authUser->is($user) && $payload === $leagues);
        });

        Event::fake([FantraxUserConnected::class]);

        $response = $this->from('/settings')->actingAs($user)->post(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertRedirect('/settings');
        $response->assertSessionHas('status', 'Fantrax connected (1 league(s) found).');
        $this->assertTrue(session('fantrax.connected'));
    }

    public function test_save_handles_request_exception_for_json_requests(): void
    {
        $user = User::factory()->create();

        $exception = new RequestException(new HttpClientResponse(new \GuzzleHttp\Psr7\Response(500)));

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->andThrow($exception);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        $response = $this->actingAs($user)->postJson(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'Unable to reach Fantrax. Try again.',
            ]);

        $this->assertDatabaseCount('integration_secrets', 0);
    }

    public function test_save_handles_request_exception_for_form_requests(): void
    {
        $user = User::factory()->create();

        $exception = new RequestException(new HttpClientResponse(new \GuzzleHttp\Psr7\Response(500)));

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->andThrow($exception);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        $response = $this->from('/settings')->actingAs($user)->post(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertRedirect('/settings');
        $response->assertSessionHasErrors([
            'fantrax_secret_key' => 'Unable to reach Fantrax. Try again.',
        ]);

        $this->assertDatabaseCount('integration_secrets', 0);
    }

    public function test_save_returns_error_when_no_leagues_found(): void
    {
        $user = User::factory()->create();

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->andReturn(['leagues' => []]);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        $response = $this->actingAs($user)->postJson(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'Invalid Fantrax Secret ID.',
            ]);
    }

    public function test_save_does_not_persist_secret_or_dispatch_event_when_no_leagues_found(): void
    {
        $user = User::factory()->create();

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->andReturn(['leagues' => []]);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        Event::fake([FantraxUserConnected::class]);

        $response = $this->actingAs($user)->postJson(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'error' => 'Invalid Fantrax Secret ID.',
            ]);

        $this->assertDatabaseCount('integration_secrets', 0);
        Event::assertNotDispatched(FantraxUserConnected::class);
        $this->assertFalse(session()->has('fantrax.connected'));
    }

    public function test_save_returns_form_error_when_no_leagues_found(): void
    {
        $user = User::factory()->create();

        $connector = Mockery::mock(ConnectFantraxUser::class)->makePartial();
        $connector->shouldReceive('getAPIData')
            ->once()
            ->andReturn(['leagues' => []]);

        $this->app->instance(ConnectFantraxUser::class, $connector);

        $response = $this->from('/settings')->actingAs($user)->post(route('integrations.fantrax.save'), [
            'fantrax_secret_key' => 'secret-key',
        ]);

        $response->assertRedirect('/settings');
        $response->assertSessionHasErrors([
            'fantrax_secret_key' => 'Invalid Fantrax Secret ID.',
        ]);
    }

    public function test_disconnect_deletes_secret_and_sets_status_message(): void
    {
        $user = User::factory()->create();
        IntegrationSecret::create([
            'user_id' => $user->id,
            'provider' => 'fantrax',
            'secret' => 'secret-key',
            'status' => 'connected',
        ]);
        $league = PlatformLeague::create([
            'platform' => 'fantrax',
            'platform_league_id' => 'league-1',
            'name' => 'League One',
            'sport' => 'hockey',
        ]);
        $team = PlatformTeam::create([
            'platform_league_id' => $league->id,
            'platform_team_id' => 'team-1',
            'name' => 'Team One',
        ]);
        $user->platformLeagues()->attach($league->id, [
            'team_id' => $team->id,
            'is_active' => true,
            'extras' => json_encode(['provider' => 'fantrax']),
            'synced_at' => now(),
        ]);

        $response = $this->from('/settings')->actingAs($user)->post(route('integrations.fantrax.disconnect'));

        $response->assertRedirect('/settings');
        $response->assertSessionHas('status', 'Fantrax disconnected.');
        $this->assertDatabaseCount('integration_secrets', 0);
        $this->assertDatabaseHas('league_user_teams', [
            'user_id' => $user->id,
            'platform_league_id' => $league->id,
            'is_active' => false,
        ]);
    }
}
