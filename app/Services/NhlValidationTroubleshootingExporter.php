<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGameValidation;
use App\Models\NhlShift;
use App\Models\PlayByPlay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes markdown troubleshooting snapshots for NHL game validations with deltas.
 */
class NhlValidationTroubleshootingExporter
{
    public function __construct(private readonly NhlPbpEventNormalizer $normalizer)
    {
    }

    /**
     * Export boxscore, play-by-play, and shift context for a validation with deltas.
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

        $this->writeFile($directory, "deltas_{$gameId}.md", fn (): string => $this->deltasMarkdown($validation));
        $this->writeFile($directory, "boxscore_{$gameId}.md", fn (): string => $this->boxscoreMarkdown($validation, $playerIds));
        $this->writeFile($directory, "pbp_{$gameId}.md", fn (): string => $this->pbpMarkdown($validation, $playerIds));
        $this->writeFile($directory, "shifts_{$gameId}.md", fn (): string => $this->shiftsMarkdown($validation, $playerIds));
        $this->writeRawProviderFiles($directory, $gameId);
    }

    /**
     * Write raw provider payloads as pretty JSON text files.
     */
    private function writeRawProviderFiles(string $directory, int $gameId): void
    {
        foreach ($this->rawProviderUrls($gameId) as $key => $url) {
            $this->writeFile(
                $directory,
                "raw_{$key}_{$gameId}.txt",
                fn (): string => $this->rawProviderPayloadText($key, $url)
            );
        }
    }

    /**
     * @return array<string,string>
     */
    private function rawProviderUrls(int $gameId): array
    {
        return [
            'boxscore' => $this->configuredNhlUrl('boxscore', $gameId),
            'pbp' => $this->configuredNhlUrl('pbp', $gameId),
            'shifts' => "https://api.nhle.com/stats/rest/en/shiftcharts?cayenneExp=gameId={$gameId}",
        ];
    }

    /**
     * Build a configured NHL web API URL.
     */
    private function configuredNhlUrl(string $endpoint, int $gameId): string
    {
        $base = rtrim((string) config('apiurls.nhl.base'), '/');
        $path = (string) config("apiurls.nhl.endpoints.{$endpoint}");

        return $base . '/' . ltrim(str_replace('{gameId}', (string) $gameId, $path), '/');
    }

    /**
     * Fetch and format one raw provider payload.
     */
    private function rawProviderPayloadText(string $source, string $url): string
    {
        try {
            $payload = Http::timeout(30)->acceptJson()->get($url)->throw()->json();

            return $this->prettyJson([
                'source' => $source,
                'url' => $url,
                'payload' => $payload,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to fetch raw NHL validation troubleshooting payload.', [
                'source' => $source,
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            return $this->prettyJson([
                'source' => $source,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Encode payloads for readable troubleshooting text files.
     *
     * @param array<string,mixed> $payload
     */
    private function prettyJson(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return ($json === false ? '{}' : $json) . "\n";
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

        if ($validation->deltas->contains('field', 'plus_minus')) {
            $lines = [
                ...$lines,
                '',
                ...$this->plusMinusGoalContextMarkdown($validation),
            ];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<int,string>
     */
    private function plusMinusGoalContextMarkdown(NhlGameValidation $validation): array
    {
        $rows = DB::table('play_by_plays as p')
            ->leftJoin('event_unit_shifts as eus', 'eus.event_id', '=', 'p.id')
            ->leftJoin('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
            ->leftJoin('nhl_units as u', 'u.id', '=', 'us.unit_id')
            ->leftJoin('nhl_unit_players as up', 'up.unit_id', '=', 'u.id')
            ->leftJoin('players as player', 'player.id', '=', 'up.player_id')
            ->where('p.nhl_game_id', $validation->nhl_game_id)
            ->where('p.type_desc_key', 'goal')
            ->whereIn('p.strength', ['EV', 'PK'])
            ->where(function ($query): void {
                $query->whereNull('p.period_type')
                    ->orWhere('p.period_type', '<>', 'SO');
            })
            ->orderBy('p.seconds_in_game')
            ->orderBy('p.sort_order')
            ->orderBy('us.team_id')
            ->orderBy('u.unit_type')
            ->orderBy('player.nhl_id')
            ->get([
                'p.nhl_event_id',
                'p.period',
                'p.time_in_period',
                'p.strength',
                'p.situation_code',
                'p.event_owner_team_id',
                'p.scoring_player_id',
                'us.id as unit_shift_id',
                'us.team_id as unit_team_id',
                'us.team_abbrev as unit_team_abbrev',
                'us.start_time as unit_start_time',
                'us.end_time as unit_end_time',
                'u.unit_type',
                'player.nhl_id as player_nhl_id',
                'player.full_name as player_name',
            ]);

        $lines = [
            '## Plus/Minus Linked Goal Context',
            '',
            '| Event | Time | Strength | Situation | Owner | Scorer | Unit Shift | Unit | Unit Team | Window | Player | Result |',
            '| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- | --- | --- | ---: |',
        ];

        if ($rows->isEmpty()) {
            $lines[] = '| N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A | N/A | No linked plus/minus goal rows found | N/A |';

            return $lines;
        }

        foreach ($rows as $row) {
            $result = 'N/A';
            if ($row->unit_team_id !== null && $row->event_owner_team_id !== null) {
                $result = (int) $row->unit_team_id === (int) $row->event_owner_team_id ? '+1' : '-1';
            }

            $lines[] = sprintf(
                '| %s | P%s %s | %s | %s | %s | %s | %s | %s | %s (%s) | %s-%s | %s (%s) | %s |',
                $row->nhl_event_id ?? 'N/A',
                $row->period ?? 'N/A',
                $row->time_in_period ?? 'N/A',
                $row->strength ?? 'N/A',
                $row->situation_code ?? 'N/A',
                $row->event_owner_team_id ?? 'N/A',
                $row->scoring_player_id ?? 'N/A',
                $row->unit_shift_id ?? 'N/A',
                $row->unit_type ?? 'N/A',
                $row->unit_team_abbrev ?? 'N/A',
                $row->unit_team_id ?? 'N/A',
                $row->unit_start_time ?? 'N/A',
                $row->unit_end_time ?? 'N/A',
                $row->player_name ?? 'N/A',
                $row->player_nhl_id ?? 'N/A',
                $result
            );
        }

        return $lines;
    }

    /**
     * @param Collection<int,int> $playerIds
     */
    private function shiftsMarkdown(NhlGameValidation $validation, Collection $playerIds): string
    {
        $rows = NhlShift::query()
            ->where('nhl_game_id', $validation->nhl_game_id)
            ->whereIn('nhl_player_id', $playerIds)
            ->orderBy('nhl_player_id')
            ->orderBy('period')
            ->orderBy('shift_start_seconds')
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
                $row->nhl_player_id,
                (int) $row->shift_number,
                (int) $row->period,
                $row->start_time,
                $row->end_time,
                (int) $row->shift_duration_seconds,
                $row->event_number ?? 'N/A',
                $row->type_code ?? 'N/A'
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function deltasMarkdown(NhlGameValidation $validation): string
    {
        return implode("\n", $this->header($validation, 'Deltas')) . "\n";
    }

    private function writeFile(string $directory, string $filename, callable $content): void
    {
        try {
            File::put($directory . '/' . $filename, $content());
        } catch (\Throwable $exception) {
            Log::warning('Failed to write NHL validation troubleshooting file.', [
                'file' => $filename,
                'message' => $exception->getMessage(),
            ]);
        }
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
