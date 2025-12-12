<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ImportCapWages;
use App\Jobs\ImportCapWagesPlayerJob;
use App\Events\ImportStreamEvent;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes one page of CapWages players and dispatches a per-player import job.
 */
class ImportCapWagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    private int $page;
    private int $perPage;
    private bool $all;

    public function __construct(int $page, int $perPage = 100, bool $all = true)
    {
        $this->page    = max(1, $page);
        $this->perPage = max(1, $perPage);
        $this->all     = $all;
    }

    public function handle(): void
    {
        ImportStreamEvent::dispatch('capwages', "Fetching CapWages page {$this->page}", 'started');

        try {
            $service  = new ImportCapWages();
            $response = $service->fetchPlayersPage($this->page, $this->perPage);

            foreach ($response['data'] ?? [] as $playerInfo) {
                $slug = $playerInfo['slug'] ?? null;
                if ($slug) {
                    ImportStreamEvent::dispatch('capwages', "Importing CapWages player {$slug}", 'started');
                    ImportCapWagesPlayerJob::dispatch($slug, $this->all);
                }
            }
        } catch (\Throwable $e) {
            Log::error('ImportCapWagesJob page fetch failed', [
                'page'    => $this->page,
                'perPage' => $this->perPage,
                'all'     => $this->all,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function tags(): array
    {
        return ['import-capwages', "page:{$this->page}", "perPage:{$this->perPage}", "all:" . ($this->all ? 'true' : 'false')];
    }
}
