<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NhlGameImportStatusUpdated;
use App\Models\NhlGameImportRun;
use App\Services\NhlDiscoverGames;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class NhlDiscoverDayJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TAG_DISCOVERY_DAY = 'nhl-discovery-day';

    public string $date; // YYYY-MM-DD
    public ?int $runId;
    public int $timeout = 60;
    public int $tries   = 1;

    public function __construct(string $date, ?int $runId = null)
    {
        $this->date = $date;
        $this->runId = $runId;
    }

    public function handle(NhlDiscoverGames $service): void
    {
        try {
            \Log::warning('Start discovering the day:', ['date'=>$this->date]);
            $service->discoverDay($this->date, $this->runId);
            $this->markDiscoveryDateResolved();
        } catch (RequestException $e) {
            $status = $e->response?->status();

            if ($status === 404) {
                $this->markDiscoveryDateResolved();
                return; // no schedule for this date
            }

            if (in_array($status, [400, 401, 403, 422], true)) {
                \Log::warning('NHL dailyscores client error', [
                    'date' => $this->date,
                    'status' => $status,
                ]);
                $this->markDiscoveryDateResolved();
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
        return [self::TAG_DISCOVERY_DAY, "date:{$this->date}", 'run-id:' . ($this->runId ?? 'none')];
    }

    /**
     * Mark one date in a discovery run as resolved.
     */
    private function markDiscoveryDateResolved(): void
    {
        if ($this->runId === null) {
            return;
        }

        $completed = DB::transaction(function (): bool {
            $run = NhlGameImportRun::query()
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->first();

            if (! $run || $run->action !== NhlGameImportRun::ACTION_DISCOVER) {
                return false;
            }

            $payload = $run->payload ?? [];
            $dates = collect($payload['discovery_completed_dates'] ?? [])
                ->map(fn ($date): string => (string) $date)
                ->push($this->date)
                ->unique()
                ->values()
                ->all();

            $payload['discovery_completed_dates'] = $dates;

            $updates = [
                'status' => NhlGameImportRun::STATUS_RUNNING,
                'payload' => $payload,
                'updated_at' => now(),
            ];

            if (count($dates) >= (int) $run->date_count) {
                $payload['completed_at'] = now()->toIso8601String();
                $updates['status'] = NhlGameImportRun::STATUS_COMPLETED;
                $updates['payload'] = $payload;
            }

            $run->forceFill($updates)->save();

            return $updates['status'] === NhlGameImportRun::STATUS_COMPLETED;
        });

        broadcast(new NhlGameImportStatusUpdated(
            $completed ? 'discovery-completed' : 'discovery-day-completed',
            $this->runId
        ));
    }
}
