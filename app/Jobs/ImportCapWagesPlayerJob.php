<?php

namespace App\Jobs;




use Illuminate\Bus\Batchable;
use App\Services\ImportCapWagesPlayer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Exceptions\PlayerNotFoundException;


class ImportCapWagesPlayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected string $slug;
    public const TAG_IMPORT = 'import-capwages-player';

    /**
     * Create a new job instance.
     */
    public function __construct(string $slug)
    {
        $this->slug = $slug;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            (new ImportCapWagesPlayer())->syncBySlug($this->slug);
        } catch (PlayerNotFoundException $e) {
            Log::warning("from job class: Player not found in players DB: {$this->slug}");

            //service class already dispatched an nhl player import job, so just delay release for back on queue.
             $this->release(10);
             return;
        } catch (CapWagesPlayerNotFoundException $e) {
            Log::warning("Player not found on CapWages API: {$slug}");
            // Optionally delay retry or exit gracefully
            throw $e; // to trigger retry with backoff
        } catch (\Exception $e) {
            Log::error("Unexpected error importing player {$this->slug}: " . $e->getMessage());
            throw $e; // Fail job to trigger retry or failure
        }
    }


    public function tags(): array
    {
        return [self::TAG_IMPORT, "player-slug:{$this->slug}"];
    }

    
}
