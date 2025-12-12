<?php

declare(strict_types=1);

use App\Events\ImportStreamEvent;
use App\Jobs\ImportNHLPlayerJob;
use App\Jobs\ImportPlayersJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2025-10-01 12:00:00');
    config(['cache.default' => 'array']);
});

function fakeNhlRosterPayload(): array
{
    return [
        'forwards' => [
            [
                'id' => '99',
                'firstName' => ['default' => 'Wayne'],
                'lastName' => ['default' => 'Gretzky'],
                'positionCode' => 'C',
            ],
        ],
        'defensemen' => [],
        'goalies' => [],
    ];
}

function fakeProspectsPayload(): array
{
    return [
        'forwards' => [
            [
                'id' => '99',
                'firstName' => ['default' => 'Wayne'],
                'lastName' => ['default' => 'Gretzky'],
                'positionCode' => 'C',
            ],
        ],
        'defensemen' => [],
        'goalies' => [],
    ];
}

it('deduplicates players that appear across seasons and prospects', function () {
    Event::fake();
    Bus::fake();

    $currentSeason = '20252026';
    $previousSeason = '20242025';

    Http::fake([
        'https://api-web.nhle.com/v1/roster/ANA/current' => Http::response(fakeNhlRosterPayload()),
        "https://api-web.nhle.com/v1/roster/ANA/{$previousSeason}" => Http::response(fakeNhlRosterPayload()),
        'https://api-web.nhle.com/v1/prospects/ANA' => Http::response(fakeProspectsPayload()),
    ]);

    (new ImportPlayersJob('ANA', 'run-1'))->handle();

    $events = Event::dispatched(ImportStreamEvent::class);

    $playerEvents = $events->filter(function (array $call) {
        /** @var ImportStreamEvent $event */
        $event = $call[0];
        return $event->message === 'Importing Wayne Gretzky - ANA, C';
    });

    expect($playerEvents)->toHaveCount(1);

    Bus::assertDispatched(ImportNHLPlayerJob::class, function (ImportNHLPlayerJob $job) {
        $ref = new ReflectionClass($job);
        $prop = $ref->getProperty('playerId');
        $prop->setAccessible(true);

        return $prop->getValue($job) === '99';
    });

    Bus::assertDispatchedTimes(ImportNHLPlayerJob::class, 1);
});

it('uses the cache guard to skip re-dispatching the same player payload', function () {
    Event::fake();
    Bus::fake();

    $payload = fakeNhlRosterPayload();

    $job = new class('ANA', 'run-dup') extends ImportPlayersJob {
        public function exposeDispatch(array $data): void
        {
            $this->dispatchGroupedPlayers($data);
        }
    };

    $job->exposeDispatch($payload);

    $cacheKey = 'nhl-import:run-dup:player:99';
    expect(Cache::has($cacheKey))->toBeTrue();

    $firstPass = Event::dispatched(ImportStreamEvent::class);
    expect($firstPass)->toHaveCount(1); // first sighting emits one stream event

    $job->exposeDispatch($payload);

    $secondPass = Event::dispatched(ImportStreamEvent::class);

    $playerEvents = $secondPass->filter(function (array $call) {
        /** @var ImportStreamEvent $event */
        $event = $call[0];
        return $event->message === 'Importing Wayne Gretzky - ANA, C';
    });

    expect($playerEvents)->toHaveCount(1);
    Bus::assertDispatchedTimes(ImportNHLPlayerJob::class, 1);
});

it('keeps import run caches isolated per importRunId', function () {
    Event::fake();
    Bus::fake();

    Http::fake([
        'https://api-web.nhle.com/v1/roster/ANA/current' => Http::response(fakeNhlRosterPayload()),
        'https://api-web.nhle.com/v1/roster/ANA/20242025' => Http::response(fakeNhlRosterPayload()),
        'https://api-web.nhle.com/v1/prospects/ANA' => Http::response(fakeProspectsPayload()),
    ]);

    (new ImportPlayersJob('ANA', 'run-A'))->handle();
    (new ImportPlayersJob('ANA', 'run-B'))->handle();

    $events = Event::dispatched(ImportStreamEvent::class);

    $playerEvents = $events->filter(function (array $call) {
        /** @var ImportStreamEvent $event */
        $event = $call[0];
        return $event->message === 'Importing Wayne Gretzky - ANA, C';
    });

    expect($playerEvents)->toHaveCount(2);
    Bus::assertDispatchedTimes(ImportNHLPlayerJob::class, 2);
});

it('dispatches a job per team when the import command is executed', function () {
    Event::fake();
    Bus::fake();

    $this->artisan('nhl:import', ['--players' => true])->assertOk();

    $dispatched = Bus::dispatched(ImportPlayersJob::class);
    expect($dispatched)->toHaveCount(33);

    $runIds = $dispatched->map(function ($call) {
        /** @var ImportPlayersJob $job */
        $job = is_array($call) ? $call[0] : $call;
        $ref = new ReflectionClass($job);
        $prop = $ref->getProperty('importRunId');
        $prop->setAccessible(true);

        return $prop->getValue($job);
    });

    expect($runIds)->each->toBeString();
    expect($runIds->unique()->count())->toBe($runIds->count());
});
