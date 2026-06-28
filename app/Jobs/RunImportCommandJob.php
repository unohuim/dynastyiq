<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\PlayersAvailable;
use App\Models\ImportRun;
use App\Models\FantraxPlayer;
use App\Models\Player;
use App\Support\ImportBroadcast;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunImportCommandJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<string, mixed> */
    public array $options;

    public function __construct(
        public string $command,
        array $options = [],
        public string $source = '',
        public ?int $importRunId = null,
    ) {
        $this->options = $options;
    }

    public function handle(): void
    {
        $source = $this->source ?: $this->command;
        $broadcast = new ImportBroadcast($source, $this->batchId);
        $shouldDetectPlayers = $this->isNhlPlayersImport();
        $shouldDetectFantraxPlayers = $this->isFantraxPlayersImport();
        $playersExistedBefore = $shouldDetectPlayers ? Player::query()->exists() : false;
        $fantraxPlayersExistedBefore = $shouldDetectFantraxPlayers ? FantraxPlayer::query()->exists() : false;

        try {
            $broadcast->started();
            $options = $this->options;

            if ($this->importRunId !== null) {
                $options['--import-run-id'] = $this->importRunId;
            }

            $exitCode = Artisan::call($this->command, $options, $broadcast->output());

            if ($exitCode !== 0) {
                $message = "Import command {$this->command} failed with exit code {$exitCode}.";
                $this->importRun()?->markFailed($message);
                $broadcast->failed(new \RuntimeException($message));
                return;
            }

            if ($shouldDetectPlayers && ! $playersExistedBefore && Player::query()->exists()) {
                PlayersAvailable::dispatch('nhl', Player::query()->count());
            }

            if (
                $shouldDetectFantraxPlayers &&
                ! $fantraxPlayersExistedBefore &&
                FantraxPlayer::query()->exists()
            ) {
                PlayersAvailable::dispatch('fantrax', FantraxPlayer::query()->count());
            }
        } catch (Throwable $throwable) {
            $this->importRun()?->markFailed($throwable);
            $broadcast->failed($throwable);
            throw $throwable;
        }
    }

    private function isNhlPlayersImport(): bool
    {
        if ($this->command !== 'nhl:import') {
            return false;
        }

        $flag = $this->options['--players'] ?? $this->options['players'] ?? false;

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN) !== false || $flag === true;
    }

    private function isFantraxPlayersImport(): bool
    {
        if ($this->command !== 'fx:import') {
            return false;
        }

        $flag = $this->options['--players'] ?? $this->options['players'] ?? false;

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN) !== false || $flag === true;
    }

    private function importRun(): ?ImportRun
    {
        if ($this->importRunId === null) {
            return null;
        }

        return ImportRun::query()->find($this->importRunId);
    }
}
