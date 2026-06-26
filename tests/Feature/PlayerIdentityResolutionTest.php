<?php

declare(strict_types=1);

use App\Classes\ImportNHLPlayer as LegacyImportNHLPlayer;
use App\DTO\PlayerIdentityMatchResult;
use App\Jobs\ImportNHLPlayerJob;
use App\Models\Contract;
use App\Models\ContractSeason;
use App\Models\FantraxPlayer;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Services\ImportCapWagesPlayer;
use App\Services\ImportFantraxPlayer;
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

    $this->makePlayer = static function (array $overrides = []): Player {
        return Player::create(array_merge([
            'nhl_id' => null,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'full_name' => 'Test Player',
            'dob' => '1990-01-01',
            'position' => 'C',
            'team_abbrev' => 'ANA',
            'current_league_abbrev' => 'NHL',
        ], $overrides));
    };

    $this->fantraxEntry = static function (array $overrides = []): array {
        return array_merge([
            'fantraxId' => 'fantrax-1',
            'name' => 'Player, Test',
            'team' => 'ANA',
            'position' => 'C',
            'birthDate' => '1990-01-01',
        ], $overrides);
    };

    $this->capWagesPayload = static function (array $overrides = []): array {
        return array_replace_recursive([
            'nhlId' => 123456,
            'name' => 'Test Player',
            'birthDate' => '1990-01-01',
            'position' => 'C',
            'team' => 'ANA',
            'contracts' => [
                [
                    'signingDate' => '2026-07-01',
                    'contractType' => 'Standard',
                    'contractLength' => '2 years',
                    'contractValue' => 2000000,
                    'expiryStatus' => 'UFA',
                    'signingTeam' => 'ANA',
                    'signedBy' => 'Club',
                    'seasons' => [
                        [
                            'season' => '2026-27',
                            'clause' => null,
                            'capHit' => 1000000,
                            'aav' => 1000000,
                            'performanceBonuses' => 0,
                            'signingBonuses' => 0,
                            'baseSalary' => 1000000,
                            'totalSalary' => 1000000,
                            'minorsSalary' => 100000,
                        ],
                    ],
                ],
            ],
        ], $overrides);
    };

    $this->fakeCapWagesPlayer = static function (string $slug, array $payload): void {
        Http::fake([
            "https://capwages.com/api/gateway/v1/players/{$slug}" => Http::response([
                'data' => $payload,
            ]),
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
})->throws(\InvalidArgumentException::class);

it('rejects matched identity results without a canonical player reference', function () {
    new PlayerIdentityMatchResult(PlayerExternalIdentity::STATUS_MATCHED);
})->throws(\InvalidArgumentException::class);

it('rejects undocumented identity match statuses', function () {
    new PlayerIdentityMatchResult('review-needed');
})->throws(\InvalidArgumentException::class);

it('rejects identity match confidence outside the percentage range', function () {
    PlayerIdentityMatchResult::matched(123, 101);
})->throws(\InvalidArgumentException::class);

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

it('fantrax import upserts an external identity', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)());

    $identity = PlayerExternalIdentity::first();

    expect($identity->provider)->toBe(PlayerExternalIdentity::PROVIDER_FANTRAX);
    expect($identity->provider_player_id)->toBe('fantrax-1');
    expect($identity->raw_payload['name'])->toBe('Player, Test');
    expect(FantraxPlayer::query()->count())->toBe(1);
});

it('fantrax known provider id links to an existing canonical player', function () {
    $player = ($this->makePlayer)();
    FantraxPlayer::create([
        'fantrax_id' => 'fantrax-1',
        'player_id' => $player->id,
        'name' => 'Player, Test',
    ]);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['birthDate' => null]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->player_id)->toBe($player->id);
    expect(FantraxPlayer::first()->player_id)->toBe($player->id);
});

it('fantrax exact normalized name and birthdate auto-links', function () {
    $player = ($this->makePlayer)();

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)());

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->match_confidence)->toBe(95);
    expect($identity->player_id)->toBe($player->id);
});

it('fantrax ambiguous names become candidates', function () {
    ($this->makePlayer)(['dob' => '1990-01-01']);
    ($this->makePlayer)(['dob' => '1991-01-01']);

    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['birthDate' => null]));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_CANDIDATE);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_MULTIPLE_CANDIDATES);
    expect($identity->player_id)->toBeNull();
});

it('fantrax insufficient name data remains unmatched with a reason', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['name' => '']));

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_UNMATCHED);
    expect($identity->unmatched_reason)->toBe(PlayerExternalIdentity::REASON_PROVIDER_PAYLOAD_MISSING_NAME);
    expect(FantraxPlayer::first()->player_id)->toBeNull();
});

it('fantrax import remains idempotent for identity and legacy player rows', function () {
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)());
    (new ImportFantraxPlayer())->syncOne(($this->fantraxEntry)(['team' => 'BOS']));

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(FantraxPlayer::query()->count())->toBe(1);
    expect(PlayerExternalIdentity::first()->team)->toBe('BOS');
});

it('capwages import upserts an external identity before contract writes', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)());

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->provider)->toBe(PlayerExternalIdentity::PROVIDER_CAPWAGES);
    expect($identity->provider_player_id)->toBe('123456');
    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_UNMATCHED);
    expect(Contract::query()->count())->toBe(0);
});

it('capwages unresolved identity does not write contracts', function () {
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)());

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::first()->player_id)->toBeNull();
    expect(Contract::query()->count())->toBe(0);
    expect(ContractSeason::query()->count())->toBe(0);
});

it('capwages matched identity applies contract data to the linked canonical player', function () {
    $player = ($this->makePlayer)(['nhl_id' => 123456]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)());

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    $identity = PlayerExternalIdentity::first();

    expect($identity->match_status)->toBe(PlayerExternalIdentity::STATUS_MATCHED);
    expect($identity->player_id)->toBe($player->id);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('capwages import remains idempotent for identities contracts and seasons', function () {
    ($this->makePlayer)(['nhl_id' => 123456]);
    ($this->fakeCapWagesPlayer)('test-player', ($this->capWagesPayload)());

    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);
    (new ImportCapWagesPlayer())->syncBySlug('test-player', false);

    expect(PlayerExternalIdentity::query()->count())->toBe(1);
    expect(Contract::query()->count())->toBe(1);
    expect(ContractSeason::query()->count())->toBe(1);
});

it('provider audit counts include every documented identity status', function () {
    foreach ([
        PlayerExternalIdentity::STATUS_MATCHED,
        PlayerExternalIdentity::STATUS_CANDIDATE,
        PlayerExternalIdentity::STATUS_UNMATCHED,
        PlayerExternalIdentity::STATUS_IGNORED,
        PlayerExternalIdentity::STATUS_CONFLICT,
    ] as $status) {
        PlayerExternalIdentity::create([
            'provider' => PlayerExternalIdentity::PROVIDER_FANTRAX,
            'provider_player_id' => "fantrax-{$status}",
            'match_status' => $status,
            'player_id' => $status === PlayerExternalIdentity::STATUS_MATCHED
                ? ($this->makePlayer)(['nhl_id' => 900001])->id
                : null,
        ]);
    }

    $counts = app(PlayerIdentityResolver::class)->statusCountsByProvider();

    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_MATCHED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_CANDIDATE])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_UNMATCHED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_IGNORED])->toBe(1);
    expect($counts[PlayerExternalIdentity::PROVIDER_FANTRAX][PlayerExternalIdentity::STATUS_CONFLICT])->toBe(1);
});
