<?php

namespace App\Services;

use App\Jobs\RunImportCommandJob;
use Illuminate\Bus\PendingBatch;
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

    public function dispatch(string $key): PendingBatch
    {
        $source = $this->sources()->firstWhere('key', $key);
        abort_unless($source, 404);

        return Bus::batch([
            new RunImportCommandJob($source['command'], $source['options'] ?? [], $source['key']),
        ])->name("manual-{$key}-import")
            ->allowFailures()
            ->onQueue('default')
            ->dispatch();
    }
}
