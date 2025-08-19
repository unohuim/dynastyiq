<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlGameSummary;
use App\Services\ImportNHLPlayer;

class SumNHLPlayByPlay
{
    public function summarize(int $nhlGameId): int
    {
        try {
            $plays = PlayByPlay::where('nhl_game_id', $nhlGameId)->get();

            $playerIds = $plays->pluck('nhl_player_id')
                ->filter()
                ->merge($plays->pluck('scoring_player_id')->filter())
                ->merge($plays->pluck('assist1_player_id')->filter())
                ->merge($plays->pluck('assist2_player_id')->filter())
                ->merge($plays->pluck('committed_by_player_id')->filter())
                ->merge($plays->pluck('drawn_by_player_id')->filter())
                ->merge($plays->pluck('blocking_player_id')->filter())
                ->merge($plays->pluck('hitting_player_id')->filter())
                ->merge($plays->pluck('hittee_player_id')->filter())
                ->merge($plays->pluck('shooting_player_id')->filter())
                ->merge($plays->pluck('goalie_in_net_player_id')->filter())
                ->merge($plays->pluck('fo_winning_player_id')->filter())
                ->merge($plays->pluck('fo_losing_player_id')->filter())
                ->unique()
                ->values();

            $byStr = fn (string $s) => $plays->filter(fn ($p) => $p->strength === $s);

            $playerCount = 0;

            foreach ($playerIds as $playerId) {
                $P = fn () => $plays->filter(
                    fn ($p) => in_array($playerId, [
                        $p->nhl_player_id, $p->scoring_player_id, $p->assist1_player_id, $p->assist2_player_id,
                        $p->committed_by_player_id, $p->drawn_by_player_id, $p->blocking_player_id,
                        $p->hitting_player_id, $p->hittee_player_id, $p->shooting_player_id,
                        $p->goalie_in_net_player_id, $p->fo_winning_player_id, $p->fo_losing_player_id,
                    ], true)
                );

                $pp = fn () => $byStr('PP');
                $ev = fn () => $byStr('EV');
                $pk = fn () => $byStr('PK');

                $playerPlays = $P();

                // Fights
                $f = $playerPlays
                    ->where('type_desc_key', 'penalty')
                    ->where('desc_key', 'fighting')
                    ->where('committed_by_player_id', $playerId)
                    ->count();

                // Faceoffs
                $fow = $playerPlays->where('fo_winning_player_id', $playerId)->count();
                $fol = $playerPlays->where('fo_losing_player_id', $playerId)->count();
                $fot = $fow + $fol;
                $fowPct = $fot ? round(($fow / $fot) * 100, 2) : 0.0;

                // Giveaways/Takeaways
                $tk = $playerPlays->where('nhl_player_id', $playerId)->where('type_desc_key', 'takeaway')->count();
                $gv = $playerPlays->where('nhl_player_id', $playerId)->where('type_desc_key', 'giveaway')->count();

                // Shots on goal (include goals as SOG)
                $isSOG = fn ($p) => in_array($p->type_desc_key, ['shot-on-goal', 'goal'], true);
                $sog   = $playerPlays->filter(fn ($p) => $p->shooting_player_id == $playerId && $isSOG($p))->count();
                $ppsog = $pp()->filter(fn ($p) => $p->shooting_player_id == $playerId && $isSOG($p))->count();
                $evsog = $ev()->filter(fn ($p) => $p->shooting_player_id == $playerId && $isSOG($p))->count();
                $pksog = $pk()->filter(fn ($p) => $p->shooting_player_id == $playerId && $isSOG($p))->count();

                // Missed shots
                $sm   = $playerPlays->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();
                $ppsm = $pp()->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();
                $evsm = $ev()->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();
                $pksm = $pk()->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();

                // Blocked shots (by opponent)
                $sb   = $playerPlays->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();
                $ppsb = $pp()->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();
                $evsb = $ev()->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();
                $pksb = $pk()->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();

                // Shot attempts (skater) = sog + sm + sb
                $sat   = $sog + $sm + $sb;
                $ppsat = $ppsog + $ppsm + $ppsb;
                $evsat = $evsog + $evsm + $evsb;
                $pksat = $pksog + $pksm + $pksb;

                // Goals / Assists / Points
                $g   = $playerPlays->where('scoring_player_id', $playerId)->count();
                $evg = $ev()->where('scoring_player_id', $playerId)->count();
                $ppg = $pp()->where('scoring_player_id', $playerId)->count();
                $pkg = $pk()->where('scoring_player_id', $playerId)->count();

                $a   = $playerPlays->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $eva = $ev()->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $ppa = $pp()->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();

                $a1   = $playerPlays->where('assist1_player_id', $playerId)->count();
                $eva1 = $ev()->where('assist1_player_id', $playerId)->count();
                $ppa1 = $pp()->where('assist1_player_id', $playerId)->count();

                $a2   = $playerPlays->where('assist2_player_id', $playerId)->count();
                $eva2 = $ev()->where('assist2_player_id', $playerId)->count();
                $ppa2 = $pp()->where('assist2_player_id', $playerId)->count();

                $pts   = $g + $a;
                $evpts = $evg + $eva;
                $ppp   = $ppg + $ppa;
                $pkp   = $pkg + $ppa; // PK points = goals + assists

                // Blocks (by this skater)
                $b         = $playerPlays->where('blocking_player_id', $playerId)->where('reason', 'blocked')->count();
                $bTeammate = $playerPlays->where('blocking_player_id', $playerId)->where('reason', 'teammate-blocked')->count();

                // Hits
                $h  = $playerPlays->where('hitting_player_id', $playerId)->count();
                $th = $playerPlays->where('hittee_player_id', $playerId)->count();

                // Goalie stats
                $sv   = $playerPlays->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();
                $evsv = $ev()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();
                $ppsv = $pp()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();
                $pksv = $pk()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();

                $ga   = $playerPlays->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $evga = $ev()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $ppga = $pp()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $pkga = $pk()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();

                // Shots against = saves + goals
                $sa   = $sv + $ga;
                $evsa = $evsv + $evga;
                $ppsa = $ppsv + $ppga;
                $pksa = $pksv + $pkga;

                // Shooting % (goals / SOG)
                $sog_p   = $this->pct($g, $sog);
                $ppsog_p = $this->pct($ppg, $ppsog);
                $evsog_p = $this->pct($evg, $evsog);
                $pksog_p = $this->pct($pkg, $pksog);

                if (!ImportNHLPlayer::playerExists((string)$playerId)) {
                    (new ImportNHLPlayer())->import((string)$playerId);
                }

                $summary = [
                    'nhl_game_id'   => $nhlGameId,
                    'nhl_player_id' => (string)$playerId,
                    'nhl_team_id'   => $playerPlays->first()->event_owner_team_id ?? null,

                    // Goals / Assists / Points
                    'g' => $g, 'evg' => $evg, 'ppg' => $ppg, 'pkg' => $pkg,
                    'a' => $a, 'eva' => $eva, 'ppa' => $ppa,
                    'a1' => $a1, 'eva1' => $eva1, 'ppa1' => $ppa1,
                    'a2' => $a2, 'eva2' => $eva2, 'ppa2' => $ppa2,
                    'pts' => $pts, 'evpts' => $evpts, 'ppp' => $ppp, 'pkp' => $pkp,

                    // Plus/Minus (placeholder)
                    'plus_minus' => 0,

                    // PIM
                    'pim' => (int) $playerPlays->where('committed_by_player_id', $playerId)->sum('duration'),

                    // TOI/Shifts (placeholders)
                    'toi' => null,
                    'shifts' => 0,

                    // Blocks / Hits / Fights
                    'b' => $b, 'b_teammate' => $bTeammate,
                    'h' => $h, 'th' => $th,
                    'f' => $f,

                    // Giveaways / Takeaways
                    'gv' => $gv, 'tk' => $tk, 'tkvgv' => ($tk - $gv),

                    // Faceoffs
                    'fow' => $fow, 'fol' => $fol, 'fot' => $fot, 'fow_percentage' => $fowPct,

                    // Shooting (skater)
                    'sog' => $sog, 'ppsog' => $ppsog, 'evsog' => $evsog, 'pksog' => $pksog,
                    'sm'  => $sm,  'ppsm'  => $ppsm,  'evsm'  => $evsm,  'pksm'  => $pksm,
                    'sb'  => $sb,  'ppsb'  => $ppsb,  'evsb'  => $evsb,  'pksb'  => $pksb,
                    'sat' => $sat, 'ppsat' => $ppsat, 'evsat' => $evsat, 'pksat' => $pksat,

                    // Goalie-facing
                    'sv' => $sv, 'evsv' => $evsv, 'ppsv' => $ppsv, 'pksv' => $pksv,
                    'ga' => $ga, 'evga' => $evga, 'ppga' => $ppga, 'pkga' => $pkga,
                    'sa' => $sa, 'evsa' => $evsa, 'ppsa' => $ppsa, 'pksa' => $pksa,

                    // Shooting percentages
                    'sog_p' => $sog_p, 'ppsog_p' => $ppsog_p, 'evsog_p' => $evsog_p, 'pksog_p' => $pksog_p,
                ];

                NhlGameSummary::updateOrCreate(
                    ['nhl_game_id' => $nhlGameId, 'nhl_player_id' => (string)$playerId],
                    $summary
                );

                $playerCount++;
            }

            return $playerCount;
        } catch (\Throwable $e) {
            \Log::error("Summary failed for game {$nhlGameId}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function pct(int|float $num, int|float $den): float
    {
        return $den > 0 ? round(($num / $den) * 100, 2) : 0.0;
    }
}
