<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlayerExternalIdentity;
use App\Services\ImportCapWagesPlayer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes CapWages detail data and materializes contracts for a linked identity.
 */
class RefreshCapWagesContractsForIdentityJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $identityId,
    ) {
    }

    /**
     * Keep only one refresh job per identity queued or running.
     */
    public int $uniqueFor = 900;

    public function handle(ImportCapWagesPlayer $importer): void
    {
        $identity = PlayerExternalIdentity::find($this->identityId);

        if (! $identity) {
            Log::debug('Skipping CapWages contract refresh for missing identity', [
                'identity_id' => $this->identityId,
            ]);

            return;
        }

        try {
            $importer->refreshContractsForLinkedIdentity($identity);
        } catch (RequestException $e) {
            if ($e->response->status() !== 403) {
                throw $e;
            }

            $usedCachedPayload = $importer->materializeCachedContractsForLinkedIdentity($identity);

            Log::warning('CapWages refresh blocked; cached payload used and refresh retry delayed', [
                'identity_id' => $this->identityId,
                'used_cached_payload' => $usedCachedPayload,
                'status' => $e->response->status(),
            ]);

            $this->release(900);
        }
    }

    /**
     * Unique key for queued refresh jobs.
     */
    public function uniqueId(): string
    {
        return (string) $this->identityId;
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return ['refresh-capwages-contracts', "identity:{$this->identityId}"];
    }
}
