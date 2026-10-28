<?php

declare(strict_types=1);

use App\Jobs\ProcessNhlLivePbpJob;
use App\Models\PlayByPlay;
use App\Services\NhlPbpReconciliation;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-28 00:00:00 UTC'));
    $this->reconciler = app(NhlPbpReconciliation::class);
    $this->source = [
        'id' => 2026020189, 'gameType' => 2,
        'homeTeam' => ['id' => 10, 'score' => 0], 'awayTeam' => ['id' => 6, 'score' => 0],
        'plays' => [['eventId' => 1, 'typeDescKey' => 'faceoff', 'periodDescriptor' => ['number' => 1]]],
    ];
});

afterEach(function (): void { $this->travelBack(); });

it('accepts a complete zero-goal timeline', function (): void {
    $this->reconciler->validate(2026020189, $this->source);
    expect(true)->toBeTrue();
});
it('rejects mismatched game identity', function (): void {
    $this->reconciler->validate(1, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects missing event lists', function (): void {
    unset($this->source['plays']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects empty event lists', function (): void {
    $this->source['plays'] = [];
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects duplicate event identities', function (): void {
    $this->source['plays'][] = $this->source['plays'][0];
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects events without identity', function (): void {
    unset($this->source['plays'][0]['eventId']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects events without type', function (): void {
    unset($this->source['plays'][0]['typeDescKey']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects events without period', function (): void {
    unset($this->source['plays'][0]['periodDescriptor']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects missing home identity', function (): void {
    unset($this->source['homeTeam']['id']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects missing game type', function (): void {
    unset($this->source['gameType']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects an incomplete goal timeline', function (): void {
    $this->source['homeTeam']['score'] = 1;
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('rejects missing scoreboard evidence', function (): void {
    unset($this->source['awayTeam']['score']);
    $this->reconciler->validate(2026020189, $this->source);
})->throws(InvalidArgumentException::class);
it('stores accepted evidence without advancing processing', function (): void {
    $this->reconciler->accept(2026020189, $this->source, 10);
    $state = DB::table('nhl_pbp_processing_states')->first();
    expect((int) $state->pending_version)->toBe(10)->and((int) $state->processed_version)->toBe(0);
});
it('ignores clock-only changes', function (): void {
    $this->reconciler->accept(2026020189, $this->source, 10);
    $this->source['clock'] = ['timeRemaining' => '12:00'];
    $this->reconciler->accept(2026020189, $this->source, 11);
    expect((int) DB::table('nhl_pbp_processing_states')->value('pending_version'))->toBe(10);
});
it('rejects older observations without overwriting pending evidence', function (): void {
    $this->reconciler->accept(2026020189, $this->source, 10);
    $this->source['plays'][0]['typeDescKey'] = 'stoppage';
    $this->reconciler->accept(2026020189, $this->source, 9);
    expect((int) DB::table('nhl_pbp_processing_states')->value('pending_version'))->toBe(10);
});
it('coalesces changed event evidence into the latest pending snapshot', function (): void {
    $this->reconciler->accept(2026020189, $this->source, 10);
    $this->source['plays'][0]['typeDescKey'] = 'stoppage';
    $this->reconciler->accept(2026020189, $this->source, 11);
    $state = DB::table('nhl_pbp_processing_states')->first();
    expect((int) $state->pending_version)->toBe(11)
        ->and(json_decode($state->pending_payload, true)['plays'][0]['typeDescKey'])->toBe('stoppage');
});
it('routes calculations separately from live polling', function (): void {
    expect((new ProcessNhlLivePbpJob(2026020189))->queue)->toBe('nhl-live-stats');
});
it('coalesces jobs by game identity', function (): void {
    expect((new ProcessNhlLivePbpJob(2026020189))->uniqueId())->toBe('2026020189');
});
it('does not create checkpoints for invalid source evidence', function (): void {
    try {
        $this->reconciler->accept(1, $this->source, 10);
    } catch (InvalidArgumentException) {
        expect(DB::table('nhl_pbp_processing_states')->count())->toBe(0);
    }
});
it('preserves pending work when the same snapshot is observed again', function (): void {
    $this->reconciler->accept(2026020189, $this->source, 10);
    $this->reconciler->accept(2026020189, $this->source, 20);
    $state = DB::table('nhl_pbp_processing_states')->first();
    expect((int) $state->pending_version)->toBe(10)->and((int) $state->processed_version)->toBe(0);
});

it('retains withdrawn source events while excluding them from active reads', function (): void {
    $play = PlayByPlay::create(['nhl_game_id' => 2026020189, 'nhl_event_id' => '2',
        'metadata' => ['event' => ['eventId' => 2]]]);
    $this->mock(\App\Services\ImportNHLPlayByPlay::class)->shouldReceive('import')->once()->andReturn(1);
    $this->reconciler->reconcile(2026020189, $this->source, 10);
    expect(PlayByPlay::query()->whereKey($play->id)->exists())->toBeFalse()
        ->and(PlayByPlay::withoutGlobalScope('active')->find($play->id)->withdrawn_at)->not->toBeNull();
    $this->assertDatabaseHas('nhl_pbp_event_revisions', ['nhl_game_id' => 2026020189, 'nhl_event_id' => '2', 'action' => 'withdrawn']);
});

it('requires a second valid observation before accepting missing event identities', function (): void {
    PlayByPlay::create(['nhl_game_id' => 2026020189, 'nhl_event_id' => '2']);
    $this->reconciler->accept(2026020189, $this->source, 10);
    expect((int) DB::table('nhl_pbp_processing_states')->value('pending_version'))->toBe(0);
    $this->reconciler->accept(2026020189, $this->source, 11);
    expect((int) DB::table('nhl_pbp_processing_states')->value('pending_version'))->toBe(11);
});

it('rolls back withdrawal and revision history if event mapping fails', function (): void {
    $play = PlayByPlay::create(['nhl_game_id' => 2026020189, 'nhl_event_id' => '2']);
    $this->mock(\App\Services\ImportNHLPlayByPlay::class)->shouldReceive('import')->andThrow(new RuntimeException('mapping failed'));
    try {
        $this->reconciler->reconcile(2026020189, $this->source, 10);
    } catch (RuntimeException) {
        expect(PlayByPlay::query()->whereKey($play->id)->exists())->toBeTrue()
            ->and(DB::table('nhl_pbp_event_revisions')->count())->toBe(0);
    }
});

it('forces unchanged evidence through an explicit import rebuild', function (): void {
    $this->reconciler->accept(2026020189, $this->source, 10);
    DB::table('nhl_pbp_processing_states')->update(['processed_version' => 10]);
    $this->reconciler->accept(2026020189, $this->source, 20, true);
    $state = DB::table('nhl_pbp_processing_states')->first();
    expect((int) $state->pending_version)->toBe(20)->and((int) $state->processed_version)->toBe(10);
});
