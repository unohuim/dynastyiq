<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGameValidation;
use App\Models\NhlPbpSourceMismatch;
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
     * Export raw provider payloads and stoppage context for any game import stoppage.
     *
     * @param array<string,mixed> $context
     */
    public function exportRawProviderPayloads(int $gameId, array $context = []): void
    {
        $directory = (string) config('apiImportNhl.validation_troubleshooting_path');
        if ($directory === '') {
            return;
        }

        File::ensureDirectoryExists($directory);

        $this->writeFile($directory, "stoppage_{$gameId}.md", fn (): string => $this->stoppageMarkdown($gameId, $context));
        $this->writeRawProviderFiles($directory, $gameId);
    }

    /**
     * Export raw API/HTML PBP payloads and first mismatch details for HTML PBP verification failures.
     */
    public function exportHtmlPbpVerification(
        NhlGameValidation $validation,
        string $apiUrl,
        ?string $htmlUrl
    ): void {
        $root = (string) config('apiImportNhl.validation_troubleshooting_path');
        if ($root === '') {
            return;
        }

        $gameId = (int) $validation->nhl_game_id;
        $directory = $root . '/' . $gameId;

        try {
            File::ensureDirectoryExists($directory);
        } catch (\Throwable $exception) {
            Log::warning('Failed to create NHL HTML PBP troubleshooting directory.', [
                'game_id' => $gameId,
                'directory' => $directory,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $this->writeFileIfMissing(
            $directory,
            "raw_api_pbp_{$gameId}.json",
            fn (): string => $this->rawJsonPayload('api_pbp', $apiUrl)
        );

        $this->writeFileIfMissing(
            $directory,
            "raw_html_pbp_{$gameId}.html",
            fn (): string => $htmlUrl !== null && $htmlUrl !== ''
                ? $this->rawTextPayload('html_pbp', $htmlUrl, 'text/html')
                : "source: html_pbp\nurl: N/A\nerror: HTML play-by-play report URL was not available.\n"
        );

        $validation->loadMissing([
            'pbpSourceMismatches' => fn ($query) => $query
                ->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderBy('period')
                ->orderBy('time_in_period'),
        ]);

        $this->writeFile($directory, 'errors.txt', fn (): string => $this->htmlPbpErrorsText($validation, $apiUrl, $htmlUrl));
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
     * @param array<string,mixed> $context
     */
    private function stoppageMarkdown(int $gameId, array $context): string
    {
        $lines = [
            "# Import Stoppage {$gameId}",
            '',
            'Checked: `' . now()->toDateTimeString() . '`  ',
        ];

        foreach ($context as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            $lines[] = sprintf('%s: `%s`  ', ucfirst(str_replace('_', ' ', (string) $key)), (string) $value);
        }

        return implode("\n", $lines) . "\n";
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
     * Fetch one raw text provider payload.
     */
    private function rawTextPayload(string $source, string $url, string $accept): string
    {
        try {
            $response = Http::timeout(30)->accept($accept)->get($url)->throw();

            return $response->body();
        } catch (\Throwable $exception) {
            Log::warning('Failed to fetch raw NHL validation troubleshooting text payload.', [
                'source' => $source,
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            return "source: {$source}\nurl: {$url}\nerror: {$exception->getMessage()}\n";
        }
    }

    /**
     * Fetch one raw JSON provider payload.
     */
    private function rawJsonPayload(string $source, string $url): string
    {
        try {
            $response = Http::timeout(30)->acceptJson()->get($url)->throw();

            return $response->body();
        } catch (\Throwable $exception) {
            Log::warning('Failed to fetch raw NHL validation troubleshooting JSON payload.', [
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
     * Write a file only when it does not already exist.
     */
    private function writeFileIfMissing(string $directory, string $filename, callable $content): void
    {
        if (File::exists($directory . '/' . $filename)) {
            return;
        }

        $this->writeFile($directory, $filename, $content);
    }

    /**
     * Render the first five HTML/API PBP mismatches for offline review.
     */
    private function htmlPbpErrorsText(NhlGameValidation $validation, string $apiUrl, ?string $htmlUrl): string
    {
        $lines = [
            "HTML/API PBP Review {$validation->nhl_game_id}",
            '',
            'Status: ' . $validation->status,
            'Mismatch count: ' . (int) $validation->mismatch_count,
            'Checked: ' . (optional($validation->checked_at)->toDateTimeString() ?? 'N/A'),
            'HTML: ' . ($htmlUrl ?: 'N/A'),
            'API: ' . $apiUrl,
            '',
        ];

        $mismatches = $validation->pbpSourceMismatches->take(5);

        if ($mismatches->isEmpty()) {
            $lines[] = 'No mismatch rows stored.';

            return implode("\n", $lines) . "\n";
        }

        foreach ($mismatches as $index => $mismatch) {
            /** @var NhlPbpSourceMismatch $mismatch */
            $apiEvent = $this->fullApiEventPayload($mismatch);
            $htmlEvent = is_array($mismatch->html_event) ? $mismatch->html_event : null;
            $shiftchartDifference = $this->shiftchartDifference($mismatch);

            $lines[] = '---';
            $lines[] = 'Error ' . ($index + 1);
            $lines[] = 'Severity: ' . ($mismatch->severity ?? 'N/A');
            $lines[] = 'Type: ' . ($mismatch->mismatch_type ?? 'N/A');
            $lines[] = 'Event: ' . ($mismatch->nhl_event_id ?? 'N/A');
            $lines[] = 'Time: P' . ($mismatch->period ?? 'N/A') . ' ' . ($mismatch->time_in_period ?? 'N/A');
            $lines[] = 'Source: ' . ($mismatch->source_url ?? 'N/A');
            $lines[] = 'API event payload:';
            $lines[] = $this->jsonBlock($apiEvent);
            $lines[] = 'HTML PBP event payload:';
            $lines[] = $this->jsonBlock($htmlEvent);
            $lines[] = 'Shiftchart player comparison:';
            $lines[] = $this->jsonBlock([
                'missing_from_shiftcharts' => $shiftchartDifference['missing_from_shiftcharts'],
                'extra_in_shiftcharts' => $shiftchartDifference['extra_in_shiftcharts'],
            ]);

            if ($shiftchartDifference['players'] !== []) {
                $lines[] = 'Shift context for missing/extra players:';
                $lines[] = $this->jsonBlock($shiftchartDifference['players']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Return the full local API event row when available.
     *
     * @return array<string,mixed>|null
     */
    private function fullApiEventPayload(NhlPbpSourceMismatch $mismatch): ?array
    {
        if ($mismatch->play_by_play_id === null) {
            return is_array($mismatch->api_event) ? $mismatch->api_event : null;
        }

        $event = PlayByPlay::query()->find($mismatch->play_by_play_id);

        return $event instanceof PlayByPlay
            ? $event->attributesToArray()
            : (is_array($mismatch->api_event) ? $mismatch->api_event : null);
    }

    /**
     * Calculate HTML-vs-shiftchart player differences and nearby shift rows.
     *
     * @return array{
     *     missing_from_shiftcharts:array<int,int>,
     *     extra_in_shiftcharts:array<int,int>,
     *     players:array<int,array<string,mixed>>
     * }
     */
    private function shiftchartDifference(NhlPbpSourceMismatch $mismatch): array
    {
        $htmlEvent = is_array($mismatch->html_event) ? $mismatch->html_event : [];
        $htmlIds = collect($htmlEvent['resolved_html_on_ice_player_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $shiftchartIds = collect($htmlEvent['linked_on_ice_player_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $missingFromShiftcharts = array_values(array_diff($htmlIds, $shiftchartIds));
        $extraInShiftcharts = array_values(array_diff($shiftchartIds, $htmlIds));
        $playerIds = array_values(array_unique([...$missingFromShiftcharts, ...$extraInShiftcharts]));
        $htmlPlayers = collect($htmlEvent['resolved_html_on_ice_players'] ?? [])
            ->filter(fn (mixed $player): bool => is_array($player))
            ->groupBy(fn (array $player): int => (int) ($player['nhl_player_id'] ?? 0));
        $linkedPlayers = collect($htmlEvent['linked_on_ice_players'] ?? [])
            ->filter(fn (mixed $player): bool => is_array($player))
            ->groupBy(fn (array $player): int => (int) ($player['nhl_player_id'] ?? 0));

        if ($playerIds === []) {
            return [
                'missing_from_shiftcharts' => $missingFromShiftcharts,
                'extra_in_shiftcharts' => $extraInShiftcharts,
                'players' => [],
            ];
        }

        $eventSecond = $this->eventSecond($mismatch);
        $gameId = (int) $mismatch->validation->nhl_game_id;

        return [
            'missing_from_shiftcharts' => $missingFromShiftcharts,
            'extra_in_shiftcharts' => $extraInShiftcharts,
            'players' => collect($playerIds)
                ->map(fn (int $playerId): array => [
                    'nhl_player_id' => $playerId,
                    'classification' => in_array($playerId, $missingFromShiftcharts, true) ? 'missing_from_shiftcharts' : 'extra_in_shiftcharts',
                    'html_player_payloads' => $htmlPlayers->get($playerId, collect())->values()->all(),
                    'linked_shiftchart_player_payloads' => $linkedPlayers->get($playerId, collect())->values()->all(),
                    'previous_shift' => $eventSecond !== null ? $this->shiftSnapshot(
                        NhlShift::query()
                            ->where('nhl_game_id', $gameId)
                            ->where('nhl_player_id', $playerId)
                            ->where('shift_end_seconds', '<=', $eventSecond)
                            ->orderByDesc('shift_end_seconds')
                            ->orderByDesc('shift_start_seconds')
                            ->first()
                    ) : null,
                    'active_shifts' => $eventSecond !== null ? NhlShift::query()
                        ->where('nhl_game_id', $gameId)
                        ->where('nhl_player_id', $playerId)
                        ->where('shift_start_seconds', '<=', $eventSecond)
                        ->where('shift_end_seconds', '>', $eventSecond)
                        ->orderBy('shift_start_seconds')
                        ->limit(3)
                        ->get()
                        ->map(fn (NhlShift $shift): array => $this->shiftSnapshot($shift))
                        ->all() : [],
                    'next_shift' => $eventSecond !== null ? $this->shiftSnapshot(
                        NhlShift::query()
                            ->where('nhl_game_id', $gameId)
                            ->where('nhl_player_id', $playerId)
                            ->where('shift_start_seconds', '>', $eventSecond)
                            ->orderBy('shift_start_seconds')
                            ->orderBy('shift_end_seconds')
                            ->first()
                    ) : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function eventSecond(NhlPbpSourceMismatch $mismatch): ?int
    {
        if ($mismatch->play_by_play_id !== null) {
            $seconds = PlayByPlay::query()
                ->whereKey($mismatch->play_by_play_id)
                ->value('seconds_in_game');

            if ($seconds !== null) {
                return (int) $seconds;
            }
        }

        $period = (int) ($mismatch->period ?? 0);
        $clock = (string) ($mismatch->time_in_period ?? '');

        if ($period < 1 || ! preg_match('/^(\d{1,2}):(\d{2})$/', $clock, $matches)) {
            return null;
        }

        return (($period - 1) * 1200) + ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function shiftSnapshot(?NhlShift $shift): ?array
    {
        if (! $shift instanceof NhlShift) {
            return null;
        }

        return $shift->attributesToArray();
    }

    /**
     * @param mixed $value
     */
    private function jsonBlock(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json === false ? 'null' : $json;
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
