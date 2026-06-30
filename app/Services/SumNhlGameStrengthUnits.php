<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds normalized unit and player on-ice summaries by game and strength.
 */
class SumNhlGameStrengthUnits
{
    /**
     * Create the strength summary service for one NHL game.
     */
    public function __construct(
        private readonly int|string $gameId,
        private readonly NhlPbpEventNormalizer|null $normalizer = null
    )
    {
    }

    /**
     * Sum normalized strength-aware on-ice totals for one game.
     */
    public function sum(): int
    {
        $gameId = (int) $this->gameId;
        $now = Carbon::now();
        $unitRows = $this->unitRows($gameId, $now);

        if ($unitRows->isEmpty()) {
            return 0;
        }

        DB::table('nhl_unit_game_strength_summaries')->upsert(
            $unitRows->all(),
            ['nhl_game_id', 'unit_id', 'strength'],
            array_diff(array_keys($unitRows->first()), ['nhl_game_id', 'unit_id', 'strength', 'created_at'])
        );

        $playerRows = $this->playerRows($gameId, $now);

        if ($playerRows->isNotEmpty()) {
            DB::table('nhl_player_game_strength_summaries')->upsert(
                $playerRows->all(),
                ['nhl_game_id', 'player_id', 'strength'],
                array_diff(array_keys($playerRows->first()), ['nhl_game_id', 'player_id', 'strength', 'created_at'])
            );
        }

        return $unitRows->count() + $playerRows->count();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function unitRows(int $gameId, Carbon $now): Collection
    {
        $strengthExpression = $this->unitStrengthExpression();
        $normalizer = $this->normalizer ?? app(NhlPbpEventNormalizer::class);
        $sogPredicate = $normalizer->boxscoreSogSqlPredicate('p');
        $shotAttemptPredicate = $normalizer->shotAttemptSqlPredicate('p');
        $unblockedShotAttemptPredicate = $normalizer->unblockedShotAttemptSqlPredicate('p');
        $penaltyMinutesExpression = $normalizer->penaltyMinutesSqlExpression('p');

        $shiftAgg = DB::table('nhl_unit_shifts as us')
            ->join('nhl_units as u', 'u.id', '=', 'us.unit_id')
            ->where('us.nhl_game_id', $gameId)
            ->groupBy('us.unit_id', DB::raw($strengthExpression))
            ->selectRaw(<<<SQL
                us.unit_id,
                ? as nhl_game_id,
                MAX(us.team_id) as team_id,
                MAX(us.team_abbrev) as team_abbrev,
                {$strengthExpression} as strength,
                SUM(us.seconds) as toi,
                COUNT(*) as shifts
            SQL, [$gameId]);

        $eventAgg = DB::table('event_unit_shifts as eus')
            ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
            ->join('nhl_units as u', 'u.id', '=', 'us.unit_id')
            ->join('play_by_plays as p', 'p.id', '=', 'eus.event_id')
            ->where('us.nhl_game_id', $gameId)
            ->groupBy('us.unit_id', DB::raw($strengthExpression))
            ->selectRaw(<<<SQL
                us.unit_id,
                {$strengthExpression} as strength,
                SUM(CASE WHEN p.type_desc_key = 'faceoff' AND (
                    (UPPER(COALESCE(p.zone_code,'')) IN ('O','OZ') AND p.event_owner_team_id = us.team_id) OR
                    (UPPER(COALESCE(p.zone_code,'')) IN ('D','DZ') AND p.event_owner_team_id <> us.team_id)
                ) THEN 1 ELSE 0 END) as ozs,
                SUM(CASE WHEN p.type_desc_key = 'faceoff' AND UPPER(COALESCE(p.zone_code,'')) IN ('N','NZ') THEN 1 ELSE 0 END) as nzs,
                SUM(CASE WHEN p.type_desc_key = 'faceoff' AND (
                    (UPPER(COALESCE(p.zone_code,'')) IN ('D','DZ') AND p.event_owner_team_id = us.team_id) OR
                    (UPPER(COALESCE(p.zone_code,'')) IN ('O','OZ') AND p.event_owner_team_id <> us.team_id)
                ) THEN 1 ELSE 0 END) as dzs,
                SUM(CASE WHEN COALESCE(p.period_type,'') <> 'SO' AND p.type_desc_key = 'goal' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as gf,
                SUM(CASE WHEN COALESCE(p.period_type,'') <> 'SO' AND p.type_desc_key = 'goal' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as ga,
                SUM(CASE WHEN {$sogPredicate} AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as sf,
                SUM(CASE WHEN {$sogPredicate} AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as sa,
                SUM(CASE WHEN {$shotAttemptPredicate} AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as satf,
                SUM(CASE WHEN {$shotAttemptPredicate} AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as sata,
                SUM(CASE WHEN {$unblockedShotAttemptPredicate} AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as ff,
                SUM(CASE WHEN {$unblockedShotAttemptPredicate} AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as fa,
                SUM(CASE WHEN p.type_desc_key = 'blocked-shot' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as bf,
                SUM(CASE WHEN p.type_desc_key = 'blocked-shot' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as ba,
                SUM(CASE WHEN p.hitting_player_id IS NOT NULL AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as hf,
                SUM(CASE WHEN p.hitting_player_id IS NOT NULL AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as ha,
                SUM(CASE WHEN p.fo_winning_player_id IS NOT NULL AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as fow,
                SUM(CASE WHEN p.fo_winning_player_id IS NOT NULL AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as fol,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id = us.team_id THEN {$penaltyMinutesExpression} ELSE 0 END) as pim_f,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id <> us.team_id THEN {$penaltyMinutesExpression} ELSE 0 END) as pim_a,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as penalties_f,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as penalties_a
            SQL);

        return DB::query()
            ->fromSub($shiftAgg, 's')
            ->leftJoinSub($eventAgg, 'e', function ($join): void {
                $join->on('e.unit_id', '=', 's.unit_id')
                    ->on('e.strength', '=', 's.strength');
            })
            ->selectRaw(<<<'SQL'
                s.*,
                COALESCE(e.ozs,0) ozs, COALESCE(e.nzs,0) nzs, COALESCE(e.dzs,0) dzs,
                COALESCE(e.gf,0) gf, COALESCE(e.ga,0) ga,
                COALESCE(e.sf,0) sf, COALESCE(e.sa,0) sa,
                COALESCE(e.satf,0) satf, COALESCE(e.sata,0) sata,
                COALESCE(e.ff,0) ff, COALESCE(e.fa,0) fa,
                COALESCE(e.bf,0) bf, COALESCE(e.ba,0) ba,
                COALESCE(e.hf,0) hf, COALESCE(e.ha,0) ha,
                COALESCE(e.fow,0) fow, COALESCE(e.fol,0) fol,
                COALESCE(e.pim_f,0) pim_f, COALESCE(e.pim_a,0) pim_a,
                COALESCE(e.penalties_f,0) penalties_f, COALESCE(e.penalties_a,0) penalties_a
            SQL)
            ->get()
            ->map(fn ($row): array => $this->summaryPayload($row, $now));
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function playerRows(int $gameId, Carbon $now): Collection
    {
        $rows = DB::table('nhl_unit_game_strength_summaries as s')
            ->join('nhl_unit_players as up', 'up.unit_id', '=', 's.unit_id')
            ->join('players as p', 'p.id', '=', 'up.player_id')
            ->where('s.nhl_game_id', $gameId)
            ->groupBy('s.nhl_game_id', 'up.player_id', 'p.nhl_id', 's.strength')
            ->selectRaw(<<<'SQL'
                s.nhl_game_id,
                up.player_id,
                p.nhl_id as nhl_player_id,
                MAX(s.team_id) as team_id,
                MAX(s.team_abbrev) as team_abbrev,
                s.strength,
                SUM(s.toi) as toi,
                SUM(s.shifts) as shifts,
                SUM(s.ozs) as ozs,
                SUM(s.nzs) as nzs,
                SUM(s.dzs) as dzs,
                SUM(s.gf) as gf,
                SUM(s.ga) as ga,
                SUM(s.sf) as sf,
                SUM(s.sa) as sa,
                SUM(s.satf) as satf,
                SUM(s.sata) as sata,
                SUM(s.ff) as ff,
                SUM(s.fa) as fa,
                SUM(s.bf) as bf,
                SUM(s.ba) as ba,
                SUM(s.hf) as hf,
                SUM(s.ha) as ha,
                SUM(s.fow) as fow,
                SUM(s.fol) as fol,
                SUM(s.pim_f) as pim_f,
                SUM(s.pim_a) as pim_a,
                SUM(s.penalties_f) as penalties_f,
                SUM(s.penalties_a) as penalties_a
            SQL)
            ->get();

        $individual = $this->individualPointsByPlayerAndStrength($gameId);

        return $rows->map(function ($row) use ($now, $individual): array {
            $payload = $this->summaryPayload($row, $now);
            $payload['player_id'] = (int) $row->player_id;
            $payload['nhl_player_id'] = (int) $row->nhl_player_id;
            unset($payload['unit_id']);

            $key = $row->nhl_player_id . ':' . $row->strength;
            $points = $individual[$key] ?? ['g' => 0, 'a' => 0, 'pts' => 0];
            $payload['individual_g'] = $points['g'];
            $payload['individual_a'] = $points['a'];
            $payload['individual_pts'] = $points['pts'];
            $payload['ipp'] = ((int) $payload['gf']) > 0
                ? round($points['pts'] / (int) $payload['gf'], 4)
                : 0;

            return $payload;
        });
    }

    /**
     * @return array<string,array{g:int,a:int,pts:int}>
     */
    private function individualPointsByPlayerAndStrength(int $gameId): array
    {
        $points = [];
        $events = DB::table('play_by_plays')
            ->where('nhl_game_id', $gameId)
            ->where('type_desc_key', 'goal')
            ->where(function ($query): void {
                $query->whereNull('period_type')
                    ->orWhere('period_type', '<>', 'SO');
            })
            ->get(['strength', 'scoring_player_id', 'assist1_player_id', 'assist2_player_id']);

        foreach ($events as $event) {
            $strength = in_array($event->strength, ['PP', 'PK'], true) ? $event->strength : 'EV';
            foreach ([
                'scoring_player_id' => ['g' => 1, 'a' => 0, 'pts' => 1],
                'assist1_player_id' => ['g' => 0, 'a' => 1, 'pts' => 1],
                'assist2_player_id' => ['g' => 0, 'a' => 1, 'pts' => 1],
            ] as $field => $increments) {
                $playerId = $event->{$field};
                if (! $playerId) {
                    continue;
                }

                $key = $playerId . ':' . $strength;
                $points[$key] ??= ['g' => 0, 'a' => 0, 'pts' => 0];
                $points[$key]['g'] += $increments['g'];
                $points[$key]['a'] += $increments['a'];
                $points[$key]['pts'] += $increments['pts'];
            }
        }

        return $points;
    }

    private function summaryPayload(object $row, Carbon $now): array
    {
        $fow = (int) ($row->fow ?? 0);
        $fol = (int) ($row->fol ?? 0);

        return [
            'nhl_game_id' => (int) $row->nhl_game_id,
            'unit_id' => isset($row->unit_id) ? (int) $row->unit_id : null,
            'team_id' => $row->team_id ? (int) $row->team_id : null,
            'team_abbrev' => $row->team_abbrev ?: null,
            'strength' => (string) $row->strength,
            'toi' => (int) ($row->toi ?? 0),
            'shifts' => (int) ($row->shifts ?? 0),
            'ozs' => (int) ($row->ozs ?? 0),
            'nzs' => (int) ($row->nzs ?? 0),
            'dzs' => (int) ($row->dzs ?? 0),
            'gf' => (int) ($row->gf ?? 0),
            'ga' => (int) ($row->ga ?? 0),
            'sf' => (int) ($row->sf ?? 0),
            'sa' => (int) ($row->sa ?? 0),
            'satf' => (int) ($row->satf ?? 0),
            'sata' => (int) ($row->sata ?? 0),
            'ff' => (int) ($row->ff ?? 0),
            'fa' => (int) ($row->fa ?? 0),
            'bf' => (int) ($row->bf ?? 0),
            'ba' => (int) ($row->ba ?? 0),
            'hf' => (int) ($row->hf ?? 0),
            'ha' => (int) ($row->ha ?? 0),
            'fow' => $fow,
            'fol' => $fol,
            'fot' => $fow + $fol,
            'pim_f' => (int) ($row->pim_f ?? 0),
            'pim_a' => (int) ($row->pim_a ?? 0),
            'penalties_f' => (int) ($row->penalties_f ?? 0),
            'penalties_a' => (int) ($row->penalties_a ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function unitStrengthExpression(): string
    {
        return "CASE WHEN u.unit_type IN ('PP','PK') THEN u.unit_type ELSE 'EV' END";
    }
}
