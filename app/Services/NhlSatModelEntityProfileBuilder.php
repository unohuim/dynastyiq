<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\BuildNhlSatModelEntityProfileForEntityJob;
use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlModelRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Builds entity-level SAT profile rows for a trained SAT model run.
 */
class NhlSatModelEntityProfileBuilder
{
    private const REGULAR_SEASON_GAME_TYPE = 2;
    private const PROFILE_TABLE = 'nhl_sat_model_entity_profile_buckets';
    private const TEST_PROFILE_TABLE = 'nhl_sat_model_entity_test_profile_buckets';
    private const PROFILE_STRENGTHS = [
        'all' => null,
        'ev' => 'EV',
        'pp' => 'PP',
        'pk' => 'PK',
    ];

    /**
     * Recover a bounded rotating page of abandoned profile reservations only.
     * Keep shared queue timeouts and the original exception-attempt budget intact.
     */
    public function recoverAbandonedJobs(): int
    {
        if (config('queue.default') !== 'redis') {
            return 0;
        }
        $redis = Redis::connection(config('queue.connections.redis.connection', 'default'));
        $key = 'queues:projections:reserved';
        $size = (int) $redis->zcard($key);
        if ($size === 0) {
            return 0;
        }
        $offset = (intdiv(now()->timestamp, 60) % (int) ceil($size / 200)) * 200;
        $recovered = 0;
        foreach ($redis->zrange($key, $offset, $offset + 199) as $raw) {
            if (strlen($raw) > 131072) {
                continue;
            }
            $payload = json_decode($raw, true);
            if (($payload['displayName'] ?? null) !== BuildNhlSatModelEntityProfileForEntityJob::class
                || ($payload['timeout'] ?? null) !== 300) {
                continue;
            }
            $score = $redis->zscore($key, $raw);
            if (! is_numeric($score) || (int) $score <= now()->timestamp + 120
                || now()->timestamp - ((int) $score - (int) config('queue.connections.redis.retry_after')) < 450) {
                continue;
            }
            try {
                $job = @unserialize($payload['data']['command'] ?? '', ['allowed_classes' => [BuildNhlSatModelEntityProfileForEntityJob::class]]);
                if (! $job instanceof BuildNhlSatModelEntityProfileForEntityJob || $job->predictionBuildId === null
                    || ! in_array($job->profileType, ['skater_offense', 'goalie_faced'], true)
                    || $job->receiptIndex < 0 || $job->receiptIndex >= 100 || $job->deliveryGeneration < 1
                    || $job->deliveryGeneration > 3 || $job->entityKeys === []
                    || count($job->entityKeys) > BuildNhlSatModelEntityProfileForEntityJob::MAX_ENTITIES) {
                    continue;
                }
                $next = DB::transaction(function () use ($job, $payload): ?array {
                    $run = NhlModelRun::query()->whereKey($job->modelRunId)->lock('for no key update skip locked')->first();
                    if ($run === null || ! $run->acceptsPredictionStage($job->predictionBuildId, 'profiles')) {
                        return null;
                    }
                    if ($job->batchId !== null) {
                        $batch = DB::table('job_batches')->where('id', $job->batchId)->first();
                        if ($batch === null || $batch->cancelled_at !== null || $batch->finished_at !== null) {
                            return null;
                        }
                    }
                    $receipt = $job->receiptKey();
                    $metrics = $run->metrics ?? [];
                    if (($metrics['profile_build']['completed_pages'][$receipt][$job->receiptIndex] ?? '0') !== '1') {
                        $current = (int) ($metrics['profile_build']['delivery_generations'][$receipt][$job->receiptIndex] ?? 1);
                        if ($current < $job->deliveryGeneration || $current > $job->deliveryGeneration + 1) {
                            return null;
                        }
                        $replacementGeneration = $job->deliveryGeneration + 1;
                        if ($current === $job->deliveryGeneration) {
                            $metrics['profile_build']['delivery_generations'][$receipt][$job->receiptIndex] = $replacementGeneration;
                            $metrics['profile_build']['reservation_recoveries'] = (int) ($metrics['profile_build']['reservation_recoveries'] ?? 0) + 1;
                            $run->forceFill(['metrics' => $metrics])->save();
                        }
                        $job->deliveryGeneration = $replacementGeneration;
                    }
                    $next = $payload;
                    $next['data']['command'] = serialize($job);

                    return $next;
                });
                if ($next === null) {
                    continue;
                }
                // Publish only after the DB fence commits. CAS makes repeated scans safe,
                // including a Redis failure after the fence was prepared in the prior scan.
                $lua = "local s=redis.call('ZSCORE',KEYS[1],ARGV[1]); if not s or tonumber(s)~=tonumber(ARGV[2]) then return 0 end; redis.call('ZREM',KEYS[1],ARGV[1]); redis.call('RPUSH',KEYS[2],ARGV[3]); redis.call('RPUSH',KEYS[3],1); return 1";
                $recovered += (int) $redis->eval($lua, 3, $key, 'queues:projections', 'queues:projections:notify',
                    $raw, $score, json_encode($next, JSON_THROW_ON_ERROR));
            } catch (\Throwable $exception) {
                // Leave an unchanged reservation retryable on the next scheduled scan.
                report(new RuntimeException(\App\Jobs\BuildNhlSatModelEntityProfilesJob::failureMessage($exception)));
            }
        }

        return $recovered;
    }

    /**
     * Identify in-place evaluation revisions used by a profile build.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public static function probabilitySources(NhlExpectedGoalsModel $satModel, ?NhlExpectedGoalsModel $sogModel): array
    {
        $describe = static fn (NhlExpectedGoalsModel $model): array => [
            'model_id' => (int) $model->id,
            'trained_at' => $model->trained_at?->toIso8601String(),
            'smoothing_policy' => data_get($model->feature_config, 'probability_smoothing.policy'),
            'bucket_signature' => data_get($model->feature_config, 'probability_smoothing.bucket_signature'),
        ];

        return ['sat' => $describe($satModel), 'sog' => $sogModel === null ? null : $describe($sogModel)];
    }

    /**
     * Reuse only fully committed snapshots from a failed, unchanged build.
     *
     * @param array<int, string> $profileTypes
     * @return array<string, mixed>|null
     */
    public static function reusableSnapshots(NhlModelRun $run, NhlExpectedGoalsModel $satModel, ?NhlExpectedGoalsModel $sogModel, array $profileTypes): ?array
    {
        $metrics = $run->metrics ?? [];
        $build = $metrics['profile_build'] ?? [];
        $queued = (int) ($metrics['season_snapshot_entities_queued'] ?? 0);
        $seasons = (new self())->snapshotSeasonIds($run);
        $builtSeasons = $build['snapshot_reuse']['season_ids'] ?? array_values(array_filter(array_column($build['partitions'] ?? [], 'season_id')));
        $builtSeasons = array_values(array_unique($builtSeasons));
        sort($builtSeasons);
        if (($build['status'] ?? null) !== 'failed' || empty($build['loading_complete'])
            || $queued <= 0 || $queued !== (int) ($metrics['season_snapshot_entities_completed'] ?? 0)
            || ($build['profile_types'] ?? []) !== $profileTypes || $builtSeasons !== $seasons
            || ($build['evaluation_sources'] ?? null) != self::probabilitySources($satModel, $sogModel)) {
            return null;
        }
        $reuse = [
            'from_build_id' => $build['id'],
            'since' => $build['snapshot_reuse']['since'] ?? $metrics['profiles_started_at'] ?? null,
            'evaluation_sources' => $build['evaluation_sources'],
            'profile_types' => $profileTypes, 'season_ids' => $seasons, 'entities' => $queued,
        ];

        return self::snapshotsUnchanged($run, $reuse) ? $reuse : null;
    }

    /**
     * Recheck persisted source changes before retaining snapshots at worker execution.
     *
     * @param array<string, mixed> $reuse
     */
    public static function snapshotsUnchanged(NhlModelRun $run, array $reuse): bool
    {
        if (empty($reuse['since']) || empty($reuse['season_ids']) || empty($reuse['profile_types'])) {
            return false;
        }
        $since = \Carbon\CarbonImmutable::parse($reuse['since'])->utc();
        $seasons = $reuse['season_ids'];
        if ($seasons !== (new self())->snapshotSeasonIds($run)) {
            return false;
        }
        $entities = DB::table(self::TEST_PROFILE_TABLE)->where('model_run_id', $run->id)
            ->whereIn('profile_type', $reuse['profile_types'])->whereIn('test_season_id', $seasons)
            ->select('test_season_id', 'profile_type', 'entity_key')->distinct();
        if (DB::query()->fromSub($entities, 'snapshot_entities')->count() !== (int) $reuse['entities']) {
            return false;
        }
        foreach (['nhl_games', 'nhl_shot_attempts_facts'] as $table) {
            if (DB::table($table)->whereIn('season_id', $seasons)->where('updated_at', '>=', $since)->exists()) {
                return false;
            }
        }
        foreach (['nhl_game_summaries', 'nhl_boxscores', 'nhl_player_game_strength_summaries', 'nhl_pbp_processing_states'] as $table) {
            if (DB::table($table . ' as source')->join('nhl_games as games', 'games.nhl_game_id', '=', 'source.nhl_game_id')
                ->whereIn('games.season_id', $seasons)->where('source.updated_at', '>=', $since)->exists()) {
                return false;
            }
        }
        $gameIds = DB::table('nhl_games')->whereIn('season_id', $seasons)->selectRaw('CAST(nhl_game_id AS TEXT)');
        if (DB::table('nhl_import_progress')->whereIn('game_id', $gameIds)->where('updated_at', '>=', $since)->exists()) {
            return false;
        }

        return ! DB::table('players')->where('updated_at', '>=', $since)->exists();
    }

    /**
     * Build selected entity profile buckets for one SAT model run.
     *
     * @param  array<int, string>  $profileTypes
     * @return array<string, int>
     */
    public function build(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel = null,
        array $profileTypes = ['skater_offense']
    ): array
    {
        $seasonIds = $this->seasonIds($run);

        if ($seasonIds === []) {
            throw new RuntimeException('This SAT model has no training seasons.');
        }

        $satBucketKeySql = $this->modelBucketKeySql($satModel, 'profile_facts');
        $sogBucketKeySql = $sogModel === null ? null : $this->modelBucketKeySql($sogModel, 'profile_facts');
        $counts = [];

        $profileTypes = $this->supportedProfileTypes($profileTypes);
        DB::table(self::PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->whereIn('profile_type', $profileTypes)
            ->delete();

        foreach ($profileTypes as $profileType) {
            $definition = $this->profileDefinitions()[$profileType];
            foreach (($profileType === 'skater_offense' ? self::PROFILE_STRENGTHS : ['all' => null]) as $projectionStrength => $factStrength) {
                $this->insertProfileRows(
                    run: $run,
                    satModel: $satModel,
                    sogModel: $sogModel,
                    profileType: $profileType,
                    definition: $definition,
                    seasonIds: $seasonIds,
                    satBucketKeySql: $satBucketKeySql,
                    sogBucketKeySql: $sogBucketKeySql,
                    projectionStrength: $projectionStrength,
                    factStrength: $factStrength
                );
            }

            $counts[$profileType] = DB::table(self::PROFILE_TABLE)
                ->where('model_run_id', $run->id)
                ->where('profile_type', $profileType)
                ->count();
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * List the small, fixed set of profile type/season partitions.
     *
     * @param  array<int, string>  $profileTypes
     * @return array<int, array{profile_type:string,season_id:?string}>
     */
    public function profilePartitions(NhlModelRun $run, array $profileTypes = ['skater_offense']): array
    {
        $partitions = [];
        foreach ([null, ...$this->snapshotSeasonIds($run)] as $seasonId) {
            foreach ($this->supportedProfileTypes($profileTypes) as $profileType) {
                $partitions[] = ['profile_type' => $profileType, 'season_id' => $seasonId];
            }
        }

        return $partitions;
    }

    /**
     * Clear prior outputs once, inside the owning build's initialization transaction.
     *
     * @param  array<int, string>  $profileTypes
     */
    public function clearProfileOutputs(NhlModelRun $run, array $profileTypes = ['skater_offense'], bool $preserveSnapshots = false): void
    {
        $profileTypes = $this->supportedProfileTypes($profileTypes);
        DB::table(self::PROFILE_TABLE)->where('model_run_id', $run->id)->whereIn('profile_type', $profileTypes)->delete();
        if (! $preserveSnapshots) {
            DB::table(self::TEST_PROFILE_TABLE)->where('model_run_id', $run->id)->whereIn('profile_type', $profileTypes)->delete();
        }
    }

    /**
     * Discover at most one page of entity identifiers; never materialize the full run.
     *
     * @return array<int, string>
     */
    public function profileEntityPage(NhlModelRun $run, string $profileType, ?string $seasonId, ?string $after): array
    {
        $this->supportedProfileTypes([$profileType]);
        $definition = $this->profileDefinitions()[$profileType];

        return $this->profileEntities($seasonId === null ? $this->seasonIds($run) : [$seasonId], $definition, $after, 100);
    }

    /**
     * Clear existing rows and list profile entities that should be queued.
     *
     * @param  array<int, string>  $profileTypes
     * @return array<int, array{profile_type:string,entity_key:string}>
     */
    public function prepareBuild(NhlModelRun $run, array $profileTypes = ['skater_offense']): array
    {
        $profileTypes = $this->supportedProfileTypes($profileTypes);
        DB::table(self::PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->whereIn('profile_type', $profileTypes)
            ->delete();

        $entities = [];

        foreach ($profileTypes as $profileType) {
            $definition = $this->profileDefinitions()[$profileType];
            foreach ($this->profileEntities($this->seasonIds($run), $definition) as $entityKey) {
                $entities[] = [
                    'profile_type' => $profileType,
                    'entity_key' => $entityKey,
                ];
            }
        }

        return $entities;
    }

    /**
     * Clear existing single-season snapshot rows and list entities that should be queued.
     *
     * @param  array<int, string>  $profileTypes
     * @return array<int, array{profile_type:string,entity_key:string,season_id:string}>
     */
    public function prepareSeasonSnapshotBuilds(NhlModelRun $run, array $profileTypes = ['skater_offense']): array
    {
        $profileTypes = $this->supportedProfileTypes($profileTypes);
        $seasonIds = $this->snapshotSeasonIds($run);

        if ($seasonIds === []) {
            return [];
        }

        DB::table(self::TEST_PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->whereIn('test_season_id', $seasonIds)
            ->whereIn('profile_type', $profileTypes)
            ->delete();

        $entities = [];

        foreach ($seasonIds as $seasonId) {
            foreach ($profileTypes as $profileType) {
                $definition = $this->profileDefinitions()[$profileType];
                foreach ($this->profileEntities([$seasonId], $definition) as $entityKey) {
                    $entities[] = [
                        'profile_type' => $profileType,
                        'entity_key' => $entityKey,
                        'season_id' => $seasonId,
                    ];
                }
            }
        }

        return $entities;
    }

    /**
     * Build one entity's profile rows.
     */
    public function buildEntity(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel,
        string $profileType,
        string $entityKey
    ): int {
        $seasonIds = $this->seasonIds($run);

        if ($seasonIds === []) {
            throw new RuntimeException('This SAT model has no training seasons.');
        }

        $definition = $this->profileDefinitions()[$profileType] ?? null;

        if ($definition === null) {
            throw new RuntimeException('Unknown SAT model profile type.');
        }

        foreach (($profileType === 'skater_offense' ? self::PROFILE_STRENGTHS : ['all' => null]) as $projectionStrength => $factStrength) {
            $this->insertProfileRows(
                run: $run,
                satModel: $satModel,
                sogModel: $sogModel,
                profileType: $profileType,
                definition: $definition,
                seasonIds: $seasonIds,
                satBucketKeySql: $this->modelBucketKeySql($satModel, 'profile_facts'),
                sogBucketKeySql: $sogModel === null ? null : $this->modelBucketKeySql($sogModel, 'profile_facts'),
                entityKey: $entityKey,
                projectionStrength: $projectionStrength,
                factStrength: $factStrength
            );
        }

        return DB::table(self::PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->where('profile_type', $profileType)
            ->where('entity_key', $entityKey)
            ->count();
    }

    /**
     * Build one entity's single-season snapshot rows.
     */
    public function buildSeasonSnapshotEntity(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel,
        string $profileType,
        string $entityKey,
        string $seasonId
    ): int {
        if (preg_match('/^\d{8}$/', $seasonId) !== 1) {
            throw new RuntimeException('Invalid season snapshot id.');
        }

        $definition = $this->profileDefinitions()[$profileType] ?? null;

        if ($definition === null) {
            throw new RuntimeException('Unknown SAT model profile type.');
        }

        foreach (($profileType === 'skater_offense' ? self::PROFILE_STRENGTHS : ['all' => null]) as $projectionStrength => $factStrength) {
            $this->insertProfileRows(
                run: $run,
                satModel: $satModel,
                sogModel: $sogModel,
                profileType: $profileType,
                definition: $definition,
                seasonIds: [$seasonId],
                satBucketKeySql: $this->modelBucketKeySql($satModel, 'profile_facts'),
                sogBucketKeySql: $sogModel === null ? null : $this->modelBucketKeySql($sogModel, 'profile_facts'),
                entityKey: $entityKey,
                tableName: self::TEST_PROFILE_TABLE,
                testSeasonId: $seasonId,
                projectionStrength: $projectionStrength,
                factStrength: $factStrength
            );
        }

        return DB::table(self::TEST_PROFILE_TABLE)
            ->where('model_run_id', $run->id)
            ->where('test_season_id', $seasonId)
            ->where('profile_type', $profileType)
            ->where('entity_key', $entityKey)
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function seasonIds(NhlModelRun $run): array
    {
        return collect($run->train_season_ids ?? [])
            ->map(fn (mixed $seasonId): string => trim((string) $seasonId))
            ->filter(fn (string $seasonId): bool => preg_match('/^\d{8}$/', $seasonId) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function testSeasonId(NhlModelRun $run): ?string
    {
        $seasonId = trim((string) ($run->target_season_id ?? ''));

        return preg_match('/^\d{8}$/', $seasonId) === 1 ? $seasonId : null;
    }

    /**
     * @return array<int, string>
     */
    private function snapshotSeasonIds(NhlModelRun $run): array
    {
        $seasonIds = $this->seasonIds($run);
        $testSeasonId = $this->testSeasonId($run);

        if ($testSeasonId !== null) {
            $seasonIds[] = $testSeasonId;
        }

        return collect($seasonIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int, string> $profileTypes @return array<int, string> */
    private function supportedProfileTypes(array $profileTypes): array
    {
        $profileTypes = array_values(array_unique($profileTypes));
        $supported = ['skater_offense', 'goalie_faced'];
        if ($profileTypes === [] || array_diff($profileTypes, $supported) !== []) {
            throw new RuntimeException('Unknown SAT model profile type.');
        }

        return $profileTypes;
    }

    /**
     * @return array<string, array{joins:string,entity_id:string,entity_key:string,entity_name:string,entity_role:string,team_context:string,where:string}>
     */
    public function profileDefinitions(): array
    {
        return [
            'skater_offense' => [
                'joins' => "LEFT JOIN players shooter_player ON shooter_player.nhl_id = facts.shooter_player_id",
                'entity_id' => 'facts.shooter_player_id',
                'entity_key' => "'skater_offense:' || facts.shooter_player_id::text",
                'entity_name' => "COALESCE(shooter_player.full_name, CONCAT(shooter_player.first_name, ' ', shooter_player.last_name), facts.shooter_player_id::text)",
                'entity_role' => "'skater'",
                'team_context' => "'offense'",
                'where' => 'facts.shooter_player_id IS NOT NULL',
            ],
            'skater_defense' => [
                'joins' => <<<SQL
INNER JOIN event_unit_shifts event_links ON event_links.event_id = facts.play_by_play_id
INNER JOIN nhl_unit_shifts unit_shifts ON unit_shifts.id = event_links.unit_shift_id
INNER JOIN nhl_unit_shift_players shift_players ON shift_players.unit_shift_id = unit_shifts.id
INNER JOIN players shift_players_meta ON shift_players_meta.id = shift_players.player_id
SQL,
                'entity_id' => 'shift_players_meta.nhl_id',
                'entity_key' => "'skater_defense:' || shift_players_meta.nhl_id::text",
                'entity_name' => "COALESCE(shift_players_meta.full_name, CONCAT(shift_players_meta.first_name, ' ', shift_players_meta.last_name), shift_players_meta.nhl_id::text)",
                'entity_role' => "'skater'",
                'team_context' => "'defense'",
                'where' => "unit_shifts.team_id = facts.opponent_team_id
                    AND shift_players_meta.nhl_id IS NOT NULL
                    AND COALESCE(shift_players_meta.pos_type, '') <> 'G'",
            ],
            'goalie_faced' => [
                'joins' => "LEFT JOIN players goalie_player ON goalie_player.nhl_id = facts.goalie_player_id",
                'entity_id' => 'facts.goalie_player_id',
                'entity_key' => "'goalie_faced:' || facts.goalie_player_id::text",
                'entity_name' => "COALESCE(goalie_player.full_name, CONCAT(goalie_player.first_name, ' ', goalie_player.last_name), facts.goalie_player_id::text)",
                'entity_role' => "'goalie'",
                'team_context' => "'faced'",
                'where' => 'facts.goalie_player_id IS NOT NULL',
            ],
            'team_offense' => [
                'joins' => 'LEFT JOIN nhl_teams entity_team ON entity_team.nhl_id = facts.team_id',
                'entity_id' => 'facts.team_id',
                'entity_key' => "'team_offense:' || facts.team_id::text",
                'entity_name' => 'COALESCE(entity_team.abbrev, entity_team.full_name, facts.team_id::text)',
                'entity_role' => "'team'",
                'team_context' => "'offense'",
                'where' => 'facts.team_id IS NOT NULL',
            ],
            'team_defense' => [
                'joins' => 'LEFT JOIN nhl_teams entity_team ON entity_team.nhl_id = facts.opponent_team_id',
                'entity_id' => 'facts.opponent_team_id',
                'entity_key' => "'team_defense:' || facts.opponent_team_id::text",
                'entity_name' => 'COALESCE(entity_team.abbrev, entity_team.full_name, facts.opponent_team_id::text)',
                'entity_role' => "'team'",
                'team_context' => "'defense'",
                'where' => 'facts.opponent_team_id IS NOT NULL',
            ],
            'official' => [
                'joins' => <<<SQL
INNER JOIN nhl_game_officials assignments ON assignments.nhl_game_id = facts.nhl_game_id
LEFT JOIN nhl_officials officials ON officials.id = assignments.nhl_official_id
SQL,
                'entity_id' => 'assignments.nhl_official_id',
                'entity_key' => "'official:' || assignments.role || ':' || assignments.nhl_official_id::text",
                'entity_name' => 'COALESCE(officials.display_name, assignments.nhl_official_id::text)',
                'entity_role' => 'assignments.role',
                'team_context' => "'game'",
                'where' => 'assignments.nhl_official_id IS NOT NULL',
            ],
            'staff_offense' => [
                'joins' => <<<SQL
INNER JOIN nhl_game_team_staff assignments
    ON assignments.nhl_game_id = facts.nhl_game_id
    AND assignments.role = 'head_coach'
    AND (
        assignments.nhl_team_id = facts.team_id
        OR assignments.team_side = CASE
            WHEN facts.team_id = games.home_team_id THEN 'home'
            WHEN facts.team_id = games.away_team_id THEN 'away'
            ELSE NULL
        END
    )
LEFT JOIN nhl_staff staff ON staff.id = assignments.nhl_staff_id
SQL,
                'entity_id' => 'assignments.nhl_staff_id',
                'entity_key' => "'staff:offense:' || assignments.role || ':' || assignments.nhl_staff_id::text",
                'entity_name' => 'COALESCE(staff.display_name, assignments.nhl_staff_id::text)',
                'entity_role' => 'assignments.role',
                'team_context' => "'offense'",
                'where' => 'assignments.nhl_staff_id IS NOT NULL',
            ],
            'staff_defense' => [
                'joins' => <<<SQL
INNER JOIN nhl_game_team_staff assignments
    ON assignments.nhl_game_id = facts.nhl_game_id
    AND assignments.role = 'head_coach'
    AND (
        assignments.nhl_team_id = facts.opponent_team_id
        OR assignments.team_side = CASE
            WHEN facts.opponent_team_id = games.home_team_id THEN 'home'
            WHEN facts.opponent_team_id = games.away_team_id THEN 'away'
            ELSE NULL
        END
    )
LEFT JOIN nhl_staff staff ON staff.id = assignments.nhl_staff_id
SQL,
                'entity_id' => 'assignments.nhl_staff_id',
                'entity_key' => "'staff:defense:' || assignments.role || ':' || assignments.nhl_staff_id::text",
                'entity_name' => 'COALESCE(staff.display_name, assignments.nhl_staff_id::text)',
                'entity_role' => 'assignments.role',
                'team_context' => "'defense'",
                'where' => 'assignments.nhl_staff_id IS NOT NULL',
            ],
        ];
    }

    /**
     * @param array{joins:string,entity_id:string,entity_key:string,entity_name:string,entity_role:string,team_context:string,where:string} $definition
     * @param array<int, string> $seasonIds
     */
    private function insertProfileRows(
        NhlModelRun $run,
        NhlExpectedGoalsModel $satModel,
        ?NhlExpectedGoalsModel $sogModel,
        string $profileType,
        array $definition,
        array $seasonIds,
        string $satBucketKeySql,
        ?string $sogBucketKeySql,
        ?string $entityKey = null,
        string $tableName = self::PROFILE_TABLE,
        ?string $testSeasonId = null,
        string $projectionStrength = 'all',
        ?string $factStrength = null
    ): void {
        $now = now();
        $seasonJson = json_encode($seasonIds, JSON_THROW_ON_ERROR);
        $seasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        $bucketLookup = app(NhlShotAttemptAnalysisBuckets::class);
        $satBucketJoin = $bucketLookup->probabilityBucketJoinSql(
            (int) $satModel->id, $satBucketKeySql, 'sat_buckets', 'profile_facts'
        );
        $goalBucketJoin = $sogModel === null || $sogBucketKeySql === null
            ? ''
            : $bucketLookup->probabilityBucketJoinSql(
                (int) $sogModel->id, $sogBucketKeySql, 'goal_buckets', 'profile_facts'
            );
        $goalProbability = $sogModel === null ? '0' : 'COALESCE(goal_buckets.smoothed_goal_probability, 0)';
        $usesPlayerExposure = in_array($profileType, ['skater_offense', 'skater_defense', 'goalie_faced'], true);
        $sourceExposureSeconds = $factStrength === null
            ? ($usesPlayerExposure ? 'COALESCE(game_summaries.toi, boxscores.toi_seconds, 0)' : '3600')
            : 'COALESCE(strength_summaries.toi, 0)';
        [$entityWhereSql, $entityBindings] = $this->entityFilter($definition, $entityKey);
        $factStrengthSql = $factStrength === null
            ? ''
            : "AND UPPER(COALESCE(NULLIF(facts.strength_bucket, ''), NULLIF(facts.strength, ''))) = ?";
        $strengthSummaryJoin = $factStrength === null
            ? ''
            : "LEFT JOIN nhl_player_game_strength_summaries strength_summaries\n        ON strength_summaries.nhl_game_id = entity_games.nhl_game_id\n        AND strength_summaries.nhl_player_id = entity_games.entity_id\n        AND strength_summaries.strength = ?";
        $testColumnSql = $testSeasonId === null ? '' : "    test_season_id,\n";
        $testSelectSql = $testSeasonId === null ? '' : "    ? as test_season_id,\n";
        $conflictColumns = $testSeasonId === null
            ? 'model_run_id, profile_type, strength, entity_key, matched_bucket_key'
            : 'model_run_id, test_season_id, profile_type, strength, entity_key, matched_bucket_key';
        $profileSample = $testSeasonId === null ? 'training' : 'test';
        // Skater partitions use their own strength exposure; goalie split rates remain historical ratios only.
        $hasStrengthExposure = $profileType === 'goalie_faced'
            ? "strength_profile_facts.profile_strength = 'all'"
            : 'true';
        $strengthProfileRowsSql = $profileType === 'goalie_faced'
            ? <<<SQL
UNION ALL
    SELECT
        scored_facts.*,
        LOWER(COALESCE(NULLIF(scored_facts.strength_bucket, ''), NULLIF(scored_facts.strength, ''))) as profile_strength
    FROM scored_facts
    WHERE LOWER(COALESCE(NULLIF(scored_facts.strength_bucket, ''), NULLIF(scored_facts.strength, ''))) IN ('ev', 'pp', 'pk')
SQL
            : '';

        $sql = <<<SQL
INSERT INTO {$tableName} (
    model_run_id,
    sat_expected_goals_model_id,
    sog_expected_goals_model_id,
    source_season_ids,
{$testColumnSql}
    game_type,
    profile_type,
    strength,
    entity_key,
    entity_id,
    entity_name,
    entity_role,
    team_context,
    matched_bucket_key,
    fallback_level,
    bucket_dimensions,
    source_sat,
    source_sog,
    source_goals,
    source_profile_share,
    source_toi_seconds,
    source_xsat_per_60,
    source_xsog_per_60,
    source_xg_per_60,
    expected_sog,
    expected_goals,
    sog_above_expected,
    goals_above_expected,
    source_gsax,
    source_gsax_per_60,
    source_gsax_per_100_xga,
    source_save_percentage,
    sat_probability,
    goal_probability,
    confidence_score,
    shrinkage_weight,
    confidence_bucket,
    metadata,
    profiled_at,
    created_at,
    updated_at
)
WITH profile_facts AS (
    SELECT DISTINCT
        facts.id,
        facts.nhl_game_id,
        {$definition['entity_id']} as entity_id,
        {$definition['entity_key']} as entity_key,
        {$definition['entity_name']} as entity_name,
        {$definition['entity_role']} as entity_role,
        {$definition['team_context']} as team_context,
        facts.is_shot_on_goal,
        facts.is_goal,
        facts.shot_type_bucket,
        facts.shot_distance,
        facts.abs_shot_angle,
        facts.is_rush,
        facts.is_rebound,
        facts.strength_bucket,
        facts.strength,
        facts.period,
        facts.score_state_bucket,
        facts.shooter_age_years,
        facts.shooter_height_inches,
        facts.shooter_weight_lbs
    FROM nhl_shot_attempts_facts facts
    INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
    {$definition['joins']}
    WHERE facts.season_id IN ({$seasonPlaceholders})
        AND games.game_type = ?
        AND COALESCE(facts.period_type, '') <> 'SO'
        AND COALESCE(facts.is_empty_net, false) = false
        AND {$definition['where']}
        {$factStrengthSql}
        {$entityWhereSql}
),
scored_facts AS (
    SELECT
        profile_facts.*,
        sat_buckets.bucket_key as matched_bucket_key,
        sat_buckets.fallback_level as fallback_level,
        sat_buckets.bucket_dimensions as bucket_dimensions,
        COALESCE(sat_buckets.smoothed_goal_probability, 0) as sat_probability,
        {$goalProbability} as goal_probability,
        COALESCE(sat_buckets.confidence_score, 0) as confidence_score,
        COALESCE(sat_buckets.shrinkage_weight, 0) as shrinkage_weight,
        sat_buckets.confidence_bucket as confidence_bucket
    FROM profile_facts
    {$satBucketJoin}
    {$goalBucketJoin}
),
strength_profile_facts AS (
    SELECT scored_facts.*, ?::varchar as profile_strength
    FROM scored_facts
    {$strengthProfileRowsSql}
),
entity_totals AS (
    SELECT entity_key, profile_strength, COUNT(*) as total_sat
    FROM strength_profile_facts
    GROUP BY entity_key, profile_strength
),
entity_games AS (
    SELECT DISTINCT entity_key, entity_id, nhl_game_id
    FROM profile_facts
),
entity_exposure AS (
    SELECT
        entity_games.entity_key,
        SUM({$sourceExposureSeconds}) as source_toi_seconds
    FROM entity_games
    LEFT JOIN nhl_game_summaries game_summaries
        ON game_summaries.nhl_game_id = entity_games.nhl_game_id
        AND game_summaries.nhl_player_id = entity_games.entity_id
    LEFT JOIN nhl_boxscores boxscores
        ON boxscores.nhl_game_id = entity_games.nhl_game_id
        AND boxscores.nhl_player_id = entity_games.entity_id
    {$strengthSummaryJoin}
    GROUP BY entity_games.entity_key
)
SELECT
    ? as model_run_id,
    ? as sat_expected_goals_model_id,
    ? as sog_expected_goals_model_id,
    ?::json as source_season_ids,
{$testSelectSql}
    ? as game_type,
    ? as profile_type,
    strength_profile_facts.profile_strength as strength,
    strength_profile_facts.entity_key,
    MAX(strength_profile_facts.entity_id) as entity_id,
    MAX(strength_profile_facts.entity_name) as entity_name,
    MAX(strength_profile_facts.entity_role) as entity_role,
    MAX(strength_profile_facts.team_context) as team_context,
    strength_profile_facts.matched_bucket_key,
    MAX(strength_profile_facts.fallback_level) as fallback_level,
    (MAX(strength_profile_facts.bucket_dimensions::text)::jsonb || jsonb_build_object('strength', strength_profile_facts.profile_strength))::json as bucket_dimensions,
    COUNT(*) as source_sat,
    SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN 1 ELSE 0 END) as source_sog,
    SUM(CASE WHEN strength_profile_facts.is_goal THEN 1 ELSE 0 END) as source_goals,
    ROUND((COUNT(*)::numeric / NULLIF(MAX(entity_totals.total_sat), 0)), 6) as source_profile_share,
    CASE WHEN {$hasStrengthExposure} THEN MAX(entity_exposure.source_toi_seconds) ELSE NULL END as source_toi_seconds,
    CASE WHEN {$hasStrengthExposure} THEN ROUND((COUNT(*)::numeric * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0)), 4) ELSE NULL END as source_xsat_per_60,
    CASE WHEN {$hasStrengthExposure} THEN ROUND((SUM(strength_profile_facts.sat_probability)::numeric * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0)), 4) ELSE NULL END as source_xsog_per_60,
    CASE WHEN {$hasStrengthExposure} THEN ROUND((SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END)::numeric * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0)), 4) ELSE NULL END as source_xg_per_60,
    ROUND(SUM(strength_profile_facts.sat_probability)::numeric, 4) as expected_sog,
    ROUND(SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END)::numeric, 4) as expected_goals,
    ROUND((SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN 1 ELSE 0 END) - SUM(strength_profile_facts.sat_probability))::numeric, 4) as sog_above_expected,
    ROUND((SUM(CASE WHEN strength_profile_facts.is_goal THEN 1 ELSE 0 END) - SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END))::numeric, 4) as goals_above_expected,
    ROUND((SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END) - SUM(CASE WHEN strength_profile_facts.is_goal THEN 1 ELSE 0 END))::numeric, 4) as source_gsax,
    CASE WHEN {$hasStrengthExposure} THEN ROUND(((SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END) - SUM(CASE WHEN strength_profile_facts.is_goal THEN 1 ELSE 0 END)) * 3600 / NULLIF(MAX(entity_exposure.source_toi_seconds), 0))::numeric, 4) ELSE NULL END as source_gsax_per_60,
    ROUND(((SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END) - SUM(CASE WHEN strength_profile_facts.is_goal THEN 1 ELSE 0 END)) * 100 / NULLIF(SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN strength_profile_facts.goal_probability ELSE 0 END), 0))::numeric, 4) as source_gsax_per_100_xga,
    ROUND(((SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN 1 ELSE 0 END) - SUM(CASE WHEN strength_profile_facts.is_goal THEN 1 ELSE 0 END)) * 100 / NULLIF(SUM(CASE WHEN strength_profile_facts.is_shot_on_goal THEN 1 ELSE 0 END), 0))::numeric, 4) as source_save_percentage,
    ROUND(AVG(strength_profile_facts.sat_probability)::numeric, 6) as sat_probability,
    ROUND(AVG(strength_profile_facts.goal_probability)::numeric, 6) as goal_probability,
    ROUND(AVG(strength_profile_facts.confidence_score)::numeric, 4) as confidence_score,
    ROUND(AVG(strength_profile_facts.shrinkage_weight)::numeric, 4) as shrinkage_weight,
    MAX(strength_profile_facts.confidence_bucket) as confidence_bucket,
    json_build_object('bucket_source', 'model_run_eval', 'profile_type', ?::text, 'profile_sample', ?::text,
        'probability_sources', ?::json, 'strength', strength_profile_facts.profile_strength) as metadata,
    ?::timestamp as profiled_at,
    ?::timestamp as created_at,
    ?::timestamp as updated_at
FROM strength_profile_facts
INNER JOIN entity_totals ON entity_totals.entity_key = strength_profile_facts.entity_key
    AND entity_totals.profile_strength = strength_profile_facts.profile_strength
INNER JOIN entity_exposure ON entity_exposure.entity_key = strength_profile_facts.entity_key
WHERE strength_profile_facts.matched_bucket_key IS NOT NULL
GROUP BY strength_profile_facts.entity_key, strength_profile_facts.profile_strength, strength_profile_facts.matched_bucket_key
HAVING COUNT(*) >= 1
ON CONFLICT ({$conflictColumns})
DO UPDATE SET
    sat_expected_goals_model_id = EXCLUDED.sat_expected_goals_model_id,
    sog_expected_goals_model_id = EXCLUDED.sog_expected_goals_model_id,
    source_season_ids = EXCLUDED.source_season_ids,
    game_type = EXCLUDED.game_type,
    entity_id = EXCLUDED.entity_id,
    entity_name = EXCLUDED.entity_name,
    entity_role = EXCLUDED.entity_role,
    team_context = EXCLUDED.team_context,
    strength = EXCLUDED.strength,
    fallback_level = EXCLUDED.fallback_level,
    bucket_dimensions = EXCLUDED.bucket_dimensions,
    source_sat = EXCLUDED.source_sat,
    source_sog = EXCLUDED.source_sog,
    source_goals = EXCLUDED.source_goals,
    source_profile_share = EXCLUDED.source_profile_share,
    source_toi_seconds = EXCLUDED.source_toi_seconds,
    source_xsat_per_60 = EXCLUDED.source_xsat_per_60,
    source_xsog_per_60 = EXCLUDED.source_xsog_per_60,
    source_xg_per_60 = EXCLUDED.source_xg_per_60,
    expected_sog = EXCLUDED.expected_sog,
    expected_goals = EXCLUDED.expected_goals,
    sog_above_expected = EXCLUDED.sog_above_expected,
    goals_above_expected = EXCLUDED.goals_above_expected,
    source_gsax = EXCLUDED.source_gsax,
    source_gsax_per_60 = EXCLUDED.source_gsax_per_60,
    source_gsax_per_100_xga = EXCLUDED.source_gsax_per_100_xga,
    source_save_percentage = EXCLUDED.source_save_percentage,
    sat_probability = EXCLUDED.sat_probability,
    goal_probability = EXCLUDED.goal_probability,
    confidence_score = EXCLUDED.confidence_score,
    shrinkage_weight = EXCLUDED.shrinkage_weight,
    confidence_bucket = EXCLUDED.confidence_bucket,
    metadata = EXCLUDED.metadata,
    profiled_at = EXCLUDED.profiled_at,
    updated_at = EXCLUDED.updated_at
SQL;

        DB::statement($sql, [
            ...$seasonIds,
            self::REGULAR_SEASON_GAME_TYPE,
            ...($factStrength === null ? [] : [$factStrength]),
            ...$entityBindings,
            $projectionStrength,
            ...($factStrength === null ? [] : [$factStrength]),
            $run->id,
            $satModel->id,
            $sogModel?->id,
            $seasonJson,
            ...($testSeasonId === null ? [] : [$testSeasonId]),
            self::REGULAR_SEASON_GAME_TYPE,
            $profileType,
            $profileType,
            $profileSample,
            json_encode(self::probabilitySources($satModel, $sogModel), JSON_THROW_ON_ERROR),
            $now,
            $now,
            $now,
        ]);

        if ($factStrength !== null) {
            $this->assertStrengthProfilesExist(
                $run, $definition, $seasonIds, $profileType, $projectionStrength,
                $entityKey, $tableName, $testSeasonId
            );
        }
    }

    /**
     * Keep player predicates numeric so PostgreSQL can use existing fact indexes.
     *
     * @param array<string, string> $definition
     * @return array{string, array<int, int|string>}
     */
    private function entityFilter(array $definition, ?string $entityKey): array
    {
        if ($entityKey === null) {
            return ['', []];
        }
        $prefix = match ($definition['entity_id']) {
            'facts.shooter_player_id' => 'skater_offense',
            'facts.goalie_player_id' => 'goalie_faced',
            default => null,
        };
        if ($prefix === null) {
            return ["AND {$definition['entity_key']} = ?", [$entityKey]];
        }
        if (preg_match('/^' . $prefix . ':([1-9][0-9]*)$/D', $entityKey, $matches) !== 1
            || strlen($matches[1]) > 10 || (int) $matches[1] > 2147483647) {
            throw new RuntimeException('Invalid profile player identifier.');
        }

        return ["AND {$definition['entity_id']} = ?", [(int) $matches[1]]];
    }

    /**
     * Fail the owning job before its completion receipt if eligible split evidence was lost.
     * Players without attempts at a strength legitimately have no profile at that strength.
     *
     * @param array<string, string> $definition
     * @param array<int, string> $seasonIds
     */
    private function assertStrengthProfilesExist(
        NhlModelRun $run,
        array $definition,
        array $seasonIds,
        string $profileType,
        string $strength,
        ?string $entityKey,
        string $tableName,
        ?string $testSeasonId
    ): void {
        $seasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        [$entityFilter, $entityBindings] = $this->entityFilter($definition, $entityKey);
        $snapshotFilter = $testSeasonId === null ? '' : 'AND profiles.test_season_id = ?';
        $missing = DB::selectOne(<<<SQL
SELECT {$definition['entity_key']} AS entity_key
FROM nhl_shot_attempts_facts facts
INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
{$definition['joins']}
WHERE facts.season_id IN ({$seasonPlaceholders})
    AND games.game_type = ?
    AND COALESCE(facts.period_type, '') <> 'SO'
    AND COALESCE(facts.is_empty_net, false) = false
    AND {$definition['where']}
    AND LOWER(COALESCE(NULLIF(facts.strength_bucket, ''), NULLIF(facts.strength, ''))) = ?
    {$entityFilter}
    AND NOT EXISTS (
        SELECT 1 FROM {$tableName} profiles
        WHERE profiles.model_run_id = ?
            AND profiles.profile_type = ?
            AND profiles.strength = ?
            AND profiles.entity_key = {$definition['entity_key']}
            {$snapshotFilter}
    )
LIMIT 1
SQL, [
            ...$seasonIds,
            self::REGULAR_SEASON_GAME_TYPE,
            strtolower($strength),
            ...$entityBindings,
            $run->id,
            $profileType,
            $strength,
            ...($testSeasonId === null ? [] : [$testSeasonId]),
        ]);

        if ($missing !== null) {
            $sample = $testSeasonId ?? 'training';
            throw new RuntimeException(
                "Missing {$strength} profile for {$missing->entity_key} ({$sample}) despite eligible source shots."
            );
        }
    }

    /**
     * @param array{joins:string,entity_id:string,entity_key:string,entity_name:string,entity_role:string,team_context:string,where:string} $definition
     * @return array<int, string>
     */
    private function profileEntities(array $seasonIds, array $definition, ?string $after = null, ?int $limit = null): array
    {
        if ($seasonIds === []) {
            return [];
        }

        $seasonPlaceholders = implode(', ', array_fill(0, count($seasonIds), '?'));
        $sql = <<<SQL
SELECT DISTINCT {$definition['entity_key']} as entity_key
FROM nhl_shot_attempts_facts facts
INNER JOIN nhl_games games ON games.nhl_game_id = facts.nhl_game_id
{$definition['joins']}
WHERE facts.season_id IN ({$seasonPlaceholders})
    AND games.game_type = ?
    AND COALESCE(facts.period_type, '') <> 'SO'
    AND COALESCE(facts.is_empty_net, false) = false
    AND {$definition['where']}
ORDER BY entity_key
SQL;

        $bindings = [
            ...$seasonIds,
            self::REGULAR_SEASON_GAME_TYPE,
        ];
        if ($limit !== null) {
            $sql = 'SELECT entity_key FROM (' . $sql . ') entities WHERE entity_key > ? ORDER BY entity_key LIMIT ?';
            $bindings[] = $after ?? '';
            $bindings[] = $limit;
        }

        return collect(DB::select($sql, $bindings))
            ->pluck('entity_key')
            ->map(fn (mixed $entityKey): string => (string) $entityKey)
            ->filter(fn (string $entityKey): bool => $entityKey !== '')
            ->values()
            ->all();
    }

    /** Share the profile builder's exact shot-shape mapping with read-only evaluations. */
    public function modelBucketKeySql(NhlExpectedGoalsModel $model, string $tableAlias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $tableAlias) !== 1) {
            throw new \InvalidArgumentException('Invalid shot-fact table alias.');
        }
        $fallbackLevels = collect((array) data_get($model->feature_config, 'fallback_levels', []));
        $factorKeys = $fallbackLevels
            ->first(function (mixed $level): bool {
                $keys = collect((array) $level)
                    ->map(fn (mixed $key): string => (string) $key)
                    ->reject(fn (string $key): bool => $key === '' || $key === 'baseline');

                return $keys->isNotEmpty();
            });
        $factorKeys = collect((array) $factorKeys)
            ->map(fn (mixed $key): string => (string) $key)
            ->reject(fn (string $key): bool => $key === '' || $key === 'baseline')
            ->values()
            ->all();

        if ($factorKeys === []) {
            $factorKeys = ['distance_group', 'angle_group'];
        }

        $parts = [];

        foreach ($factorKeys as $factorKey) {
            $parts[] = "'{$factorKey}=' || " . $this->factorExpression($factorKey, $tableAlias);
        }

        return "'L01|' || " . implode(" || '|' || ", $parts);
    }

    private function factorExpression(string $factorKey, string $tableAlias): string
    {
        return match ($factorKey) {
            'distance_group' => "CASE
                WHEN {$tableAlias}.shot_distance IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shot_distance < 5 THEN 'd_000_005'
                WHEN {$tableAlias}.shot_distance < 10 THEN 'd_005_010'
                WHEN {$tableAlias}.shot_distance < 15 THEN 'd_010_015'
                WHEN {$tableAlias}.shot_distance < 20 THEN 'd_015_020'
                WHEN {$tableAlias}.shot_distance < 25 THEN 'd_020_025'
                WHEN {$tableAlias}.shot_distance < 30 THEN 'd_025_030'
                WHEN {$tableAlias}.shot_distance < 35 THEN 'd_030_035'
                WHEN {$tableAlias}.shot_distance < 40 THEN 'd_035_040'
                WHEN {$tableAlias}.shot_distance < 45 THEN 'd_040_045'
                WHEN {$tableAlias}.shot_distance < 50 THEN 'd_045_050'
                WHEN {$tableAlias}.shot_distance < 55 THEN 'd_050_055'
                WHEN {$tableAlias}.shot_distance < 60 THEN 'd_055_060'
                ELSE 'd_060_plus'
            END",
            'angle_group' => "CASE
                WHEN {$tableAlias}.abs_shot_angle IS NULL THEN 'unknown'
                WHEN {$tableAlias}.abs_shot_angle < 10 THEN 'a_000_010'
                WHEN {$tableAlias}.abs_shot_angle < 20 THEN 'a_010_020'
                WHEN {$tableAlias}.abs_shot_angle < 30 THEN 'a_020_030'
                WHEN {$tableAlias}.abs_shot_angle < 40 THEN 'a_030_040'
                WHEN {$tableAlias}.abs_shot_angle < 50 THEN 'a_040_050'
                WHEN {$tableAlias}.abs_shot_angle < 60 THEN 'a_050_060'
                WHEN {$tableAlias}.abs_shot_angle < 70 THEN 'a_060_070'
                WHEN {$tableAlias}.abs_shot_angle < 80 THEN 'a_070_080'
                WHEN {$tableAlias}.abs_shot_angle <= 90 THEN 'a_080_090'
                ELSE 'invalid_gt_90'
            END",
            'shot_type_group' => "COALESCE(NULLIF({$tableAlias}.shot_type_bucket, ''), 'unknown')",
            'sequence_group' => "CASE
                WHEN {$tableAlias}.is_rush = true AND {$tableAlias}.is_rebound = true THEN 'rush_rebound'
                WHEN {$tableAlias}.is_rebound = true THEN 'rebound'
                WHEN {$tableAlias}.is_rush = true THEN 'rush'
                ELSE 'settled'
            END",
            'strength_group' => "COALESCE(NULLIF({$tableAlias}.strength_bucket, ''), NULLIF({$tableAlias}.strength, ''), 'unknown')",
            'period_group' => "CASE
                WHEN {$tableAlias}.period = 1 THEN 'p1'
                WHEN {$tableAlias}.period = 2 THEN 'p2'
                WHEN {$tableAlias}.period = 3 THEN 'p3'
                WHEN {$tableAlias}.period > 3 THEN 'ot'
                ELSE 'unknown'
            END",
            'score_state_group' => "COALESCE(NULLIF({$tableAlias}.score_state_bucket, ''), 'unknown')",
            'shooter_age_group' => "CASE
                WHEN {$tableAlias}.shooter_age_years IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shooter_age_years <= 21 THEN 'age_le_21'
                WHEN {$tableAlias}.shooter_age_years <= 25 THEN 'age_22_25'
                WHEN {$tableAlias}.shooter_age_years <= 29 THEN 'age_26_29'
                WHEN {$tableAlias}.shooter_age_years <= 33 THEN 'age_30_33'
                ELSE 'age_34_plus'
            END",
            'shooter_height_group' => "CASE
                WHEN {$tableAlias}.shooter_height_inches IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shooter_height_inches <= 68 THEN 'h_le_68'
                WHEN {$tableAlias}.shooter_height_inches <= 71 THEN 'h_69_71'
                WHEN {$tableAlias}.shooter_height_inches <= 74 THEN 'h_72_74'
                WHEN {$tableAlias}.shooter_height_inches <= 77 THEN 'h_75_77'
                ELSE 'h_78_plus'
            END",
            'shooter_weight_group' => "CASE
                WHEN {$tableAlias}.shooter_weight_lbs IS NULL THEN 'unknown'
                WHEN {$tableAlias}.shooter_weight_lbs < 180 THEN 'w_lt_180'
                WHEN {$tableAlias}.shooter_weight_lbs < 200 THEN 'w_180_199'
                WHEN {$tableAlias}.shooter_weight_lbs < 220 THEN 'w_200_219'
                ELSE 'w_220_plus'
            END",
            default => "'unknown'",
        };
    }
}
