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
