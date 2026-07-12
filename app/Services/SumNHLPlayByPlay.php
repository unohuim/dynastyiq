<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NhlBoxscore;
use App\Models\NhlGame;
use App\Models\NhlGameSummary;
use App\Models\PlayByPlay;
use App\Models\Player;
use App\Services\ImportNHLPlayer;
use Illuminate\Support\Collection;

class SumNHLPlayByPlay
{
    public function __construct(private readonly NhlPbpEventNormalizer $normalizer)
    {
    }

    public function summarize(int $nhlGameId, bool $reconcileGoalies = true): int
    {
        try {
            $plays = PlayByPlay::where('nhl_game_id', $nhlGameId)->get();

            // Home/Away map (needed for empty-net detection via situationCode)
            $game = NhlGame::where('nhl_game_id', $nhlGameId)->first();
            $homeTeamId = (int)($game->home_team_id ?? 0);
            $awayTeamId = (int)($game->away_team_id ?? 0);

            // --- GWG/OTG/SHOG/SHOGWG/PS/PSG pre-computation -------------------
            [$gwgByPlayer, $otgByPlayer, $otaByPlayer] = $this->computeGwgAndOtg($plays, $game);
            [$shogByPlayer, $shogwgByPlayer] = $this->computeShogAndWinner($plays);
            [$psByPlayer, $psgByPlayer] = $this->computePenaltyShots($plays);
            $firstGoalPid = $this->computeFirstGoalScorer($plays);
            $playsByPlayerId = $this->playsByPlayerId($plays);
            $nonSOPlaysByStrength = $plays
                ->filter(fn ($p) => $this->normalizer->isBoxscoreComparable($p))
                ->groupBy('strength');
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

            $playerCount = 0;

            foreach ($playerIds as $playerId) {
                $playerId = (int) $playerId;
                $playerPlays = $playsByPlayerId[$playerId] ?? collect();
                $nonSO = $playerPlays->filter(fn ($p) => $this->normalizer->isBoxscoreComparable($p));
                $nonSOForStrength = fn (string $strength) => $nonSOPlaysByStrength->get($strength, collect());

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
                $isSOG = fn ($p) => $this->normalizer->isShotOnGoal($p);
                $isShooter = fn ($p) => (int) ($p->shooting_player_id ?? $p->scoring_player_id ?? 0) === (int) $playerId;

                $sog   = $nonSO->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();
                $ppsog = $nonSOForStrength('PP')->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();
                $evsog = $nonSOForStrength('EV')->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();
                $pksog = $nonSOForStrength('PK')->filter(fn ($p) => $isShooter($p) && $isSOG($p))->count();


                // Missed shots
                $sm   = $nonSO->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();
                $ppsm = $nonSOForStrength('PP')->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();
                $evsm = $nonSOForStrength('EV')->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();
                $pksm = $nonSOForStrength('PK')->where('shooting_player_id', $playerId)->where('type_desc_key', 'missed-shot')->count();

                // Blocked shots (by opponent)
                $sb   = $nonSO->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();
                $ppsb = $nonSOForStrength('PP')->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();
                $evsb = $nonSOForStrength('EV')->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();
                $pksb = $nonSOForStrength('PK')->where('shooting_player_id', $playerId)->where('type_desc_key', 'blocked-shot')->count();

                // Shot attempts (skater) = sog + sm + sb
                $sat   = $sog + $sm + $sb;
                $ppsat = $ppsog + $ppsm + $ppsb;
                $evsat = $evsog + $evsm + $evsb;
                $pksat = $pksog + $pksm + $pksb;

                // Goals / Assists / Points (exclude SO from G/A/PTS)
                $g   = $nonSO->where('scoring_player_id', $playerId)->count();
                $evg = $nonSOForStrength('EV')->where('scoring_player_id', $playerId)->count();
                $ppg = $nonSOForStrength('PP')->where('scoring_player_id', $playerId)->count();
                $pkg = $nonSOForStrength('PK')->where('scoring_player_id', $playerId)->count();

                $a   = $nonSO->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $eva = $nonSOForStrength('EV')->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $ppa = $nonSOForStrength('PP')->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();
                $pka = $nonSOForStrength('PK')->filter(fn ($p) => $p->assist1_player_id == $playerId || $p->assist2_player_id == $playerId)->count();

                $a1   = $nonSO->where('assist1_player_id', $playerId)->count();
                $eva1 = $nonSOForStrength('EV')->where('assist1_player_id', $playerId)->count();
                $ppa1 = $nonSOForStrength('PP')->where('assist1_player_id', $playerId)->count();

                $a2   = $nonSO->where('assist2_player_id', $playerId)->count();
                $eva2 = $nonSOForStrength('EV')->where('assist2_player_id', $playerId)->count();
                $ppa2 = $nonSOForStrength('PP')->where('assist2_player_id', $playerId)->count();

                $pts   = $g + $a;
                $evpts = $evg + $eva;
                $ppp   = $ppg + $ppa;
                $pkp   = $pkg + $pka;


                // Empty-net (ENS/ENG) using situationCode
                $isENAttempt = fn ($p) => in_array(($p->type_desc_key ?? ''), ['goal','shot-on-goal','missed-shot','blocked-shot'], true);

                $ens = $nonSO->filter(function ($p) use ($playerId, $isENAttempt, $homeTeamId, $awayTeamId) {
                    $shooterId = (int) ($p->shooting_player_id ?? $p->scoring_player_id ?? 0);
                    if ($shooterId !== (int) $playerId) return false;
                    if (!$isENAttempt($p)) return false;
                    return $this->normalizer->isEmptyNetAgainst($p, $homeTeamId, $awayTeamId);
                })->count();

                $eng = $nonSO->filter(function ($p) use ($playerId, $homeTeamId, $awayTeamId) {
                    return ($p->type_desc_key ?? null) === 'goal'
                        && (int) ($p->scoring_player_id ?? 0) === (int) $playerId
                        && $this->normalizer->isEmptyNetAgainst($p, $homeTeamId, $awayTeamId);
                })->count();


                // Blocks (by this skater)
                $b         = $playerPlays->where('blocking_player_id', $playerId)->where('reason', 'blocked')->count();
                $bTeammate = $playerPlays->where('blocking_player_id', $playerId)->where('reason', 'teammate-blocked')->count();

                // Hits
                $h  = $playerPlays->where('hitting_player_id', $playerId)->count();
                $th = $playerPlays->where('hittee_player_id', $playerId)->count();

                // Goalie stats
                $sv   = $nonSO->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();
                $evsv = $nonSOForStrength('EV')->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();
                $ppsv = $nonSOForStrength('PP')->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();
                $pksv = $nonSOForStrength('PK')->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'shot-on-goal')->count();

                $ga = $nonSO
                    ->where('goalie_in_net_player_id', $playerId)
                    ->where('type_desc_key', 'goal')
                    ->count();
                $evga = $nonSOForStrength('EV')->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $ppga = $nonSOForStrength('PP')->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();
                $pkga = $nonSOForStrength('PK')->where('goalie_in_net_player_id', $playerId)->where('type_desc_key', 'goal')->count();

                $sa = $nonSO
                    ->where('goalie_in_net_player_id', $playerId)
                    ->filter(fn ($p) => $this->normalizer->isShotOnGoal($p))
                    ->count();
                $evsa = $nonSOForStrength('EV')
                    ->where('goalie_in_net_player_id', $playerId)
                    ->filter(fn ($p) => $this->normalizer->isShotOnGoal($p))
                    ->count();
                $ppsa = $nonSOForStrength('PP')
                    ->where('goalie_in_net_player_id', $playerId)
                    ->filter(fn ($p) => $this->normalizer->isShotOnGoal($p))
                    ->count();
                $pksa = $nonSOForStrength('PK')
                    ->where('goalie_in_net_player_id', $playerId)
                    ->filter(fn ($p) => $this->normalizer->isShotOnGoal($p))
                    ->count();


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
                    app(ImportNHLPlayer::class)->import((string)$playerId);
                }

                $summary = [
                    'nhl_game_id'   => $nhlGameId,
                    'nhl_player_id' => (string)$playerId,
                    'nhl_team_id'   => $this->resolvePlayerTeamId($nhlGameId, (int) $playerId, $playerPlays, $game),

                    // Goals / Assists / Points
                    'g' => $g, 'evg' => $evg, 'ppg' => $ppg, 'pkg' => $pkg,
                    'a' => $a, 'eva' => $eva, 'ppa' => $ppa, 'pka' => $pka,
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
                    'pim' => $this->penaltyMinutesForPlayer($playerPlays, (int) $playerId),

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

            if ($reconcileGoalies) {
                $this->reconcileGoalieBoxscoreTotals($nhlGameId);
            }

            return $playerCount;
        } catch (\Throwable $e) {
            \Log::error("Summary failed for game {$nhlGameId}: {$e->getMessage()}");
            throw $e;
        }
    }




    private function computeGwgAndOtg(Collection $plays, ?NhlGame $game): array
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
            $finalHome = is_numeric($game?->home_team_score)
                ? (int) $game->home_team_score
                : (int) ($last->home_score ?? 0);
            $finalAway = is_numeric($game?->away_team_score)
                ? (int) $game->away_team_score
                : (int) ($last->away_score ?? 0);

            if ($finalHome !== $finalAway) {
                $winnerIsHome = $finalHome > $finalAway;
                $losingFinalScore = $winnerIsHome ? $finalAway : $finalHome;
                $gameWinningScore = $losingFinalScore + 1;

                foreach ($goals as $g) {
                    if ($winnerIsHome && (int)$g->home_score === $gameWinningScore) {
                        if ($g->scoring_player_id) $gwgByPlayer[(int)$g->scoring_player_id] = 1;
                        break;
                    }
                    if (!$winnerIsHome && (int)$g->away_score === $gameWinningScore) {
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

    /**
     * Index every play by each related player id so per-player summaries avoid full-game scans.
     *
     * @param Collection<int,PlayByPlay> $plays
     * @return array<int,Collection<int,PlayByPlay>>
     */
    private function playsByPlayerId(Collection $plays): array
    {
        $playerIdFields = [
            'nhl_player_id',
            'scoring_player_id',
            'assist1_player_id',
            'assist2_player_id',
            'committed_by_player_id',
            'drawn_by_player_id',
            'blocking_player_id',
            'hitting_player_id',
            'hittee_player_id',
            'shooting_player_id',
            'goalie_in_net_player_id',
            'fo_winning_player_id',
            'fo_losing_player_id',
        ];

        $playsByPlayerId = [];

        foreach ($plays as $play) {
            $playPlayerIds = [];

            foreach ($playerIdFields as $field) {
                $playerId = (int) ($play->{$field} ?? 0);

                if ($playerId > 0) {
                    $playPlayerIds[$playerId] = true;
                }
            }

            foreach (array_keys($playPlayerIds) as $playerId) {
                $playsByPlayerId[$playerId][] = $play;
            }
        }

        return array_map(
            fn (array $playerPlays): Collection => collect($playerPlays),
            $playsByPlayerId
        );
    }

    /**
     * Resolve the game team id for a player summary without allowing nullable writes.
     *
     * @param Collection<int,PlayByPlay> $playerPlays
     */
    private function resolvePlayerTeamId(int $nhlGameId, int $playerId, Collection $playerPlays, ?NhlGame $game): int
    {
        $boxscoreTeamId = NhlBoxscore::query()
            ->where('nhl_game_id', $nhlGameId)
            ->where('nhl_player_id', $playerId)
            ->value('nhl_team_id');

        if ($boxscoreTeamId !== null) {
            return (int) $boxscoreTeamId;
        }

        $homeTeamId = (int) ($game->home_team_id ?? 0);
        $awayTeamId = (int) ($game->away_team_id ?? 0);
        $eventOwnerTeamId = $playerPlays
            ->pluck('event_owner_team_id')
            ->filter()
            ->first();

        if (in_array((int) $eventOwnerTeamId, [$homeTeamId, $awayTeamId], true)) {
            return (int) $eventOwnerTeamId;
        }

        $teamAbbrev = Player::query()
            ->where('nhl_id', $playerId)
            ->value('team_abbrev');

        if ($game && is_string($teamAbbrev) && $teamAbbrev !== '') {
            $teamId = $game->getTeamIdByAbbrev($teamAbbrev);

            if ($teamId !== null) {
                return (int) $teamId;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to resolve NHL team id for game %s PBP summary player %s.',
            (string) $nhlGameId,
            (string) $playerId
        ));
    }

    /**
     * Keep official goalie-facing boxscore totals as the summary source of truth.
     *
     * PBP goal events can omit goalieInNetId even when shot events include it. Without this reconciliation,
     * saves can aggregate correctly while goals against are undercounted.
     */
    private function reconcileGoalieBoxscoreTotals(int $nhlGameId): void
    {
        NhlBoxscore::query()
            ->where('nhl_game_id', $nhlGameId)
            ->whereRaw('UPPER(position) = ?', ['G'])
            ->get()
            ->each(function (NhlBoxscore $boxscore) use ($nhlGameId): void {
                if (empty($boxscore->nhl_player_id)) {
                    return;
                }

                if (! ImportNHLPlayer::playerExists((string) $boxscore->nhl_player_id)) {
                    app(ImportNHLPlayer::class)->import((string) $boxscore->nhl_player_id);
                }

                $toiSeconds = $this->boxscoreToiSeconds($boxscore);
                $shotsAgainst = (int) $boxscore->shots_against;
                $saves = (int) $boxscore->saves;
                $goalsAgainst = (int) $boxscore->goals_against;

                NhlGameSummary::updateOrCreate(
                    [
                        'nhl_game_id' => $nhlGameId,
                        'nhl_player_id' => (string) $boxscore->nhl_player_id,
                    ],
                    [
                        'nhl_team_id' => (int) $boxscore->nhl_team_id,
                        'toi' => $toiSeconds,
                        'sa' => $shotsAgainst,
                        'sv' => $saves,
                        'ga' => $goalsAgainst,
                        'evsa' => (int) $boxscore->ev_shots_against,
                        'evsv' => (int) $boxscore->ev_saves,
                        'evga' => (int) $boxscore->ev_goals_against,
                        'ppsa' => (int) $boxscore->pp_shots_against,
                        'ppsv' => (int) $boxscore->pp_saves,
                        'ppga' => (int) $boxscore->pp_goals_against,
                        'pksa' => (int) $boxscore->pk_shots_against,
                        'pksv' => (int) $boxscore->pk_saves,
                        'pkga' => (int) $boxscore->pk_goals_against,
                        'sv_pct' => $shotsAgainst > 0 ? round($saves / $shotsAgainst, 3) : 0.0,
                        'gaa' => $toiSeconds > 0 ? round(($goalsAgainst * 3600) / $toiSeconds, 3) : 0.0,
                    ]
                );
            });
    }

    private function boxscoreToiSeconds(NhlBoxscore $boxscore): int
    {
        if (is_numeric($boxscore->toi_seconds) && (int) $boxscore->toi_seconds > 0) {
            return (int) $boxscore->toi_seconds;
        }

        if (! is_string($boxscore->toi) || ! str_contains($boxscore->toi, ':')) {
            return 0;
        }

        [$minutes, $seconds] = array_pad(explode(':', $boxscore->toi, 2), 2, '0');

        return ((int) $minutes * 60) + (int) $seconds;
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

    /**
     * Sum player penalty minutes with NHL provider penalty-code normalization.
     */
    private function penaltyMinutesForPlayer(Collection $plays, int $playerId): int
    {
        return (int) $plays
            ->filter(fn ($p): bool => (int) ($p->committed_by_player_id ?? 0) === $playerId)
            ->sum(fn ($p): int => $this->normalizer->normalizedPenaltyMinutes($p));
    }

    private function pct(int|float $num, int|float $den): float
    {
        return $den > 0 ? round(($num / $den) * 100, 2) : 0.0;
    }
}
