<?php

declare(strict_types=1);

use App\Events\PlayersAvailable;
use App\Jobs\ImportFantraxPlayerJob;
use App\Jobs\ImportNHLPlayerJob;
use App\Models\FantraxPlayer;
use App\Models\Player;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('broadcasts when the first nhl player is imported', function () {
    Event::fake([PlayersAvailable::class]);

    Http::fake([
        'https://api-web.nhle.com/v1/player/123/landing' => Http::response([
            'playerId' => '123',
            'currentTeamId' => 1,
            'currentTeamAbbrev' => 'ANA',
            'firstName' => ['default' => 'Test'],
            'lastName' => ['default' => 'Player'],
            'birthDate' => '1990-01-01',
            'birthCountry' => 'USA',
            'position' => 'C',
            'headshot' => null,
            'heroImage' => null,
            'seasonTotals' => [
                [
                    'teamName' => ['default' => 'Anaheim Ducks'],
                    'season' => '20242025',
                    'gameTypeId' => 2,
                    'sequence' => 1,
                    'leagueAbbrev' => 'NHL',
                    'gamesPlayed' => 1,
                    'goals' => 1,
                    'assists' => 2,
                    'points' => 3,
                    'gameWinningGoals' => 0,
                    'powerPlayGoals' => 0,
                    'powerPlayPoints' => 0,
                    'shorthandedGoals' => 0,
                    'otGoals' => 0,
                    'pim' => 0,
                    'plusMinus' => 0,
                    'shots' => 1,
                    'shootingPctg' => 10,
                    'wins' => null,
                    'losses' => null,
                    'otLosses' => null,
                    'shutouts' => null,
                    'gaa' => null,
                    'savePctg' => null,
                    'saves' => null,
                    'shotsAgainst' => null,
                    'goalsAgainst' => null,
                    'avgToi' => '00:10',
                    'timeOnIce' => '00:10',
                ],
            ],
        ]),
    ]);

    expect(Player::count())->toBe(0);

    (new ImportNHLPlayerJob('123'))->handle();

    Event::assertDispatched(PlayersAvailable::class, function (PlayersAvailable $event) {
        return $event->source === 'nhl' && $event->count === Player::query()->count();
    });
});

it('broadcasts when the first fantrax player is imported', function () {
    Event::fake([PlayersAvailable::class]);

    $entry = [
        'fantraxId' => 'fid-1',
        'name' => 'Player, Test',
        'team' => 'ANA',
        'position' => 'C',
    ];

    expect(FantraxPlayer::count())->toBe(0);

    (new ImportFantraxPlayerJob($entry))->handle();

    Event::assertDispatched(PlayersAvailable::class, function (PlayersAvailable $event) {
        return $event->source === 'fantrax' && $event->count === FantraxPlayer::query()->count();
    });
});
