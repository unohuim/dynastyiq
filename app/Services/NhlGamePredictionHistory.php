<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Append immutable default-path calculations and expose compact admin comparison rows. */
final class NhlGamePredictionHistory
{
    /** Persist once per manual run/game; independent API recalculations always append.
     * @param array<string,mixed> $input
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function record(int $gameId, array $input, string $fingerprint, array $evidence, array $payload, ?int $runId = null): array
    {
        $existing = $runId === null ? null : DB::table('nhl_game_prediction_revisions')
            ->where('run_id', $runId)->where('nhl_game_id', $gameId)->first();
        if ($existing !== null) {
            return $this->payload($existing);
        }
        // Market selection changes pricing output, not the core matchup. Explicit roster/model overrides remain separate variants.
        $canonical = array_diff(array_keys($input), ['nhl_game_id', 'markets', 'puckline', 'puckline_spreads', 'total', 'total_lines']) === [];
        $at = now();
        $id = DB::table('nhl_game_prediction_revisions')->insertGetId([
            'nhl_game_id' => $gameId, 'run_id' => $runId, 'source' => $runId === null ? 'api' : 'admin',
            'fingerprint' => $fingerprint, 'is_canonical' => $canonical,
            'prediction_available' => ($payload['prediction_available'] ?? false) === true,
            'request_inputs' => json_encode($input, JSON_THROW_ON_ERROR),
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'summary' => json_encode($this->metrics($payload), JSON_THROW_ON_ERROR), 'predicted_at' => $at,
        ]);
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
            'winner' => $winner, 'win_probability' => $moneyline['probability'] ?? null,
            'confidence' => data_get($payload, 'prediction.confidence_score'),
            'qualified' => $payload['pick_qualified'] ?? null,
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
