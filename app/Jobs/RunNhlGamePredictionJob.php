<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGamePredictionHistory;
use App\Services\NhlGamePredictionPayload;
use App\Services\NhlGamePredictionResponseCache;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Calculate exactly one scheduled game through the ordinary default-stack pathway. */
final class RunNhlGamePredictionJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [10, 30];

    /** Capture a persisted work item, never a browser-supplied engine definition. */
    public function __construct(public readonly int $itemId)
    {
        $this->onQueue('projections');
    }

    /** Record success, unavailable inputs or an explicit failure without losing prior revisions. */
    public function handle(NhlGamePredictionPayload $builder, NhlGamePredictionResponseCache $cache, NhlGamePredictionHistory $history): void
    {
        $item = DB::table('nhl_game_prediction_run_games')->find($this->itemId);
        if ($item === null || in_array($item->status, ['completed', 'unavailable', 'failed'], true)) {
            return;
        }
        // A worker may die after committing a revision but before acknowledging its work item.
        $saved = DB::table('nhl_game_prediction_revisions')->where('run_id', $item->run_id)
            ->where('nhl_game_id', $item->nhl_game_id)->first();
        if ($saved !== null) {
            $history->finish($this->itemId, $saved->prediction_available ? 'completed' : 'unavailable', (int) $saved->id);
            return;
        }
        DB::table('nhl_game_prediction_runs')->where('id', $item->run_id)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        $claimed = DB::table('nhl_game_prediction_run_games')->where('id', $this->itemId)
            ->whereIn('status', ['queued', 'running'])->update([
                'status' => 'running', 'started_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }
        try {
            $input = ['nhl_game_id' => (int) $item->nhl_game_id];
            $payload = $cache->respond((int) $item->nhl_game_id, $input,
                fn (): array => $builder->build((int) $item->nhl_game_id, $input), true, (int) $item->run_id);
            $history->finish($this->itemId, ($payload['prediction_available'] ?? false) ? 'completed' : 'unavailable',
                (int) $payload['meta']['prediction_revision_id']);
        } catch (ValidationException $error) {
            $history->finish($this->itemId, 'failed', error: $error->getMessage());
        } catch (Throwable $error) {
            DB::table('nhl_game_prediction_run_games')->where('id', $this->itemId)
                ->whereNotIn('status', ['completed', 'unavailable', 'failed'])
                ->update(['status' => 'queued', 'last_error' => mb_substr($error->getMessage(), 0, 2000), 'updated_at' => now()]);
            throw $error;
        }
    }

    /** Persist exhausted retries, including queue timeouts, for the admin status view. */
    public function failed(?Throwable $error): void
    {
        $item = DB::table('nhl_game_prediction_run_games')->find($this->itemId);
        $saved = $item === null ? null : DB::table('nhl_game_prediction_revisions')->where('run_id', $item->run_id)
            ->where('nhl_game_id', $item->nhl_game_id)->first();
        if ($saved !== null) {
            app(NhlGamePredictionHistory::class)->finish($this->itemId, $saved->prediction_available ? 'completed' : 'unavailable', (int) $saved->id);
            return;
        }
        app(NhlGamePredictionHistory::class)->finish($this->itemId, 'failed', error: mb_substr($error?->getMessage() ?? 'Prediction job failed.', 0, 2000));
    }
}
