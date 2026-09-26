<?php

declare(strict_types=1);

use App\Models\ApiClient;
use App\Models\NhlGame;
use App\Models\NhlLineupObservation;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlLineupImageOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-23 12:00:00 America/Toronto'));
    Http::fake();
    DB::table('nhl_teams')->insert([['nhl_id' => 10, 'abbrev' => 'TOR'], ['nhl_id' => 8, 'abbrev' => 'MTL']]);
    NhlGame::query()->create([
        'nhl_game_id' => 2026010088, 'season_id' => '20262027', 'game_type' => 1,
        'game_date' => '2026-09-23', 'game_dow' => 'Wednesday', 'game_month' => 'September',
        'home_team_abbrev' => 'TOR', 'away_team_abbrev' => 'MTL', 'start_time_utc' => '2026-09-23 23:00:00',
    ]);
    $groups = [];
    foreach (range(1, 20) as $index) {
        $role = $index <= 12 ? 'C' : ($index <= 18 ? 'D' : 'G');
        $name = $index <= 18 ? 'Player ' . $index : ($index === 19 ? 'Starter Goalie' : 'Backup Goalie');
        Player::query()->create([
            'nhl_id' => 8488000 + $index, 'full_name' => $name,
            'first_name' => explode(' ', $name)[0], 'last_name' => explode(' ', $name)[1],
            'position' => $role, 'team_abbrev' => 'TOR',
        ]);
        if ($index <= 12) $groups['F' . (int) ceil($index / 3)][] = $name;
        elseif ($index <= 18) $groups['D' . (int) ceil(($index - 12) / 2)][] = $name;
    }
    $this->text = collect($groups)->map(fn (array $names): string => implode(' - ', $names))->implode("\n")
        . "\nGoalies\nStarter Goalie\nBackup Goalie";
    $this->body = ['nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'text' => $this->text];
    $this->token = 'test-lineup-write-token';
    $this->client = ApiClient::query()->create([
        'name' => 'Partner lineup submitter', 'slug' => 'partner-lineups',
        'token_hash' => ApiClient::hashToken($this->token), 'scopes' => ['nhl-lineups:write'],
    ]);
    $this->image = fn () => UploadedFile::fake()->createWithContent('lineup.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='
    ));
});

afterEach(function (): void { $this->travelBack(); });

describe('sectioned mixed-team roster submissions', function (): void {
    beforeEach(function (): void {
        $names = ['Oliver Moore', 'Anton Frondell', 'Ryan Greene', 'Dillon Boucher', 'Connor Mylymok',
            'Nick Lardis', 'AJ Spellacy', 'Roman Kantserov', 'Landon Slaggert', 'Patrick Kane',
            'Sacha Boisvert', 'Frank Nazar', 'Sam Rinzel', 'Kevin Korchinski', 'Connor Mackey',
            'Ethan Del Mastro', 'Wyatt Kaiser', 'Artyom Levshunov', 'Drew Commesso', 'Arvid Soderblom'];
        $opponents = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel',
            'India', 'Juliet', 'Kilo', 'Lima', 'Mike', 'November', 'Oscar', 'Papa', 'Quebec', 'Romeo', 'Sierra', 'Tango'];
        foreach ($names as $index => $name) {
            $parts = explode(' ', $name);
            Player::query()->where('nhl_id', 8488001 + $index)->update([
                'full_name' => $name, 'first_name' => array_shift($parts), 'last_name' => implode(' ', $parts),
                'team_abbrev' => 'CHI',
            ]);
            Player::query()->create(['nhl_id' => 8498001 + $index, 'full_name' => 'Visitor ' . $opponents[$index],
                'first_name' => 'Visitor', 'last_name' => $opponents[$index], 'team_abbrev' => 'STL',
                'position' => $index < 12 ? 'C' : ($index < 18 ? 'D' : 'G')]);
        }
        DB::table('nhl_teams')->insert([['nhl_id' => 16, 'abbrev' => 'CHI'], ['nhl_id' => 19, 'abbrev' => 'STL']]);
        NhlGame::query()->whereKey(2026010088)->update(['home_team_abbrev' => 'CHI', 'away_team_abbrev' => 'STL']);
        $this->sections = [];
        foreach (['GOALTENDERS' => [18, 19], 'DEFENSEMEN' => range(12, 17), 'FORWARDS' => range(0, 11)] as $heading => $indices) {
            $lines = [$heading . ' - GP - PTS - ' . $heading . ' - GP - PTS'];
            foreach ($indices as $index) {
                $lines[] = ($index + 1) . ' - ' . strtoupper($names[$index])
                    . ' - 0 - 0 - ' . ($index + 30) . ' - VISITOR ' . strtoupper($opponents[$index]) . ' - 0 - 0';
            }
            $this->sections[$heading] = implode("\n", $lines);
        }
    });

    it('filters the opponent columns and groups selected team players in printed order', function (bool $reverse): void {
        $sections = $reverse ? array_reverse($this->sections) : $this->sections;
        $this->withToken($this->token)->postJson('/api/nhl-lineups', [
            'nhl_game_id' => 2026010088, 'team_abbrev' => 'CHI', 'text' => implode("\n", $sections),
        ])->assertCreated()->assertJsonCount(20, 'lineup.players')
            ->assertJsonPath('starting_goalie.nhl_player_id', 8488019);
        $this->assertDatabaseHas('nhl_lineup_observation_players', ['nhl_player_id' => 8488001, 'line_key' => 'F1', 'slot_index' => 1]);
        $this->assertDatabaseHas('nhl_lineup_observation_players', ['nhl_player_id' => 8488018, 'line_key' => 'D3', 'slot_index' => 2]);
        $this->assertDatabaseHas('nhl_lineup_observation_players', ['nhl_player_id' => 8488020, 'line_key' => 'G', 'slot_index' => 2]);
        $this->assertDatabaseMissing('nhl_lineup_observation_players', ['team_abbrev' => 'STL']);
        $this->client->update(['scopes' => ['nhl-stats:read']]);
        $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
            ->assertJsonPath('games.0.teams.home.lineup_status', 'manual')
            ->assertJsonCount(20, 'games.0.teams.home.players');
        Http::assertNothingSent();
    })->with([false, true]);

    it('rejects incomplete or duplicate selected-team roster tables', function (bool $duplicate): void {
        $text = str_replace('OLIVER MOORE', $duplicate ? 'ANTON FRONDELL' : 'UNIDENTIFIED PERSON', implode("\n", $this->sections));
        $this->withToken($this->token)->postJson('/api/nhl-lineups', [
            'nhl_game_id' => 2026010088, 'team_abbrev' => 'CHI', 'text' => $text,
        ])->assertUnprocessable()->assertJsonValidationErrors('text');
        $this->assertDatabaseCount('nhl_lineup_observations', 0);
    })->with([false, true]);
});

it('uses reported skater slots rather than usual forward or defense position', function (): void {
    Player::query()->where('nhl_id', 8488001)->update(['position' => 'D']);
    Player::query()->where('nhl_id', 8488013)->update(['position' => 'C']);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)
        ->assertCreated()->assertJsonCount(20, 'lineup.players');
    $this->assertDatabaseHas('nhl_lineup_observation_players', ['nhl_player_id' => 8488001, 'lineup_role' => 'forward', 'line_key' => 'F1']);
    $this->assertDatabaseHas('nhl_lineup_observation_players', ['nhl_player_id' => 8488013, 'lineup_role' => 'defense', 'line_key' => 'D1']);
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonPath('games.0.teams.home.lineup_status', 'manual');
});

it('rejects recognized wrong-team players outside preseason including depth slots and goalies', function (int $id, int $gameType): void {
    NhlGame::query()->whereKey(2026010088)->update(['game_type' => $gameType]);
    Player::query()->where('nhl_id', $id)->update(['team_abbrev' => 'STL']);
    $response = $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)
        ->assertUnprocessable()->assertJsonValidationErrors('text');
    expect(implode(' ', $response->json('errors.text')))->toContain('STL, not TOR');
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
    $this->assertDatabaseCount('nhl_starting_goalie_observations', 0);
})->with([8488001, 8488012, 8488018, 8488019])->with([2, 3]);

it('accepts recognized cross-team players in preseason without changing canonical assignments', function (int $id): void {
    Player::query()->where('nhl_id', $id)->update(['team_abbrev' => 'STL']);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)
        ->assertCreated()->assertJsonCount(20, 'lineup.players');
    $this->assertDatabaseHas('players', ['nhl_id' => $id, 'team_abbrev' => 'STL']);
    $this->assertDatabaseHas('nhl_lineup_observation_players', ['nhl_player_id' => $id, 'team_abbrev' => 'TOR']);
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonPath('games.0.teams.home.lineup_status', 'manual')
        ->assertJsonCount(20, 'games.0.teams.home.players')
        ->assertJsonPath('games.0.teams.home.starting_goalie.nhl_player_id', 8488019);
})->with([8488001, 8488012, 8488018, 8488019]);

it('accepts same-team prospects without requiring an NHL assignment', function (): void {
    Player::query()->where('nhl_id', 8488001)->update(['current_league_abbrev' => 'AHL']);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated();
});

it('still rejects a goalie assigned to a skater slot', function (): void {
    Player::query()->where('nhl_id', 8488001)->update(['position' => 'G']);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)
        ->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
});

it('keeps previously accepted lineups visible when canonical team assignments change', function (): void {
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated();
    Player::query()->where('nhl_id', 8488001)->update(['team_abbrev' => 'STL']);
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonPath('games.0.teams.home.lineup_status', 'manual')
        ->assertJsonCount(20, 'games.0.teams.home.players');
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
    $this->assertDatabaseCount('nhl_current_lineups', 1);
});

it('accepts new submissions with unassigned canonical players', function (?string $team): void {
    Player::query()->whereIn('nhl_id', [8488001, 8488019])->update(['team_abbrev' => $team]);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)
        ->assertCreated()->assertJsonCount(20, 'lineup.players')
        ->assertJsonPath('starting_goalie.nhl_player_id', 8488019);
})->with([null, '', '   ']);

it('fetches a submitted X post once and preserves manual attribution and original evidence', function (string $url): void {
    config(['services.x.bearer_token' => 'test-x-token']);
    Http::fake(['api.x.com/2/tweets/123456*' => Http::response(['data' => [
        'id' => '123456', 'text' => 'Truncated caption', 'note_tweet' => ['text' => $this->text],
        'author_id' => '987', 'created_at' => '2026-09-22T15:00:00Z',
    ]])]);
    $response = $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'post_url' => $url,
    ])->assertCreated()->assertJsonPath('lineup.manual_override', true)
        ->assertJsonCount(20, 'lineup.players')->assertJsonPath('starting_goalie.nhl_player_id', 8488019);
    $observation = NhlLineupObservation::query()->findOrFail($response->json('submission_id'));
    expect(data_get($observation->raw_evidence, 'submitted_post.raw_post.id'))->toBe('123456')
        ->and(data_get($observation->raw_evidence, 'submitted_post.published_at'))->toBe('2026-09-22T15:00:00Z')
        ->and($observation->raw_evidence['submitted_by_api_client_id'])->toBe($this->client->id)
        ->and($observation->source->platform)->toBe('manual');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.x.com/2/tweets/123456?')
        && $request->hasHeader('Authorization', 'Bearer test-x-token'));
    $this->assertDatabaseHas('integration_api_usage_logs', ['operation' => 'nhl_lineup_post_lookup']);
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonPath('games.0.teams.home.lineup_status', 'manual')
        ->assertJsonCount(20, 'games.0.teams.home.players');
})->with(['https://x.com/reporter/status/123456?s=20', 'https://twitter.com/reporter/status/123456', 'https://x.com/i/web/status/123456']);

it('rejects unsafe or non-post URLs without a provider request', function (string $url): void {
    config(['services.x.bearer_token' => 'test-x-token']);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'post_url' => $url,
    ])->assertUnprocessable()->assertJsonValidationErrors('post_url');
    Http::assertNothingSent();
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
})->with(['https://example.com/user/status/123456', 'https://x.com.evil.test/user/status/123456',
    'http://x.com/user/status/123456', 'https://x.com/user', 'https://user:pass@x.com/user/status/123456']);

it('preserves the current lineup when X cannot return the requested post', function (int $status): void {
    config(['services.x.bearer_token' => 'test-x-token']);
    $original = $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated()->json('submission_id');
    Http::fake(['api.x.com/*' => Http::response(['errors' => [['detail' => 'Unavailable']]], $status)]);
    $this->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'post_url' => 'https://x.com/user/status/123456',
    ])->assertUnprocessable()->assertJsonPath('success', false)->assertJsonValidationErrors('post_url');
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $original]);
    Http::assertSentCount(1);
})->with([200, 403, 404, 429, 500]);

it('uses bounded photo OCR for an image-only submitted post', function (): void {
    config(['services.x.bearer_token' => 'test-x-token']);
    Http::fake(['api.x.com/*' => Http::response([
        'data' => ['id' => '123456', 'text' => 'Tonight', 'attachments' => ['media_keys' => ['photo1']]],
        'includes' => ['media' => [['media_key' => 'photo1', 'type' => 'photo', 'url' => 'https://pbs.twimg.com/media/lineup.png']]],
    ])]);
    $text = $this->text;
    $this->mock(NhlLineupImageOcr::class, function ($mock) use ($text): void {
        $mock->shouldReceive('extract')->once()->with('https://pbs.twimg.com/media/lineup.png', \Mockery::type('float'))
            ->andReturn(['status' => 'ok', 'text' => $text]);
        $mock->shouldNotReceive('extractUpload');
    });
    $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'post_url' => 'https://x.com/user/status/123456',
    ])->assertCreated()->assertJsonCount(20, 'lineup.players');
    Http::assertSentCount(1);
});

it('checks authorization and team membership before fetching submitted URLs', function (): void {
    $body = ['nhl_game_id' => 2026010088, 'team_abbrev' => 'DET', 'post_url' => 'https://x.com/user/status/123456'];
    $this->postJson('/api/nhl-lineups', $body)->assertUnauthorized();
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $body)
        ->assertUnprocessable()->assertJsonValidationErrors('team_abbrev');
    Http::assertNothingSent();
});

it('fails clearly when X credentials are missing without attempting a lookup', function (): void {
    config(['services.x.bearer_token' => '']);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'post_url' => 'https://x.com/user/status/123456',
    ])->assertUnprocessable()->assertJsonValidationErrors('post_url');
    Http::assertNothingSent();
});

it('keeps explicit reviewed text ahead of fetched caption and photo OCR', function (): void {
    config(['services.x.bearer_token' => 'test-x-token']);
    Http::fake(['api.x.com/*' => Http::response(['data' => ['id' => '123456', 'text' => 'Unrelated caption']])]);
    $this->mock(NhlLineupImageOcr::class, function ($mock): void {
        $mock->shouldNotReceive('extract');
        $mock->shouldNotReceive('extractUpload');
    });
    $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        ...$this->body, 'post_url' => 'https://x.com/user/status/123456', 'image_reviewed' => 1,
    ])->assertCreated()->assertJsonCount(20, 'lineup.players');
    Http::assertSentCount(1);
});

it('does not accept a URL solely because the X lookup succeeded', function (): void {
    config(['services.x.bearer_token' => 'test-x-token']);
    Http::fake(['api.x.com/*' => Http::response(['data' => ['id' => '123456', 'text' => 'No lineup today']])]);
    $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'post_url' => 'https://x.com/user/status/123456',
    ])->assertUnprocessable()->assertJsonValidationErrors('text')
        ->assertJsonPath('interpreted_text', 'No lineup today');
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
});

it('protects lineup reads with the existing read scope', function (string $case, int $status, string $path): void {
    if ($case === 'read' || $case === 'revoked') {
        $this->client->update(['scopes' => ['nhl-stats:read']]);
    }
    if ($case === 'revoked') {
        $this->client->update(['revoked_at' => now()]);
    }
    if ($case !== 'missing') {
        $this->withToken($case === 'invalid' ? 'invalid-token' : $this->token);
    }
    $this->getJson($path)->assertStatus($status);
    Http::assertNothingSent();
})->with([['missing', 401], ['invalid', 403], ['write only', 403], ['revoked', 403], ['read', 200]])
    ->with(['/api/nhl/lineups?date=2026-09-24', '/api/nhl/lineups/2026010088']);

it('reads only the requested game with submitted players and locked goalie', function (): void {
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated();
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
    $other = NhlGame::query()->findOrFail(2026010088)->replicate();
    $other->nhl_game_id = 2026010089;
    $other->save();
    app(\App\Services\NhlStartingGoalieSelector::class)->lockBoxscoreStarters(2026010088, [
        'id' => 2026010088, 'gameState' => 'LIVE', 'homeTeam' => ['abbrev' => 'TOR'],
        'playerByGameStats' => ['homeTeam' => ['goalies' => [['playerId' => 8488020, 'starter' => true]]]],
    ]);
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups/2026010088')->assertOk()
        ->assertJsonPath('game.nhl_game_id', 2026010088)
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('game.teams.home.lineup_status', 'manual')
        ->assertJsonCount(20, 'game.teams.home.players')
        ->assertJsonPath('game.teams.home.starting_goalie.nhl_player_id', 8488020)
        ->assertJsonPath('game.teams.home.starting_goalie.status', 'confirmed')
        ->assertJsonPath('game.teams.away.players', [])
        ->assertJsonPath('game.teams.away.lineup_status', 'not_reported');
    Http::assertNothingSent();
});

it('returns not found for unknown or malformed lineup game ids', function (string $id): void {
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->withToken($this->token)->getJson('/api/nhl/lineups/' . $id)->assertNotFound();
    Http::assertNothingSent();
})->with(['9999999999', 'not-a-game']);

it('requires a valid explicit date for lineup reads', function (string $query): void {
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->withToken($this->token)->getJson('/api/nhl/lineups' . $query)
        ->assertUnprocessable()->assertJsonValidationErrors('date');
})->with(['', '?date=tomorrow', '?date=2026-02-30']);

it('reads submitted players and goalies with independent per-team lineup status', function (): void {
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated();
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonCount(1, 'games')->assertJsonPath('games.0.nhl_game_id', 2026010088)
        ->assertJsonPath('games.0.teams.home.team_id', 10)
        ->assertJsonPath('games.0.teams.home.team_abbrev', 'TOR')
        ->assertJsonPath('games.0.teams.home.lineup_status', 'manual')
        ->assertJsonCount(20, 'games.0.teams.home.players')
        ->assertJsonPath('games.0.teams.home.starting_goalie.nhl_player_id', 8488019)
        ->assertJsonPath('games.0.teams.home.starting_goalie.status', 'expected')
        ->assertJsonPath('games.0.teams.away.lineup_status', 'not_reported')
        ->assertJsonPath('games.0.teams.away.players', [])
        ->assertJsonPath('games.0.teams.away.updated_at', null);
    $this->getJson('/api/nhl/lineups?date=2026-09-24')->assertOk()
        ->assertJsonPath('games', [])->assertJsonPath('meta.count', 0);
    Http::assertNothingSent();
});

it('maps verified evidence without reporting an invalid roster', function (string $evidence, bool $invalidate, string $status): void {
    $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated();
    $observation = NhlLineupObservation::query()->firstOrFail();
    $observation->update(['raw_evidence' => [...$observation->raw_evidence, 'manual_override' => false]]);
    DB::table('nhl_current_lineups')->update(['evidence_status' => $evidence]);
    if ($invalidate) {
        $observation->players()->where('line_key', 'F1')->delete();
    }
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonPath('games.0.teams.home.lineup_status', $status)
        ->assertJsonCount($invalidate ? 0 : 20, 'games.0.teams.home.players');
})->with([
    ['reported', false, 'reported'], ['corroborated', false, 'reported'],
    ['official', false, 'official'], ['reported', true, 'not_reported'],
]);

it('keeps split squad games separate and marks projected goalies explicitly', function (): void {
    $game = NhlGame::query()->firstOrFail();
    $second = $game->replicate();
    $second->nhl_game_id = 2026010089;
    $second->start_time_utc = '2026-09-23 20:00:00';
    $second->save();
    $this->mock(\App\Services\NhlStartingGoalieSelector::class, function ($mock): void {
        foreach ([2026010088, 2026010089] as $id) {
            foreach (['TOR', 'MTL'] as $team) {
                $mock->shouldReceive('select')->once()->with($id, $team, '20262027')->andReturn([
                    'nhl_player_id' => 8488019, 'status' => 'projected', 'selection_source' => 'goalie_projection',
                ]);
            }
        }
    });
    $this->client->update(['scopes' => ['nhl-stats:read']]);
    $this->withToken($this->token)->getJson('/api/nhl/lineups?date=2026-09-23')->assertOk()
        ->assertJsonCount(2, 'games')->assertJsonPath('games.0.nhl_game_id', 2026010089)
        ->assertJsonPath('games.1.nhl_game_id', 2026010088)
        ->assertJsonPath('games.0.teams.home.players', [])
        ->assertJsonPath('games.0.teams.home.starting_goalie.status', 'projected');
    Http::assertNothingSent();
});

it('requires the dedicated live write key before any lineup processing', function (string $case, int $status): void {
    $this->mock(NhlLineupImageOcr::class, fn ($mock) => $mock->shouldNotReceive('extractUpload'));
    if ($case === 'read only') $this->client->update(['scopes' => ['nhl-stats:read']]);
    if ($case === 'revoked') $this->client->update(['revoked_at' => now()]);
    if ($case !== 'missing') $this->withToken($case === 'invalid' ? 'wrong' : $this->token);
    $this->postJson('/api/nhl-lineups', $this->body)->assertStatus($status);
    $this->assertDatabaseCount('nhl_lineup_observations', 0);
})->with([['missing', 401], ['invalid', 403], ['read only', 403], ['revoked', 403]]);

it('keeps the write key separate from read permissions', function (): void {
    $this->withToken($this->token)->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026010088')->assertForbidden();
});

it('accepts text with server-owned attribution and exposes the accepted lineup and goalies', function (): void {
    $response = $this->withToken($this->token)->postJson('/api/nhl-lineups', [
        ...$this->body, 'submitted_by_user_id' => 123, 'submitted_by_api_client_id' => 999, 'published_at' => '2099-01-01',
    ])->assertCreated()->assertJsonPath('success', true)->assertJsonPath('lineup.manual_override', true)
        ->assertJsonCount(20, 'lineup.players')->assertJsonPath('starting_goalie.nhl_player_id', 8488019);
    $observation = NhlLineupObservation::query()->findOrFail($response->json('submission_id'));
    expect($observation->raw_evidence['submitted_by_api_client_id'])->toBe($this->client->id)
        ->and($observation->raw_evidence['submitted_by_user_id'])->toBeNull()
        ->and($observation->provider_published_at->equalTo(now()))->toBeTrue()
        ->and($observation->source->handle)->toBe('api-client-' . $this->client->id);
    $this->assertDatabaseHas('nhl_starting_goalie_observations', [
        'nhl_game_id' => 2026010088, 'nhl_player_id' => 8488019, 'provider' => 'manual',
    ]);
    ApiClient::query()->create(['name' => 'Reader', 'token_hash' => ApiClient::hashToken('read-token'), 'scopes' => ['nhl-stats:read']]);
    $this->withToken('read-token')->getJson('/api/nhl-anticipated-lineups?nhl_game_id=2026010088')
        ->assertOk()->assertJsonCount(20, 'anticipated_lineups.0.players')
        ->assertJsonPath('anticipated_lineups.0.manual_override', true);
    Http::assertNothingSent();
});

it('orders UI and API manual submissions equally even within the same second', function (): void {
    $first = $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated()->json('submission_id');
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'Super Admin', 'slug' => 'super-admin', 'level' => 99]);
    $user->roles()->attach($role->id, ['organization_id' => null]);
    $this->actingAs($user)->postJson('/games/2026010088/lineup', [
        'team_abbrev' => 'TOR', 'text' => str_replace("Starter Goalie\nBackup Goalie", "Backup Goalie\nStarter Goalie", $this->text),
    ])->assertOk();
    expect(app(\App\Services\NhlStartingGoalieSelector::class)->select(2026010088, 'TOR')['nhl_player_id'])->toBe(8488020);
    $last = $this->postJson('/api/nhl-lineups', $this->body)->assertCreated()
        ->assertJsonPath('starting_goalie.nhl_player_id', 8488019)->json('submission_id');
    expect($last)->toBeGreaterThan($first);
    $this->assertDatabaseCount('nhl_lineup_observations', 3);
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $last]);
});

it('leaves the previous accepted submission unchanged on validation failure', function (string $case): void {
    $id = $this->withToken($this->token)->postJson('/api/nhl-lineups', $this->body)->assertCreated()->json('submission_id');
    $body = match ($case) {
        'missing evidence' => ['nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR'],
        'wrong team' => [...$this->body, 'team_abbrev' => 'LAK'],
        'partial' => [...$this->body, 'text' => 'Player 1 - Player 2 - Player 3'],
        'duplicate' => [...$this->body, 'text' => str_replace('Player 2 -', 'Player 1 -', $this->text)],
        'image URL' => ['nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'image' => 'https://example.com/image.png'],
        'too long' => [...$this->body, 'text' => str_repeat('x', 20001)],
    };
    $this->postJson('/api/nhl-lineups', $body)->assertUnprocessable()->assertJsonPath('success', false)->assertJsonStructure(['message', 'errors']);
    $this->assertDatabaseCount('nhl_lineup_observations', 1);
    $this->assertDatabaseCount('nhl_starting_goalie_observations', 1);
    $this->assertDatabaseHas('nhl_current_lineups', ['nhl_lineup_observation_id' => $id]);
})->with(['missing evidence', 'wrong team', 'partial', 'duplicate', 'image URL', 'too long']);

it('rejects a missing game before OCR', function (): void {
    $this->mock(NhlLineupImageOcr::class, fn ($mock) => $mock->shouldNotReceive('extractUpload'));
    $this->withToken($this->token)->postJson('/api/nhl-lineups', [...$this->body, 'nhl_game_id' => 999])->assertNotFound();
});

it('processes image uploads through the existing OCR and validator', function (bool $valid): void {
    $text = $this->text;
    $this->mock(NhlLineupImageOcr::class, function ($mock) use ($valid, $text): void {
        $mock->shouldReceive('extractUpload')->once()->andReturn([
            'status' => $valid ? 'ok' : 'low_confidence', 'text' => $text, 'reason' => 'Review the extracted text.',
        ]);
        $mock->shouldReceive('reviewText')->andReturn($text);
    });
    $response = $this->withToken($this->token)->post('/api/nhl-lineups', [
        'nhl_game_id' => 2026010088, 'team_abbrev' => 'TOR', 'image' => ($this->image)(),
    ], ['Accept' => 'application/json'])->assertStatus($valid ? 201 : 422)->assertJsonPath('success', $valid);
    if (! $valid) $response->assertJsonPath('interpreted_text', $this->text);
    $this->assertDatabaseCount('nhl_lineup_observations', $valid ? 1 : 0);
    Http::assertNothingSent();
})->with([true, false]);
