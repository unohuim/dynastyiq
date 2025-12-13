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
        public string $source = ''
    ) {
        $this->options = $options;
    }

    public function handle(): void
    {
        $broadcast = new ImportBroadcast($this->source ?: $this->command, $this->batchId);
        $shouldDetectPlayers = $this->isNhlPlayersImport();
        $shouldDetectFantraxPlayers = $this->isFantraxPlayersImport();
        $playersExistedBefore = $shouldDetectPlayers ? Player::query()->exists() : false;
        $fantraxPlayersExistedBefore = $shouldDetectFantraxPlayers ? FantraxPlayer::query()->exists() : false;

        try {
            $broadcast->started();
            Artisan::call($this->command, $this->options, $broadcast->output());

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

            ImportRun::create([
                'source' => $this->source ?: $this->command,
                'ran_at' => now(),
                'batch_id' => $this->batchId,
            ]);

            $broadcast->finished();
        } catch (Throwable $throwable) {
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
}
