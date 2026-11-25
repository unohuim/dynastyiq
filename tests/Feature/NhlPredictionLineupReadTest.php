<?php

declare(strict_types=1);

use App\Jobs\PrepareNhlGamePredictionJob;
use App\Jobs\RefreshNhlGamePredictionJob;
use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Models\NhlPlayerInjury;
use App\Services\NhlAnticipatedLineupPayload;
use App\Services\NhlGamePredictionHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00 UTC'));
    config(['cache.default' => 'array']);
    Bus::fake();
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    DB::table('nhl_teams')->insertOrIgnore([['nhl_id' => 19, 'abbrev' => 'STL'], ['nhl_id' => 2, 'abbrev' => 'NYI']]);
    $this->game = NhlGame::create(['nhl_game_id' => 2026020390, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-11-25', 'start_time_utc' => '2026-11-26 00:00:00', 'game_state' => 'FUT',
        'game_dow' => 'Wednesday', 'game_month' => 'November', 'away_team_id' => 19, 'home_team_id' => 2,
        'away_team_abbrev' => 'STL', 'home_team_abbrev' => 'NYI']);
    $this->payload = ['prediction_available' => true, 'game' => ['nhl_game_id' => 2026020390],
        'inputs' => [], 'prediction' => ['winner' => ['side' => 'away', 'team_abbrev' => 'STL'],
            'predicted_score' => ['away' => 3, 'home' => 2], 'away_win_probability' => 0.6, 'home_win_probability' => 0.4,
            'confidence_score' => 55, 'public_confidence_score' => 61], 'market_probabilities' => [], 'anticipated_lineups' => [], 'teams' => []];
    $this->texts = [];
    foreach (['away' => ['STL', 19], 'home' => ['NYI', 2]] as $side => [$team, $teamId]) {
        $players = [];
        $lines = [];
        foreach (range(1, 19) as $slot) {
            $id = 8800000 + $teamId * 100 + $slot;
            $name = ucfirst(strtolower($team)).chr(96 + $slot).' Skater'.chr(96 + $slot);
            $role = $slot <= 12 ? 'forward' : ($slot <= 18 ? 'defense' : 'goalie');
            $line = $slot <= 12 ? 'F'.(int) ceil($slot / 3) : ($slot <= 18 ? 'D'.(int) ceil(($slot - 12) / 2) : 'G');
            $index = $slot <= 12 ? ($slot - 1) % 3 + 1 : ($slot <= 18 ? ($slot - 13) % 2 + 1 : 1);
            DB::table('players')->insert(['nhl_id' => $id, 'full_name' => $name, 'first_name' => explode(' ', $name)[0],
                'last_name' => explode(' ', $name)[1], 'team_abbrev' => $team, 'position' => $role === 'forward' ? 'C' : ($role === 'defense' ? 'D' : 'G'),
                'pos_type' => $role === 'forward' ? 'F' : ($role === 'defense' ? 'D' : 'G')]);
            $players[] = ['nhl_player_id' => $id, 'player_name' => $name, 'lineup_role' => $role,
                'line_key' => $line, 'slot_index' => $index, 'position' => $role === 'forward' ? 'F' : ($role === 'defense' ? 'D' : 'G')];
            if ($slot <= 18) {
                $lines[$line][] = $name;
            } else {
                $this->payload['inputs'][$side.'_goalie_id'] = $id;
                $this->payload['goalies'][$side] = ['nhl_player_id' => $id, 'name' => $name];
            }
        }
        $this->texts[$team] = collect($lines)->map(fn ($names, $key): string => $key.': '.implode(', ', $names))->implode("\n");
        $this->payload['anticipated_lineups'][$side] = ['nhl_game_id' => 2026020390, 'team_abbrev' => $team,
            'manual_override' => false, 'prediction_source' => 'projected_roster', 'evidence_status' => 'projected',
            'players' => $players, 'sources' => [], 'source_count' => 0];
        $this->payload['teams'][$side] = ['team_abbrev' => $team, 'roster' => collect($players)
            ->whereIn('lineup_role', ['forward', 'defense'])->map(fn ($p): array => [
                'nhl_player_id' => $p['nhl_player_id'], 'player_name' => $p['player_name'], 'position' => $p['position'],
                'line_key' => $p['line_key'], 'projected_goals' => 0.1])->values()->all()];
    }
    $this->client = ApiClient::create(['name' => 'Lineup reader', 'slug' => 'prediction-lineup-reader', 'token_prefix' => 'fixture',
        'token_hash' => ApiClient::hashToken('prediction-lineup-token'), 'scopes' => ['nhl-stats:read', 'nhl-lineups:write']]);
    $this->save = fn (?array $payload = null) => app(NhlGamePredictionHistory::class)->record(2026020390,
        ['nhl_game_id' => 2026020390], 'fixture', [], $payload ?? $this->payload);
    $this->read = fn () => $this->withToken('prediction-lineup-token')->getJson('/api/nhl/lineups/2026020390');
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('restores STLs complete projected roster after prediction publication with no report', function (): void {
    $revision = ($this->save)();
    $this->assertDatabaseCount('nhl_current_lineups', 0);
    ($this->read)()->assertOk()->assertJsonCount(19, 'game.teams.away.players')
        ->assertJsonPath('game.teams.away.lineup_status', 'projected')
        ->assertJsonPath('game.teams.away.prediction_revision_id', $revision['meta']['prediction_revision_id']);
});

it('returns the exact calculated player list rather than independently selecting players', function (): void {
    ($this->save)();
    expect(($this->read)()->assertOk()->json('game.teams.away.players'))->toBe($this->payload['anticipated_lineups']['away']['players']);
});

it('returns both teams and preserves home and away ownership', function (): void {
    ($this->save)();
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.team_abbrev', 'STL')
        ->assertJsonPath('game.teams.home.team_abbrev', 'NYI')->assertJsonCount(19, 'game.teams.home.players');
});

it('provides the same saved participants on the date schedule endpoint', function (): void {
    ($this->save)();
    $this->withToken('prediction-lineup-token')->getJson('/api/nhl/lineups?date=2026-11-25')->assertOk()
        ->assertJsonPath('games.0.teams.away.players', $this->payload['anticipated_lineups']['away']['players']);
});

it('provides the same saved participants on the anticipated lineup endpoint', function (): void {
    ($this->save)();
    $response = $this->withToken('prediction-lineup-token')->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026020390')->assertOk();
    $away = collect($response->json('anticipated_lineups'))->firstWhere('team_abbrev', 'STL');
    expect($away['players'])->toBe($this->payload['anticipated_lineups']['away']['players']);
    $response->assertJsonPath('meta.count', 2);
});

it('matches the lineup returned by the prediction API for the same revision', function (): void {
    ($this->save)();
    $prediction = $this->withToken('prediction-lineup-token')->getJson('/api/nhl-game-predictions?nhl_game_id=2026020390')->assertOk();
    expect(($this->read)()->json('game.teams.away.players'))->toBe($prediction->json('anticipated_lineups.away.players'));
});

it('exposes the original prediction timestamp instead of pretending it was just calculated', function (): void {
    $revision = ($this->save)();
    $this->travel(10)->minutes();
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.predicted_at', $revision['meta']['predicted_at']);
});

it('preserves calculated line keys and slot ordering', function (): void {
    ($this->save)();
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players.0.line_key', 'F1')
        ->assertJsonPath('game.teams.away.players.11.line_key', 'F4')->assertJsonPath('game.teams.away.players.17.slot_index', 2);
});

it('preserves calculated NHL player identities', function (): void {
    ($this->save)();
    expect(collect(($this->read)()->json('game.teams.away.players'))->pluck('nhl_player_id')->all())
        ->toBe(array_column($this->payload['anticipated_lineups']['away']['players'], 'nhl_player_id'));
});

it('does not write a fake lineup observation or current lineup when reusing a prediction', function (): void {
    ($this->save)();
    ($this->read)()->assertOk();
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
    $this->assertDatabaseCount('nhl_current_lineups', 0);
});

it('does not queue or calculate another forecast when reading a published lineup', function (): void {
    ($this->save)();
    ($this->read)()->assertOk();
    Bus::assertNotDispatched(PrepareNhlGamePredictionJob::class);
    Bus::assertNotDispatched(RefreshNhlGamePredictionJob::class);
    $this->assertDatabaseCount('nhl_game_prediction_revisions', 1);
});

it('keeps internal prediction input reads independent from saved prediction output', function (): void {
    ($this->save)();
    expect(app(NhlAnticipatedLineupPayload::class)->forGameTeam(2026020390, 'STL', false))->toBeNull();
    expect(app(NhlAnticipatedLineupPayload::class)->build(Carbon::parse('2026-11-25'), 2026020390)['anticipated_lineups'])->toHaveCount(0);
});

it('retains not reported when neither a report nor a prediction exists', function (): void {
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', [])->assertJsonPath('game.teams.away.lineup_status', 'not_reported');
});

it('ignores unavailable later attempts and keeps the latest successful revision', function (): void {
    $first = ($this->save)();
    ($this->save)([...$this->payload, 'prediction_available' => false]);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.prediction_revision_id', $first['meta']['prediction_revision_id']);
});

it('uses the same latest successful variant selection as the prediction API', function (): void {
    ($this->save)();
    $second = ($this->save)();
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.prediction_revision_id', $second['meta']['prediction_revision_id']);
});

it('does not borrow another games prediction', function (): void {
    ($this->save)();
    DB::table('nhl_game_prediction_revisions')->update(['nhl_game_id' => 2026020999]);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', []);
});

it('rejects mismatched payload game identity', function (): void {
    $payload = $this->payload;
    $payload['game']['nhl_game_id'] = 2026020999;
    ($this->save)($payload);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', []);
});

it('rejects a snapshot assigned to a different team', function (): void {
    $payload = $this->payload;
    $payload['anticipated_lineups']['away']['team_abbrev'] = 'BOS';
    ($this->save)($payload);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', []);
});

it('does not let an invalid team snapshot suppress the other complete team', function (): void {
    $payload = $this->payload;
    unset($payload['anticipated_lineups']['away'], $payload['teams']['away']);
    ($this->save)($payload);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', [])->assertJsonCount(19, 'game.teams.home.players');
});

it('reconstructs older presentation from that same revisions calculated roster', function (): void {
    $payload = $this->payload;
    $payload['anticipated_lineups']['away']['players'][0]['nhl_player_id'] = 999999;
    ($this->save)($payload);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players.0.nhl_player_id', 8801901);
});

it('does not overlay pregame snapshots onto live game lineup authority', function (): void {
    ($this->save)();
    $this->game->update(['game_state' => 'LIVE']);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', []);
});

it('does not overlay pregame snapshots onto final game lineup authority', function (): void {
    ($this->save)();
    $this->game->update(['game_state' => 'OFF']);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', []);
});

it('does not restore an injured unavailable player from a stale successful snapshot', function (): void {
    ($this->save)();
    NhlPlayerInjury::create(['nhl_player_id' => 8801901, 'player_name' => 'Fixture injured skater', 'team_abbrev' => 'STL',
        'availability' => 'out', 'evidence_level' => 'reported', 'status_text' => 'Out', 'sources' => ['cbs'],
        'first_observed_at' => now(), 'last_observed_at' => now(), 'last_seen_at' => now()]);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', [])->assertJsonCount(19, 'game.teams.home.players');
});

it('preserves valid manual input and publishes that same lineup to partner reads', function (): void {
    $submitted = $this->withToken('prediction-lineup-token')->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026020390, 'team_abbrev' => 'STL', 'text' => $this->texts['STL'],
    ])->assertCreated()->json('lineup');
    $payload = $this->payload;
    $payload['anticipated_lineups']['away'] = $submitted;
    // This fixture has no submitted starter; retain the calculated starter in the snapshot.
    $payload['anticipated_lineups']['away']['players'][] = $this->payload['anticipated_lineups']['away']['players'][18];
    $revision = ($this->save)($payload);
    $before = DB::table('nhl_current_lineups')->first();
    $read = ($this->read)()->assertOk()->assertJsonPath('game.teams.away.lineup_status', 'manual');
    expect($read->json('game.teams.away.players'))->toBe($revision['anticipated_lineups']['away']['players']);
    expect(DB::table('nhl_current_lineups')->first())->toEqual($before);
    expect(app(NhlAnticipatedLineupPayload::class)->forGameTeam(2026020390, 'STL', false)['manual_override'])->toBeTrue();
});

it('preserves manual attribution and source evidence stored with the prediction', function (): void {
    $payload = $this->payload;
    $payload['anticipated_lineups']['away']['manual_override'] = true;
    $payload['anticipated_lineups']['away']['evidence_status'] = 'reported';
    $payload['anticipated_lineups']['away']['sources'] = [['platform' => 'manual', 'name' => 'Partner submission']];
    ($this->save)($payload);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.lineup_status', 'manual')
        ->assertJsonPath('game.teams.away.sources.0.platform', 'manual');
});

it('requires authentication on every partner lineup read shape', function (string $url): void {
    $this->getJson($url)->assertUnauthorized();
})->with(['/api/nhl/lineups/2026020390', '/api/nhl/lineups?date=2026-11-25', '/api/nhl-anticipated-lineups?nhl_game_id=2026020390']);

it('requires stats read scope on every partner lineup read shape', function (string $url): void {
    $this->client->update(['scopes' => ['nhl-lineups:write']]);
    $this->withToken('prediction-lineup-token')->getJson($url)->assertForbidden();
})->with(['/api/nhl/lineups/2026020390', '/api/nhl/lineups?date=2026-11-25', '/api/nhl-anticipated-lineups?nhl_game_id=2026020390']);

it('returns not found for an unknown requested game', function (): void {
    $this->withToken('prediction-lineup-token')->getJson('/api/nhl/lineups/2026020999')->assertNotFound();
});

it('does not mutate saved prediction history during lineup serialization', function (): void {
    ($this->save)();
    $before = DB::table('nhl_game_prediction_revisions')->get()->toJson();
    ($this->read)()->assertOk();
    expect(DB::table('nhl_game_prediction_revisions')->get()->toJson())->toBe($before);
});

it('handles a malformed saved payload without blocking the lineup response', function (): void {
    $revision = ($this->save)();
    DB::table('nhl_game_prediction_revisions')->where('id', $revision['meta']['prediction_revision_id'])->update(['payload' => 'null']);
    ($this->read)()->assertOk()->assertJsonPath('game.teams.away.players', []);
});
