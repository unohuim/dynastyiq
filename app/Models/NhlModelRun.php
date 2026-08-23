<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned NHL modeling experiment or projection run.
 */
class NhlModelRun extends Model
{
    public const FAMILY_SAT = 'sat';

    public const STAGE_TRAINING = 'training';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'train_season_ids' => 'array',
        'season_weights' => 'array',
        'run_config' => 'array',
        'metrics' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Allowed model families.
     *
     * @return array<int, string>
     */
    public static function families(): array
    {
        return [
            self::FAMILY_SAT,
        ];
    }

    /**
     * Allowed workflow stages.
     *
     * @return array<int, string>
     */
    public static function workflowStages(): array
    {
        return [
            self::STAGE_TRAINING,
        ];
    }

    /**
     * Allowed run statuses.
     *
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_RUNNING,
            self::STATUS_COMPLETE,
            self::STATUS_FAILED,
            self::STATUS_ARCHIVED,
        ];
    }
}
