<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildNhlPregameContextDayBatchJob;
use App\Jobs\BuildNhlPregameContextDayJob;
use App\Jobs\BuildNhlPregameContextGameJob;
use App\Jobs\CalculateNhlPregameContextLeagueMetricsJob;
use App\Jobs\CalculateNhlPregameContextRunDayLeagueAveragesJob;
use App\Jobs\CalculateNhlPregameLeagueBatchJob;
use App\Jobs\CompareNhlPregameContextRunDayJob;
use App\Jobs\LoadNhlPregameContextRunJob;
use App\Jobs\PrepareNhlPregameContextRunJob;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Best-effort, pilot-only execution telemetry; never changes queue or prediction authority. */
class NhlPregameWorkerActivity
{
    private const JOBS = [
        PrepareNhlPregameContextRunJob::class => 'Preparing game selection',
        LoadNhlPregameContextRunJob::class => 'Coordinating work',
        BuildNhlPregameContextGameJob::class => 'Building game context',
        BuildNhlPregameContextDayJob::class => 'Building context',
        BuildNhlPregameContextDayBatchJob::class => 'Building context',
        CalculateNhlPregameLeagueBatchJob::class => 'Calculating league averages',
        CalculateNhlPregameContextRunDayLeagueAveragesJob::class => 'Calculating league averages',
        CalculateNhlPregameContextLeagueMetricsJob::class => 'Attaching comparisons',
        CompareNhlPregameContextRunDayJob::class => 'Attaching comparisons',
    ];

    /** @var array<int,array{key:string,member:string}> Current queue-job objects in this container scope. */
    private array $active = [];

    /** Observe queue execution before handle/middleware; command objects are never instantiated. */
    public function started(JobProcessing $event): void
    {
        if ($event->connectionName === 'sync' || isset($this->active[spl_object_id($event->job)])) {
            return;
        }
        try {
            $payload = $event->job->payload();
            $name = $payload['data']['commandName'] ?? null;
            $command = $payload['data']['command'] ?? null;
            if (! is_string($name) || ! isset(self::JOBS[$name]) || ! is_string($command) || strlen($command) > 262144) {
                return;
            }
            // Disallow all classes, including the queued job: no wakeup, model restoration or arbitrary code.
            $decoded = @unserialize($command, ['allowed_classes' => false, 'max_depth' => 32]);
            if (! $decoded instanceof \__PHP_Incomplete_Class) {
                return;
            }
            $properties = (array) $decoded;
            if (($properties['__PHP_Incomplete_Class_Name'] ?? null) !== $name
                || ! is_int($properties['runId'] ?? null) || $properties['runId'] <= 0) {
                return;
            }
            $pilot = app(NhlSatPregameWorkspace::class)->pilot();
            if ($pilot === null) {
                return;
            }
            $run = NhlPregameContextRun::query()->where('model_run_id', $pilot->id)
                ->whereKey($properties['runId'])->first(['id']);
            if ($run === null) {
                return;
            }
            $dayId = is_int($properties['runDayId'] ?? null) ? $properties['runDayId'] : null;
            $gameId = is_int($properties['gameId'] ?? null) ? $properties['gameId'] : null;
            $date = $dayId === null ? null : DB::table('nhl_pregame_context_run_days')->where('run_id', $run->id)
                ->where('id', $dayId)->value('game_date');
            if ($date === null && $gameId !== null) {
                $date = DB::table('nhl_pregame_context_run_games')->where('run_id', $run->id)
                    ->where('nhl_game_id', $gameId)->value('game_date');
            }
            if ($date === null && is_string($properties['gameDate'] ?? null)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $properties['gameDate']) === 1) {
                $date = $properties['gameDate'];
            }
            $identity = $this->identity();
            $timeout = is_numeric($payload['timeout'] ?? null) ? max(1, min(3600, (int) $payload['timeout'])) : 300;
            $record = ['job_id' => (string) ($payload['uuid'] ?? $event->job->getJobId() ?? 'unknown'),
                'run_id' => (int) $run->id, 'task' => self::JOBS[$name], 'job' => class_basename($name),
                'game_id' => $gameId, 'game_date' => $date, 'server' => $identity['server'], 'pid' => $identity['pid'],
                'connection' => $event->connectionName, 'queue' => (string) $event->job->getQueue(),
                'attempt' => $event->job->attempts(), 'started_at' => now()->toIso8601String(),
                'deadline_at' => now()->addSeconds($timeout + 30)->toIso8601String()];
            $member = json_encode($record, JSON_THROW_ON_ERROR);
            $key = $this->key((int) $pilot->id);
            // Store cleanup ownership before writing, so a partial Redis failure can still be cleaned up.
            $this->active[spl_object_id($event->job)] = compact('key', 'member');
            $redis = $this->redis();
            $redis->zadd($key, now()->timestamp + $timeout + 30, $member);
            $redis->expire($key, 7200);
        } catch (Throwable) {
            // Observability must never fail/release/retry the business job or leak serialized inputs.
        }
    }

    /** Remove precisely this execution on success, release, exception or final failure. */
    public function finished(JobProcessed|JobExceptionOccurred|JobFailed $event): void
    {
        $id = spl_object_id($event->job);
        $record = $this->active[$id] ?? null;
        unset($this->active[$id]);
        if ($record === null) {
            return;
        }
        try {
            $this->redis()->zrem($record['key'], $record['member']);
        } catch (Throwable) {
            // Deadline pruning remains the backstop when a worker dies or Redis is unavailable.
        }
    }

    /** Read bounded shared execution evidence without consulting Horizon's private HTTP API. */
    public function snapshot(NhlModelRun $model): array
    {
        $empty = ['available' => false, 'workers' => [], 'truncated' => false, 'observed_at' => now()->toIso8601String()];
        if (! NhlSatPregameWorkspace::isPilot($model)) {
            return $empty;
        }
        try {
            $redis = $this->redis();
            $key = $this->key((int) $model->id);
            $redis->zremrangebyscore($key, '-inf', now()->timestamp - 120);
            $members = $redis->zrange($key, 0, 200);
            $workers = [];
            foreach (array_slice($members, 0, 200) as $member) {
                try {
                    $record = json_decode($member, true, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }
                if (! is_array($record) || ! is_int($record['run_id'] ?? null) || ! is_string($record['deadline_at'] ?? null)) {
                    continue;
                }
                $record['state'] = \Carbon\CarbonImmutable::parse($record['deadline_at'])->isPast() ? 'unconfirmed' : 'running';
                $workers[] = $record;
            }

            return ['available' => true, 'workers' => $workers, 'truncated' => count($members) > 200,
                'observed_at' => now()->toIso8601String()];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** A shared queue Redis connection makes activity visible across application and worker hosts. */
    protected function redis(): mixed
    {
        return Redis::connection(config('queue.connections.redis.connection', 'default'));
    }

    /** Narrow deterministic seam; record only hostname and PID, never command lines or environment. */
    protected function identity(): array
    {
        return ['server' => gethostname() ?: 'unknown', 'pid' => getmypid() ?: 0];
    }

    /** Keep operational telemetry separate from queue reservation keys and pregame data. */
    private function key(int $modelId): string
    {
        return 'nhl-pregame-worker-activity:model:'.$modelId;
    }
}
