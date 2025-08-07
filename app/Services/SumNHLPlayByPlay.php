<?php

namespace App\Services;

use App\Models\PlayByPlay;
use App\Models\NhlGameSummary;

class SumNHLPlayByPlay
{
    public function summarize(int $nhlGameId): void
    {
        $plays = PlayByPlay::where('nhl_game_id', $nhlGameId)->get();

        $playerIds = $plays->pluck('nhl_player_id')
            ->filter()
            ->merge(
                $plays->pluck('scoring_player_id')->filter()
            )
            ->merge(
                $plays->pluck('assist1_player_id')->filter()
            )
            ->merge(
                $plays->pluck('assist2_player_id')->filter()
            )
            ->merge(
                $plays->pluck('committed_by_player_id')->filter()
            )
            ->merge(
                $plays->pluck('drawn_by_player_id')->filter()
            )
            ->merge(
                $plays->pluck('blocking_player_id')->filter()
            )
            ->merge(
                $plays->pluck('hitting_player_id')->filter()
            )
            ->merge(
                $plays->pluck('hittee_player_id')->filter()
            )
            ->merge(
                $plays->pluck('shooting_player_id')->filter()
            )
            ->merge(
                $plays->pluck('goalie_in_net_player_id')->filter()
            )
            ->merge(
                $plays->pluck('fo_winning_player_id')->filter()
            )
            ->merge(
                $plays->pluck('fo_losing_player_id')->filter()
            )
            ->unique();

        foreach ($playerIds as $playerId) {
            $playerPlays = $plays->filter(function ($play) use ($playerId) {
                return in_array($playerId, [
                    $play->nhl_player_id,
                    $play->scoring_player_id,
                    $play->assist1_player_id,
                    $play->assist2_player_id,
                    $play->committed_by_player_id,
                    $play->drawn_by_player_id,
                    $play->blocking_player_id,
                    $play->hitting_player_id,
                    $play->hittee_player_id,
                    $play->shooting_player_id,
                    $play->goalie_in_net_player_id,
                    $play->fo_winning_player_id,
                    $play->fo_losing_player_id,
                ]);
            });

            // Helper filters by strength
            $byStrength = function ($strength) use ($playerPlays) {
                return $playerPlays->filter(function ($p) use ($strength) {
                    return $p->strength === $strength;
                });
            };

            // Shots + Blocks for SHA
            $shaCount = $playerPlays->filter(function ($p) use ($playerId) {
                return ($p->type_desc_key === 'shot-on-goal' || $p->type_desc_key === 'blocked-shot')
                    && $p->shooting_player_id == $playerId;
            })->count();

            $ppshaCount = $byStrength('PP')->filter(function ($p) use ($playerId) {
                return ($p->type_desc_key === 'shot-on-goal' || $p->type_desc_key === 'blocked-shot')
                    && $p->shooting_player_id == $playerId;
            })->count();

            $evshaCount = $byStrength('EV')->filter(function ($p) use ($playerId) {
                return ($p->type_desc_key === 'shot-on-goal' || $p->type_desc_key === 'blocked-shot')
                    && $p->shooting_player_id == $playerId;
            })->count();

            $fowCount = $playerPlays->where('fo_winning_player_id', $playerId)->count();
            $folCount = $playerPlays->where('fo_losing_player_id', $playerId)->count();
            $fotCount = $playerPlays->filter(function ($p) use ($playerId) {
                return $p->fo_winning_player_id === $playerId || $p->fo_losing_player_id === $playerId;
            })->count();

            $fowPercentage = $fotCount > 0 ? round(($fowCount / $fotCount), 2) : 0;                

            

            $tk = $playerPlays->where('nhl_player_id', $playerId)->where('type_desc_key', 'takeaway')->count();
            $gv = $playerPlays->where('nhl_player_id', $playerId)->where('type_desc_key', 'giveaway')->count();
            $tkgv = $tk-$gv;



            $summaryData = [
                'nhl_game_id' => $nhlGameId,
                'nhl_player_id' => $playerId,
                'nhl_team_id' => $playerPlays->first()->event_owner_team_id ?? null,                



                // Goals
                'g' => $playerPlays->where('scoring_player_id', $playerId)->count(),
                'evg' => $byStrength('EV')->where('scoring_player_id', $playerId)->count(),

                // Assists
                'a' => $playerPlays->filter(function ($p) use ($playerId) {
                    return $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId;
                })->count(),
                'eva' => $byStrength('EV')->filter(function ($p) use ($playerId) {
                    return $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId;
                })->count(),

                // Primary Assists
                'a1' => $playerPlays->where('assist1_player_id', $playerId)->count(),
                'eva1' => $byStrength('EV')->where('assist1_player_id', $playerId)->count(),

                // Secondary Assists
                'a2' => $playerPlays->where('assist2_player_id', $playerId)->count(),
                'eva2' => $byStrength('EV')->where('assist2_player_id', $playerId)->count(),

                // Points
                'pts' => $playerPlays->filter(function ($p) use ($playerId) {
                    return $p->scoring_player_id == $playerId
                        || $p->assist1_player_id == $playerId
                        || $p->assist2_player_id == $playerId;
                })->count(),
                'evpts' => $byStrength('EV')->filter(function ($p) use ($playerId) {
                    return $p->scoring_player_id == $playerId
                        || $p->assist1_player_id == $playerId
                        || $p->assist2_player_id == $playerId;
                })->count(),

                // Plus/Minus placeholder
                'plus_minus' => 0,

                // Penalty Minutes
                'pim' => $playerPlays->where('committed_by_player_id', $playerId)->sum('duration'),

                
                // Shifts placeholder
                'shifts' => 0,

                // Powerplay
                'ppg' => $byStrength('PP')->where('scoring_player_id', $playerId)->count(),
                'ppa' => $byStrength('PP')->filter(function ($p) use ($playerId) {
                    return $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId;
                })->count(),
                'ppa1' => $byStrength('PP')->where('assist1_player_id', $playerId)->count(),
                'ppa2' => $byStrength('PP')->where('assist2_player_id', $playerId)->count(),
                'ppp' => $byStrength('PP')->filter(function ($p) use ($playerId) {
                    return $p->scoring_player_id == $playerId
                        || $p->assist1_player_id == $playerId
                        || $p->assist2_player_id == $playerId;
                })->count(),

                // Penalty Kill
                'pkg' => $byStrength('PK')->where('scoring_player_id', $playerId)->count(),
                'pka' => $byStrength('PK')->filter(function ($p) use ($playerId) {
                    return $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId;
                })->count(),
                'pkp' => $byStrength('PK')->filter(function ($p) use ($playerId) {
                    return $p->scoring_player_id == $playerId
                        || $p->assist1_player_id == $playerId
                        || $p->assist2_player_id == $playerId;
                })->count(),

                // Blocks
                'b' => $playerPlays->where('blocking_player_id', $playerId)
                    ->where('reason', 'blocked')
                    ->count(),
                'b_teammate' => $playerPlays->where('blocking_player_id', $playerId)
                    ->where('reason', 'teammate-blocked')
                    ->count(),

                // Hits
                'h' => $playerPlays->where('hitting_player_id', $playerId)->count(),
                'th' => $playerPlays->where('hitting_player_id', $playerId)->count(),

                // Giveaways & Takeaways
                'gv' => $gv,
                'tk' => $tk,
                'tkvgv' => $tkgv,

                // Faceoffs
                'fow' => $fowCount,
                'fol' => $folCount,
                'fot' => $fotCount,
                'fow_percentage' => $fowPercentage,

                // Shots For
                'sog' => $playerPlays->where('shooting_player_id', $playerId)
                    ->where('type_desc_key', 'shot-on-goal')
                    ->count(),
                'ppsog' => $byStrength('PP')->where('shooting_player_id', $playerId)->count(),
                'evsog' => $byStrength('EV')->where('shooting_player_id', $playerId)->count(),

                // Shots Attempts (SHA)
                'sha' => $shaCount,
                'ppsha' => $ppshaCount,
                'evsha' => $evshaCount,




                // Saves (Goalie)
                'sv' => $playerPlays->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'shot-on-goal')
                    ->count(),
                'evsv' => $byStrength('EV')->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'shot-on-goal')
                    ->count(),
                'ppsv' => $byStrength('PP')->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'shot-on-goal')
                    ->count(),
                'pksv' => $byStrength('PK')->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'shot-on-goal')
                    ->count(),


                // Goals Against (Goalie)
                'ga' => $playerPlays->where('type_desc_key', 'goal')->where('goalie_in_net_player_id', $playerId)->count(),
                'evga' => $byStrength('EV')->where(function ($p) use ($playerId) {
                    return $p->goalie_in_net_player_id == $playerId && $p->type_desc_key === 'goal';
                })->count(),
                'ppga' => $byStrength('PP')->where(function ($p) use ($playerId) {
                    return $p->goalie_in_net_player_id == $playerId && $p->type_desc_key === 'goal';
                })->count(),
                'pkga' => $byStrength('PK')->where(function ($p) use ($playerId) {
                    return $p->goalie_in_net_player_id == $playerId && $p->type_desc_key === 'goal';
                })->count(),


                // Shots Against (Goalie)
                'sa' => $playerPlays->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', '<>', 'missed-shot')
                    ->count(),
                'evsa' => $byStrength('EV')->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', '<>', 'missed-shot')
                    ->count(),
                'ppsa' => $byStrength('PP')->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', '<>', 'missed-shot')
                    ->count(),
                'pksa' => $byStrength('PK')->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', '<>', 'missed-shot')
                    ->count(),



                // Shooting Attempts (Shots + Blocks)
                'sha' => $shaCount,
                'ppsha' => $ppshaCount,
                'evsha' => $evshaCount,

                // Shooting Percentage
                'sog_p' => $this->safePercentage(
                    $playerPlays->where('type_desc_key', 'shot-on-goal')->count(),
                    $playerPlays->whereIn('type_desc_key', ['shot-on-goal', 'missed-shot', 'blocked-shot'])->count()
                ),
                'ppsog_p' => $this->safePercentage(
                    $byStrength('PP')->where('type_desc_key', 'shot-on-goal')->count(),
                    $byStrength('PP')->whereIn('type_desc_key', ['shot-on-goal', 'missed-shot', 'blocked-shot'])->count()
                ),
                'evsog_p' => $this->safePercentage(
                    $byStrength('EV')->where('type_desc_key', 'shot-on-goal')->count(),
                    $byStrength('EV')->whereIn('type_desc_key', ['shot-on-goal', 'missed-shot', 'blocked-shot'])->count()
                ),
            ];

            NhlGameSummary::updateOrCreate(
                ['nhl_game_id' => $nhlGameId, 'nhl_player_id' => $playerId],
                $summaryData
            );
        }
    }

    private function safePercentage(int $made, int $attempts): float
    {
        if ($attempts === 0) {
            return 0.0;
        }
        return round(($made / $attempts) * 100, 2);
    }
}
