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
            [
                'key' => 'nhl',
                'label' => 'NHL Players',
                'group' => 'player',
                'command' => 'nhl:import',
                'options' => ['--players' => true],
                'actions' => [
                    [
                        'key' => 'full',
                        'label' => 'Full Run',
                        'command' => 'nhl:import',
                        'options' => ['--players' => true],
                    ],
                    [
                        'key' => 'update-db',
                        'label' => 'Update DB',
                        'command' => 'nhl:refresh-player-metadata',
                        'options' => [],
                    ],
                ],
            ],
            [
                'key' => 'nhl-resolve-players',
                'label' => 'Resolve NHL Players',
                'group' => 'player',
                'command' => 'nhl:resolve',
                'options' => ['--players' => true, '--inline' => true],
            ],
            ['key' => 'fantrax', 'label' => 'Fantrax Players', 'group' => 'player', 'command' => 'fx:import', 'options' => ['--players' => true]],
            [
                'key' => 'yahoo',
                'label' => 'Yahoo Players',
                'group' => 'player',
                'run_route' => 'admin.yahoo.players.import',
                'options' => [
                    'all_players' => true,
                    'page_size' => max(1, min((int) config('yahoo.fantasy.players_page_size', 25), 25)),
                ],
                'can_retry' => false,
            ],
            ['key' => 'contracts', 'label' => 'Contracts', 'group' => 'player', 'command' => 'cap:import', 'options' => ['--per-page' => 100, '--all' => true]],
            [
                'key' => 'fantrax-category-definitions',
                'label' => 'Fantrax Categories Definitions',
                'group' => 'platform',
                'command' => 'fantrax:import-category-definitions',
                'options' => ['--path' => 'docs/import-templates/fantrax_category_alignment.csv'],
            ],
            [
                'key' => 'fantrax-league-category-backfill',
                'label' => 'Backfill Fantrax League Categories',
                'group' => 'platform',
                'command' => 'platform-leagues:backfill-scoring-categories',
                'options' => ['--platform' => 'fantrax'],
            ],
            [
                'key' => 'nhl-empty-games',
                'label' => 'Empty NHL Game Imports',
                'group' => 'game',
                'command' => 'nhl:empty',
                'options' => ['--games' => true],
            ],
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

    /**
     * Dispatch an admin import source.
     */
    public function dispatch(string $key, ?string $action = null): Batch
    {
        $source = $this->sourceForAction($key, $action);

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

    /**
     * @return array<string,mixed>
     */
    private function sourceForAction(string $key, ?string $action): array
    {
        $source = $this->source($key);

        if ($action === null || $action === '' || $action === 'full') {
            return $source;
        }

        $actions = collect($source['actions'] ?? []);
        $selected = $actions->firstWhere('key', $action);

        abort_unless(is_array($selected), 404);

        $merged = array_merge($source, $selected);
        $merged['key'] = $source['key'];
        $merged['action'] = $selected['key'];

        return $merged;
    }
}
