<?php

declare(strict_types=1);

use App\Jobs\RefreshNhlGamePredictionJob;
use App\Models\ApiClient;
use App\Models\NhlCurrentLineup;
use App\Models\NhlGame;
use App\Models\NhlPlayerInjury;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlCustomGameForecast;
use App\Services\NhlGamePredictionResponseCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    DB::table('nhl_teams')->insertOrIgnore([['nhl_id' => 10, 'abbrev' => 'TOR'], ['nhl_id' => 8, 'abbrev' => 'MTL']]);
    $this->game = NhlGame::create(['nhl_game_id' => 2026020390, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-25', 'start_time_utc' => '2026-11-26 00:00:00', 'game_state' => 'FUT',
        'game_dow' => 'Wednesday', 'game_month' => 'November', 'away_team_id' => 8, 'home_team_id' => 10,
        'away_team_abbrev' => 'MTL', 'home_team_abbrev' => 'TOR']);
    $this->names = [];
    $lines = [];
    foreach (range(1, 21) as $slot) {
        $name = 'Fixture'.chr(96 + $slot).' Skater'.chr(96 + $slot);
        $position = $slot <= 12 || $slot === 21 ? 'C' : ($slot <= 18 ? 'D' : 'G');
        Player::create(['nhl_id' => 8800000 + $slot, 'full_name' => $name,
            'first_name' => explode(' ', $name)[0], 'last_name' => explode(' ', $name)[1],
            'position' => $position, 'pos_type' => $position === 'C' ? 'F' : $position, 'team_abbrev' => 'TOR']);
        $this->names[$slot] = $name;
        if ($slot <= 18) {
            $key = $slot <= 12 ? 'F'.(int) ceil($slot / 3) : 'D'.(int) ceil(($slot - 12) / 2);
            $lines[$key][] = $name;
        }
    }
    $this->text = collect($lines)->map(fn ($names, $key): string => $key.': '.implode(', ', $names))->implode("\n")
        ."\nGoalies\n".$this->names[19]."\n".$this->names[20];
    $this->body = ['nhl_game_id' => 2026020390, 'team_abbrev' => 'TOR', 'text' => $this->text];
    $this->token = 'manual-refresh-token';
    $this->client = ApiClient::create(['name' => 'Lineup refresh partner', 'slug' => 'manual-refresh',
        'token_hash' => ApiClient::hashToken($this->token), 'scopes' => ['nhl-lineups:write', 'nhl-stats:read']]);
    $this->admin = User::factory()->create();
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $this->admin->roles()->attach($role->id, ['organization_id' => null]);

    // Hold post-commit callbacks explicitly: these tests assert both sides of that boundary.
    $this->databaseManager = DB::getFacadeRoot();
    $this->callbacks = [];
    DB::partialMock()->shouldReceive('afterCommit')->andReturnUsing(function (callable $callback): void {
        $this->callbacks[] = $callback;
    });
    $this->commitCallbacks = function (): void {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    };
    $this->submit = fn (?array $body = null) => $this->withToken($this->token)->postJson('/api/nhl-lineups', $body ?? $this->body);
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('queues a prediction after an authorized partner lineup has committed', function (): void {
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class, fn ($job): bool => $job->gameId === 2026020390);
});

it('queues a prediction after a super admin game page lineup has committed', function (): void {
    $this->actingAs($this->admin)->postJson('/games/2026020390/lineup', $this->body)->assertOk();
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class, fn ($job): bool => $job->gameId === 2026020390);
});

it('does not dispatch prediction work before the partner save commits', function (): void {
    ($this->submit)()->assertCreated();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
    expect($this->callbacks)->not->toBeEmpty();
});

it('does not dispatch prediction work before the game page save commits', function (): void {
    $this->actingAs($this->admin)->postJson('/games/2026020390/lineup', $this->body)->assertOk();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('uses the existing lineups queue with only the game request input', function (): void {
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class, fn ($job): bool =>
        $job->queue === 'lineups' && $job->input === ['nhl_game_id' => 2026020390]);
});

it('does not require a prior saved prediction to trigger work', function (): void {
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});

it('does not resolve a model or prepare forecast inputs in the submission request', function (): void {
    $this->mock(NhlCustomGameForecast::class)->shouldNotReceive('model');
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not publish a new prediction inline with the lineup response', function (): void {
    ($this->submit)()->assertCreated()->assertJsonMissingPath('prediction');
    ($this->commitCallbacks)();
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 0);
});

it('retains the accepted manual roster in storage and in the partner read endpoint', function (): void {
    $id = ($this->submit)()->assertCreated()->json('submission_id');
    ($this->commitCallbacks)();
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_game_id' => 2026020390, 'nhl_lineup_observation_id' => $id]);
    $response = $this->withToken($this->token)->getJson('/api/nhl/lineups/2026020390')->assertOk()
        ->assertJsonPath('game.teams.home.lineup_status', 'manual');
    expect(collect($response->json('game.teams.home.players'))->firstWhere('nhl_player_id', 8800001)['line_key'])->toBe('F1');
});

it('keeps the manual observation authoritative after refresh admission', function (): void {
    $id = ($this->submit)()->assertCreated()->json('submission_id');
    ($this->commitCallbacks)();
    $current = NhlCurrentLineup::where('nhl_game_id', 2026020390)->firstOrFail();
    expect($current->nhl_lineup_observation_id)->toBe($id);
    expect(data_get($current->observation->raw_evidence, 'manual_override'))->toBeTrue();
});

it('protects the saved roster while its queued refresh owns prediction admission', function (): void {
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    ($this->submit)()->assertStatus(409);
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
});

it('coalesces duplicate refresh admissions for the same game', function (): void {
    $service = app(NhlGamePredictionResponseCache::class);
    $service->refreshAfterLineupSave(2026020390);
    $service->refreshAfterLineupSave(2026020390);
    ($this->commitCallbacks)();
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
});

it('accepts a changed skater and queues another refresh after previous work ends', function (): void {
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    app(NhlGamePredictionResponseCache::class)->refreshFailed(2026020390, ['nhl_game_id' => 2026020390]);
    $body = $this->body;
    $body['text'] = str_replace($this->names[1], $this->names[21], $this->text);
    $response = ($this->submit)($body)->assertCreated();
    expect(collect($response->json('lineup.players'))->firstWhere('nhl_player_id', 8800021)['line_key'])->toBe('F1');
    ($this->commitCallbacks)();
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 2);
});

it('accepts changed line deployment and queues a refresh', function (): void {
    $body = $this->body;
    $body['text'] = str_replace([$this->names[1], $this->names[4]], ['PLACEHOLDER', $this->names[1]], $this->text);
    $body['text'] = str_replace('PLACEHOLDER', $this->names[4], $body['text']);
    $response = ($this->submit)($body)->assertCreated();
    $player = collect($response->json('lineup.players'))->firstWhere('nhl_player_id', 8800004);
    expect($player['line_key'])->toBe('F1');
    expect($player['slot_index'])->toBe(1);
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
});

it('accepts a changed manual starter and queues a refresh', function (): void {
    $body = $this->body;
    $body['text'] = str_replace("Goalies\n".$this->names[19]."\n".$this->names[20],
        "Goalies\n".$this->names[20]."\n".$this->names[19], $this->text);
    ($this->submit)($body)->assertCreated()->assertJsonPath('starting_goalie.nhl_player_id', 8800020);
    ($this->commitCallbacks)();
    Bus::assertDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue anything when the partner omits authentication', function (): void {
    $this->postJson('/api/nhl-lineups', $this->body)->assertUnauthorized();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue anything when the partner lacks lineup write scope', function (): void {
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    ($this->submit)()->assertForbidden();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue anything for a guest game page submission', function (): void {
    $this->postJson('/games/2026020390/lineup', $this->body)->assertUnauthorized();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue anything for a game page member without super admin permission', function (): void {
    $this->actingAs(User::factory()->create())->postJson('/games/2026020390/lineup', $this->body)->assertForbidden();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue a refresh for a team outside the matchup', function (): void {
    ($this->submit)([...$this->body, 'team_abbrev' => 'BOS'])->assertUnprocessable();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue a refresh for incomplete roster text', function (): void {
    ($this->submit)([...$this->body, 'text' => 'F1: '.$this->names[1]])->assertUnprocessable();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue a refresh for duplicate skaters', function (): void {
    ($this->submit)([...$this->body, 'text' => str_replace($this->names[2], $this->names[1], $this->text)])->assertUnprocessable();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue a refresh for an unavailable dressed player', function (): void {
    NhlPlayerInjury::create(['nhl_player_id' => 8800001, 'player_name' => $this->names[1],
        'team_abbrev' => 'TOR', 'availability' => 'out', 'evidence_level' => 'reported',
        'status_text' => 'Out', 'sources' => ['cbs'], 'first_observed_at' => now(), 'last_seen_at' => now(), 'last_observed_at' => now()]);
    ($this->submit)()->assertUnprocessable();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue a refresh for a canonical player assigned to another team', function (): void {
    Player::where('nhl_id', 8800001)->update(['team_abbrev' => 'BOS']);
    ($this->submit)()->assertUnprocessable();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('does not queue work for a nonexistent game', function (): void {
    ($this->submit)([...$this->body, 'nhl_game_id' => 9999999999])->assertNotFound();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('leaves no queued work when a registered lineup transaction is rolled back', function (): void {
    DB::swap($this->databaseManager);
    DB::beginTransaction();
    app(NhlGamePredictionResponseCache::class)->refreshAfterLineupSave(2026020390);
    DB::rollBack();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
    expect(Cache::get('nhl:prediction-input-writers:2026020390', []))->toBe([]);
});

it('does not queue a refresh for a game page team outside the matchup', function (): void {
    $this->actingAs($this->admin)->postJson('/games/2026020390/lineup', [...$this->body, 'team_abbrev' => 'BOS'])
        ->assertUnprocessable();
    ($this->commitCallbacks)();
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
});

it('reports a queue failure and releases prediction admission without undoing the saved lineup', function (): void {
    ($this->submit)()->assertCreated();
    $current = NhlCurrentLineup::where('nhl_game_id', 2026020390)->firstOrFail();
    // Isolate dispatch failure to the prediction callback rather than the existing page-refresh callbacks.
    $this->callbacks = [];
    app(NhlGamePredictionResponseCache::class)->refreshAfterLineupSave(2026020390);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    expect(fn () => ($this->commitCallbacks)())->toThrow(
        \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException::class,
        'The lineup was saved, but its prediction refresh could not be queued.'
    );
    $this->assertDatabaseHas('nhl_current_lineups', ['id' => $current->id]);
    expect(Cache::get('nhl:prediction-input-writers:2026020390', []))->toBe([]);
});


it('does not queue another prediction for an identical partner resubmission', function (): void {
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    app(NhlGamePredictionResponseCache::class)->refreshFailed(2026020390, ['nhl_game_id' => 2026020390]);
    ($this->submit)()->assertCreated();
    ($this->commitCallbacks)();
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
    $this->assertDatabaseCount('nhl_lineup_observations', 2);
});

it('does not queue another prediction for identical first party lineup text', function (): void {
    $this->actingAs($this->admin)->postJson('/games/2026020390/lineup', $this->body)->assertOk();
    ($this->commitCallbacks)();
    app(NhlGamePredictionResponseCache::class)->refreshFailed(2026020390, ['nhl_game_id' => 2026020390]);
    $this->actingAs($this->admin)->postJson('/games/2026020390/lineup', $this->body)->assertOk();
    ($this->commitCallbacks)();
    Bus::assertDispatchedTimes(RefreshNhlGamePredictionJob::class, 1);
});

it('still blocks an identical submission while prediction work is active', function (): void {
    NhlGame::predictionActivity(2026020390, 'active-fixture', true);
    ($this->submit)()->assertStatus(409);
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
    NhlGame::predictionActivity(2026020390, 'active-fixture', false);
});

it('does not confuse an unchanged player list with changed special teams deployment', function (): void {
    $players = [['nhl_player_id' => 8800001, 'lineup_role' => 'forward', 'line_key' => 'F1', 'slot_index' => 1]];
    $before = \App\Services\NhlAnticipatedLineupPayload::playerFingerprint($players);
    $players[0]['power_play_unit'] = 1;
    expect(\App\Services\NhlAnticipatedLineupPayload::playerFingerprint($players))->not->toBe($before);
});
