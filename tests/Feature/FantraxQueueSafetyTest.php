<?php

declare(strict_types=1);

use App\Jobs\ImportFantraxPlayersChunkJob;
use App\Jobs\ImportFantraxPlayersJob;
use App\Jobs\RunImportCommandJob;
use App\Jobs\SyncFantraxDraftStateJob;
use App\Jobs\SyncFantraxLeagueJob;
use App\Models\ImportRun;
use App\Services\AdminImports;
use App\Services\ImportFantraxPlayers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-13 12:00:00 UTC'));
    Queue::fake();
    \Illuminate\Support\Facades\Event::fake([\App\Events\ImportStreamEvent::class]);
    $this->run = ImportRun::create([
        'source' => 'fantrax',
        'status' => 'working',
        'ran_at' => now(),
        'started_at' => now(),
        'meta' => ['unrelated' => 'preserved'],
    ]);
});

afterEach(function (): void {
    $this->travelBack();
});

it('persists fetched entries once and queues bounded work', function (): void {
    $importer = Mockery::mock(ImportFantraxPlayers::class)->makePartial();
    $importer->shouldReceive('fetchEntries')->once()->andReturn(array_fill(0, 201, ['fantraxId' => 'player']));

    $importer->prepare($this->run->id);
    $importer->prepare($this->run->id);

    expect($this->run->fresh()->total_records)->toBe(201)
        ->and($this->run->fresh()->meta['fantrax_players']['entries'])->toHaveCount(201)
        ->and($this->run->fresh()->meta['unrelated'])->toBe('preserved');
    Queue::assertPushed(ImportFantraxPlayersChunkJob::class, 2);
    Queue::assertNotPushed(\App\Jobs\ImportFantraxPlayerJob::class);
});

it('processes at most one hundred entries and checkpoints its progress', function (): void {
    $this->run->update(['meta' => ['fantrax_players' => ['entries' => array_fill(0, 201, ['fantraxId' => 'player']), 'offset' => 0]]]);
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldReceive('importChunk')->once()->withArgs(fn (array $entries, int $id): bool => count($entries) === 100 && $id === $this->run->id);

    (new ImportFantraxPlayersChunkJob('persisted', 0, 201, $this->run->id))->handle($importer);

    expect($this->run->fresh()->meta['fantrax_players']['offset'])->toBe(100);
    Queue::assertPushed(ImportFantraxPlayersChunkJob::class, 1);
});

it('does not repeat player writes for a delivered checkpoint', function (): void {
    $this->run->update(['meta' => ['fantrax_players' => ['entries' => array_fill(0, 201, []), 'offset' => 100]]]);
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldNotReceive('importChunk');

    (new ImportFantraxPlayersChunkJob('persisted', 0, 201, $this->run->id))->handle($importer);

    expect($this->run->fresh()->meta['fantrax_players']['offset'])->toBe(100);
    Queue::assertPushed(ImportFantraxPlayersChunkJob::class, 1);
});

it('keeps input beyond a day of queue delay and releases it after completion', function (): void {
    $this->run->update(['meta' => ['unrelated' => 'preserved', 'fantrax_players' => ['entries' => [['fantraxId' => 'player']], 'offset' => 0]]]);
    $this->travel(2)->days();
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldReceive('importChunk')->once()->with([['fantraxId' => 'player']], $this->run->id);

    (new ImportFantraxPlayersChunkJob('persisted', 0, 1, $this->run->id))->handle($importer);

    expect($this->run->fresh()->status)->toBe('completed')
        ->and($this->run->fresh()->meta)->toBe(['unrelated' => 'preserved']);
    Queue::assertNothingPushed();
});

it('does not repeat completed imports', function (): void {
    $this->run->update(['status' => 'completed']);
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldNotReceive('importChunk');
    (new ImportFantraxPlayersChunkJob('persisted', 0, 1, $this->run->id))->handle($importer);
    Queue::assertNothingPushed();
});

it('retains the snapshot on failure for diagnosis', function (): void {
    $this->run->update(['meta' => ['fantrax_players' => ['entries' => [['fantraxId' => 'player']], 'offset' => 0]]]);
    (new ImportFantraxPlayersChunkJob('persisted', 0, 1, $this->run->id))->failed(new RuntimeException('Example failure'));
    expect($this->run->fresh()->status)->toBe('failed')
        ->and($this->run->fresh()->meta['fantrax_players']['entries'])->toHaveCount(1);
});

it('retains compatibility with already queued cache-backed chunks', function (): void {
    Cache::put('test-fantrax-legacy', [['fantraxId' => 'player']], 3600);
    \Illuminate\Support\Facades\Event::fake();
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldReceive('importChunk')->once()->with([['fantraxId' => 'player']], $this->run->id);
    (new ImportFantraxPlayersChunkJob('test-fantrax-legacy', 0, 1, $this->run->id))->handle($importer);
    expect($this->run->fresh()->status)->toBe('completed');
});

it('routes legacy no-run jobs through admin admission', function (): void {
    $this->run->delete();
    (new ImportFantraxPlayersJob())->handle();
    (new ImportFantraxPlayersJob())->handle();
    expect(ImportRun::where('source', 'fantrax')->count())->toBe(1);
    Queue::assertPushed(RunImportCommandJob::class, 1);
    Queue::assertNotPushed(\App\Jobs\ImportFantraxPlayerJob::class);
});

it('coalesces manual and command admission to the same active batch', function (): void {
    $this->run->delete();
    $batch = app(AdminImports::class)->dispatch('fantrax');
    $this->artisan('fx:import', ['--players' => true])->assertSuccessful();
    expect(ImportRun::where('source', 'fantrax')->count())->toBe(1)
        ->and(ImportRun::where('source', 'fantrax')->first()->batch_id)->toBe($batch->id);
    Queue::assertPushed(RunImportCommandJob::class, 1);
});

it('deduplicates pending background league syncs but preserves separate user requests', function (): void {
    SyncFantraxLeagueJob::dispatch(11);
    SyncFantraxLeagueJob::dispatch(11);
    SyncFantraxLeagueJob::dispatch(12);
    SyncFantraxLeagueJob::dispatch(11, 42);
    Queue::assertPushed(SyncFantraxLeagueJob::class, 3);
});

it('deduplicates draft polls without losing distinct supplied payloads', function (): void {
    SyncFantraxDraftStateJob::dispatch(11);
    SyncFantraxDraftStateJob::dispatch(11);
    SyncFantraxDraftStateJob::dispatch(11, ['pick' => 1]);
    SyncFantraxDraftStateJob::dispatch(11, ['pick' => 2]);
    Queue::assertPushed(SyncFantraxDraftStateJob::class, 3);
});

it('rolls back progress and checkpoint when a chunk throws', function (): void {
    $this->run->update(['meta' => ['fantrax_players' => ['entries' => [['fantraxId' => 'player']], 'offset' => 0]]]);
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldReceive('importChunk')->once()->andReturnUsing(function (): void {
        $this->run->recordProcessed();
        throw new RuntimeException('Interrupted chunk');
    });

    expect(fn () => (new ImportFantraxPlayersChunkJob('persisted', 0, 1, $this->run->id))->handle($importer))
        ->toThrow(RuntimeException::class, 'Interrupted chunk');
    expect($this->run->fresh()->processed_records)->toBe(0)
        ->and($this->run->fresh()->meta['fantrax_players']['offset'])->toBe(0);
    Queue::assertNothingPushed();
});

it('does not let a stale failure overwrite a newer checkpoint', function (): void {
    $this->run->update(['meta' => ['fantrax_players' => ['entries' => array_fill(0, 201, []), 'offset' => 100]]]);
    (new ImportFantraxPlayersChunkJob('persisted', 0, 201, $this->run->id))->failed(new RuntimeException('Old delivery'));
    expect($this->run->fresh()->status)->toBe('working');
});

it('fails clearly when durable input is missing rather than completing an empty import', function (): void {
    $importer = Mockery::mock(ImportFantraxPlayers::class);
    $importer->shouldNotReceive('importChunk');
    expect(fn () => (new ImportFantraxPlayersChunkJob('persisted', 0, 100, $this->run->id))->handle($importer))
        ->toThrow(RuntimeException::class, 'Fantrax player import snapshot is missing.');
    expect($this->run->fresh()->status)->toBe('working');
});
