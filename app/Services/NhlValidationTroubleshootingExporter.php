<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGameValidation;
use App\Models\NhlShift;
use App\Models\PlayByPlay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Writes markdown troubleshooting snapshots for failed NHL game validations.
 */
class NhlValidationTroubleshootingExporter
{
    public function __construct(private readonly NhlPbpEventNormalizer $normalizer)
    {
    }

    /**
     * Export boxscore, play-by-play, and shift context for a failed validation.
     */
    public function export(NhlGameValidation $validation): void
    {
        $validation->loadMissing(['deltas.player', 'game']);

        $directory = (string) config('apiImportNhl.validation_troubleshooting_path');
        if ($directory === '') {
            return;
        }

        File::ensureDirectoryExists($directory);

        $gameId = (int) $validation->nhl_game_id;
        $playerIds = $validation->deltas
            ->pluck('nhl_player_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        File::put($directory . "/boxscore_{$gameId}.md", $this->boxscoreMarkdown($validation, $playerIds));
        File::put($directory . "/pbp_{$gameId}.md", $this->pbpMarkdown($validation, $playerIds));
        File::put($directory . "/shifts_{$gameId}.md", $this->shiftsMarkdown($validation, $playerIds));
    }

    /**
     * @param Collection<int,int> $playerIds
     */
    private function boxscoreMarkdown(NhlGameValidation $validation, Collection $playerIds): string
    {
        $rows = NhlBoxscore::query()
            ->where('nhl_game_id', $validation->nhl_game_id)
            ->whereIn('nhl_player_id', $playerIds)
            ->orderBy('nhl_team_id')
            ->orderBy('player_name')
            ->get();

        $lines = $this->header($validation, 'Boxscore');
        $lines[] = '## Official Rows';
        $lines[] = '';
        $lines[] = '| Player | Position | SOG | Saves | SA | GA | EV S/SA/GA | PP S/SA/GA | PK S/SA/GA | TOI | Shifts |';
        $lines[] = '| --- | --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- | ---: |';

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %s (%s) | %s | %d | %d | %d | %d | %d/%d/%d | %d/%d/%d | %d/%d/%d | %s | %d |',
                $row->player_name ?? 'N/A',
                $row->nhl_player_id,
                $row->position ?? 'N/A',
                (int) $row->sog,
                (int) $row->saves,
                (int) $row->shots_against,
                (int) $row->goals_against,
                (int) $row->ev_saves,
                (int) $row->ev_shots_against,
                (int) $row->ev_goals_against,
                (int) $row->pp_saves,
                (int) $row->pp_shots_against,
                (int) $row->pp_goals_against,
                (int) $row->pk_saves,
                (int) $row->pk_shots_against,
                (int) $row->pk_goals_against,
                $row->toi ?? 'N/A',
                (int) $row->shifts
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param Collection<int,int> $playerIds
     */
    private function pbpMarkdown(NhlGameValidation $validation, Collection $playerIds): string
    {
        $events = PlayByPlay::query()
            ->where('nhl_game_id', $validation->nhl_game_id)
            ->where(function ($query) use ($playerIds): void {
                foreach ([
                    'nhl_player_id',
                    'scoring_player_id',
                    'assist1_player_id',
                    'assist2_player_id',
                    'shooting_player_id',
                    'goalie_in_net_player_id',
                    'committed_by_player_id',
                    'drawn_by_player_id',
                ] as $column) {
                    $query->orWhereIn($column, $playerIds);
                }
            })
            ->orderBy('seconds_in_game')
            ->orderBy('sort_order')
            ->get();

        $lines = $this->header($validation, 'Play By Play');
        $lines[] = '## Related Events';
        $lines[] = '';
        $lines[] = '| Event | Time | Type | Strength | Situation | Owner | Shooter | Scorer | Goalie | Shot Type | Counts SOG | Provider SOG |';
        $lines[] = '| ---: | --- | --- | --- | --- | ---: | ---: | ---: | ---: | --- | --- | --- |';

        foreach ($events as $event) {
            $metadata = $event->metadata ?? [];
            $details = $metadata['details'] ?? [];
            $lines[] = sprintf(
                '| %s | P%s %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s/%s |',
                $event->nhl_event_id,
                $event->period,
                $event->time_in_period,
                $event->type_desc_key,
                $event->strength,
                $event->situation_code ?? 'N/A',
                $event->event_owner_team_id ?? 'N/A',
                $event->shooting_player_id ?? 'N/A',
                $event->scoring_player_id ?? 'N/A',
                $event->goalie_in_net_player_id ?? 'N/A',
                $event->shot_type ?? 'N/A',
                $this->normalizer->isShotOnGoal($event) ? 'yes' : 'no',
                $details['awaySOG'] ?? 'N/A',
                $details['homeSOG'] ?? 'N/A'
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param Collection<int,int> $playerIds
     */
    private function shiftsMarkdown(NhlGameValidation $validation, Collection $playerIds): string
    {
        $rows = NhlShift::query()
            ->where('nhl_game_id', $validation->nhl_game_id)
            ->whereIn('player_id', $playerIds)
            ->orderBy('player_id')
            ->orderBy('period')
            ->orderBy('start_game_seconds')
            ->get();

        $lines = $this->header($validation, 'Shifts');
        $lines[] = '## Related Shifts';
        $lines[] = '';
        $lines[] = '| Player | Shift | Period | Start | End | Seconds | Event | Type |';
        $lines[] = '| --- | ---: | ---: | --- | --- | ---: | ---: | ---: |';

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %s %s (%s) | %d | %d | %s | %s | %d | %s | %s |',
                $row->first_name,
                $row->last_name,
                $row->player_id,
                (int) $row->shift_number,
                (int) $row->period,
                $row->start_time,
                $row->end_time,
                (int) $row->seconds,
                $row->event_number ?? 'N/A',
                $row->type_code ?? 'N/A'
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<int,string>
     */
    private function header(NhlGameValidation $validation, string $kind): array
    {
        $lines = [
            "# {$kind} Troubleshooting {$validation->nhl_game_id}",
            '',
            sprintf(
                'Status: `%s`  ',
                $validation->status
            ),
            sprintf(
                'Checked: `%s`  ',
                optional($validation->checked_at)->toDateTimeString() ?? 'N/A'
            ),
            sprintf(
                'Mismatch count: `%d`',
                (int) $validation->mismatch_count
            ),
            '',
            '## Deltas',
            '',
            '| Player | Field | Boxscore | Summary | Delta |',
            '| --- | --- | ---: | ---: | ---: |',
        ];

        foreach ($validation->deltas as $delta) {
            $lines[] = sprintf(
                '| %s (%s) | %s | %s | %s | %s |',
                optional($delta->player)->full_name ?? 'NHL ' . ($delta->nhl_player_id ?? 'N/A'),
                $delta->nhl_player_id ?? 'N/A',
                $delta->field,
                $delta->boxscore_value ?? 'NULL',
                $delta->summary_value ?? 'NULL',
                $delta->delta ?? 'N/A'
            );
        }

        $lines[] = '';

        return $lines;
    }
}
