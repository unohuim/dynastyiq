<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\BaseNhlJob;
use App\Jobs\ConnectEventsShiftUnitsNhlJob;
use App\Jobs\VerifyHtmlPbpNhlJob;
use App\Jobs\ImportBoxscoreNhlJob;
use App\Jobs\ImportPbpNhlJob;
use App\Jobs\ImportShiftsNhlJob;
use App\Jobs\MakeShiftUnitsNhlJob;
use App\Jobs\SumNhlGameUnitsJob;
use App\Jobs\SummarizePbpNhlJob;
use App\Jobs\ValidateNhlGameSummaryJob;

/**
 * Canonical stage metadata for the NHL game import pipeline.
 */
final class NhlImportStages
{
    public const PBP = 'pbp';
    public const SUMMARY = 'summary';
    public const BOXSCORE = 'boxscore';
    public const SHIFTS = 'shifts';
    public const SHIFT_UNITS = 'shift-units';
    public const CONNECT_EVENTS = 'connect-events';
    public const HTML_PBP_VERIFY = 'html-pbp-verify';
    public const SUM_GAME_UNITS = 'sum-game-units';
    public const VALIDATE_SUMMARY = 'validate-summary';

    /**
     * Return NHL import stages in execution order.
     *
     * @return array<int,string>
     */
    public static function ordered(): array
    {
        return [
            self::PBP,
            self::SUMMARY,
            self::BOXSCORE,
            self::SHIFTS,
            self::SHIFT_UNITS,
            self::CONNECT_EVENTS,
            self::HTML_PBP_VERIFY,
            self::SUM_GAME_UNITS,
            self::VALIDATE_SUMMARY,
        ];
    }

    /**
     * Return dependency stages that must be completed before the given stage.
     *
     * @return array<int,string>
     */
    public static function dependenciesFor(string $stage): array
    {
        return match ($stage) {
            self::PBP => [],
            self::SUMMARY => [self::PBP],
            self::BOXSCORE => [self::PBP, self::SUMMARY],
            self::SHIFTS => [self::PBP, self::SUMMARY, self::BOXSCORE],
            self::SHIFT_UNITS => [
                self::PBP,
                self::SUMMARY,
                self::BOXSCORE,
                self::SHIFTS,
            ],
            self::CONNECT_EVENTS => [
                self::PBP,
                self::SUMMARY,
                self::BOXSCORE,
                self::SHIFTS,
                self::SHIFT_UNITS,
            ],
            self::HTML_PBP_VERIFY => [
                self::PBP,
                self::SUMMARY,
                self::BOXSCORE,
                self::SHIFTS,
                self::SHIFT_UNITS,
                self::CONNECT_EVENTS,
            ],
            self::SUM_GAME_UNITS => [
                self::PBP,
                self::SUMMARY,
                self::BOXSCORE,
                self::SHIFTS,
                self::SHIFT_UNITS,
                self::CONNECT_EVENTS,
                self::HTML_PBP_VERIFY,
            ],
            self::VALIDATE_SUMMARY => [
                self::PBP,
                self::SUMMARY,
                self::BOXSCORE,
                self::SHIFTS,
                self::SHIFT_UNITS,
                self::CONNECT_EVENTS,
                self::HTML_PBP_VERIFY,
                self::SUM_GAME_UNITS,
            ],
            default => [],
        };
    }

    /**
     * Return the next stage after a completed stage.
     */
    public static function nextAfter(string $stage): ?string
    {
        $stages = self::ordered();
        $index = array_search($stage, $stages, true);

        if ($index === false) {
            return null;
        }

        return $stages[$index + 1] ?? null;
    }

    /**
     * Return the queue job class for a pipeline stage.
     *
     * @return class-string<BaseNhlJob>|null
     */
    public static function jobClassFor(string $stage): ?string
    {
        return match ($stage) {
            self::PBP => ImportPbpNhlJob::class,
            self::SUMMARY => SummarizePbpNhlJob::class,
            self::BOXSCORE => ImportBoxscoreNhlJob::class,
            self::SHIFTS => ImportShiftsNhlJob::class,
            self::SHIFT_UNITS => MakeShiftUnitsNhlJob::class,
            self::CONNECT_EVENTS => ConnectEventsShiftUnitsNhlJob::class,
            self::HTML_PBP_VERIFY => VerifyHtmlPbpNhlJob::class,
            self::SUM_GAME_UNITS => SumNhlGameUnitsJob::class,
            self::VALIDATE_SUMMARY => ValidateNhlGameSummaryJob::class,
            default => null,
        };
    }

    /**
     * Return the config key for the stale-running timeout for a stage.
     */
    public static function timeoutConfigKeyFor(string $stage): ?string
    {
        return match ($stage) {
            self::PBP => 'apiImportNhl.max_pbp_seconds',
            self::SUMMARY => 'apiImportNhl.max_game_summaries_seconds',
            self::BOXSCORE => 'apiImportNhl.max_boxscore_seconds',
            self::SHIFTS => 'apiImportNhl.max_shifts_seconds',
            self::SHIFT_UNITS => 'apiImportNhl.max_shift_units_seconds',
            self::CONNECT_EVENTS => 'apiImportNhl.max_connect_events_seconds',
            self::HTML_PBP_VERIFY => 'apiImportNhl.max_html_pbp_verify_seconds',
            self::SUM_GAME_UNITS => 'apiImportNhl.max_sum_game_units_seconds',
            self::VALIDATE_SUMMARY => 'apiImportNhl.max_validate_summary_seconds',
            default => null,
        };
    }
}
