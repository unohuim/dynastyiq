<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlGameImportEligibility;
use App\Services\NhlImportOrchestrator;
use App\Services\NhlValidationTroubleshootingExporter;
use App\Support\NhlImportStages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abstract base for NHL pipeline jobs.
 */
abstract class BaseNhlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public int $gameId;

    /** @var int */
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 120, 300, 600];

    /** @var int */
    public int $timeout = 600;

    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
    }

    /**
     * Return the canonical stage key.
     */
    abstract protected function stageName(): string;

    /**
     * Execute work for this stage and return processed item count.
     */
    abstract protected function perform(int $gameId): int;

    /**
     * Template handle: verify claimed work, perform it, then report.
     */
    public function handle(
        NhlImportOrchestrator $orchestrator,
        NhlGameImportEligibility $eligibility
    ): void
    {
        if (! $orchestrator->isRunning($this->gameId, $this->stageName())) {
            return;
        }

        try {
            if (
                $this->stageName() !== NhlImportStages::PBP
                && ! $eligibility->allowsStoredGame($this->gameId)
            ) {
                throw new \DomainException(sprintf(
                    'NHL game %d cannot run %s before PBP stores an allowed game type (%s).',
                    $this->gameId,
                    $this->stageName(),
                    $eligibility->allowedGameTypeList()
                ));
            }

            if ($orchestrator->isReprocessStage($this->gameId, $this->stageName())) {
                $this->clearStageDataForReprocess($this->gameId, $this->stageName());
            }

            $count = $this->perform($this->gameId);

            $orchestrator->onSuccess($this->gameId, $this->stageName(), [
                'items_count' => $count,
            ]);

            return;
        } catch (Throwable $e) {
            $this->exportTroubleshootingPayloads($e);

            app(NhlImportOrchestrator::class)->onFailure(
                $this->gameId,
                $this->stageName(),
                $e->getMessage(),
                $e->getCode()
            );

            $this->fail($e);
        }
    }

    /**
     * Export raw provider payloads for any stage stoppage without masking the original failure.
     */
    private function exportTroubleshootingPayloads(Throwable $throwable): void
    {
        try {
            app(NhlValidationTroubleshootingExporter::class)->exportRawProviderPayloads($this->gameId, [
                'stage' => $this->stageName(),
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
            ]);
        } catch (Throwable $exportThrowable) {
            Log::warning('Failed to export NHL import stoppage troubleshooting payloads.', [
                'game_id' => $this->gameId,
                'stage' => $this->stageName(),
                'message' => $exportThrowable->getMessage(),
            ]);
        }
    }

    /**
     * Queue/Horizon tags.
     *
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-import-' . $this->stageName(),
            "game-id:{$this->gameId}",
        ];
    }

    /**
     * Clear game-scoped rows owned by the stage before an explicit reprocess sync.
     */
    private function clearStageDataForReprocess(int $gameId, string $stage): void
    {
        match ($stage) {
            NhlImportStages::PBP => $this->clearFullGameSyncData($gameId),
            NhlImportStages::SUMMARY => $this->clearGameSummaries($gameId),
            NhlImportStages::BOXSCORE => $this->clearBoxscores($gameId),
            NhlImportStages::SHIFTS => $this->clearRawShifts($gameId),
            NhlImportStages::SHIFT_UNITS => $this->clearUnitShiftOutputs($gameId),
            NhlImportStages::CONNECT_EVENTS => $this->clearEventUnitLinks($gameId),
            NhlImportStages::HTML_PBP_VERIFY => $this->clearHtmlPbpVerification($gameId),
            NhlImportStages::SUM_GAME_UNITS => $this->clearUnitSummaryOutputs($gameId),
            default => null,
        };
    }

    /**
     * Clear the previous game-scoped import graph before reading current provider sources.
     */
    private function clearFullGameSyncData(int $gameId): void
    {
        $this->clearHtmlPbpVerification($gameId);
        $this->clearEventUnitLinks($gameId);
        $this->clearUnitSummaryOutputs($gameId);
        DB::table('nhl_unit_shifts')->where('nhl_game_id', $gameId)->delete();
        $this->clearRawShifts($gameId);
        $this->clearGameSummaries($gameId);
        $this->clearBoxscores($gameId);
        DB::table('play_by_plays')->where('nhl_game_id', $gameId)->delete();
    }

    private function clearEventUnitLinks(int $gameId): void
    {
        DB::table('event_unit_shifts')
            ->whereIn('unit_shift_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('nhl_unit_shifts')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();

        DB::table('event_unit_shifts')
            ->whereIn('event_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('play_by_plays')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();
    }

    private function clearUnitShiftOutputs(int $gameId): void
    {
        $this->clearEventUnitLinks($gameId);
        $this->clearUnitSummaryOutputs($gameId);
        DB::table('nhl_unit_shift_players')
            ->whereIn('unit_shift_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('nhl_unit_shifts')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();
        DB::table('nhl_unit_shifts')->where('nhl_game_id', $gameId)->delete();
    }

    private function clearHtmlPbpVerification(int $gameId): void
    {
        DB::table('nhl_unit_shift_players')
            ->whereIn('unit_shift_id', function ($query) use ($gameId): void {
                $query->select('id')
                    ->from('nhl_unit_shifts')
                    ->where('nhl_game_id', $gameId);
            })
            ->delete();

        DB::table('nhl_game_validations')
            ->where('nhl_game_id', $gameId)
            ->where('validation_type', \App\Models\NhlGameValidation::TYPE_PBP_HTML_REPORT)
            ->delete();
    }

    private function clearUnitSummaryOutputs(int $gameId): void
    {
        DB::table('nhl_player_game_strength_summaries')->where('nhl_game_id', $gameId)->delete();
        DB::table('nhl_unit_game_strength_summaries')->where('nhl_game_id', $gameId)->delete();
        DB::table('nhl_unit_game_summaries')->where('nhl_game_id', $gameId)->delete();
    }

    private function clearRawShifts(int $gameId): void
    {
        DB::table('nhl_shifts')->where('nhl_game_id', $gameId)->delete();
    }

    private function clearGameSummaries(int $gameId): void
    {
        DB::table('nhl_game_summaries')->where('nhl_game_id', $gameId)->delete();
    }

    private function clearBoxscores(int $gameId): void
    {
        DB::table('nhl_boxscores')->where('nhl_game_id', $gameId)->delete();
    }
}
