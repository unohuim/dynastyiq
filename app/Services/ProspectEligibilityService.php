<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\Stat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Determines whether a canonical NHL player should currently be treated as a prospect.
 */
final class ProspectEligibilityService
{
    /**
     * Determine prospect eligibility from local draft evidence and legacy stats rows.
     */
    public function isProspect(int $nhlPlayerId, ?CarbonInterface $asOf = null): bool
    {
        $player = Player::query()
            ->where('nhl_id', $nhlPlayerId)
            ->first();

        if (! $player instanceof Player || $player->id === null) {
            return false;
        }

        if (! $this->hasDraftEvidence($player)) {
            return false;
        }

        $asOf ??= Carbon::now();

        if (! $this->isYoungerThanTwentySixOnCutoff($player, $asOf)) {
            return false;
        }

        if ($this->careerNhlRegularSeasonGamesPlayed($player) > 25) {
            return false;
        }

        return $this->hasCurrentEvaluationSeasonNonNhlStats($player, $asOf);
    }

    /**
     * @return array<int,string>
     */
    public function evaluationSeasonIds(CarbonInterface $asOf): array
    {
        $year = (int) $asOf->format('Y');
        $month = (int) $asOf->format('n');

        if ($month === 10) {
            return [
                $this->seasonId($year - 1),
                $this->seasonId($year),
            ];
        }

        if ($month >= 11) {
            return [$this->seasonId($year)];
        }

        return [$this->seasonId($year - 1)];
    }

    private function hasDraftEvidence(Player $player): bool
    {
        return PlayerExternalIdentity::query()
            ->where('player_id', $player->id)
            ->where(function ($query): void {
                $query->where('provider', PlayerExternalIdentity::PROVIDER_NHL_DRAFT)
                    ->orWhere('provider', PlayerExternalIdentity::PROVIDER_NHL);
            })
            ->get()
            ->contains(function (PlayerExternalIdentity $identity): bool {
                if ($identity->provider === PlayerExternalIdentity::PROVIDER_NHL_DRAFT) {
                    return true;
                }

                $draftDetails = data_get($identity->raw_payload, 'draftDetails');

                return is_array($draftDetails) && $draftDetails !== [];
            });
    }

    private function careerNhlRegularSeasonGamesPlayed(Player $player): int
    {
        return (int) Stat::query()
            ->where('player_id', $player->id)
            ->where('league_abbrev', 'NHL')
            ->where('game_type_id', 2)
            ->sum('gp');
    }

    private function isYoungerThanTwentySixOnCutoff(Player $player, CarbonInterface $asOf): bool
    {
        if (! $player->dob) {
            return false;
        }

        try {
            $birthDate = Carbon::parse((string) $player->dob);
        } catch (\Throwable) {
            return false;
        }

        return $birthDate->diffInYears($this->ageCutoffDate($asOf)) < 26;
    }

    private function ageCutoffDate(CarbonInterface $asOf): CarbonInterface
    {
        $year = (int) $asOf->format('Y');

        return Carbon::create($year, 9, 15)->startOfDay();
    }

    private function hasCurrentEvaluationSeasonNonNhlStats(Player $player, CarbonInterface $asOf): bool
    {
        return Stat::query()
            ->where('player_id', $player->id)
            ->where('league_abbrev', '<>', 'NHL')
            ->whereIn('season_id', $this->evaluationSeasonIds($asOf))
            ->where('gp', '>', 0)
            ->exists();
    }

    private function seasonId(int $startYear): string
    {
        return (string) $startYear . (string) ($startYear + 1);
    }
}
