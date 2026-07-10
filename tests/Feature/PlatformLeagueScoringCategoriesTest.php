<?php

declare(strict_types=1);

use App\Models\FantasyScoringCategoryMapping;
use App\Models\PlatformLeague;
use App\Models\PlatformLeagueScoringCategory;
use App\Services\PlatformLeagueScoringCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(PlatformLeagueScoringCategoryService::class);
    $this->league = PlatformLeague::create([
        'platform' => 'fantrax',
        'platform_league_id' => 'league-1',
        'name' => 'League One',
        'sport' => 'hockey',
    ]);
});

it('syncs a provider category into a first-class row', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
        'short' => 'G',
        'value' => 1,
        'stat_key' => 'g',
        'auto_stat_key' => 'g',
        'mapping_source' => 'auto',
        'is_supported' => true,
    ]]);

    $row = PlatformLeagueScoringCategory::query()->first();

    expect($row)->not->toBeNull()
        ->and($row->platform_league_id)->toBe($this->league->id)
        ->and($row->provider_identity_key)->toBe('hockey_skating:g')
        ->and($row->stat_key)->toBe('g');
});

it('normalizes Fantrax skating shorthand groups', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'SKATING:A',
        'label' => 'Assists',
        'short' => 'A',
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->normalized_group)->toBe('HOCKEY_SKATING');
});

it('normalizes Fantrax goalie shorthand groups', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'GOALIE:SV',
        'label' => 'Saves',
        'short' => 'SV',
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->normalized_group)->toBe('HOCKEY_GOALIE');
});

it('keeps the richer duplicate category row for the same identity', function (): void {
    $this->service->sync($this->league, [
        [
            'id' => 'HOCKEY_SKATING:BP3',
            'label' => 'BP3',
            'short' => 'BP3',
        ],
        [
            'id' => 'HOCKEY_SKATING:BP3',
            'label' => 'Big Points 3',
            'short' => 'BP3',
            'dictionary_provider_label' => 'Big Points 3',
            'formula' => 'gwg + otg',
            'alignment_status' => 'formula',
        ],
    ]);

    expect(PlatformLeagueScoringCategory::query()->count())->toBe(1)
        ->and(PlatformLeagueScoringCategory::query()->first()?->provider_label)->toBe('Big Points 3');
});

it('deletes stale rows that disappear from the provider payload', function (): void {
    $this->service->sync($this->league, [
        ['id' => 'HOCKEY_SKATING:G', 'label' => 'Goals', 'short' => 'G'],
        ['id' => 'HOCKEY_SKATING:A', 'label' => 'Assists', 'short' => 'A'],
    ]);

    $this->service->sync($this->league, [
        ['id' => 'HOCKEY_SKATING:G', 'label' => 'Goals', 'short' => 'G'],
    ]);

    expect(PlatformLeagueScoringCategory::query()->pluck('provider_identity_key')->all())
        ->toBe(['hockey_skating:g']);
});

it('preserves a manual mapping keyed by provider category id', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
        'short' => 'G',
    ]], [
        'HOCKEY_SKATING:G' => 'stat:pts',
    ]);

    expect(PlatformLeagueScoringCategory::query()->first()?->manual_mapping_key)->toBe('stat:pts');
});

it('preserves a manual mapping keyed by normalized identity', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'SKATING:A',
        'label' => 'Assists',
        'short' => 'A',
    ]], [
        'hockey_skating:a' => 'stat:a',
    ]);

    expect(PlatformLeagueScoringCategory::query()->first()?->manual_mapping_key)->toBe('stat:a');
});

it('links a dictionary mapping by provider label', function (): void {
    $mapping = FantasyScoringCategoryMapping::create([
        'platform' => 'fantrax',
        'provider_label' => 'Big Points 3',
        'alignment_status' => 'formula',
        'formula' => 'gwg + otg',
        'required_schema_columns' => ['gwg', 'otg'],
    ]);

    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:BP3',
        'label' => 'Big Points 3',
        'short' => 'BP3',
        'dictionary_provider_label' => 'Big Points 3',
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->dictionary_mapping_id)->toBe($mapping->id);
});

it('persists required schema columns from enriched categories', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:NFOW',
        'label' => 'Net Faceoffs Won',
        'required_schema_columns' => ['fow', 'fol'],
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->required_schema_columns)->toBe(['fow', 'fol']);
});

it('uses persisted rows before legacy scoring settings payload rows', function (): void {
    $this->league->forceFill([
        'scoring_settings' => [
            'categories' => [
                ['id' => 'legacy', 'label' => 'Legacy'],
            ],
        ],
    ])->save();
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
        'short' => 'G',
    ]]);

    expect($this->service->payloadRows($this->league)[0]['label'])->toBe('Goals');
});

it('falls back to legacy scoring settings when no rows exist', function (): void {
    $this->league->forceFill([
        'scoring_settings' => [
            'categories' => [
                ['id' => 'legacy', 'label' => 'Legacy'],
            ],
        ],
    ])->save();

    expect($this->service->payloadRows($this->league)[0]['label'])->toBe('Legacy');
});

it('returns persisted manual mappings by identity', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
    ]], [
        'HOCKEY_SKATING:G' => 'stat:g',
    ]);

    expect($this->service->manualMappings($this->league))->toBe(['hockey_skating:g' => 'stat:g']);
});

it('falls back to legacy manual mappings when no rows exist', function (): void {
    $this->league->forceFill([
        'scoring_settings' => [
            'manual_mappings' => ['legacy' => 'stat:g'],
        ],
    ])->save();

    expect($this->service->manualMappings($this->league))->toBe(['legacy' => 'stat:g']);
});

it('returns false when updating manual mappings without persisted rows', function (): void {
    expect($this->service->updateManualMappings($this->league, ['x' => 'stat:g'], []))->toBeFalse();
});

it('updates persisted manual mappings with stat options', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
        'auto_mapping_key' => 'stat:g',
    ]]);

    $updated = $this->service->updateManualMappings($this->league, [
        'hockey_skating:g' => 'stat:pts',
    ], [
        'stat:pts' => [
            'type' => 'stat',
            'stat_key' => 'pts',
            'alignment_status' => 'direct',
            'formula' => 'pts',
            'required_schema_columns' => ['pts'],
            'is_supported' => true,
            'support_message' => null,
        ],
    ]);

    $row = PlatformLeagueScoringCategory::query()->first();

    expect($updated)->toBeTrue()
        ->and($row?->manual_mapping_key)->toBe('stat:pts')
        ->and($row?->stat_key)->toBe('pts')
        ->and($row?->mapping_source)->toBe('manual');
});

it('updates persisted manual mappings with dictionary options', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:BP3',
        'label' => 'Big Points 3',
    ]]);

    $this->service->updateManualMappings($this->league, [
        'hockey_skating:bp3' => 'dictionary:fantrax:Big Points 3',
    ], [
        'dictionary:fantrax:Big Points 3' => [
            'type' => 'dictionary',
            'stat_key' => null,
            'alignment_status' => 'formula',
            'formula' => 'gwg + otg',
            'required_schema_columns' => ['gwg', 'otg'],
            'is_supported' => true,
            'support_message' => null,
        ],
    ]);

    $row = PlatformLeagueScoringCategory::query()->first();

    expect($row?->formula)->toBe('gwg + otg')
        ->and($row?->alignment_status)->toBe('formula')
        ->and($row?->mapping_source)->toBe('manual');
});

it('marks rows supported from an explicit supported flag', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:OSG3',
        'label' => 'Old School Grit 3',
        'is_supported' => true,
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->is_supported)->toBeTrue();
});

it('marks rows supported when a stat key exists', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:SOG',
        'label' => 'Shots on Goal',
        'stat_key' => 'sog',
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->is_supported)->toBeTrue();
});

it('persists position values for provider categories', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
        'position_values' => ['F' => 1, 'D' => 2],
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->position_values)->toBe(['F' => 1, 'D' => 2]);
});

it('orders payload rows by sort order', function (): void {
    $this->service->sync($this->league, [
        ['id' => 'HOCKEY_SKATING:A', 'label' => 'Assists', 'sort_order' => 2],
        ['id' => 'HOCKEY_SKATING:G', 'label' => 'Goals', 'sort_order' => 1],
    ]);

    expect(array_column($this->service->payloadRows($this->league), 'label'))->toBe(['Goals', 'Assists']);
});

it('stores numeric values and leaves non numeric values null', function (): void {
    $this->service->sync($this->league, [
        ['id' => 'HOCKEY_SKATING:G', 'label' => 'Goals', 'value' => '2.5'],
        ['id' => 'HOCKEY_SKATING:A', 'label' => 'Assists', 'value' => 'not numeric'],
    ]);

    $rows = PlatformLeagueScoringCategory::query()->orderBy('provider_code')->get();

    expect($rows[1]->value)->toBe(2.5)
        ->and($rows[0]->value)->toBeNull();
});

it('wraps scalar raw provider values for audit context', function (): void {
    $this->service->sync($this->league, [[
        'id' => 'HOCKEY_SKATING:G',
        'label' => 'Goals',
        'raw_payload' => '1',
    ]]);

    expect(PlatformLeagueScoringCategory::query()->first()?->raw_payload)->toBe(['value' => '1']);
});
