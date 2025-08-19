<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates nhl_game_summaries into nhl_season_stats per season AND game_type.
 * No zero-fill rows are created.
 */
class SumNhlSeasonStats
{
    /**
     * @param  string $seasonId e.g. '20242025'
     * @return int    number of rows upserted
     */
    public function sum(string $seasonId): int
    {
        // Aggregate per player + game_type
        $rows = DB::table('nhl_game_summaries as gs')
            ->join('nhl_games as g', 'g.nhl_game_id', '=', 'gs.nhl_game_id')
            ->where('g.season_id', $seasonId)
            ->selectRaw('
                gs.nhl_player_id,
                g.game_type as game_type,
                MAX(gs.nhl_team_id) as nhl_team_id,
                COUNT(DISTINCT gs.nhl_game_id) as gp,

                SUM(gs.g) as g,         SUM(gs.evg) as evg,
                SUM(gs.a) as a,         SUM(gs.eva) as eva,
                SUM(gs.a1) as a1,       SUM(gs.eva1) as eva1,
                SUM(gs.a2) as a2,       SUM(gs.eva2) as eva2,
                SUM(gs.pts) as pts,     SUM(gs.evpts) as evpts,

                SUM(gs.gwg) as gwg,     SUM(gs.otg) as otg, SUM(gs.ota) as ota,
                SUM(gs.shog) as shog,   SUM(gs.shogwg) as shogwg,
                SUM(gs.ps) as ps,       SUM(gs.psg) as psg,

                SUM(gs.ens) as ens,     SUM(gs.eng) as eng,

                SUM(gs.fg) as fg,       SUM(gs.htk) as htk,

                SUM(gs.plus_minus) as plus_minus,
                SUM(gs.pim) as pim,

                SUM(COALESCE(gs.toi,0)) as toi,
                SUM(gs.shifts) as shifts,

                SUM(gs.ppg) as ppg,     SUM(gs.ppa) as ppa,
                SUM(gs.ppa1) as ppa1,   SUM(gs.ppa2) as ppa2,
                SUM(gs.ppp) as ppp,

                SUM(gs.pkg) as pkg,     SUM(gs.pka) as pka,
                SUM(gs.pkp) as pkp,

                SUM(gs.b) as b,         SUM(gs.b_teammate) as b_teammate,
                SUM(gs.h) as h,         SUM(gs.th) as th,
                SUM(gs.f) as f,

                SUM(gs.gv) as gv,       SUM(gs.tk) as tk,
                SUM(gs.tkvgv) as tkvgv,

                SUM(gs.fow) as fow,     SUM(gs.fol) as fol,    SUM(gs.fot) as fot,

                SUM(gs.sog) as sog,     SUM(gs.ppsog) as ppsog,   SUM(gs.evsog) as evsog,   SUM(gs.pksog) as pksog,
                SUM(gs.sm)  as sm,      SUM(gs.ppsm)  as ppsm,    SUM(gs.evsm)  as evsm,    SUM(gs.pksm)  as pksm,
                SUM(gs.sb)  as sb,      SUM(gs.ppsb)  as ppsb,    SUM(gs.evsb)  as evsb,    SUM(gs.pksb)  as pksb,
                SUM(gs.sat) as sat,     SUM(gs.ppsat) as ppsat,   SUM(gs.evsat) as evsat,   SUM(gs.pksat) as pksat,

                SUM(gs.sa)  as sa,      SUM(gs.evsa) as evsa,     SUM(gs.ppsa) as ppsa,     SUM(gs.pksa) as pksa,
                SUM(gs.sv)  as sv,      SUM(gs.evsv) as evsv,     SUM(gs.ppsv) as ppsv,     SUM(gs.pksv) as pksv,
                SUM(gs.ga)  as ga,      SUM(gs.evga) as evga,     SUM(gs.ppga) as ppga,     SUM(gs.pkga) as pkga,
                SUM(gs.shosv) as shosv, SUM(gs.so) as so
            ')
            ->groupBy('gs.nhl_player_id', 'g.game_type')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $now = Carbon::now();

        $payload = $rows->map(function ($r) use ($seasonId, $now) {
            $gpRaw = (int) $r->gp;
            $gp    = max(1, $gpRaw);
            $toi   = (int) $r->toi; // seconds

            $g=(int)$r->g; $evg=(int)$r->evg;
            $a=(int)$r->a; $eva=(int)$r->eva;
            $a1=(int)$r->a1; $eva1=(int)$r->eva1;
            $a2=(int)$r->a2; $eva2=(int)$r->eva2;
            $pts=(int)$r->pts; $evpts=(int)$r->evpts;

            $plus_minus=(int)$r->plus_minus; $pim=(int)$r->pim;

            $gwg=(int)$r->gwg; $otg=(int)$r->otg; $ota=(int)$r->ota;
            $shog=(int)$r->shog; $shogwg=(int)$r->shogwg;
            $ps=(int)$r->ps; $psg=(int)$r->psg;

            $ens = (int)$r->ens; $eng = (int)$r->eng;

            $ppg=(int)$r->ppg; $ppa=(int)$r->ppa; $ppa1=(int)$r->ppa1; $ppa2=(int)$r->ppa2; $ppp=(int)$r->ppp;
            $pkg=(int)$r->pkg; $pka=(int)$r->pka; $pkp=(int)$r->pkp;

            $b=(int)$r->b; $b_teammate=(int)$r->b_teammate;
            $h=(int)$r->h; $th=(int)$r->th;
            $f = (int) $r->f;

            $fg=(int)$r->fg; $htk=(int)$r->htk;

            $gv=(int)$r->gv; $tk=(int)$r->tk; $tkvgv=(int)$r->tkvgv;

            $fow=(int)$r->fow; $fol=(int)$r->fol; $fot=(int)$r->fot;

            $sog=(int)$r->sog; $ppsog=(int)$r->ppsog; $evsog=(int)$r->evsog; $pksog=(int)$r->pksog;
            $sm=(int)$r->sm;   $ppsm=(int)$r->ppsm;   $evsm=(int)$r->evsm;   $pksm=(int)$r->pksm;
            $sb=(int)$r->sb;   $ppsb=(int)$r->ppsb;   $evsb=(int)$r->evsb;   $pksb=(int)$r->pksb;
            $sat=(int)$r->sat; $ppsat=(int)$r->ppsat; $evsat=(int)$r->evsat; $pksat=(int)$r->pksat;

            $sa=(int)$r->sa; $evsa=(int)$r->evsa; $ppsa=(int)$r->ppsa; $pksa=(int)$r->pksa;
            $sv=(int)$r->sv; $evsv=(int)$r->evsv; $ppsv=(int)$r->ppsv; $pksv=(int)$r->pksv;
            $ga=(int)$r->ga; $evga=(int)$r->evga; $ppga=(int)$r->ppga; $pkga=(int)$r->pkga;
            $shosv=(int)$r->shosv; $so=(int)$r->so;

            $sog_p   = $this->pct($g,   $sog);
            $ppsog_p = $this->pct($ppg, $ppsog);
            $evsog_p = $this->pct($evg, $evsog);
            $pksog_p = $this->pct($pkg, $pksog);

            $sat_p   = $this->pct($g,   $sat);
            $ppsat_p = $this->pct($ppg, $ppsat);
            $evsat_p = $this->pct($evg, $evsat);
            $pksat_p = $this->pct($pkg, $pksat);

            $fow_percentage = ($fow + $fol) > 0 ? round(($fow / ($fow + $fol)) * 100, 2) : 0.0;

            $g_pg   = $this->perGame($g,   $gp);
            $a_pg   = $this->perGame($a,   $gp);
            $pts_pg = $this->perGame($pts, $gp);

            $g_p60      = $this->per60($g,   $toi);
            $a_p60      = $this->per60($a,   $toi);
            $pts_p60    = $this->per60($pts, $toi);
            $sog_p60    = $this->per60($sog, $toi);
            $sat_p60    = $this->per60($sat, $toi);
            $hits_p60   = $this->per60($h,   $toi);
            $blocks_p60 = $this->per60($b,   $toi);

            $b_pg = $this->perGame($b, $gp);
            $h_pg = $this->perGame($h, $gp);
            $th_pg = $this->perGame($th, $gp);


            return [
                'season_id'     => $seasonId,
                'nhl_player_id' => (string)$r->nhl_player_id,
                'nhl_team_id'   => (int)$r->nhl_team_id,

                'gp'        => $gpRaw,
                'game_type' => (int)$r->game_type,

                'g'=>$g,'evg'=>$evg,
                'a'=>$a,'eva'=>$eva,
                'a1'=>$a1,'eva1'=>$eva1,
                'a2'=>$a2,'eva2'=>$eva2,
                'pts'=>$pts,'evpts'=>$evpts,

                'plus_minus'=>$plus_minus,
                'pim'=>$pim,

                'toi'=>$toi,
                'shifts'=>(int)$r->shifts,

                'ppg'=>$ppg,'ppa'=>$ppa,'ppa1'=>$ppa1,'ppa2'=>$ppa2,'ppp'=>$ppp,
                'pkg'=>$pkg,'pka'=>$pka,'pkp'=>$pkp,

                'b'=>$b,'b_teammate'=>$b_teammate,
                'h'=>$h,'th'=>$th,
                'f' => $f,

                'gwg'=>$gwg,'otg'=>$otg,'ota'=>$ota,
                'shog'=>$shog,'shogwg'=>$shogwg,
                'ps'=>$ps,'psg'=>$psg,

                'fg'=>$fg,'htk'=>$htk,
                'ens'=>$ens,'eng'=>$eng,

                'gv'=>$gv,'tk'=>$tk,'tkvgv'=>$tkvgv,

                'fow'=>$fow,'fol'=>$fol,'fot'=>$fot,'fow_percentage'=>$fow_percentage,

                'sog'=>$sog,'ppsog'=>$ppsog,'evsog'=>$evsog,'pksog'=>$pksog,
                'sm'=>$sm,'ppsm'=>$ppsm,'evsm'=>$evsm,'pksm'=>$pksm,
                'sb'=>$sb,'ppsb'=>$ppsb,'evsb'=>$evsb,'pksb'=>$pksb,
                'sat'=>$sat,'ppsat'=>$ppsat,'evsat'=>$evsat,'pksat'=>$pksat,

                'sa'=>$sa,'evsa'=>$evsa,'ppsa'=>$ppsa,'pksa'=>$pksa,
                'sv'=>$sv,'evsv'=>$evsv,'ppsv'=>$ppsv,'pksv'=>$pksv,
                'ga'=>$ga,'evga'=>$evga,'ppga'=>$ppga,'pkga'=>$pkga,
                'shosv'=>$shosv,'so'=>$so,

                'sog_p'=>$sog_p,'ppsog_p'=>$ppsog_p,'evsog_p'=>$evsog_p,'pksog_p'=>$pksog_p,
                'sat_p'=>$sat_p,'ppsat_p'=>$ppsat_p,'evsat_p'=>$evsat_p,'pksat_p'=>$pksat_p,

                'g_pg'=>$g_pg,'a_pg'=>$a_pg,'pts_pg'=>$pts_pg,
                'g_p60'=>$g_p60,'a_p60'=>$a_p60,'pts_p60'=>$pts_p60,
                'sog_p60'=>$sog_p60,'sat_p60'=>$sat_p60,
                'hits_p60'=>$hits_p60,'blocks_p60'=>$blocks_p60,

                'b_pg'=>$b_pg,'h_pg'=>$h_pg,'th_pg'=>$th_pg,

                'created_at'=>$now,
                'updated_at'=>$now,
            ];
        });


        $uniqueBy = ['season_id', 'nhl_player_id', 'game_type'];
        $allCols  = array_keys($payload->first());
        $update   = array_diff($allCols, array_merge($uniqueBy, ['created_at']));

        $maxParams = 65000;
        $colsCount = count($allCols);
        $chunkSize = max(1, intdiv($maxParams, $colsCount));

        $payload->chunk($chunkSize)->each(function ($chunk) use ($uniqueBy, $update) {
            DB::table('nhl_season_stats')->upsert($chunk->all(), $uniqueBy, $update);
        });

        // DB::table('nhl_season_stats')->upsert(
        //     $payload->all(),
        //     ['season_id', 'nhl_player_id', 'game_type'],
        //     array_diff(array_keys($payload->first()), ['season_id', 'nhl_player_id', 'game_type', 'created_at'])
        // );

        return $payload->count();
    }

    private function pct(int|float $num, int|float $den): float
    {
        return $den > 0 ? round(($num / $den) * 100, 3) : 0.0;
    }

    private function perGame(int|float $total, int $gp): float
    {
        return $gp > 0 ? round($total / $gp, 3) : 0.0;
    }

    private function per60(int|float $total, int $toiSeconds): float
    {
        return $toiSeconds > 0 ? round(($total * 3600) / $toiSeconds, 3) : 0.0;
    }
}
