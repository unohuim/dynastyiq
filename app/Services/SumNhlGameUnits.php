<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SumNhlGameUnits
{
    protected int $gameId;

    public function __construct(int|string $gameId)
    {
        $this->gameId = (int) $gameId;
    }

    public function sum(): int
    {
        $now = Carbon::now();

        // 1) Aggregate shifts only (no zone counts here)
        $shiftAgg = DB::table('nhl_unit_shifts as us')
            ->where('us.nhl_game_id', $this->gameId)
            ->groupBy('us.unit_id')
            ->selectRaw(<<<'SQL'
                us.unit_id,
                ? as nhl_game_id,
                MAX(us.team_id)       as team_id,
                MAX(us.team_abbrev)   as team_abbrev,
                SUM(us.seconds)       as toi,
                COUNT(*)              as shifts
            SQL, [$this->gameId]);

        // 2) Aggregate events per unit (includes zone starts from faceoffs)
        $eventAgg = DB::table('event_unit_shifts as eus')
            ->join('nhl_unit_shifts as us', 'us.id', '=', 'eus.unit_shift_id')
            ->join('play_by_plays as p', 'p.id', '=', 'eus.event_id')
            ->where('us.nhl_game_id', $this->gameId)
            ->groupBy('us.unit_id')
            ->selectRaw(<<<'SQL'
                us.unit_id,

                -- Goals for/against (and strength splits)
                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as gf,
                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as ga,

                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.strength = 'EV' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as ev_gf,
                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.strength = 'PP' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as pp_gf,
                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.strength = 'PK' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as pk_gf,

                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.strength = 'EV' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as ev_ga,
                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.strength = 'PP' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as pp_ga,
                SUM(CASE WHEN p.type_desc_key = 'goal' AND p.strength = 'PK' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as pk_ga,

                -- Shots on goal (goal counts as SOG)
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as sf,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as sa,

                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.strength = 'EV' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as ev_sf,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.strength = 'PP' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as pp_sf,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.strength = 'PK' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as pk_sf,

                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.strength = 'EV' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as ev_sa,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.strength = 'PP' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as pp_sa,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal') AND p.strength = 'PK' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as pk_sa,

                -- Corsi/Fenwick
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal','missed-shot','blocked-shot') AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as satf,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal','missed-shot','blocked-shot') AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as sata,

                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal','missed-shot') AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as ff,
                SUM(CASE WHEN p.type_desc_key IN ('shot-on-goal','goal','missed-shot') AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as fa,

                -- Blocks & hits (team perspective)
                SUM(CASE WHEN p.type_desc_key = 'blocked-shot' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as bf,
                SUM(CASE WHEN p.type_desc_key = 'blocked-shot' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as ba,

                SUM(CASE WHEN p.hitting_player_id IS NOT NULL AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as hf,
                SUM(CASE WHEN p.hitting_player_id IS NOT NULL AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as ha,

                -- Faceoffs
                SUM(CASE WHEN p.fo_winning_player_id IS NOT NULL AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as fow,
                SUM(CASE WHEN p.fo_winning_player_id IS NOT NULL AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as fol,

                -- Fights & PIM
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.desc_key = 'fighting' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as f,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id = us.team_id THEN COALESCE(p.duration,0) ELSE 0 END) as pim_f,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id <> us.team_id THEN COALESCE(p.duration,0) ELSE 0 END) as pim_a,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id = us.team_id THEN 1 ELSE 0 END) as penalties_f,
                SUM(CASE WHEN p.type_desc_key = 'penalty' AND p.event_owner_team_id <> us.team_id THEN 1 ELSE 0 END) as penalties_a,

                -- Zone starts from faceoffs while this unit is on-ice
                SUM(CASE 
                      WHEN p.type_desc_key = 'faceoff' 
                       AND (
                            (UPPER(IFNULL(p.zone_code,'')) IN ('O','OZ') AND p.event_owner_team_id = us.team_id) OR
                            (UPPER(IFNULL(p.zone_code,'')) IN ('D','DZ') AND p.event_owner_team_id <> us.team_id)
                       )
                    THEN 1 ELSE 0 END) AS ozs,

                SUM(CASE 
                      WHEN p.type_desc_key = 'faceoff' 
                       AND UPPER(IFNULL(p.zone_code,'')) IN ('N','NZ')
                    THEN 1 ELSE 0 END) AS nzs,

                SUM(CASE 
                      WHEN p.type_desc_key = 'faceoff' 
                       AND (
                            (UPPER(IFNULL(p.zone_code,'')) IN ('D','DZ') AND p.event_owner_team_id = us.team_id) OR
                            (UPPER(IFNULL(p.zone_code,'')) IN ('O','OZ') AND p.event_owner_team_id <> us.team_id)
                       )
                    THEN 1 ELSE 0 END) AS dzs
            SQL);

        // 3) Join aggregates and fetch
        $rows = DB::query()
            ->fromSub($shiftAgg, 's')
            ->leftJoinSub($eventAgg, 'e', 'e.unit_id', '=', 's.unit_id')
            ->selectRaw(<<<'SQL'
                s.*,
                COALESCE(e.ozs,0) ozs, COALESCE(e.nzs,0) nzs, COALESCE(e.dzs,0) dzs,
                COALESCE(e.gf,0) gf, COALESCE(e.ga,0) ga,
                COALESCE(e.ev_gf,0) ev_gf, COALESCE(e.pp_gf,0) pp_gf, COALESCE(e.pk_gf,0) pk_gf,
                COALESCE(e.ev_ga,0) ev_ga, COALESCE(e.pp_ga,0) pp_ga, COALESCE(e.pk_ga,0) pk_ga,
                COALESCE(e.sf,0) sf, COALESCE(e.sa,0) sa,
                COALESCE(e.ev_sf,0) ev_sf, COALESCE(e.pp_sf,0) pp_sf, COALESCE(e.pk_sf,0) pk_sf,
                COALESCE(e.ev_sa,0) ev_sa, COALESCE(e.pp_sa,0) pp_sa, COALESCE(e.pk_sa,0) pk_sa,
                COALESCE(e.satf,0) satf, COALESCE(e.sata,0) sata,
                COALESCE(e.ff,0) ff, COALESCE(e.fa,0) fa,
                COALESCE(e.bf,0) bf, COALESCE(e.ba,0) ba,
                COALESCE(e.hf,0) hf, COALESCE(e.ha,0) ha,
                COALESCE(e.fow,0) fow, COALESCE(e.fol,0) fol,
                COALESCE(e.f,0) f, COALESCE(e.pim_f,0) pim_f, COALESCE(e.pim_a,0) pim_a,
                COALESCE(e.penalties_f,0) penalties_f, COALESCE(e.penalties_a,0) penalties_a
            SQL)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $payload = $rows->map(function ($r) use ($now) {
            $fow = (int) ($r->fow ?? 0);
            $fol = (int) ($r->fol ?? 0);

            return [
                'nhl_game_id'  => (int) $r->nhl_game_id,
                'unit_id'      => (int) $r->unit_id,
                'team_id'      => $r->team_id ? (int) $r->team_id : null,
                'team_abbrev'  => $r->team_abbrev ?: null,
                'toi'          => (int) ($r->toi ?? 0),
                'shifts'       => (int) ($r->shifts ?? 0),
                'ozs'          => (int) ($r->ozs ?? 0),
                'nzs'          => (int) ($r->nzs ?? 0),
                'dzs'          => (int) ($r->dzs ?? 0),
                'gf'           => (int) ($r->gf ?? 0),
                'ga'           => (int) ($r->ga ?? 0),
                'ev_gf'        => (int) ($r->ev_gf ?? 0),
                'pp_gf'        => (int) ($r->pp_gf ?? 0),
                'pk_gf'        => (int) ($r->pk_gf ?? 0),
                'ev_ga'        => (int) ($r->ev_ga ?? 0),
                'pp_ga'        => (int) ($r->pp_ga ?? 0),
                'pk_ga'        => (int) ($r->pk_ga ?? 0),
                'sf'           => (int) ($r->sf ?? 0),
                'sa'           => (int) ($r->sa ?? 0),
                'ev_sf'        => (int) ($r->ev_sf ?? 0),
                'pp_sf'        => (int) ($r->pp_sf ?? 0),
                'pk_sf'        => (int) ($r->pk_sf ?? 0),
                'ev_sa'        => (int) ($r->ev_sa ?? 0),
                'pp_sa'        => (int) ($r->pp_sa ?? 0),
                'pk_sa'        => (int) ($r->pk_sa ?? 0),
                'satf'         => (int) ($r->satf ?? 0),
                'sata'         => (int) ($r->sata ?? 0),
                'ff'           => (int) ($r->ff ?? 0),
                'fa'           => (int) ($r->fa ?? 0),
                'bf'           => (int) ($r->bf ?? 0),
                'ba'           => (int) ($r->ba ?? 0),
                'hf'           => (int) ($r->hf ?? 0),
                'ha'           => (int) ($r->ha ?? 0),
                'fow'          => $fow,
                'fol'          => $fol,
                'fot'          => $fow + $fol,
                'f'            => (int) ($r->f ?? 0),
                'pim_f'        => (int) ($r->pim_f ?? 0),
                'pim_a'        => (int) ($r->pim_a ?? 0),
                'penalties_f'  => (int) ($r->penalties_f ?? 0),
                'penalties_a'  => (int) ($r->penalties_a ?? 0),
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        });

        DB::table('nhl_unit_game_summaries')->upsert(
            $payload->all(),
            ['nhl_game_id', 'unit_id'],
            array_diff(array_keys($payload->first()), ['nhl_game_id', 'unit_id', 'created_at'])
        );

        return $payload->count();
    }
}
