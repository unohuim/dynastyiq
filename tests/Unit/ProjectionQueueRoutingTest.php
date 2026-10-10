<?php

declare(strict_types=1);

use App\Jobs\BuildNhlPlayerProjectionsJob;
use App\Jobs\ImportFantraxPlayerJob;
use App\Jobs\ImportNhlAnticipatedLineupTeamJob;
use App\Jobs\RefreshNhlSpecialTeamsSplitsJob;
use App\Services\NhlPlayerProjectionBuilder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake();
});

it('routes each model coordinator child and continuation to projections', function (string $class) {
    // Exercise real constructors without executing any model work or database I/O.
    $reflection = new \ReflectionClass($class);
    $arguments = array_map(static function (\ReflectionParameter $parameter): mixed {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($parameter->allowsNull()) {
            return null;
        }
        $type = $parameter->getType();
        if ($type instanceof \ReflectionUnionType) {
            return ['skater_offense:1'];
        }

        return match ($type->getName()) {
            'int' => 1,
            'string' => 'test',
            'array' => [],
            default => throw new \LogicException('Add a fixture for '.$parameter->getName()),
        };
    }, $reflection->getConstructor()->getParameters());
    $job = $reflection->newInstanceArgs($arguments);

    expect($job->queue)->toBe('projections')
        ->and($job->connection)->toBeNull()
        ->and($job->afterCommit)->toBeTrue()
        ->and(unserialize(serialize($job))->queue)->toBe('projections');
    Bus::assertNothingDispatched();
})->with([
    App\Jobs\BackfillNhlExpectedGoalsJob::class,
    App\Jobs\BuildNhlGoalieChanceProfileForGoalieJob::class,
    App\Jobs\BuildNhlGoalieChanceProfilesJob::class,
    App\Jobs\BuildNhlGoalieProjectionForGoalieJob::class,
    App\Jobs\BuildNhlGoalieProjectionsJob::class,
    App\Jobs\BuildNhlGoalieWorkloadProjectionForGoalieJob::class,
    App\Jobs\BuildNhlGoalieWorkloadProjectionsJob::class,
    App\Jobs\BuildNhlOfficialSatProfileForOfficialJob::class,
    App\Jobs\BuildNhlOfficialSatProfilesJob::class,
    App\Jobs\BuildNhlPlayerProjectionForPlayerJob::class,
    App\Jobs\BuildNhlPlayerProjectionsJob::class,
    App\Jobs\BuildNhlPlayerToiProjectionForPlayerJob::class,
    App\Jobs\BuildNhlPlayerToiProjectionsJob::class,
    App\Jobs\BuildNhlPregameContextGameJob::class,
    App\Jobs\BuildNhlSatModelEntityProfileForEntityJob::class,
    App\Jobs\BuildNhlSatModelEntityProfilesJob::class,
    App\Jobs\BuildNhlSatModelEntityRateComparisonForEntityJob::class,
    App\Jobs\BuildNhlSatModelEntityRateComparisonsJob::class,
    App\Jobs\BuildNhlSatModelEntityRateProjectionForEntityJob::class,
    App\Jobs\BuildNhlSatModelEntityRateProjectionsJob::class,
    App\Jobs\BuildNhlSatModelEntityToiProjectionForEntityJob::class,
    App\Jobs\BuildNhlSatModelEntityToiProjectionsJob::class,
    App\Jobs\BuildNhlSkaterDefensiveChanceProfileForPlayerJob::class,
    App\Jobs\BuildNhlSkaterDefensiveChanceProfilesJob::class,
    App\Jobs\BuildNhlSkaterOffensiveChanceProfileForPlayerJob::class,
    App\Jobs\BuildNhlSkaterOffensiveChanceProfilesJob::class,
    App\Jobs\BuildNhlStaffSatProfileForStaffJob::class,
    App\Jobs\BuildNhlStaffSatProfilesJob::class,
    App\Jobs\EvaluateNhlNextGameJob::class,
    App\Jobs\EvaluateNhlSatEngineGameJob::class,
    App\Jobs\DispatchNhlSatEngineGamesJob::class,
    App\Jobs\LoadNhlPregameContextRunJob::class,
    App\Jobs\LoadNhlSatModelProfileBatchJob::class,
    App\Jobs\PrepareNhlPregameContextRunJob::class,
    App\Jobs\RankNhlSatEngineCandidatesJob::class,
]);

it('queues a projection batch and its children on projections', function () {
    $builder = Mockery::mock(NhlPlayerProjectionBuilder::class);
    $builder->shouldReceive('prepareBuild')->once()
        ->with('20242025', '20252026', 'test')
        ->andReturn([
            'projection_version' => 'test',
            'source_season_id' => '20242025',
            'target_season_id' => '20252026',
            'goal_model_id' => 1,
            'sog_model_id' => 2,
            'player_ids' => [10, 20],
        ]);

    (new BuildNhlPlayerProjectionsJob('20242025', '20252026', 'test'))->handle($builder);

    Bus::assertBatched(fn (PendingBatch $batch): bool =>
        $batch->options['queue'] === 'projections'
        && $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job): bool => $job->queue === 'projections'));
});

it('keeps ordinary jobs and provider jobs out of projections', function () {
    expect((new RefreshNhlSpecialTeamsSplitsJob(1))->queue)->toBeNull()
        ->and((new ImportFantraxPlayerJob([]))->queue)->toBe('fantrax')
        ->and((new ImportNhlAnticipatedLineupTeamJob(1, 'TOR', 10))->queue)->toBe('lineups');
});

it('allocates thirty seven fixed workers across four isolated queues', function () {
    $allocation = ['projections' => 25, 'default' => 5, 'fantrax' => 5, 'lineups' => 2];

    foreach ($allocation as $queue => $workers) {
        $supervisor = config('horizon.defaults.supervisor-'.$queue);
        expect($supervisor['queue'])->toBe([$queue])
            ->and($supervisor['connection'])->toBe('redis')
            ->and($supervisor['minProcesses'])->toBe($workers)
            ->and($supervisor['maxProcesses'])->toBe($workers)
            ->and(config('horizon.environments.*'))->toHaveKey('supervisor-'.$queue);
    }

    expect(array_sum(array_column(config('horizon.defaults'), 'maxProcesses')))->toBe(37);
});

it('keeps heavy job timeouts below their reservation expiry', function () {
    expect(config('horizon.defaults.supervisor-projections.timeout'))->toBeGreaterThan(3600)
        ->toBeLessThan(config('queue.connections.redis.retry_after'));
});

