<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Bus\Batchable;
use App\Classes\SumGame;


class SumGameJob implements ShouldQueue
{
    use Queueable, Batchable;


    protected $sumGame;
    protected $game;


    /**
     * Create a new job instance.
     */
    public function __construct($game)
    {
        $this->game = $game;
        $this->sumGame = new SumGame();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->sumGame->sum($this->game);
    }
}
