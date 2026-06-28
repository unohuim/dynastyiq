<?php

namespace App\Services;

use App\Jobs\RunImportCommandJob;
use App\Models\ImportRun;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Throwable;

class AdminImports
{
    /**
     * @return \Illuminate\Support\Collection<int, array<string,mixed>>
     */
    public function sources()
    {
        return collect([
            ['key' => 'nhl', 'label' => 'NHL Players', 'command' => 'nhl:import', 'options' => ['--players' => true]],
            ['key' => 'fantrax', 'label' => 'Fantrax Players', 'command' => 'fx:import', 'options' => ['--players' => true]],
            ['key' => 'contracts', 'label' => 'Contracts', 'command' => 'cap:import', 'options' => ['--per-page' => 100, '--all' => true]],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function source(string $key): array
    {
        $source = $this->sources()->keyBy('key')->get($key);

        abort_unless($source, 404);

        return $source;
    }

    public function dispatch(string $key): Batch
    {
        $source = $this->source($key);

        $startedAt = now();
        $importRun = ImportRun::create([
            'source' => $source['key'],
            'status' => 'working',
            'command' => $source['command'],
            'options' => $source['options'] ?? [],
            'ran_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        try {
            $batch = Bus::batch([
                new RunImportCommandJob($source['command'], $source['options'] ?? [], $source['key'], $importRun->id),
            ])->name("manual-{$source['key']}-import")
                ->allowFailures()
                ->onQueue('default')
                ->dispatch();
        } catch (Throwable $throwable) {
            $importRun->markFailed($throwable);
            throw $throwable;
        }

        $importRun->update(['batch_id' => $batch->id]);

        return $batch;
    }
}
