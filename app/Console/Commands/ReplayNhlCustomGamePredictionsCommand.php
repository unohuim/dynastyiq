<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NhlCustomGamePredictionReplay;
use App\Services\NhlCustomGamePredictionInputs;
use App\Services\NhlCustomGamePredictionEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Manually replay a model's Test season through the isolated custom EV/PP engine. */
class ReplayNhlCustomGamePredictionsCommand extends Command
{
    protected $signature = 'nhl:custom-predict
        {--model= : SAT model owning the Train/Test seasons and pregame context}
        {--date= : Predict this scheduled date in any game state using prior results and current lineups}
        {--from= : First Test game date, YYYY-MM-DD}
        {--to= : Last Test game date, YYYY-MM-DD}
        {--save : Append a new run and its frozen predictions to the custom tables}
        {--compare-schedule : Compare schedule off/on on identical games}
        {--json : Emit the full summary and compact per-game results as JSON}';

    protected $description = 'Replay custom EV/PP predictions without using legacy engines or changing their records';

    /** Default execution only reads sources; writes require the explicit --save option. */
    public function handle(NhlCustomGamePredictionReplay $replay): int
    {
        try {
            $model = filter_var($this->option('model'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($model === false) {
                throw new \InvalidArgumentException('Provide --model with a positive SAT model ID.');
            }
            if ($this->option('date') !== null) {
                if ($this->option('from') || $this->option('to') || $this->option('save') || $this->option('compare-schedule')) {
                    throw new \InvalidArgumentException('--date is a read-only prediction mode; do not combine it with replay range, save or schedule comparison options.');
                }

                return $this->forecastDate($model, $this->dateOption('date'));
            }
            $from = $this->dateOption('from');
            $to = $this->dateOption('to');
            if ($from !== null && $to !== null && $from > $to) {
                throw new \InvalidArgumentException('--from must not follow --to.');
            }
            $json = (bool) $this->option('json');
            if (! $json) {
                $this->info('Preparing frozen Train inputs, then predicting Test games chronologically.');
            }
            $report = $replay->run($model, $from, $to, (bool) $this->option('save'),
                (bool) $this->option('compare-schedule'), $json ? null : function (int $count, int $gameId, bool $predicted): void {
                    if ($count === 1 || $count % 25 === 0) {
                        $this->line($count.' games processed; '.$gameId.' '.($predicted ? 'predicted' : 'excluded'));
                    }
                });
            if ($json) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                foreach ($report['summary'] as $name => $summary) {
                    $this->info($name.': '.$summary['wins'].' wins / '.$summary['predicted_games'].' predicted games; '
                        .$this->number($summary['win_pct']).'% win; '.$this->number($summary['game_coverage_pct']).'% game coverage');
                    $rows = [];
                    foreach (['team_goals' => 'Team EV+PP goals', 'game_goals' => 'Game EV+PP goals'] as $key => $label) {
                        $values = $summary[$key];
                        $rows[] = [$label, $values['count'], $this->number($values['prediction']), $this->number($values['actual']),
                            $this->number($values['err_pct']).'%', $this->number($values['p10']).'%', $this->number($values['p20']).'%'];
                    }
                    $this->table(['Metric', 'Comparisons', 'Prediction', 'Actual', 'err%', 'p10', 'p20'], $rows);
                }
                $this->line($report['excluded_games'].' excluded games. Goal errors use retained regulation EV+PP; winners use official finals including OT/SO.');
                if ($report['exclusions'] !== []) {
                    $this->table(['Exclusion reason', 'Games'], array_map(
                        fn (string $reason, int $count): array => [$reason, $count],
                        array_keys(array_count_values($report['exclusions'])), array_values(array_count_values($report['exclusions']))
                    ));
                }
            }

            return array_sum(array_column($report['summary'], 'predicted_games')) > 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** Calculate requested-date forecasts without requiring observed target results. */
    private function forecastDate(int $model, string $date): int
    {
        $inputs = app(NhlCustomGamePredictionInputs::class);
        $engine = app(NhlCustomGamePredictionEngine::class);
        $rows = [];
        foreach ($inputs->forecasts($model, $date, progress: $this->option('json') ? null : fn (string $message) => $this->line($message)) as $event) {
            $game = $event['game'];
            $row = ['game_id' => (int) $game->nhl_game_id, 'away' => $game->away_team_abbrev, 'home' => $game->home_team_abbrev,
                'error' => $event['error'], 'prediction' => null];
            if ($row['error'] === null) {
                try {
                    $row['prediction'] = $engine->predict($event['input']);
                } catch (\DomainException $exception) {
                    $row['error'] = $exception->getMessage();
                }
            }
            $rows[] = $row;
        }
        if ($this->option('json')) {
            $this->line(json_encode(['model' => $inputs->metadata(), 'date' => $date, 'games' => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Game', 'Away goals', 'Home goals', 'Pick', 'Win probability', 'Unavailable reason'], array_map(function (array $row): array {
                $p = $row['prediction'];

                return [$row['away'].' @ '.$row['home'], $p ? $this->number($p['teams']['away']['goals']) : '—',
                    $p ? $this->number($p['teams']['home']['goals']) : '—', $p['recommendation'] ?? '—',
                    $p ? $this->number(100 * max($p['away_win_probability'], $p['home_win_probability'])).'%' : '—', $row['error'] ?? ''];
            }, $rows));
        }

        return count(array_filter($rows, fn (array $row): bool => $row['prediction'] !== null)) > 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Require an exact calendar date rather than accepting Carbon's relative-date syntax. */
    private function dateOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            || ! CarbonImmutable::hasFormatWithModifiers($value, 'Y-m-d')
            || CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('--'.$name.' must be an actual date in YYYY-MM-DD format.');
        }

        return $value;
    }

    /** Keep unavailable accuracy distinct from a measured zero. */
    private function number(?float $value): string
    {
        return $value === null ? 'unavailable' : number_format($value, 2);
    }
}
