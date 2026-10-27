<?php

declare(strict_types=1);

use App\Events\NhlLiveGameUpdated;
use App\Jobs\PollNhlLiveGameJob;
use App\Models\NhlGame;
use App\Services\NhlLiveGameFeed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-27 23:00:00 UTC'));
    config(['cache.default' => 'array']);
    Cache::flush();
    Bus::fake();
    Event::fake([NhlLiveGameUpdated::class]);
    Http::preventStrayRequests();
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->game = NhlGame::create([
        'nhl_game_id' => 2026020001, 'season_id' => '20262027', 'game_type' => 2,
        'game_date' => '2026-10-27', 'game_dow' => 'Tue', 'game_month' => 'Oct',
        'start_time_utc' => now(), 'game_state' => 'PRE', 'away_team_id' => 6, 'home_team_id' => 10,
        'away_team_abbrev' => 'BOS', 'home_team_abbrev' => 'TOR',
    ]);
    $this->source = [
        'id' => 2026020001, 'gameState' => 'LIVE', 'gameDate' => '2026-10-27', 'startTimeUTC' => '2026-10-27T23:00:00Z',
        'awayTeam' => ['id' => 6, 'abbrev' => 'BOS', 'score' => 0, 'sog' => 4],
        'homeTeam' => ['id' => 10, 'abbrev' => 'TOR', 'score' => 1, 'sog' => 6],
        'clock' => ['timeRemaining' => '12:34', 'inIntermission' => false],
        'periodDescriptor' => ['number' => 1, 'periodType' => 'REG'],
        'playerByGameStats' => [], 'plays' => [], 'rosterSpots' => [],
    ];
    $this->provider = function (array $extra = []): void {
        Http::fake(['api-web.nhle.com/*' => Http::response([...$this->source, ...$extra])]);
    };
    $this->feed = app(NhlLiveGameFeed::class);
});

afterEach(function (): void { $this->travelBack(); });

it('dispatches a single bounded game poll onto the dedicated live queue', function (): void {
    $this->feed->dispatchDue();
    Bus::assertDispatched(PollNhlLiveGameJob::class, fn ($job): bool => $job->gameId === 2026020001 && $job->queue === 'nhl-live');
});

it('does not poll games whose puck drop is outside the monitoring window', function (): void {
    $this->game->update(['start_time_utc' => now()->addHours(2)]);
    $this->feed->dispatchDue();
    Bus::assertNotDispatched(PollNhlLiveGameJob::class);
});

it('does not admit terminal postponed or cancelled schedule rows', function (string $state): void {
    $this->game->update(['game_state' => $state]);
    $this->feed->dispatchDue();
    Bus::assertNotDispatched(PollNhlLiveGameJob::class);
})->with(['OFF', 'FINAL', 'PPD', 'CNCL']);

it('continues to monitor yesterday unfinished games across midnight', function (): void {
    $this->game->update(['game_date' => '2026-10-26', 'game_state' => 'LIVE']);
    $this->feed->dispatchDue();
    Bus::assertDispatched(PollNhlLiveGameJob::class);
});

it('does not replay an obsolete queued live tick', function (): void {
    ($this->provider)();
    (new PollNhlLiveGameJob(2026020001, now()->timestamp - 11))->handle($this->feed);
    Http::assertNothingSent();
});

it('uses one request per source endpoint regardless of viewer count', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    foreach (range(1, 10) as $viewer) {
        $this->getJson('/games/live?game_id=2026020001')->assertOk();
    }
    Http::assertSentCount(2);
});

it('skips a duplicate poll while the cross-server game lock is held', function (): void {
    $lock = Cache::lock('nhl:live:poll:2026020001', 15);
    $lock->get();
    try { $this->feed->poll(2026020001); } finally { $lock->release(); }
    Http::assertNothingSent();
});

it('persists the shared provider result before broadcasting', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $this->assertDatabaseHas('nhl_games', ['nhl_game_id' => 2026020001, 'game_state' => 'LIVE', 'home_team_score' => 1]);
    Event::assertDispatched(NhlLiveGameUpdated::class, fn ($event): bool => $event->snapshot['game']['home']['score'] === 1
        && $this->feed->snapshot(2026020001)['game']['home']['score'] === 1);
});

it('publishes the exact provider clock instead of estimating elapsed time', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    expect($this->feed->snapshot(2026020001)['game']['time_remaining'])->toBe('12:34');
});

it('keeps polling unchanged games fresh through heartbeat events', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $this->travel(5)->seconds();
    $this->feed->poll(2026020001);
    Event::assertDispatched(NhlLiveGameUpdated::class, fn ($event): bool => $event->snapshot['changed'] === false);
    expect($this->feed->fresh($this->feed->snapshot(2026020001)))->toBeTrue();
});

it('accepts score corrections with a newer sequence', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $first = $this->feed->snapshot(2026020001);
    ($this->provider)(['homeTeam' => ['id' => 10, 'abbrev' => 'TOR', 'score' => 0, 'sog' => 6]]);
    $this->feed->poll(2026020001);
    $next = $this->feed->snapshot(2026020001);
    expect($next['sequence'])->toBeGreaterThan($first['sequence'])->and($next['game']['home']['score'])->toBe(0);
});

it('marks shared live results stale after the freshness deadline', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $this->travel(21)->seconds();
    $this->getJson('/games/live?game_id=2026020001')->assertOk()->assertJsonPath('snapshots.0.stale', true);
});

it('does not substitute an expired feed for current public live data', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $this->travel(21)->seconds();
    expect(app(\App\Services\NhlGameLiveContext::class)->publicDetail($this->game->fresh()))->toBeNull();
});

it('keeps future shared snapshots in preview mode', function (): void {
    ($this->provider)(['gameState' => 'PRE']);
    $this->feed->poll(2026020001);
    expect($this->feed->snapshot(2026020001)['game']['mode'])->toBe('preview');
});

it('publishes the final transition and stops further polling', function (): void {
    ($this->provider)(['gameState' => 'OFF']);
    $this->feed->poll(2026020001);
    expect($this->feed->snapshot(2026020001)['game']['mode'])->toBe('recap');
    $this->feed->poll(2026020001);
    Http::assertSentCount(2);
});

it('retains final provider details while waiting for player boxscore imports', function (): void {
    ($this->provider)(['gameState' => 'OFF']);
    $this->feed->poll(2026020001);
    $this->get('/games/2026020001')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
        ->where('game.mode', 'recap')->where('game.home.score', 1));
});

it('rejects provider responses for another game', function (): void {
    ($this->provider)(['id' => 999]);
    expect(fn () => $this->feed->poll(2026020001))->toThrow(\RuntimeException::class);
    expect($this->feed->snapshot(2026020001))->toBeNull();
    Event::assertNotDispatched(NhlLiveGameUpdated::class);
});

it('retains the last snapshot when the provider fails', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $previous = $this->feed->snapshot(2026020001);
    Http::fake(['api-web.nhle.com/*' => Http::response([], 503)]);
    expect(fn () => $this->feed->poll(2026020001))->toThrow(\RuntimeException::class);
    expect($this->feed->snapshot(2026020001)['sequence'])->toBe($previous['sequence']);
});

it('exposes the shared feed publicly without granting private channel access', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $this->getJson('/games/live')->assertOk()->assertJsonCount(1, 'snapshots');
});

it('allows an ordinary authenticated viewer to read the same public feed', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $this->actingAs(\App\Models\User::factory()->create())->getJson('/games/live')->assertOk();
});

it('rejects malformed snapshot game identifiers', function (): void {
    $this->getJson('/games/live?game_id=bad')->assertUnprocessable()->assertJsonValidationErrors('game_id');
});

it('returns an empty feed while no provider snapshot exists', function (): void {
    $this->getJson('/games/live')->assertOk()->assertJsonCount(0, 'snapshots');
    Http::assertNothingSent();
});

it('prevents browser caching of live snapshot responses', function (): void {
    expect($this->getJson('/games/live')->headers->get('Cache-Control'))->toContain('no-store');
});

it('keeps raw source rows and player details out of compact broadcasts', function (): void {
    ($this->provider)();
    $this->feed->poll(2026020001);
    $compact = $this->feed->compact($this->feed->snapshot(2026020001));
    expect($compact)->not->toHaveKey('boxscore')->not->toHaveKey('pbp')
        ->and($compact['game']['home'])->not->toHaveKey('boxscore');
});
