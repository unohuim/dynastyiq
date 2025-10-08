<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlGameSummary;
use App\Services\ImportNHLPlayer;
use App\Models\NhlGame;
use Illuminate\Support\Collection;

class SumNHLPlayByPlay
{
    public function summarize(int $nhlGameId): int
    {
        try {
            $plays = PlayByPlay::where('nhl_game_id', $nhlGameId)->get();

            // Home/Away map (needed for empty-net detection via situationCode)
            $game = NhlGame::where('nhl_game_id', $nhlGameId)->first();
            $homeTeamId = (int)($game->home_team_id ?? 0);
            $awayTeamId = (int)($game->away_team_id ?? 0);

            // --- GWG/OTG/SHOG/SHOGWG/PS/PSG pre-computation -------------------
            [$gwgByPlayer, $otgByPlayer, $otaByPlayer] = $this->computeGwgAndOtg($plays);
            [$shogByPlayer, $shogwgByPlayer] = $this->computeShogAndWinner($plays);
            [$psByPlayer, $psgByPlayer] = $this->computePenaltyShots($plays);
            // -------------------------------------------------------------------

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
                $isShooter = fn ($p) => (int) ($p->shooting_player_id ?? $p->scoring_player_id ?? 0) === (int) $playerId;

                $sog   = $playerPlays->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();
                $ppsog = $pp()->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();
                $evsog = $ev()->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();
                $pksog = $pk()->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();


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

                // Goals / Assists / Points (exclude SO from G/A/PTS)
                $firstGoalPid = $this->computeFirstGoalScorer($plays);
                $nonSO = $playerPlays->filter(fn ($p) => ($p->period_type ?? null) !== 'SO');

                $g   = $nonSO->where('scoring_player_id', $playerId)->count();
                $evg = $ev()->where('scoring_player_id', $playerId)->count();
                $ppg = $pp()->where('scoring_player_id', $playerId)->count();
                $pkg = $pk()->where('scoring_player_id', $playerId)->count();

                $a   = $nonSO->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $eva = $ev()->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $ppa = $pp()->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();

                $a1   = $nonSO->where('assist1_player_id', $playerId)->count();
                $eva1 = $ev()->where('assist1_player_id', $playerId)->count();
                $ppa1 = $pp()->where('assist1_player_id', $playerId)->count();

                $a2   = $nonSO->where('assist2_player_id', $playerId)->count();
                $eva2 = $ev()->where('assist2_player_id', $playerId)->count();
                $ppa2 = $pp()->where('assist2_player_id', $playerId)->count();

                $pts   = $g + $a;
                $evpts = $evg + $eva;
                $ppp   = $ppg + $ppa;
                $pkp   = $pkg + $ppa;


                // Empty-net (ENS/ENG) using situationCode
                $isENAttempt = fn ($p) => in_array(($p->type_desc_key ?? ''), ['goal','shot-on-goal','missed-shot','blocked-shot'], true);

                $ens = $nonSO->filter(function ($p) use ($playerId, $isENAttempt, $homeTeamId, $awayTeamId) {
                    $shooterId = (int) ($p->shooting_player_id ?? $p->scoring_player_id ?? 0);
                    if ($shooterId !== (int) $playerId) return false;
                    if (!$isENAttempt($p)) return false;
                    return $this->isEmptyNetAgainst($p, $homeTeamId, $awayTeamId);
                })->count();

                $eng = $nonSO->filter(function ($p) use ($playerId, $homeTeamId, $awayTeamId) {
                    return ($p->type_desc_key ?? null) === 'goal'
                        && (int) ($p->scoring_player_id ?? 0) === (int) $playerId
                        && $this->isEmptyNetAgainst($p, $homeTeamId, $awayTeamId);
                })->count();


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

                $ga = $playerPlays
                    ->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'goal')
                    ->where('period_type', '!=', 'SO')
                    ->count();
                $evga = $ev()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $ppga = $pp()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $pkga = $pk()->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();

                // Shots against = saves + goals
                $sa   = $sv + $ga;
                $evsa = $evsv + $evga;
                $ppsa = $ppsv + $ppga;
                $pksa = $pksv + $pkga;


                //ot saves
                $shosv = $playerPlays
                    ->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'shot-on-goal')
                    ->where('period_type', 'SO')
                    ->count();


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

                    // GWG/OTG/SHOG/SHOGWG/PS/PSG
                    'gwg'    => (int) ($gwgByPlayer[(int)$playerId] ?? 0),
                    'otg'    => (int) ($otgByPlayer[(int)$playerId] ?? 0),
                    'ota'    => (int) ($otaByPlayer[(int)$playerId] ?? 0),
                    'shog'   => (int) ($shogByPlayer[(int)$playerId] ?? 0),
                    'shogwg' => (int) ($shogwgByPlayer[(int)$playerId] ?? 0),
                    'ps'     => (int) ($psByPlayer[(int)$playerId] ?? 0),
                    'psg'    => (int) ($psgByPlayer[(int)$playerId] ?? 0),

                    'fg'  => (int) ((int)$playerId === (int)$firstGoalPid), // first goal of the game
                    'htk' => (int) ($g >= 3),

                    // Empty net
                    'ens' => (int) $ens,
                    'eng' => (int) $eng,

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

                    //ot saves
                    'shosv' => $shosv,


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




    private function computeGwgAndOtg(Collection $plays): array
    {
        // All non-shootout goals in chronological order
        $goals = $plays->filter(fn ($p) => ($p->type_desc_key ?? null) === 'goal' && ($p->period_type ?? null) !== 'SO')
                       ->sortBy(fn ($p) => $p->seconds_in_game ?? $p->sort_order ?? $p->id)
                       ->values();

        // OT goals and OT assists
        $otgByPlayer = [];
        $otaByPlayer = [];
        foreach ($goals as $g) {
            if (($g->period_type ?? null) === 'OT') {
                if ($g->scoring_player_id) {
                    $otgByPlayer[(int)$g->scoring_player_id] = 1; // OTG is binary per player per game
                }
                $a1 = (int)($g->assist1_player_id ?? 0);
                $a2 = (int)($g->assist2_player_id ?? 0);
                if ($a1) $otaByPlayer[$a1] = ($otaByPlayer[$a1] ?? 0) + 1;
                if ($a2) $otaByPlayer[$a2] = ($otaByPlayer[$a2] ?? 0) + 1;
            }
        }

        // GWG (no GWG if game decided by SO; OTG implies GWG)
        $hadShootout = $plays->contains(fn ($p) => ($p->period_type ?? null) === 'SO');
        $gwgByPlayer = [];

        if (!$hadShootout && $goals->isNotEmpty()) {
            $last = $goals->last();
            $finalHome = (int)($last->home_score ?? 0);
            $finalAway = (int)($last->away_score ?? 0);

            if ($finalHome !== $finalAway) {
                $finalDiff    = abs($finalHome - $finalAway);
                $winnerIsHome = $finalHome > $finalAway;

                foreach ($goals as $g) {
                    $leadHome = (int)$g->home_score - (int)$g->away_score;
                    if ($winnerIsHome && $leadHome === $finalDiff) {
                        if ($g->scoring_player_id) $gwgByPlayer[(int)$g->scoring_player_id] = 1;
                        break;
                    }
                    $leadAway = (int)$g->away_score - (int)$g->home_score;
                    if (!$winnerIsHome && $leadAway === $finalDiff) {
                        if ($g->scoring_player_id) $gwgByPlayer[(int)$g->scoring_player_id] = 1;
                        break;
                    }
                }
            }
        }

        // Any OTG is also the GWG in sudden-death OT
        foreach ($otgByPlayer as $pid => $_) $gwgByPlayer[$pid] = 1;

        return [$gwgByPlayer, $otgByPlayer, $otaByPlayer];
    }


    private function computeShogAndWinner(Collection $plays): array
    {
        $so = $plays->filter(fn ($p) => ($p->period_type ?? null) === 'SO')
                    ->sortBy(fn ($p) => $p->seconds_in_game ?? $p->sort_order ?? $p->id)
                    ->values();

        $isMade = function ($p): bool {
            if (($p->type_desc_key ?? null) === 'goal') return true;
            $meta = $p->metadata ?? null;
            if (is_array($meta) || $meta instanceof \ArrayAccess) {
                if (!empty($meta['shootout_scored'])) return true;
                if (($meta['result'] ?? null) === 'scored') return true;
            }
            return false;
        };

        $shogByPlayer = [];
        $shogwgByPlayer = [];

        $teams = [];
        foreach ($so as $p) {
            $pid = (int) ($p->shooting_player_id ?? $p->scoring_player_id ?? 0);
            $tid = (int) ($p->event_owner_team_id ?? 0);
            if (!$pid || !$tid) continue;

            $beforeShooterUsed = $teams[$tid]['used'] ?? 0;
            $oppTid = null;

            foreach ($teams as $tId => $_) {
                if ($tId !== $tid) { $oppTid = $tId; break; }
            }
            if ($oppTid === null) {
                $oppTid = (int) ($so->firstWhere(fn ($x) => (int)($x->event_owner_team_id ?? 0) !== $tid)->event_owner_team_id ?? 0);
            }

            $oppUsed  = $teams[$oppTid]['used']  ?? 0;
            $oppGoals = $teams[$oppTid]['goals'] ?? 0;

            $made = $isMade($p);

            $teams[$tid]['used']  = ($teams[$tid]['used'] ?? 0) + 1;
            $teams[$tid]['goals'] = ($teams[$tid]['goals'] ?? 0) + ($made ? 1 : 0);

            if ($made) {
                $shogByPlayer[$pid] = ($shogByPlayer[$pid] ?? 0) + 1;
            }

            if (!$made || !$oppTid) continue;

            if ($oppUsed < 3) {
                $oppRemaining = 3 - $oppUsed;
            } else {
                $oppRemaining = ($beforeShooterUsed < $oppUsed) ? 0 : 1;
            }

            $lead = ($teams[$tid]['goals'] ?? 0) - $oppGoals;

            if ($lead > $oppRemaining) {
                $shogwgByPlayer[$pid] = 1;
            }
        }

        return [$shogByPlayer, $shogwgByPlayer];
    }

    private function computePenaltyShots(Collection $plays): array
    {
        $nonSO = $plays->filter(fn ($p) => ($p->period_type ?? null) !== 'SO');

        $isPsPenalty = function ($p): bool {
            if (strtolower((string)($p->type_desc_key ?? '')) !== 'penalty') return false;

            if (strtoupper((string)($p->penalty_type_code ?? '')) === 'PS') return true;
            if (str_starts_with(strtolower((string)($p->desc_key ?? '')), 'ps-')) return true;

            $meta = $p->metadata ?? null;
            if (is_array($meta) || $meta instanceof \ArrayAccess) {
                $d = $meta['details'] ?? $meta;
                if (strtoupper((string)($d['typeCode'] ?? '')) === 'PS') return true;
            }
            return false;
        };

        $psKeys = [];
        foreach ($nonSO as $e) {
            if ($isPsPenalty($e)) {
                $k = $this->psKey($e);
                if ($k !== null) $psKeys[$k] = true;
            }
        }

        $isAttempt = fn ($p) => in_array(($p->type_desc_key ?? ''), ['goal', 'shot-on-goal', 'missed-shot'], true);

        $psByPlayer  = [];
        $psgByPlayer = [];

        foreach ($nonSO as $e) {
            if (!$isAttempt($e)) continue;
            $k = $this->psKey($e);
            if ($k === null || empty($psKeys[$k])) continue;

            $pid = (int) ($e->shooting_player_id ?? $e->scoring_player_id ?? 0);
            if (!$pid) continue;

            $psByPlayer[$pid] = ($psByPlayer[$pid] ?? 0) + 1;
            if (($e->type_desc_key ?? null) === 'goal') {
                $psgByPlayer[$pid] = ($psgByPlayer[$pid] ?? 0) + 1;
            }
        }

        return [$psByPlayer, $psgByPlayer];
    }

    private function psKey($p): ?string
    {
        $period = $p->period ?? null;
        if ($period === null) return null;

        if (!is_null($p->seconds_in_game)) {
            return $period . '|' . (int) $p->seconds_in_game;
        }
        if (!empty($p->time_in_period)) {
            return $period . '|' . (string) $p->time_in_period;
        }
        return null;
    }

    /**
     * Empty net against the shooter’s opponent?
     * situationCode: first digit = away goalie (0=no goalie, 1=goalie), fourth digit = home goalie.
     * Decide which digit to read based on whether event_owner_team_id is home or away.
     */
    private function isEmptyNetAgainst($p, ?int $homeTeamId, ?int $awayTeamId): bool
    {
        if (($p->period_type ?? null) === 'SO') return false;

        $ownerTid = (int) ($p->event_owner_team_id ?? 0);
        if (!$ownerTid || !$homeTeamId || !$awayTeamId) return false;

        $sc = (string) ($p->situation_code ?? '');
        if (strlen($sc) < 4) return false;

        // Normalize to string of digits; take only first 4 chars
        $sc = substr($sc, 0, 4);

        // If owner is home, defender is away -> check first digit; else check fourth
        if ($ownerTid === $homeTeamId) {
            $awayGoalieDigit = $sc[0];
            return $awayGoalieDigit === '0';
        } elseif ($ownerTid === $awayTeamId) {
            $homeGoalieDigit = $sc[3];
            return $homeGoalieDigit === '0';
        }

        return false;
    }


    private function computeFirstGoalScorer(Collection $plays): ?int
    {
        $goals = $plays
            ->filter(fn ($p) => ($p->type_desc_key ?? null) === 'goal' && ($p->period_type ?? null) !== 'SO')
            ->sortBy(fn ($p) => $p->seconds_in_game ?? $p->sort_order ?? $p->id)
            ->values();

        // Prefer the first event where total score becomes 1 (home + away == 1)
        $withScore = $goals->first(function ($g) {
            $hs = isset($g->home_score) ? (int)$g->home_score : null;
            $as = isset($g->away_score) ? (int)$g->away_score : null;
            return $hs !== null && $as !== null && ($hs + $as) === 1;
        });

        $first = $withScore ?? $goals->first();
        return $first && $first->scoring_player_id ? (int)$first->scoring_player_id : null;
    }




    private function pct(int|float $num, int|float $den): float
    {
        return $den > 0 ? round(($num / $den) * 100, 2) : 0.0;
    }
}
