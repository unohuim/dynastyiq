<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\HasAPITrait;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refreshes NHL schedule rows without seeding the game import pipeline.
 */
class NhlScheduleRefresh
{
    use HasAPITrait;

    /**
     * @var array<int, string>
     */
    private const PREGAME_STATES = ['', 'FUT', 'PRE'];

    /**
     * @var array<int, string>
     */
    private const UPSERT_COLUMNS = [
        'season_id',
        'game_type',
        'game_date',
        'game_dow',
        'game_month',
        'venue',
        'venue_location',
        'start_time_utc',
        'eastern_utc_offset',
        'venue_utc_offset',
        'shootout_in_use',
        'ot_in_use',
        'game_state',
        'game_schedule_state',
        'current_period',
        'period_type',
        'max_regulation_periods',
        'clock_time_remaining',
        'clock_seconds_remaining',
        'clock_running',
        'clock_in_intermission',
        'clock_display_period',
        'clock_max_periods',
        'tv_broadcasts',
        'game_outcome',
        'home_team_id',
        'home_team_common_name',
        'home_team_abbrev',
        'home_team_score',
        'home_team_sog',
        'home_team_logo',
        'home_team_dark_logo',
        'home_team_place_name',
        'away_team_id',
        'away_team_common_name',
        'away_team_abbrev',
        'away_team_score',
        'away_team_sog',
        'away_team_logo',
        'away_team_dark_logo',
        'away_team_place_name',
        'limited_scoring',
        'updated_at',
    ];

    /**
     * Refresh one NHL schedule date.
     *
     * @return array{date:string,fetched:int,deleted:int,inserted:int,upserted:int,mode:string,replaceable:bool,skipped:bool}
     */
    public function refreshDate(Carbon|string $date): array
    {
        $date = $this->date($date);
        $payload = $this->schedulePayload($date);
        $rows = $this->gameRows($payload, $date);
        $replaceable = $this->dateIsReplaceable($date);

        if ($replaceable) {
            $deleted = DB::table('nhl_games')
                ->whereDate('game_date', $date->toDateString())
                ->delete();

            if ($rows !== []) {
                DB::table('nhl_games')->insert($rows);
            }

            return [
                'date' => $date->toDateString(),
                'fetched' => count($rows),
                'deleted' => $deleted,
                'inserted' => count($rows),
                'upserted' => 0,
                'mode' => 'replace',
                'replaceable' => true,
                'skipped' => false,
            ];
        }

        if ($rows !== []) {
            DB::table('nhl_games')->upsert(
                $rows,
                ['nhl_game_id'],
                $this->upsertColumns()
            );
        }

        return [
            'date' => $date->toDateString(),
            'fetched' => count($rows),
            'deleted' => 0,
            'inserted' => 0,
            'upserted' => count($rows),
            'mode' => 'upsert',
            'replaceable' => false,
            'skipped' => false,
        ];
    }

    /**
     * Refresh an inclusive date range.
     *
     * @return array{from:string,to:string,dates:int,fetched:int,deleted:int,inserted:int,upserted:int,replaced_dates:int,upserted_dates:int,failed_dates:array<int,array{date:string,error:string}>}
     */
    public function refreshRange(Carbon|string $from, Carbon|string $to): array
    {
        $from = $this->date($from);
        $to = $this->date($to);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $summary = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dates' => 0,
            'fetched' => 0,
            'deleted' => 0,
            'inserted' => 0,
            'upserted' => 0,
            'replaced_dates' => 0,
            'upserted_dates' => 0,
            'failed_dates' => [],
        ];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            try {
                $result = $this->refreshDate($date);
            } catch (\Throwable $throwable) {
                $summary['failed_dates'][] = [
                    'date' => $date->toDateString(),
                    'error' => $throwable->getMessage(),
                ];

                continue;
            }

            $summary['dates']++;
            $summary['fetched'] += $result['fetched'];
            $summary['deleted'] += $result['deleted'];
            $summary['inserted'] += $result['inserted'];
            $summary['upserted'] += $result['upserted'];

            if ($result['mode'] === 'replace') {
                $summary['replaced_dates']++;
            } else {
                $summary['upserted_dates']++;
            }
        }

        return $summary;
    }

    private function date(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(Carbon $date): array
    {
        try {
            $payload = $this->getAPIData('nhl', 'dailyscores', [
                'date' => $date->toDateString(),
            ]);
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                return ['games' => []];
            }

            throw $exception;
        }

        return is_array($payload) ? $payload : ['games' => []];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gameRows(array $payload, Carbon $date): array
    {
        $now = now();
        $rows = [];

        foreach (($payload['games'] ?? []) as $game) {
            if (! is_array($game)) {
                continue;
            }

            $gameId = (int) ($game['id'] ?? 0);
            $seasonId = (string) ($game['season'] ?? '');
            $gameType = (int) ($game['gameType'] ?? 0);

            if ($gameId <= 0 || $seasonId === '' || $gameType <= 0) {
                continue;
            }

            $gameDate = $this->date((string) ($game['gameDate'] ?? $date->toDateString()));

            $rows[] = [
                'nhl_game_id' => $gameId,
                'season_id' => $seasonId,
                'game_type' => $gameType,
                'game_date' => $gameDate->toDateString(),
                'game_dow' => $gameDate->format('l'),
                'game_month' => $gameDate->format('F'),
                'venue' => $this->localizedString($game['venue'] ?? null),
                'venue_location' => $this->localizedString($game['venueLocation'] ?? null),
                'start_time_utc' => $game['startTimeUTC'] ?? null,
                'eastern_utc_offset' => $game['easternUTCOffset'] ?? null,
                'venue_utc_offset' => $game['venueUTCOffset'] ?? null,
                'shootout_in_use' => (bool) ($game['shootoutInUse'] ?? false),
                'ot_in_use' => (bool) ($game['otInUse'] ?? false),
                'game_state' => $game['gameState'] ?? null,
                'game_schedule_state' => $game['gameScheduleState'] ?? null,
                'current_period' => $game['periodDescriptor']['number'] ?? null,
                'period_type' => $game['periodDescriptor']['periodType'] ?? null,
                'max_regulation_periods' => $game['periodDescriptor']['maxRegulationPeriods'] ?? null,
                'clock_time_remaining' => $game['clock']['timeRemaining'] ?? null,
                'clock_seconds_remaining' => isset($game['clock']['secondsRemaining'])
                    ? (string) $game['clock']['secondsRemaining']
                    : null,
                'clock_running' => isset($game['clock']['running']) ? (string) (int) (bool) $game['clock']['running'] : null,
                'clock_in_intermission' => isset($game['clock']['inIntermission'])
                    ? (string) (int) (bool) $game['clock']['inIntermission']
                    : null,
                'clock_display_period' => $game['clock']['displayPeriod'] ?? null,
                'clock_max_periods' => isset($game['clock']['maxPeriods']) ? (string) $game['clock']['maxPeriods'] : null,
                'tv_broadcasts' => isset($game['tvBroadcasts']) ? json_encode($game['tvBroadcasts'], JSON_THROW_ON_ERROR) : null,
                'game_outcome' => isset($game['gameOutcome']) ? json_encode($game['gameOutcome'], JSON_THROW_ON_ERROR) : null,
                'home_team_id' => $game['homeTeam']['id'] ?? null,
                'home_team_common_name' => $this->localizedString($game['homeTeam']['commonName'] ?? null),
                'home_team_abbrev' => $game['homeTeam']['abbrev'] ?? null,
                'home_team_score' => $game['homeTeam']['score'] ?? null,
                'home_team_sog' => $game['homeTeam']['sog'] ?? null,
                'home_team_logo' => $game['homeTeam']['logo'] ?? null,
                'home_team_dark_logo' => $game['homeTeam']['darkLogo'] ?? null,
                'home_team_place_name' => $this->localizedString($game['homeTeam']['placeName'] ?? null),
                'away_team_id' => $game['awayTeam']['id'] ?? null,
                'away_team_common_name' => $this->localizedString($game['awayTeam']['commonName'] ?? null),
                'away_team_abbrev' => $game['awayTeam']['abbrev'] ?? null,
                'away_team_score' => $game['awayTeam']['score'] ?? null,
                'away_team_sog' => $game['awayTeam']['sog'] ?? null,
                'away_team_logo' => $game['awayTeam']['logo'] ?? null,
                'away_team_dark_logo' => $game['awayTeam']['darkLogo'] ?? null,
                'away_team_place_name' => $this->localizedString($game['awayTeam']['placeName'] ?? null),
                'limited_scoring' => (bool) ($game['limitedScoring'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function localizedString(mixed $value): ?string
    {
        if (is_array($value)) {
            $localized = $value['default'] ?? $value['en'] ?? null;

            return is_scalar($localized) ? (string) $localized : null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function dateIsReplaceable(Carbon $date): bool
    {
        if ($date->lt(today())) {
            return false;
        }

        $gameIds = DB::table('nhl_games')
            ->whereDate('game_date', $date->toDateString())
            ->pluck('nhl_game_id')
            ->map(fn (mixed $gameId): int => (int) $gameId)
            ->values();

        if ($gameIds->isEmpty()) {
            return true;
        }

        $hasNonPregame = DB::table('nhl_games')
            ->whereIn('nhl_game_id', $gameIds)
            ->whereNotNull('game_state')
            ->whereNotIn('game_state', self::PREGAME_STATES)
            ->exists();

        if ($hasNonPregame) {
            return false;
        }

        return ! $this->hasDownstreamData($gameIds->all());
    }

    /**
     * @param array<int, int> $gameIds
     */
    private function hasDownstreamData(array $gameIds): bool
    {
        foreach ($this->downstreamTables() as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->whereIn($column, $gameIds)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function downstreamTables(): array
    {
        return [
            'play_by_plays' => 'nhl_game_id',
            'nhl_game_summaries' => 'nhl_game_id',
            'nhl_boxscores' => 'nhl_game_id',
            'nhl_unit_shifts' => 'nhl_game_id',
            'nhl_unit_game_summaries' => 'nhl_game_id',
            'nhl_player_game_strength_summaries' => 'nhl_game_id',
            'nhl_unit_game_strength_summaries' => 'nhl_game_id',
            'nhl_game_validations' => 'nhl_game_id',
            'nhl_shot_attempts_facts' => 'nhl_game_id',
            'nhl_faceoff_facts' => 'nhl_game_id',
            'nhl_shot_attempt_predictions' => 'nhl_game_id',
            'nhl_import_progress' => 'game_id',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function upsertColumns(): array
    {
        return self::UPSERT_COLUMNS;
    }
}
