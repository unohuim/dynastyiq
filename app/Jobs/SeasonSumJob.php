<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\SumNhlSeasonStats;

class SeasonSumJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var string */
    public string $seasonId;

    /** @var int */
    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [60, 120, 300, 600];

    /** @var int */
    public int $timeout = 600;

    public function __construct(string $seasonId)
    {
        $this->seasonId = $seasonId;
    }

    public function handle(SumNhlSeasonStats $service): void
    {
        try {
            $service->sum($this->seasonId);
        } catch (Throwable $e) {
            report($e);
            $this->fail($e);
        }
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'nhl-season-sum',
            "season-id:{$this->seasonId}",
        ];
    }
}
