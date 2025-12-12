<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use App\Services\ImportCapWagesPlayer;
use App\Events\ImportStreamEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Exceptions\PlayerNotFoundException;
use App\Exceptions\CapWagesPlayerNotFoundException;

class ImportCapWagesPlayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected string $slug;
    protected bool $all;

    public const TAG_IMPORT = 'import-capwages-player';

    /**
     * @param string $slug
     * @param bool   $all  When true, preserve original behavior; when false, skip imports if Player missing.
     */
    public function __construct(string $slug, bool $all = true)
    {
        $this->slug = $slug;
        $this->all  = $all;
    }

    public function handle(): void
    {
        ImportStreamEvent::dispatch('capwages', "Importing CapWages player {$this->slug}", 'started');

        try {
            (new ImportCapWagesPlayer())->syncBySlug($this->slug, $this->all);
        } catch (PlayerNotFoundException $e) {
            Log::warning("from job class: Player not found in players DB: {$this->slug}");

            if ($this->all) {
                // Service already dispatched NHL import; retry later.
                $this->release(10);
            }
            // all=false should not reach here, but if it does, just exit quietly.
            return;
        } catch (CapWagesPlayerNotFoundException $e) {
            Log::warning("Player not found on CapWages API: {$this->slug}");
            throw $e; // trigger retry/backoff per queue config
        } catch (\Exception $e) {
            Log::error("Unexpected error importing player {$this->slug}: " . $e->getMessage());
            throw $e;
        }

        ImportStreamEvent::dispatch('capwages', "Finished importing CapWages player {$this->slug}", 'finished');
    }

    public function tags(): array
    {
        return [self::TAG_IMPORT, "player-slug:{$this->slug}", "all:" . ($this->all ? 'true' : 'false')];
    }
}
