<?php

declare(strict_types=1);

use App\Exceptions\NhlPregameContextRunStopped;
use App\Models\NhlModelRun;
use App\Models\NhlPregameContextRun;
use App\Models\NhlPregameContextRunDay;
use App\Models\NhlPregameContextRunGame;
use App\Services\NhlPregameContextBuilder;
use App\Services\NhlPregameLeagueResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00 UTC'));
    config(['cache.default' => 'array', 'nhl-pregame.comparison_metric_page_size' => 2]);
    Cache::flush();
    Http::preventStrayRequests();
    $this->model = NhlModelRun::create(['run_key' => 'comparison-pages', 'name' => 'Comparison pages',
        'model_family' => 'sat', 'workflow_stage' => 'training', 'model_version' => 'fixture',
        'status' => 'complete', 'train_season_ids' => ['20252026']]);
    $this->run = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'running',
        'model_run_id' => $this->model->id, 'season_ids' => ['20262027'], 'options' => []]);
    $this->day = NhlPregameContextRunDay::create(['run_id' => $this->run->id, 'game_date' => '2026-11-25',
        'league_state' => 'complete', 'context_state' => 'processing']);
    $this->work = NhlPregameContextRunGame::create(['run_id' => $this->run->id, 'nhl_game_id' => 2026020391,
        'game_date' => '2026-11-25', 'sequence' => 1, 'state' => 'processing', 'snapshot_completed_at' => now(),
        'readiness' => ['existing_note' => 'preserve']]);
    $this->cutoff = '2026-11-25 11:00:00';
    $team = DB::table('nhl_team_game_pregame_contexts')->insertGetId([
        'run_id' => $this->run->id, 'model_run_id' => $this->model->id, 'nhl_game_id' => 2026020391,
        'nhl_team_id' => 1, 'opponent_team_id' => 2, 'game_date' => '2026-11-25',
        'source_cutoff_at' => $this->cutoff, 'venue' => 'away', 'context_version' => NhlPregameContextBuilder::VERSION,
        'schedule_metrics' => '{}', 'opponent_metrics' => '{}', 'metrics' => '{}',
    ]);
    $this->context = DB::table('nhl_player_game_pregame_contexts')->insertGetId([
        'run_id' => $this->run->id, 'model_run_id' => $this->model->id, 'team_context_id' => $team,
        'nhl_game_id' => 2026020391, 'nhl_player_id' => 102, 'nhl_team_id' => 1, 'opponent_team_id' => 2,
        'game_date' => '2026-11-25', 'source_cutoff_at' => $this->cutoff, 'venue' => 'away',
        'participant_source' => 'projected_roster', 'context_version' => NhlPregameContextBuilder::VERSION,
        'metrics' => '{}', 'ranks' => '{}',
    ]);
    $source = NhlPregameContextRun::create(['action' => 'backfill', 'status' => 'completed',
        'model_run_id' => $this->model->id, 'season_ids' => ['20262027']]);
    $sourceDay = NhlPregameContextRunDay::create(['run_id' => $source->id, 'game_date' => '2026-11-25',
        'league_state' => 'complete', 'context_state' => 'complete']);
    $references = [];
    foreach (['team', 'player'] as $type) {
        $id = DB::table('nhl_pregame_context_league_averages')->insertGetId([
            'run_id' => $source->id, 'run_day_id' => $sourceDay->id, 'model_run_id' => $this->model->id,
            'game_date' => '2026-11-25', 'source_cutoff_at' => $this->cutoff, 'entity_type' => $type,
            'metric_key' => 'sat_per_60', 'window_key' => 'last_20', 'strength' => 'EV',
            'league_average' => 50 / 3, 'rank_population' => 3,
        ]);
        $peers = [101 => [10, 3], 102 => [20, 1], 103 => [20, 1]];
        Cache::put('nhl:pregame:comparison-peers:v1:'.$id, [
            'source' => 'frozen-source', 'peers' => $peers, 'checksum' => hash('sha256', serialize($peers)),
        ], 3600);
        $references[$type] = ['run_id' => $source->id, 'run_day_id' => $sourceDay->id,
            'average_ids' => [$id], 'source' => 'frozen-source'];
        if ($type === 'player') {
            $this->averageId = $id;
        }
    }
    $this->run->update(['options' => ['prediction_league_references' => [$this->cutoff => $references]]]);
    $this->service = new NhlPregameLeagueResults(app(NhlPregameContextBuilder::class));
    $this->metrics = function (int $count = 5): array {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = DB::table('nhl_player_game_pregame_context_metrics')->insertGetId([
                'player_context_id' => $this->context, 'metric_key' => 'sat_per_60', 'window_key' => 'last_20',
                'strength' => 'EV', 'measure_key' => $index === 0 ? 'value' : 'sample_'.$index,
                'value' => 20, 'sample_games' => 4, 'exposure_seconds' => 600,
                'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
            ]);
        }

        return $ids;
    };
    $this->page = fn (?Closure $stopped = null): bool => $this->service->attachGameComparisonsPage(
        $this->run, $this->day, 2026020391, $stopped
    );
    $this->processed = fn (): int => DB::table('nhl_player_game_pregame_context_metrics')
        ->where('player_context_id', $this->context)->where('updated_at', now()->toDateTimeString())->count();
});

afterEach(function (): void {
    Http::assertNothingSent();
    $this->travelBack();
});

it('limits a delivery to the configured metric page', function (): void {
    ($this->metrics)();
    expect(($this->page)())->toBeFalse()->and(($this->processed)())->toBe(2);
});

it('saves the last committed metric identity', function (): void {
    $ids = ($this->metrics)();
    ($this->page)();
    expect(data_get($this->work->fresh()->readiness, 'comparison_progress.after_metric_ids.player'))->toBe($ids[1]);
});

it('resumes from a saved cursor with a fresh service instance', function (): void {
    ($this->metrics)();
    ($this->page)();
    $this->service = new NhlPregameLeagueResults(app(NhlPregameContextBuilder::class));
    expect(($this->page)())->toBeFalse()->and(($this->processed)())->toBe(4);
});

it('does not rewrite a previously committed comparison page', function (): void {
    $ids = ($this->metrics)();
    ($this->page)();
    DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->update(['league_average' => 777]);
    ($this->page)();
    expect((float) DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->value('league_average'))->toBe(777.0);
});

it('returns complete only after the final comparison page', function (): void {
    ($this->metrics)();
    expect(($this->page)())->toBeFalse()->and(($this->page)())->toBeFalse()->and(($this->page)())->toBeTrue()
        ->and(($this->processed)())->toBe(5);
});

it('keeps repeated completed deliveries idempotent', function (): void {
    ($this->metrics)(1);
    expect(($this->page)())->toBeTrue()->and(($this->page)())->toBeTrue();
    $this->assertDatabaseCount('nhl_player_game_pregame_context_metrics', 1);
});

it('checks permission at page boundaries instead of per metric', function (): void {
    ($this->metrics)(100);
    config(['nhl-pregame.comparison_metric_page_size' => 100]);
    $checks = 0;
    ($this->page)(function () use (&$checks): bool {
        $checks++;

        return false;
    });
    expect($checks)->toBe(2)->and(($this->processed)())->toBe(100);
});

it('rejects stopped work before comparison writes', function (): void {
    ($this->metrics)();
    expect(fn () => ($this->page)(fn (): bool => true))->toThrow(NhlPregameContextRunStopped::class);
    expect(($this->processed)())->toBe(0)->and($this->work->fresh()->readiness)->not->toHaveKey('comparison_progress');
});

it('rolls back a page if ownership is lost before commit', function (): void {
    ($this->metrics)();
    $checks = 0;
    expect(fn () => ($this->page)(function () use (&$checks): bool {
        return ++$checks === 2;
    }))->toThrow(NhlPregameContextRunStopped::class);
    expect(($this->processed)())->toBe(0)->and($this->work->fresh()->readiness)->not->toHaveKey('comparison_progress');
});

it('preserves earlier pages when a later page loses its claim', function (): void {
    ($this->metrics)();
    ($this->page)();
    $before = $this->work->fresh()->readiness;
    $checks = 0;
    expect(fn () => ($this->page)(function () use (&$checks): bool {
        return ++$checks === 2;
    }))->toThrow(NhlPregameContextRunStopped::class);
    expect(($this->processed)())->toBe(2)->and($this->work->fresh()->readiness)->toBe($before);
});

it('fails visibly on an unknown checkpoint format', function (): void {
    $this->work->update(['readiness' => ['comparison_progress' => ['version' => 99]]]);
    expect(fn () => ($this->page)())->toThrow(\RuntimeException::class, 'Unsupported pregame comparison progress.');
});

it('rejects a negative metric cursor', function (): void {
    $this->work->update(['readiness' => ['comparison_progress' => ['version' => 1, 'completed_types' => ['team'],
        'after_metric_ids' => ['player' => -1]]]]);
    expect(fn () => ($this->page)())->toThrow(\RuntimeException::class, 'Invalid pregame comparison cursor.');
});

it('does not advance progress if retained peers are missing', function (): void {
    ($this->metrics)();
    Cache::forget('nhl:pregame:comparison-peers:v1:'.$this->averageId);
    expect(fn () => ($this->page)())->toThrow(\RuntimeException::class, 'The retained league rank population is unavailable');
    expect($this->work->fresh()->readiness)->not->toHaveKey('comparison_progress');
});

it('does not commit a page with a corrupt peer checksum', function (): void {
    ($this->metrics)();
    $key = 'nhl:pregame:comparison-peers:v1:'.$this->averageId;
    Cache::put($key, [...Cache::get($key), 'checksum' => 'wrong'], 3600);
    expect(fn () => ($this->page)())->toThrow(\RuntimeException::class);
    expect(($this->processed)())->toBe(0);
});

it('preserves metric values and sample exposure', function (): void {
    $ids = ($this->metrics)(1);
    ($this->page)();
    $row = DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->first();
    expect((float) $row->value)->toBe(20.0)->and($row->sample_games)->toBe(4)->and((float) $row->exposure_seconds)->toBe(600.0);
});

it('retains descending competition rank for ties', function (): void {
    $ids = ($this->metrics)(1);
    ($this->page)();
    expect(DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->value('league_rank'))->toBe(1);
});

it('recalculates a changed player rank against the original peers', function (): void {
    $ids = ($this->metrics)(1);
    DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->update(['value' => 5]);
    ($this->page)();
    expect(DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->value('league_rank'))->toBe(3);
});

it('preserves unrelated readiness details while checkpointing', function (): void {
    ($this->metrics)();
    ($this->page)();
    expect($this->work->fresh()->readiness['existing_note'])->toBe('preserve');
});

it('finishes an empty metric population without inventing comparisons', function (): void {
    expect(($this->page)())->toBeTrue();
    $this->assertDatabaseCount('nhl_player_game_pregame_context_metrics', 0);
});

it('keeps undefined percentage differences null for a zero league average', function (): void {
    $ids = ($this->metrics)(1);
    DB::table('nhl_pregame_context_league_averages')->where('id', $this->averageId)->update(['league_average' => 0]);
    ($this->page)();
    expect(DB::table('nhl_player_game_pregame_context_metrics')->where('id', $ids[0])->value('percentage_difference'))->toBeNull();
});

it('rejects duplicate completed populations rather than declaring false completion', function (): void {
    $this->work->update(['readiness' => ['comparison_progress' => ['version' => 1,
        'completed_types' => ['team', 'team'], 'after_metric_ids' => []]]]);
    expect(fn () => ($this->page)())->toThrow(\RuntimeException::class);
});

it('leaves snapshot completion evidence intact while comparing', function (): void {
    ($this->metrics)();
    $snapshot = $this->work->snapshot_completed_at;
    ($this->page)();
    expect($this->work->fresh()->snapshot_completed_at->equalTo($snapshot))->toBeTrue()
        ->and($this->work->fresh()->comparisons_completed_at)->toBeNull();
});

it('handles a mixed referenced and nonreferenced population without replaying both', function (): void {
    ($this->metrics)();
    $options = $this->run->options;
    unset($options['prediction_league_references'][$this->cutoff]['team']);
    $this->run->update(['options' => $options]);
    expect(($this->page)())->toBeFalse()->and(($this->processed)())->toBe(0)
        ->and(data_get($this->work->fresh()->readiness, 'comparison_progress.completed_types'))->toBe(['team']);
    expect(($this->page)())->toBeFalse()->and(($this->processed)())->toBe(2);
});

it('keeps prior pages when a reference disappears before a later delivery', function (): void {
    ($this->metrics)();
    ($this->page)();
    $before = $this->work->fresh()->readiness;
    Cache::forget('nhl:pregame:comparison-peers:v1:'.$this->averageId);
    expect(fn () => ($this->page)())->toThrow(\RuntimeException::class);
    expect(($this->processed)())->toBe(2)->and($this->work->fresh()->readiness)->toBe($before);
});
