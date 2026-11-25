<?php

declare(strict_types=1);

use App\Models\NhlExpectedGoalsModel;
use App\Models\NhlExpectedGoalsModelBucket;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->model = NhlExpectedGoalsModel::query()->create([
        'name' => 'Strength buckets', 'version' => 'strength-schema-v1', 'prediction_target' => 'goal',
    ]);
    $this->migration = fn () => require database_path(
        'migrations/2026_11_25_000002_add_strength_to_nhl_expected_goals_model_buckets.php'
    );
    $this->bucket = fn (array $overrides = []): array => [
        'expected_goals_model_id' => $this->model->id,
        'bucket_key' => 'L01|shot_type_group=wrist|distance_group=d_010_015',
        'fallback_level' => 1,
        'bucket_dimensions' => json_encode(['shot_type_group' => 'wrist', 'distance_group' => 'd_010_015']),
        'attempts' => 100,
        'goals' => 10,
        'raw_goal_rate' => 0.1,
        'smoothed_goal_probability' => 0.1,
        'confidence_score' => 0.5,
        'confidence_bucket' => 'medium',
        'shrinkage_weight' => 0.5,
        ...$overrides,
    ];
    // Savepoints let the same contract run on PostgreSQL after a rejected insert.
    $this->rejectBucket = function (array $attributes): void {
        expect(fn () => DB::transaction(fn () => DB::table('nhl_expected_goals_model_buckets')->insert($attributes)))
            ->toThrow(QueryException::class);
    };
});

afterEach(function (): void {
    Http::assertNothingSent();
});

it('defaults existing writer inserts to combined strength', function (): void {
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)());

    expect(DB::table('nhl_expected_goals_model_buckets')->where('id', $id)->value('strength'))->toBe('all');
});

it('preserves an explicitly combined probability bucket', function (): void {
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)(['strength' => 'all']));

    expect(NhlExpectedGoalsModelBucket::query()->findOrFail($id)->strength)->toBe('all');
});

it('stores an even strength probability bucket', function (): void {
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)(['strength' => 'ev']));

    expect(NhlExpectedGoalsModelBucket::query()->findOrFail($id)->strength)->toBe('ev');
});

it('stores a power play probability bucket', function (): void {
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)(['strength' => 'pp']));

    expect(NhlExpectedGoalsModelBucket::query()->findOrFail($id)->strength)->toBe('pp');
});

it('stores a penalty kill probability bucket', function (): void {
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)(['strength' => 'pk']));

    expect(NhlExpectedGoalsModelBucket::query()->findOrFail($id)->strength)->toBe('pk');
});

it('allows all strengths to coexist for one model and bucket', function (): void {
    foreach (['all', 'ev', 'pp', 'pk'] as $strength) {
        DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)(['strength' => $strength]));
    }

    expect($this->model->buckets()->orderBy('strength')->pluck('strength')->all())->toBe(['all', 'ev', 'pk', 'pp']);
});

it('rejects duplicate combined buckets within one model', function (): void {
    $row = ($this->bucket)(['strength' => 'all']);
    DB::table('nhl_expected_goals_model_buckets')->insert($row);

    ($this->rejectBucket)($row);
});

it('rejects duplicate even strength buckets within one model', function (): void {
    $row = ($this->bucket)(['strength' => 'ev']);
    DB::table('nhl_expected_goals_model_buckets')->insert($row);

    ($this->rejectBucket)($row);
});

it('rejects duplicate power play buckets within one model', function (): void {
    $row = ($this->bucket)(['strength' => 'pp']);
    DB::table('nhl_expected_goals_model_buckets')->insert($row);

    ($this->rejectBucket)($row);
});

it('rejects duplicate penalty kill buckets within one model', function (): void {
    $row = ($this->bucket)(['strength' => 'pk']);
    DB::table('nhl_expected_goals_model_buckets')->insert($row);

    ($this->rejectBucket)($row);
});

it('allows the same bucket and strength in another model', function (): void {
    $other = NhlExpectedGoalsModel::query()->create(['name' => 'Other strength model', 'version' => 'v1']);
    DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)(['strength' => 'pk']));
    DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)([
        'expected_goals_model_id' => $other->id, 'strength' => 'pk',
    ]));

    expect($this->model->buckets()->count())->toBe(1)->and($other->buckets()->count())->toBe(1);
});

it('allows different buckets at the same strength within one model', function (): void {
    DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)(['strength' => 'pk']));
    DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)([
        'strength' => 'pk', 'bucket_key' => 'L01|shot_type_group=wrist|distance_group=d_015_020',
    ]));

    expect($this->model->buckets()->where('strength', 'pk')->count())->toBe(2);
});

it('rejects null strength instead of bypassing bucket uniqueness', function (): void {
    ($this->rejectBucket)(($this->bucket)(['strength' => null]));
});

it('rejects an unsupported strength', function (): void {
    ($this->rejectBucket)(($this->bucket)(['strength' => 'five_on_five']));
});

it('rejects blank strength', function (): void {
    ($this->rejectBucket)(($this->bucket)(['strength' => '']));
});

it('rejects noncanonical uppercase strength', function (): void {
    ($this->rejectBucket)(($this->bucket)(['strength' => 'PK']));
});

it('upserts one strength without overwriting the other strengths', function (): void {
    foreach (['ev', 'pp', 'pk'] as $strength) {
        DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)(['strength' => $strength]));
    }
    DB::table('nhl_expected_goals_model_buckets')->upsert([
        ($this->bucket)(['strength' => 'pk', 'attempts' => 200, 'goals' => 40,
            'raw_goal_rate' => 0.2, 'smoothed_goal_probability' => 0.2]),
    ], ['expected_goals_model_id', 'bucket_key', 'strength'],
        ['attempts', 'goals', 'raw_goal_rate', 'smoothed_goal_probability']);

    $rows = $this->model->buckets()->get()->keyBy('strength');
    expect($rows)->toHaveCount(3)
        ->and((float) $rows['pk']->smoothed_goal_probability)->toBe(0.2)
        ->and((int) $rows['pk']->attempts)->toBe(200)
        ->and((float) $rows['ev']->smoothed_goal_probability)->toBe(0.1)
        ->and((float) $rows['pp']->smoothed_goal_probability)->toBe(0.1);
});

it('preserves legacy evidence and identity when upgrading combined buckets', function (): void {
    ($this->migration)()->down();
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)());
    $before = (array) DB::table('nhl_expected_goals_model_buckets')->where('id', $id)->first();
    try {
        ($this->migration)()->up();
        $after = (array) DB::table('nhl_expected_goals_model_buckets')->where('id', $id)->first();

        expect($after['strength'])->toBe('all');
        unset($after['strength']);
        expect($after)->toEqual($before);
    } finally {
        if (! Schema::hasColumn('nhl_expected_goals_model_buckets', 'strength')) {
            ($this->migration)()->up();
        }
    }
});

it('restores the original combined key on safe rollback', function (): void {
    $id = DB::table('nhl_expected_goals_model_buckets')->insertGetId(($this->bucket)());
    try {
        ($this->migration)()->down();
        expect(Schema::hasColumn('nhl_expected_goals_model_buckets', 'strength'))->toBeFalse()
            ->and(DB::table('nhl_expected_goals_model_buckets')->where('id', $id)->value('attempts'))->toBe(100);
        ($this->rejectBucket)(($this->bucket)());
    } finally {
        if (! Schema::hasColumn('nhl_expected_goals_model_buckets', 'strength')) {
            ($this->migration)()->up();
        }
    }
});

it('refuses rollback before losing any strength specific probability', function (): void {
    DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)(['strength' => 'pk']));

    expect(fn () => ($this->migration)()->down())->toThrow(
        \RuntimeException::class,
        'Cannot remove bucket strength while strength-specific probability rows exist.'
    );

    // Proves the failed rollback has not removed the strength-aware key either.
    DB::table('nhl_expected_goals_model_buckets')->insert(($this->bucket)(['strength' => 'ev']));
    expect($this->model->buckets()->count())->toBe(2)
        ->and($this->model->buckets()->where('strength', 'pk')->exists())->toBeTrue();
});
