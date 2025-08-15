<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NhlDiscoverGames;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NhlDiscoverDayJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TAG_DISCOVERY_DAY = 'nhl-discovery-day';

    public string $date; // YYYY-MM-DD
    public int $timeout = 60;
    public int $tries   = 1;

    public function __construct(string $date)
    {
        $this->date = $date;
    }

    public function handle(NhlDiscoverGames $service): void
    {
        try {
            \Log::warning('Start discovering the day:', ['date'=>$this->date]);
            $service->discoverDay($this->date);
        } catch (RequestException $e) {
            $status = $e->response?->status();

            if ($status === 404) {
                return; // no schedule for this date
            }

            if (in_array($status, [400, 401, 403, 422], true)) {
                \Log::warning('NHL dailyscores client error', [
                    'date' => $this->date,
                    'status' => $status,
                ]);
                return; // don’t retry
            }

            if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
                $retryAfter = (int) ($e->response?->header('Retry-After') ?? 60);
                $this->release(max(10, min(300, $retryAfter)));
                return; // transient — retry later
            }

            throw $e; // let the job fail for anything else
        }
    }

    public function tags(): array
    {
        return [self::TAG_DISCOVERY_DAY, "date:{$this->date}"];
    }
}
