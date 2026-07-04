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
            [
                'key' => 'nhl-resolve-players',
                'label' => 'Resolve NHL Players',
                'command' => 'nhl:resolve',
                'options' => ['--players' => true, '--inline' => true],
            ],
            ['key' => 'fantrax', 'label' => 'Fantrax Players', 'command' => 'fx:import', 'options' => ['--players' => true]],
            [
                'key' => 'yahoo',
                'label' => 'Yahoo Players',
                'run_route' => 'admin.yahoo.players.import',
                'options' => [
                    'all_players' => true,
                    'page_size' => max(1, min((int) config('yahoo.fantasy.players_page_size', 25), 25)),
                ],
                'can_retry' => false,
            ],
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

        abort_unless(isset($source['command']), 409, 'This import source is not backed by an Artisan command.');

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
