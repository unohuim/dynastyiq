<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PlayerNotFoundException;
use App\Events\ImportStreamEvent;
use App\Models\ImportRun;
use App\Services\ImportCapWages;
use App\Services\ImportCapWagesPlayer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes one page of CapWages players and schedules the next page.
 */
class ImportCapWagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    private const PAGE_FORBIDDEN_BACKOFF_SECONDS = [15, 45, 120];

    private int $page;
    private int $perPage;
    private bool $all;

    public function __construct(
        int $page,
        int $perPage = 100,
        bool $all = true,
        private ?int $importRunId = null,
        private ?int $totalPages = null,
    ) {
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
            $this->totalPages ??= (int) ($response['meta']['pagination']['totalPages'] ?? $this->page);
            $this->syncProgressTotal($response);

            foreach (array_values($response['data'] ?? []) as $playerInfo) {
                $slug = $playerInfo['slug'] ?? null;
                if ($slug) {
                    ImportStreamEvent::dispatch('capwages', "Importing CapWages player {$slug}", 'started');

                    $this->importPlayerInline($slug);
                }
            }
        } catch (RequestException $e) {
            if ($this->isForbidden($e)) {
                $this->handleForbiddenPageFetch($e);
                return;
            }

            $this->failImportRun($e);
            throw $e;
        } catch (\Throwable $e) {
            $this->failImportRun($e);
            Log::error('ImportCapWagesJob page fetch failed', [
                'page'    => $this->page,
                'perPage' => $this->perPage,
                'all'     => $this->all,
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->dispatchNextPageOrComplete();
    }

    public function tags(): array
    {
        return ['import-capwages', "page:{$this->page}", "perPage:{$this->perPage}", "all:" . ($this->all ? 'true' : 'false')];
    }

    private function importPlayerInline(string $slug): void
    {
        try {
            $imported = (new ImportCapWagesPlayer())->syncBySlug($slug, $this->all);
            $this->recordProcessedRecord($imported ? 'successful' : 'skipped');
        } catch (RequestException $e) {
            if ($this->isForbidden($e)) {
                $this->recordBlockedSlug($slug, $e);
                return;
            }

            if ($this->isServerError($e)) {
                $this->recordErroredSlug($slug, $e);
                return;
            }

            throw $e;
        } catch (ConnectionException $e) {
            $this->recordConnectionFailedSlug($slug, $e);
        } catch (PlayerNotFoundException $e) {
            Log::warning("from page job: Player not found in players DB: {$slug}");
            $this->recordProcessedRecord('skipped');
        }
    }

    private function handleForbiddenPageFetch(RequestException $e): void
    {
        $attempt = $this->attempts();
        $delay = self::PAGE_FORBIDDEN_BACKOFF_SECONDS[$attempt - 1] ?? null;

        if ($delay !== null) {
            Log::warning('CapWages page fetch blocked; retrying with backoff', [
                'page' => $this->page,
                'perPage' => $this->perPage,
                'attempt' => $attempt,
                'delay_seconds' => $delay,
                'status' => $e->response?->status(),
            ]);

            $this->release($delay);
            return;
        }

        Log::warning('CapWages page fetch blocked after max attempts; skipping page', [
            'page' => $this->page,
            'perPage' => $this->perPage,
            'attempt' => $attempt,
            'status' => $e->response?->status(),
        ]);

        $this->appendImportRunMeta('blocked_pages', [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'status' => $e->response?->status(),
            'attempts' => $attempt,
        ]);

        $this->dispatchNextPageOrComplete();
    }

    private function dispatchNextPageOrComplete(): void
    {
        $totalPages = $this->totalPages ?? $this->page;

        if ($this->page >= $totalPages) {
            $createdTransactions = (new ImportCapWagesPlayer())->reconcileMissingContractTransactions();
            ImportStreamEvent::dispatch(
                'capwages',
                "Reconciled {$createdTransactions} missing CapWages contract transaction(s)",
                'completed',
            );
            ImportRun::query()->find($this->importRunId)?->markCompleted();
            return;
        }

        self::dispatch($this->page + 1, $this->perPage, $this->all, $this->importRunId, $totalPages);
    }

    private function recordBlockedSlug(string $slug, RequestException $e): void
    {
        $this->recordProcessedRecord('failed');

        Log::warning('CapWages player detail blocked; skipping slug', [
            'page' => $this->page,
            'slug' => $slug,
            'status' => $e->response?->status(),
        ]);

        $this->appendImportRunMeta('blocked_slugs', [
            'page' => $this->page,
            'slug' => $slug,
            'status' => $e->response?->status(),
        ]);
    }

    private function recordErroredSlug(string $slug, RequestException $e): void
    {
        $this->recordProcessedRecord('failed');

        Log::warning('CapWages player detail failed with provider server error; skipping slug', [
            'page' => $this->page,
            'slug' => $slug,
            'status' => $e->response?->status(),
        ]);

        $this->appendImportRunMeta('errored_slugs', [
            'page' => $this->page,
            'slug' => $slug,
            'status' => $e->response?->status(),
        ]);
    }

    private function recordConnectionFailedSlug(string $slug, ConnectionException $e): void
    {
        $this->recordProcessedRecord('failed');

        Log::warning('CapWages player detail connection failed; skipping slug', [
            'page' => $this->page,
            'slug' => $slug,
            'error' => $e->getMessage(),
        ]);

        $this->appendImportRunMeta('errored_slugs', [
            'page' => $this->page,
            'slug' => $slug,
            'status' => null,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function syncProgressTotal(array $response): void
    {
        $totalRecords = $response['meta']['pagination']['total'] ?? null;

        if ($totalRecords === null || $this->importRunId === null) {
            return;
        }

        ImportRun::query()
            ->find($this->importRunId)
            ?->setProgressTotal((int) $totalRecords, 'CapWages player records');
    }

    private function recordProcessedRecord(string $result): void
    {
        if ($this->importRunId === null) {
            return;
        }

        ImportRun::query()
            ->find($this->importRunId)
            ?->recordProcessed($result);
    }

    private function appendImportRunMeta(string $key, array $entry): void
    {
        if ($this->importRunId === null) {
            return;
        }

        $importRun = ImportRun::query()->find($this->importRunId);

        if ($importRun === null) {
            return;
        }

        $meta = $importRun->meta ?? [];
        $meta[$key] = array_values(array_merge($meta[$key] ?? [], [$entry]));

        $importRun->update(['meta' => $meta]);
    }

    private function failImportRun(\Throwable $e): void
    {
        ImportRun::query()->find($this->importRunId)?->markFailed($e);
    }

    private function isForbidden(RequestException $e): bool
    {
        return $e->response?->status() === 403;
    }

    private function isServerError(RequestException $e): bool
    {
        $status = $e->response?->status();

        return $status !== null && $status >= 500 && $status < 600;
    }
}
