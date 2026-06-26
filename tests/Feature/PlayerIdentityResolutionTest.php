<?php

declare(strict_types=1);

use App\Classes\ImportNHLPlayer as LegacyImportNHLPlayer;
use App\DTO\PlayerIdentityMatchResult;
use App\Jobs\ImportNHLPlayerJob;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\ImportNHLPlayer;
use App\Services\PlayerIdentityNormalizer;
use App\Services\PlayerIdentityResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-06-26 12:00:00');
    config(['cache.default' => 'array']);

    $this->nhlPayload = static function (array $overrides = []): array {
        return array_replace_recursive([
            'playerId' => 8478402,
            'currentTeamId' => 10,
            'currentTeamAbbrev' => 'TOR',
            'firstName' => ['default' => 'Auston'],
            'lastName' => ['default' => 'Matthews'],
            'birthDate' => '1997-09-17',
            'birthCountry' => 'USA',
            'position' => 'C',
            'headshot' => 'https://assets.nhle.com/headshots/8478402.png',
            'heroImage' => 'https://assets.nhle.com/heroes/8478402.png',
            'seasonTotals' => [
                [
                    'teamName' => ['default' => 'Toronto Maple Leafs'],
                    'season' => 20252026,
                    'gameTypeId' => 2,
                    'sequence' => 1,
                    'leagueAbbrev' => 'NHL',
                    'gamesPlayed' => 82,
                    'avgToi' => '20:00',
                    'goals' => 60,
                    'assists' => 46,
                    'points' => 106,
                    'shots' => 300,
                ],
            ],
        ], $overrides);
    };

    $this->fakeNhlLanding = function (array $payload, string $playerId = '8478402'): void {
        Http::fake([
            "https://api-web.nhle.com/v1/player/{$playerId}/landing" => Http::response($payload),
        ]);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

it('upserts an nhl external identity when importing an nhl player', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::first()->provider)->toBe(PlayerExternalIdentity::PROVIDER_NHL);
});

it('does not duplicate an nhl identity when reimporting the same player', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');
    app(ImportNHLPlayer::class)->import('8478402');

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(Player::query()->count())->toBe(1);
});

it('creates a canonical player for a new nhl authority identity', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $player = Player::first();

    expect($player)->not->toBeNull();
    expect($player->nhl_id)->toBe(8478402);
    expect($player->full_name)->toBe('Auston Matthews');
});

it('links the nhl identity to the created canonical player', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->player_id)->toBe(Player::first()->id);
    expect($identity->player->full_name)->toBe('Auston Matthews');
});

it('updates a linked canonical player on reimport', function () {
    Http::fake([
        'https://api-web.nhle.com/v1/player/8478402/landing' => Http::sequence()
            ->push(($this->nhlPayload)())
            ->push(($this->nhlPayload)([
                'currentTeamAbbrev' => 'UTA',
                'currentTeamId' => 59,
            ])),
    ]);

    app(ImportNHLPlayer::class)->import('8478402');
    app(ImportNHLPlayer::class)->import('8478402');

    $player = Player::first();

    expect($player->team_abbrev)->toBe('UTA');
    expect($player->nhl_team_id)->toBe(59);
});

it('preserves the raw nhl payload on the identity', function () {
    $payload = ($this->nhlPayload)([
        'heroImage' => 'https://example.test/hero.png',
    ]);
    ($this->fakeNhlLanding)($payload);

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->raw_payload['playerId'])->toBe(8478402);
    expect($identity->raw_payload['heroImage'])->toBe('https://example.test/hero.png');
});

it('stores matched status and confidence after import', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(100);
    expect($identity->unmatched_reason)->toBeNull();
});

it('sets first and last seen timestamps on first import', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->first_seen_at->toDateTimeString())->toBe('2026-06-26 12:00:00');
    expect($identity->last_seen_at->toDateTimeString())->toBe('2026-06-26 12:00:00');
});

it('preserves first seen and refreshes last seen on reimport', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    Carbon::setTestNow('2026-06-26 13:30:00');
    app(ImportNHLPlayer::class)->import('8478402');

    $identity = PlayerExternalIdentity::first();

    expect($identity->first_seen_at->toDateTimeString())->toBe('2026-06-26 12:00:00');
    expect($identity->last_seen_at->toDateTimeString())->toBe('2026-06-26 13:30:00');
});

it('normalizes accented names for identity matching', function () {
    $normalizer = app(PlayerIdentityNormalizer::class);

    expect($normalizer->normalizeName('Émile Bouchard'))->toBe('emile bouchard');
});

it('normalizes punctuation and spacing for identity matching', function () {
    $normalizer = app(PlayerIdentityNormalizer::class);

    expect($normalizer->normalizeName('  Jean-Luc   O\'Neill Jr.  '))->toBe('jean luc o neill jr');
});

it('normalizes empty names to null', function () {
    $normalizer = app(PlayerIdentityNormalizer::class);

    expect($normalizer->normalizeName('   '))->toBeNull();
});

it('reports status counts by provider', function () {
    PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => '1',
        'match_status' => PlayerExternalIdentity::STATUS_MATCHED,
    ]);
    PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_NHL,
        'provider_player_id' => '2',
        'match_status' => PlayerExternalIdentity::STATUS_UNMATCHED,
    ]);

    $counts = app(PlayerIdentityResolver::class)->statusCountsByProvider(PlayerExternalIdentity::PROVIDER_NHL);

    expect($counts[PlayerExternalIdentity::PROVIDER_NHL][PlayerExternalIdentity::STATUS_MATCHED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_NHL][PlayerExternalIdentity::STATUS_UNMATCHED])->toBe(1);
});

it('allows unmatched identity reasons to be persisted', function () {
    $identity = PlayerExternalIdentity::create([
        'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
        'provider_player_id' => 'fantrax-1',
    ]);

    app(PlayerIdentityResolver::class)->applyMatchResult(
        $identity,
        PlayerIdentityMatchResult::unmatched(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER),
    );

    expect($identity->refresh()->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_NO_CANONICAL_PLAYER);
});

it('throws when an nhl identity payload misses the provider player id', function () {
    app(PlayerIdentityResolver::class)->upsertNhlIdentity([
        'firstName' => ['default' => 'Missing'],
        'lastName' => ['default' => 'Identifier'],
    ]);
})->throws(InvalidArgumentException::class);

it('keeps the prospect flag when importing a prospect', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402', true);

    expect((bool)Player::first()->is_prospect)->toBeTrue();
});

it('updates current league from prospect stats', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)([
        'seasonTotals' => [
            [
                'teamName' => ['default' => 'Marlies'],
                'season' => 20252026,
                'gameTypeId' => 2,
                'sequence' => 1,
                'leagueAbbrev' => 'AHL',
                'gamesPlayed' => 10,
                'avgToi' => '18:00',
            ],
        ],
    ]));

    app(ImportNHLPlayer::class)->import('8478402', true);

    expect(Player::first()->current_league_abbrev)->toBe('AHL');
});

it('service playerExists returns true after import', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    app(ImportNHLPlayer::class)->import('8478402');

    expect(ImportNHLPlayer::playerExists('8478402'))->toBeTrue();
});

it('legacy import wrapper delegates to the identity workflow', function () {
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    (new LegacyImportNHLPlayer())->import('8478402');

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::first()->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
});

it('queued nhl player job uses the identity workflow', function () {
    Event::fake();
    ($this->fakeNhlLanding)(($this->nhlPayload)());

    (new ImportNHLPlayerJob('8478402'))->handle();

    expect(Player::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::query()->count())->toBe(1);
});
