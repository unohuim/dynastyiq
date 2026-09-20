<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Stats\NhleLeagueFactorResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds complete, game-specific skater inputs from a reported lineup. */
final class NhlGameLineupProjectionBuilder
{
    private const FORWARD_SECONDS = 10800.0;
    private const DEFENSE_SECONDS = 7200.0;

    public function __construct(private readonly NhleLeagueFactorResolver $factors)
    {
    }

    /** @param array<string,mixed>|null $lineup @return array<int,array<string,mixed>>|null */
    public function build(
        ?array $lineup,
        string $sourceSeasonId,
        string $targetSeasonId,
        string $projectionVersion,
        string $toiProjectionVersion
    ): ?array {
        $players = collect($lineup['players'] ?? [])->whereIn('lineup_role', ['forward', 'defense'])->values();
        if ($players->count() !== 18 || $players->pluck('nhl_player_id')->filter()->unique()->count() !== 18) {
            return null;
        }

        $ids = $players->pluck('nhl_player_id')->map(fn (mixed $id): int => (int) $id)->all();
        $projections = DB::table('nhl_player_season_projections')
            ->where('target_season_id', $targetSeasonId)->where('projection_version', $projectionVersion)
            ->whereIn('player_id', $ids)->get()->keyBy('player_id');
        $toi = DB::table('nhl_player_toi_projections')
            ->where('target_season_id', $targetSeasonId)->where('projection_version', $toiProjectionVersion)
            ->whereIn('player_id', $ids)->get()->keyBy('player_id');
        $careerGames = DB::table('nhl_season_stats')->where('game_type', 2)->whereIn('nhl_player_id', $ids)
            ->selectRaw('nhl_player_id, SUM(gp) AS gp')->groupBy('nhl_player_id')->pluck('gp', 'nhl_player_id');
        $nhlHistory = DB::table('nhl_season_stats')->where('season_id', $sourceSeasonId)->where('game_type', 2)
            ->whereIn('nhl_player_id', $ids)->get()->keyBy('nhl_player_id');
        $internalIds = $players->pluck('player_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $nonNhl = DB::table('stats')->where('season_id', $sourceSeasonId)->where('game_type_id', 2)
            ->where('league_abbrev', '<>', 'NHL')->whereIn('player_id', $internalIds)
            ->orderBy('gp')->get()->keyBy('player_id');

        $rows = $players->map(function (array $player) use ($projections, $toi, $careerGames, $nhlHistory, $nonNhl): array {
            $nhlId = (int) $player['nhl_player_id'];
            $projection = $projections->get($nhlId);
            $toiProjection = $toi->get($nhlId);
            $nhlGames = (int) ($careerGames->get($nhlId) ?? 0);
            $weight = $this->roleWeight($player);
            $source = 'nhl_projection';
            $factor = null;
            $confidence = $projection?->confidence_bucket ?? 'medium';
            $games = max(1.0, (float) ($projection?->projected_games ?? 0));
            $goalRate = (float) ($projection?->projected_goals ?? $projection?->projected_xgf ?? 0) / $games;
            $sogRate = (float) ($projection?->projected_xsog ?? 0) / $games;
            $nhlStat = $nhlHistory->get($nhlId);
            $assistRate = $nhlStat !== null && (int) $nhlStat->gp > 0
                ? (float) $nhlStat->a / (int) $nhlStat->gp : 0.0;

            $historical = $nonNhl->get((int) ($player['player_id'] ?? 0));
            if ($nhlGames < 25 && $historical !== null && (int) $historical->gp > 0) {
                $factorRow = $this->factors->resolve((string) $historical->league_abbrev);
                $factor = $factorRow === null ? null : (float) $factorRow->points_factor;
                if ($factor !== null) {
                    $source = 'nhle_non_nhl_history';
                    $confidence = 'low';
                    $goalRate = ((float) $historical->g / (int) $historical->gp) * $factor;
                    $assistRate = ((float) $historical->a / (int) $historical->gp) * $factor;
                    $sogRate = ((float) $historical->sog / (int) $historical->gp) * $factor;
                }
            }
            if ($projection === null && $source === 'nhl_projection') {
                $source = 'replacement_level';
                $confidence = 'low';
                $goalRate = 0.08;
                $assistRate = 0.12;
                $sogRate = 1.20;
            }

            return [
                'player_id' => $player['player_id'],
                'nhl_player_id' => $nhlId,
                'player_name' => $player['player_name'],
                'position' => $player['lineup_role'],
                'line_key' => $player['line_key'],
                'slot_index' => $player['slot_index'],
                'power_play_unit' => $player['power_play_unit'] ?? null,
                'penalty_kill_unit' => $player['penalty_kill_unit'] ?? null,
                'nhl_games_played' => $nhlGames,
                'projection_source' => $source,
                'nhle_factor' => $factor,
                'confidence' => $confidence,
                'confidence_score' => $source === 'nhl_projection' && $projection?->confidence_score !== null
                    ? (float) $projection->confidence_score : 0.25,
                'role_weight' => $weight,
                'baseline_toi_seconds' => (float) ($toiProjection?->projected_toi_per_game_seconds ?? 0),
                'goals_per_game_unscaled' => $goalRate,
                'assists_per_game_unscaled' => $assistRate,
                'sog_per_game_unscaled' => $sogRate,
            ];
        });

        return $this->applyGameToi($rows)->all();
    }

    /** @param array<string,mixed> $player */
    private function roleWeight(array $player): float
    {
        $weights = ['F1' => 1.30, 'F2' => 1.10, 'F3' => 0.90, 'F4' => 0.70, 'D1' => 1.20, 'D2' => 1.00, 'D3' => 0.80];
        $weight = $weights[(string) $player['line_key']] ?? 1.0;
        $weight += match ((int) ($player['power_play_unit'] ?? 0)) { 1 => 0.22, 2 => 0.12, default => 0.0 };
        $weight += match ((int) ($player['penalty_kill_unit'] ?? 0)) { 1 => 0.10, 2 => 0.06, default => 0.0 };

        return $weight;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return Collection<int,array<string,mixed>> */
    private function applyGameToi(Collection $rows): Collection
    {
        return $rows->map(function (array $row) use ($rows): array {
            $group = $row['position'] === 'defense' ? 'defense' : 'forward';
            $groupRows = $rows->where('position', $group);
            $roleSeconds = ($group === 'defense' ? self::DEFENSE_SECONDS : self::FORWARD_SECONDS)
                * ((float) $row['role_weight'] / max(0.01, (float) $groupRows->sum('role_weight')));
            $baseline = (float) $row['baseline_toi_seconds'];
            $gameSeconds = $baseline > 0 ? ($roleSeconds * 0.65) + ($baseline * 0.35) : $roleSeconds;
            $scale = $baseline > 0 ? $gameSeconds / $baseline : $gameSeconds / 900.0;
            $row['game_projected_toi_seconds'] = (int) round($gameSeconds);
            $row['game_projected_toi'] = sprintf('%d:%02d', intdiv((int) round($gameSeconds), 60), (int) round($gameSeconds) % 60);
            $row['projected_goals'] = round((float) $row['goals_per_game_unscaled'] * $scale, 4);
            $row['adjusted_xgf_per_game'] = $row['projected_goals'];
            $row['projected_assists'] = round((float) $row['assists_per_game_unscaled'] * $scale, 4);
            $row['projected_sog'] = round((float) $row['sog_per_game_unscaled'] * $scale, 3);
            unset($row['role_weight'], $row['goals_per_game_unscaled'], $row['assists_per_game_unscaled'], $row['sog_per_game_unscaled']);

            return $row;
        })->values();
    }
}
