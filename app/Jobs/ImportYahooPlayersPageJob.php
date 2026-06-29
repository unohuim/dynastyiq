<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ImportStreamEvent;
use App\Events\PlayersAvailable;
use App\Models\ImportRun;
use App\Models\YahooFantasyConnection;
use App\Models\YahooPlayer;
use App\Services\YahooFantasyPlayerImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports one Yahoo Fantasy player collection page and dispatches the next page.
 */
class ImportYahooPlayersPageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $connectionId,
        public int $importRunId,
        public int $start = 0,
        public int $pageSize = 25,
        public ?string $gameKey = null,
    ) {
        $this->start = max(0, $this->start);
        $this->pageSize = max(1, min($this->pageSize, 25));
    }

    public function handle(YahooFantasyPlayerImporter $importer): void
    {
        $connection = YahooFantasyConnection::query()->find($this->connectionId);
        $importRun = $this->importRun();

        if (! $connection || ! $importRun) {
            return;
        }

        try {
            $gameKey = $this->gameKey ?? $importer->gameKey($connection);

            if ($this->start === 0) {
                ImportStreamEvent::dispatch('yahoo', 'Importing Yahoo players', 'started');
            }

            $page = $importer->importPage($connection, $gameKey, $this->start, $this->pageSize);
            $this->recordProgress($page['imported'], $page['skipped']);

            ImportStreamEvent::dispatch(
                'yahoo',
                "Processed Yahoo players through offset {$this->start}",
                'progress',
            );

            if ($page['page_count'] < $this->pageSize) {
                $importRun->refresh()->markCompleted();
                ImportStreamEvent::dispatch('yahoo', 'Yahoo player import completed', 'finished');
                PlayersAvailable::dispatch('yahoo', YahooPlayer::query()->count());

                return;
            }

            self::dispatch(
                $this->connectionId,
                $this->importRunId,
                $this->start + $this->pageSize,
                $this->pageSize,
                $gameKey,
            );
        } catch (Throwable $throwable) {
            ImportRun::query()
                ->whereKey($this->importRunId)
                ->increment('failed_records');
            $this->importRun()?->markFailed($throwable);
            ImportStreamEvent::dispatch('yahoo', $throwable->getMessage(), 'failed');

            throw $throwable;
        }
    }

    public function failed(Throwable $throwable): void
    {
        $this->importRun()?->markFailed($throwable);
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['import-yahoo-players', "start:{$this->start}"];
    }

    private function importRun(): ?ImportRun
    {
        return ImportRun::query()->find($this->importRunId);
    }

    private function recordProgress(int $imported, int $skipped): void
    {
        if ($imported < 1 && $skipped < 1) {
            return;
        }

        ImportRun::query()
            ->whereKey($this->importRunId)
            ->update([
                'processed_records' => DB::raw('COALESCE(processed_records, 0) + '.($imported + $skipped)),
                'successful_records' => DB::raw('COALESCE(successful_records, 0) + '.$imported),
                'skipped_records' => DB::raw('COALESCE(skipped_records, 0) + '.$skipped),
                'progress_label' => 'Importing Yahoo players',
            ]);
    }
}
