<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RunNhlGamePredictionJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Append immutable default-path calculations and expose compact admin comparison rows. */
final class NhlGamePredictionHistory
{
    /** Admit manual or automatic work through the same durable run and publication pathway.
     * @param array<string,mixed> $automation Internal trigger evidence; empty for manual runs.
     * @return array{run_id:int,total_games:int}|null Null means no automatic work or an active overlap.
     */
    public function queueRun(string $start, string $end, ?int $createdBy, array $automation = []): ?array
    {
        $model = app(NhlCustomGameForecast::class)->model();
        $definition = ['algorithm' => config('nhl-custom-prediction'), 'model_run_id' => $model->id];
        if ($automation !== []) {
            $definition['automation'] = $automation;
        }
        return DB::transaction(function () use ($start, $end, $createdBy, $automation, $definition): ?array {
            $games = DB::table('nhl_games')->whereBetween('game_date', [$start, $end])->whereIn('game_type', [1, 2, 3])
                ->when($automation !== [], fn ($query) => $query->whereIn('game_state', ['FUT', 'PRE']))
                ->orderBy('nhl_game_id')->lockForUpdate()->pluck('nhl_game_id');
            if ($games->isEmpty()) {
                if ($automation !== []) {
                    return null;
                }
                throw ValidationException::withMessages(['start_date' => 'No stored NHL games were found for these dates.']);
            }
            if ($automation !== []) {
                $active = DB::table('nhl_game_prediction_run_games')->whereIn('nhl_game_id', $games)
                    ->whereIn('status', ['queued', 'running'])->exists();
                $apiActive = $games->contains(fn ($id): bool => collect(Cache::get('nhl:prediction-input-writers:'.$id, []))
                    ->contains(fn ($expires): bool => $expires > now()->timestamp));
                if ($active || $apiActive) {
                    return null;
                }
            }
            $runId = DB::table('nhl_game_prediction_runs')->insertGetId([
                'start_date' => $start, 'end_date' => $end, 'status' => 'queued', 'total_games' => $games->count(),
                'requested_stack' => json_encode($definition, JSON_THROW_ON_ERROR), 'created_by' => $createdBy,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('nhl_game_prediction_run_games')->insert($games->map(fn ($id): array => [
                'run_id' => $runId, 'nhl_game_id' => $id, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ])->all());
            $itemIds = DB::table('nhl_game_prediction_run_games')->where('run_id', $runId)->pluck('id');
            DB::afterCommit(function () use ($itemIds, $runId): void {
                try {
                    Bus::batch($itemIds->map(fn ($id) => new RunNhlGamePredictionJob((int) $id))->all())
                        ->name('NHL game predictions #'.$runId)->onQueue('projections')->allowFailures()->dispatch();
                } catch (Throwable $error) {
                    foreach ($itemIds as $id) {
                        $this->finish((int) $id, 'failed', error: 'Unable to queue prediction: '.mb_substr($error->getMessage(), 0, 1500));
                    }
                    report($error);
                }
            });

            return ['run_id' => $runId, 'total_games' => $games->count()];
        });
    }

    /** Read the latest published prediction for one game without refreshing any dependencies.
     * @return array<string,mixed>|null
     */
    public function latestSaved(int $gameId): ?array
    {
        $revision = DB::table('nhl_game_prediction_revisions')
            ->where('nhl_game_id', $gameId)->where('prediction_available', true)
            ->orderByDesc('id')->first();

        return $revision === null ? null : $this->payload($revision);
    }

    /** Persist once per manual run/game; independent API recalculations always append.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function record(int $gameId, array $input, string $fingerprint, array $evidence, array $payload, ?int $runId = null): array
    {
        if (isset($payload['_custom_prediction_evidence'])) {
            $evidence['custom_prediction'] = $payload['_custom_prediction_evidence'];
            unset($payload['_custom_prediction_evidence']);
        }
        $existing = $runId === null ? null : DB::table('nhl_game_prediction_revisions')
            ->where('run_id', $runId)->where('nhl_game_id', $gameId)->first();
        if ($existing !== null) {
            return $this->payload($existing);
        }
        // Market selection changes pricing output, not the core matchup. Explicit roster/model overrides remain separate variants.
        $canonical = array_diff(array_keys($input), ['nhl_game_id', 'markets', 'puckline', 'puckline_spreads', 'total', 'total_lines']) === [];
        $at = now();
        $date = DB::table('nhl_games')->where('nhl_game_id', $gameId)->value('game_date');
        $daysAway = $date === null ? null : (int) CarbonImmutable::instance($at)->setTimezone('America/Toronto')
            ->startOfDay()->diffInDays(CarbonImmutable::parse($date, 'America/Toronto')->startOfDay(), false);
        $publicConfidence = null;
        if (is_array($payload['prediction'] ?? null)) {
            $publicConfidence = app(NhlGamePredictionPayload::class)->publicConfidenceScore($payload['prediction'], $daysAway);
            $payload['prediction']['days_away'] = $daysAway;
            $payload['prediction']['public_confidence_score'] = $publicConfidence;
        }
        $id = DB::table('nhl_game_prediction_revisions')->insertGetId([
            'nhl_game_id' => $gameId, 'run_id' => $runId, 'source' => $runId === null ? 'api' : 'admin',
            'fingerprint' => $fingerprint, 'is_canonical' => $canonical,
            'prediction_available' => ($payload['prediction_available'] ?? false) === true,
            'days_away' => $daysAway, 'public_confidence_score' => $publicConfidence,
            'request_inputs' => json_encode($input, JSON_THROW_ON_ERROR),
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'summary' => json_encode($this->metrics($payload), JSON_THROW_ON_ERROR), 'predicted_at' => $at,
        ]);
        if ($canonical && ($payload['prediction_available'] ?? false) === true) {
            app(NhlPublicGamePage::class)->refreshAfterCommit($gameId);
        }
        $payload['meta']['prediction_revision_id'] = $id;
        $payload['meta']['predicted_at'] = $at->toIso8601String();

        return $payload;
    }

    /** Restore a revision without recalculating or modifying its stored inputs.
     * @return array<string,mixed>
     */
    public function payload(object $revision): array
    {
        $payload = json_decode($revision->payload, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($payload['prediction'] ?? null)) {
            $payload['prediction']['days_away'] = isset($revision->days_away) ? (int) $revision->days_away : null;
            $payload['prediction']['public_confidence_score'] = isset($revision->public_confidence_score)
                ? (float) $revision->public_confidence_score : null;
        }
        $payload['meta']['prediction_revision_id'] = (int) $revision->id;
        $payload['meta']['predicted_at'] = \Illuminate\Support\Carbon::parse($revision->predicted_at)->toIso8601String();

        return $payload;
    }

    /** Summarize a stored calculation with separate confidence and moneyline probability.
     * @return array<string,mixed>
     */
    public function summary(object $revision): array
    {
        return [
            'id' => (int) $revision->id, 'source' => $revision->source, 'run_id' => $revision->run_id,
            'predicted_at' => \Illuminate\Support\Carbon::parse($revision->predicted_at)->toIso8601String(),
            'prediction_available' => (bool) $revision->prediction_available,
            'is_canonical' => (bool) $revision->is_canonical,
            ...json_decode($revision->summary, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /** Normalize small read-side metrics without loading full evidence in polling responses.
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function metrics(array $payload): array
    {
        $winner = data_get($payload, 'prediction.winner.team_abbrev');
        $moneyline = collect($payload['market_probabilities'] ?? [])->first(fn (array $market): bool =>
            ($market['market_key'] ?? null) === 'moneyline' && ($market['team_abbrev'] ?? null) === $winner);

        return [
            'away_goals' => data_get($payload, 'prediction.predicted_score.away'),
            'home_goals' => data_get($payload, 'prediction.predicted_score.home'),
            'total_goals' => data_get($payload, 'prediction.predicted_total_goals'),
            'totals_normalization' => data_get($payload, 'prediction.totals_normalization'),
            'winner' => $winner, 'win_probability' => $moneyline['probability'] ?? null,
            'confidence' => data_get($payload, 'prediction.confidence_score'),
            'days_away' => data_get($payload, 'prediction.days_away'),
            'public_confidence_score' => data_get($payload, 'prediction.public_confidence_score'),
            'qualified' => $payload['pick_qualified'] ?? null,
            'algorithm_version' => data_get($payload, 'inputs.algorithm_version'),
            'engine_id' => data_get($payload, 'inputs.engine_id'), 'stack_id' => data_get($payload, 'inputs.stack_id'),
            'reason' => $payload['reason'] ?? null,
        ];
    }

    /** Finish one work item and derive run status while serializing concurrent completion updates. */
    public function finish(int $itemId, string $status, ?int $revisionId = null, ?string $error = null): void
    {
        DB::transaction(function () use ($itemId, $status, $revisionId, $error): void {
            $item = DB::table('nhl_game_prediction_run_games')->find($itemId);
            if ($item === null) {
                return;
            }
            DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->lockForUpdate()->first();
            $item = DB::table('nhl_game_prediction_run_games')->find($itemId);
            if (in_array($item->status, ['completed', 'unavailable', 'failed'], true)) {
                return;
            }
            DB::table('nhl_game_prediction_run_games')->where('id', $itemId)->update([
                'status' => $status, 'revision_id' => $revisionId, 'last_error' => $error,
                'finished_at' => now(), 'updated_at' => now(),
            ]);
            $counts = DB::table('nhl_game_prediction_run_games')->where('run_id', $item->run_id)
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            if ((int) $counts->get('queued', 0) + (int) $counts->get('running', 0) === 0) {
                DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->update([
                    'status' => $counts->get('failed', 0) > 0 ? 'completed_with_errors' : 'completed',
                    'finished_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}
