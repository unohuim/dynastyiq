<?php

namespace App\Services;

use App\Jobs\RunImportCommandJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

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

    public function dispatch(string $key): Batch
    {
        $sources = $this->sources()->keyBy('key');

        abort_unless($sources->has('nhl') && $sources->has('fantrax') && $sources->has('contracts'), 404);

        $players = Bus::batch([
            new RunImportCommandJob($sources['nhl']['command'], $sources['nhl']['options'] ?? [], $sources['nhl']['key']),
        ])->name('manual-nhl-import')
            ->allowFailures()
            ->onQueue('default')
            ->then(function (Batch $batch) use ($sources) {
                Bus::batch([
                    new RunImportCommandJob($sources['fantrax']['command'], $sources['fantrax']['options'] ?? [], $sources['fantrax']['key']),
                ])->name('manual-fantrax-import')
                    ->allowFailures()
                    ->onQueue('default')
                    ->then(function (Batch $fantraxBatch) use ($sources) {
                        Bus::batch([
                            new RunImportCommandJob($sources['contracts']['command'], $sources['contracts']['options'] ?? [], $sources['contracts']['key']),
                        ])->name('manual-contracts-import')
                            ->allowFailures()
                            ->onQueue('default')
                            ->dispatch();
                    })
                    ->dispatch();
            })
            ->dispatch();

        return $players;
    }
}
