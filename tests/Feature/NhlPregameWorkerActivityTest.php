<?php

declare(strict_types=1);

use App\Jobs\BuildNhlPregameContextDayBatchJob;
use App\Jobs\BuildNhlPregameContextGameJob;
use App\Jobs\CalculateNhlPregameContextLeagueMetricsJob;
use App\Jobs\CalculateNhlPregameLeagueBatchJob;
use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\Role;
use App\Models\User;
use App\Services\NhlPregameWorkerActivity;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** A serialized canary must stay inert when reading queue metadata. */
class NhlPregameActivitySerializationCanary
{
    public function __wakeup(): void
    {
        throw new RuntimeException('Queue metadata must not execute serialized objects.');
    }
}

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-26 16:00:00 UTC'));
    $this->withoutVite();
    Bus::fake();
    Http::preventStrayRequests();
    config(['cache.default' => 'array', 'nhl-pregame.workspace_model_name' => 'Sep2026']);
    $this->mock(\App\Services\PlatformState::class)->shouldReceive('seeded')->andReturn(true);
    $this->pilot = NhlModelRun::create(['run_key' => 'activity-pilot', 'name' => 'Sep2026', 'model_family' => 'sat',
        'workflow_stage' => 'training', 'status' => 'complete', 'train_season_ids' => ['20242025'], 'target_season_id' => '20252026']);
    $this->run = NhlPregameContextRun::create(['model_run_id' => $this->pilot->id, 'action' => 'backfill',
        'status' => 'running', 'season_ids' => ['20252026']]);
    $this->redis = new class
    {
        public array $sets = [];
        public array $expiries = [];
        public bool $failWrite = false;
        public bool $failRead = false;
        public bool $failDelete = false;

        public function zadd(string $key, int $score, string $member): void
        {
            if ($this->failWrite) {
                throw new RuntimeException('Redis unavailable');
            }
            $this->sets[$key][$member] = $score;
        }

        public function expire(string $key, int $seconds): void
        {
            $this->expiries[$key] = $seconds;
        }

        public function zrem(string $key, string $member): void
        {
            if ($this->failDelete) {
                throw new RuntimeException('Redis unavailable');
            }
            unset($this->sets[$key][$member]);
        }

        public function zremrangebyscore(string $key, string $minimum, int $maximum): void
        {
            if ($this->failRead) {
                throw new RuntimeException('Redis unavailable');
            }
            foreach ($this->sets[$key] ?? [] as $member => $score) {
                if ($score <= $maximum) {
                    unset($this->sets[$key][$member]);
                }
            }
        }

        public function zrange(string $key, int $from, int $through): array
        {
            if ($this->failRead) {
                throw new RuntimeException('Redis unavailable');
            }
            $set = $this->sets[$key] ?? [];
            asort($set);

            return array_slice(array_keys($set), $from, $through - $from + 1);
        }
    };
    $this->tracker = Mockery::mock(NhlPregameWorkerActivity::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $this->tracker->shouldReceive('redis')->byDefault()->andReturn($this->redis);
    $this->tracker->shouldReceive('identity')->byDefault()->andReturn(['server' => 'queue-one', 'pid' => 42]);
    app()->instance(NhlPregameWorkerActivity::class, $this->tracker);
    $this->makeJob = function (?object $command = null, array $extra = [], int $attempt = 1): Job {
        $command ??= new PrepareNhlPregameContextRunJob($this->run->id);
        $payload = array_replace_recursive(['uuid' => 'fixture-job', 'timeout' => 60,
            'data' => ['commandName' => $command::class, 'command' => serialize($command)]], $extra);
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getQueue')->andReturn('projections');
        $job->shouldReceive('getJobId')->andReturn('fixture-reservation');
        $job->shouldReceive('attempts')->andReturn($attempt);

        return $job;
    };
    $this->start = function (?Job $job = null): Job {
        $job ??= ($this->makeJob)();
        $this->tracker->started(new JobProcessing('redis', $job));

        return $job;
    };
    $this->snapshot = fn (): array => $this->tracker->snapshot($this->pilot);
    $this->day = DB::table('nhl_pregame_context_run_days')->insertGetId([
        'run_id' => $this->run->id, 'game_date' => '2025-10-10',
    ]);
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('records an actual preparation execution with worker identity and queue', function (): void {
    ($this->start)();
    $worker = ($this->snapshot)()['workers'][0];
    expect($worker['task'])->toBe('Preparing game selection')->and($worker['server'])->toBe('queue-one')
        ->and($worker['pid'])->toBe(42)->and($worker['queue'])->toBe('projections')
        ->and($worker['run_id'])->toBe($this->run->id)->and($worker['state'])->toBe('running');
});

it('associates league executions with their owning date', function (): void {
    ($this->start)(($this->makeJob)(new CalculateNhlPregameLeagueBatchJob($this->run->id, $this->day)));
    expect(($this->snapshot)()['workers'][0]['game_date'])->toBe('2025-10-10');
});

it('recognizes context continuation jobs', function (): void {
    ($this->start)(($this->makeJob)(new BuildNhlPregameContextDayBatchJob($this->run->id, $this->day)));
    expect(($this->snapshot)()['workers'][0]['task'])->toBe('Building context');
});

it('reports individual game identifiers when present', function (): void {
    DB::table('nhl_pregame_context_run_games')->insert(['run_id' => $this->run->id, 'nhl_game_id' => 2025020001,
        'game_date' => '2025-10-10', 'sequence' => 1]);
    ($this->start)(($this->makeJob)(new BuildNhlPregameContextGameJob($this->run->id, 2025020001)));
    expect(($this->snapshot)()['workers'][0]['game_id'])->toBe(2025020001)
        ->and(($this->snapshot)()['workers'][0]['game_date'])->toBe('2025-10-10');
});

it('reads legacy comparison date metadata without instantiating the command', function (): void {
    ($this->start)(($this->makeJob)(new CalculateNhlPregameContextLeagueMetricsJob($this->run->id, '2025-10-10')));
    expect(($this->snapshot)()['workers'][0]['task'])->toBe('Attaching comparisons');
});

it('ignores unrelated queue jobs', function (): void {
    ($this->start)(($this->makeJob)(extra: ['data' => ['commandName' => 'App\\Jobs\\SomethingElse']]));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('does not label synchronous execution as a queue worker', function (): void {
    $this->tracker->started(new JobProcessing('sync', ($this->makeJob)()));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('ignores production model executions', function (): void {
    $other = $this->pilot->replicate();
    $other->run_key = 'production';
    $other->name = 'sat_v2';
    $other->save();
    $this->run->update(['model_run_id' => $other->id]);
    ($this->start)();
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('ignores missing run identities', function (): void {
    ($this->start)(($this->makeJob)(new PrepareNhlPregameContextRunJob(999999)));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('does not guess a pilot when its selection is ambiguous', function (): void {
    $other = $this->pilot->replicate();
    $other->run_key = 'another-pilot';
    $other->save();
    ($this->start)();
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('ignores malformed serialized metadata', function (): void {
    ($this->start)(($this->makeJob)(extra: ['data' => ['command' => 'broken']]));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('rejects command metadata whose serialized class disagrees with its declared class', function (): void {
    ($this->start)(($this->makeJob)(extra: ['data' => ['command' => serialize(new stdClass())]]));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('bounds serialized metadata before decoding it', function (): void {
    ($this->start)(($this->makeJob)(extra: ['data' => ['command' => str_repeat('x', 262145)]]));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('never executes wakeup hooks from nested serialized objects', function (): void {
    $class = PrepareNhlPregameContextRunJob::class;
    $command = 'O:'.strlen($class).':"'.$class.'":2:{s:5:"runId";i:'.$this->run->id.';s:5:"extra";'
        .serialize(new NhlPregameActivitySerializationCanary()).'}';
    ($this->start)(($this->makeJob)(extra: ['data' => ['command' => $command]]));
    expect(($this->snapshot)()['workers'])->toHaveCount(1);
});

it('requires an integer run identity rather than casting arbitrary properties', function (): void {
    $class = PrepareNhlPregameContextRunJob::class;
    $command = 'O:'.strlen($class).':"'.$class.'":1:{s:5:"runId";s:1:"1";}';
    ($this->start)(($this->makeJob)(extra: ['data' => ['command' => $command]]));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('removes the execution after successful processing', function (): void {
    $job = ($this->start)();
    $this->tracker->finished(new JobProcessed('redis', $job));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('removes the execution when processing throws', function (): void {
    $job = ($this->start)();
    $this->tracker->finished(new JobExceptionOccurred('redis', $job, new RuntimeException('Failure')));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('removes the execution on terminal job failure', function (): void {
    $job = ($this->start)();
    $this->tracker->finished(new JobFailed('redis', $job, new RuntimeException('Failure')));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('makes repeated completion notifications harmless', function (): void {
    $job = ($this->start)();
    $this->tracker->finished(new JobProcessed('redis', $job));
    $this->tracker->finished(new JobProcessed('redis', $job));
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('does not double count duplicate start notifications for the same job object', function (): void {
    $job = ($this->start)();
    ($this->start)($job);
    expect(($this->snapshot)()['workers'])->toHaveCount(1);
});

it('keeps a newer attempt visible when an older attempt completes', function (): void {
    $old = ($this->start)();
    $new = ($this->start)(($this->makeJob)(attempt: 2));
    $this->tracker->finished(new JobProcessed('redis', $old));
    expect(($this->snapshot)()['workers'])->toHaveCount(1)
        ->and(($this->snapshot)()['workers'][0]['attempt'])->toBe(2);
});

it('keeps different worker processes distinct even for the same queue job identity', function (): void {
    $old = ($this->start)();
    $this->tracker->shouldReceive('identity')->andReturn(['server' => 'queue-two', 'pid' => 81]);
    ($this->start)(($this->makeJob)());
    $this->tracker->finished(new JobProcessed('redis', $old));
    expect(($this->snapshot)()['workers'][0]['server'])->toBe('queue-two');
});

it('marks a lost execution unconfirmed after its deadline', function (): void {
    ($this->start)();
    $this->travel(91)->seconds();
    expect(($this->snapshot)()['workers'][0]['state'])->toBe('unconfirmed');
});

it('prunes dead execution records after the bounded uncertainty period', function (): void {
    ($this->start)();
    $this->travel(211)->seconds();
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('bounds the worker payload and exposes truncation', function (): void {
    ($this->start)();
    $record = ($this->snapshot)()['workers'][0];
    $key = array_key_first($this->redis->sets);
    for ($index = 0; $index < 205; $index++) {
        $this->redis->zadd($key, now()->timestamp + 90, json_encode([...$record, 'job_id' => 'extra-'.$index]));
    }
    expect(($this->snapshot)()['workers'])->toHaveCount(200)->and(($this->snapshot)()['truncated'])->toBeTrue();
});

it('never fails the business job when telemetry writes fail', function (): void {
    $this->redis->failWrite = true;
    ($this->start)();
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('reports unavailable telemetry without failing a workspace read', function (): void {
    $this->redis->failRead = true;
    expect(($this->snapshot)()['available'])->toBeFalse();
});

it('recovers from failed completion cleanup through expiry', function (): void {
    $job = ($this->start)();
    $this->redis->failDelete = true;
    $this->tracker->finished(new JobProcessed('redis', $job));
    $this->travel(211)->seconds();
    expect(($this->snapshot)()['workers'])->toBe([]);
});

it('omits serialized inputs and claim secrets from activity records', function (): void {
    $job = ($this->start)(($this->makeJob)(new BuildNhlPregameContextDayBatchJob($this->run->id, $this->day, 'private-claim-token')));
    $encoded = json_encode(($this->snapshot)());
    expect($encoded)->not->toContain('private-claim-token')->not->toContain('commandName')->not->toContain('serialized');
});

it('observes real provider lifecycle registration and serves the same execution through admin HTTP', function (): void {
    $admin = User::factory()->create();
    $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'level' => 99]);
    $admin->roles()->attach($role->id, ['organization_id' => null]);
    $job = ($this->makeJob)();
    Event::dispatch(new JobProcessing('redis', $job));
    expect(($this->snapshot)()['workers'])->toHaveCount(1);
    $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Admin/SatModels/Index',
        'X-Inertia-Partial-Data' => 'pregame'])->get('/admin/nhl-sat-models')->assertOk()
        ->assertJsonPath('props.pregame.worker_activity.workers.0.pid', 42)
        ->assertJsonPath('props.pregame.worker_activity.workers.0.task', 'Preparing game selection');
    Event::dispatch(new JobProcessed('redis', $job));
    expect(($this->snapshot)()['workers'])->toBe([]);
    Bus::assertNothingDispatched();
});
