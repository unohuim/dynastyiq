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
     * Drafting requires non-NHL games; reporting also accepts NHL games in the same window.
     */
    public function isProspect(int $nhlPlayerId, ?CarbonInterface $asOf = null, bool $for_drafting = true): bool
    {
        return in_array($nhlPlayerId, $this->eligibleNhlPlayerIds([$nhlPlayerId], $asOf, $for_drafting), true);
    }

    /**
     * Evaluate a list with the same rules without issuing eligibility queries per player.
     *
     * @param array<int, int> $nhlPlayerIds
     * @return array<int, int>
     */
    public function eligibleNhlPlayerIds(array $nhlPlayerIds, ?CarbonInterface $asOf = null, bool $for_drafting = true): array
    {
        if ($nhlPlayerIds === []) {
            return [];
        }
        $asOf ??= Carbon::now();

        $players = Player::query()->whereIn('nhl_id', $nhlPlayerIds)
            ->with(['externalIdentities' => fn ($query) => $query->whereIn('provider', [
                PlayerExternalIdentity::PROVIDER_NHL_DRAFT, PlayerExternalIdentity::PROVIDER_NHL,
            ])])
            ->get()->filter(fn (Player $player): bool => $this->hasDraftEvidence($player)
                && $this->isYoungerThanTwentySixOnCutoff($player, $asOf));
        if ($players->isEmpty()) {
            return [];
        }
        // Group each scoped population once instead of running correlated totals for every player.
        $career = Stat::query()->whereIn('player_id', $players->modelKeys())
            ->where('league_abbrev', 'NHL')->where('game_type_id', 2)->groupBy('player_id')
            ->selectRaw('player_id, SUM(gp) AS career_gp')->pluck('career_gp', 'player_id');
        $active = Stat::query()->whereIn('player_id', $players->modelKeys())
            ->whereIn('season_id', $this->evaluationSeasonIds($asOf))->where('gp', '>', 0)
            ->when($for_drafting, fn ($query) => $query->where('league_abbrev', '<>', 'NHL'))
            ->distinct()->pluck('player_id')->map(fn ($id): int => (int) $id)->all();

        return $players->filter(fn (Player $player): bool => (int) ($career[$player->id] ?? 0) <= 25
            && in_array($player->id, $active, true))->map(fn (Player $player): int => (int) $player->nhl_id)->values()->all();
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
        return $player->externalIdentities
            ->contains(function (PlayerExternalIdentity $identity): bool {
                if ($identity->provider === PlayerExternalIdentity::PROVIDER_NHL_DRAFT) {
                    return true;
                }

                $draftDetails = data_get($identity->raw_payload, 'draftDetails');

                return is_array($draftDetails) && $draftDetails !== [];
            });
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

    private function seasonId(int $startYear): string
    {
        return (string) $startYear . (string) ($startYear + 1);
    }
}
