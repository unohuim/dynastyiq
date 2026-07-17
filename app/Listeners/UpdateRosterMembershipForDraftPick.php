<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DraftPickMade;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\FantraxPlayer;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\PlayerExternalIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Mirror a made draft pick into the current platform roster membership table.
 */
final class UpdateRosterMembershipForDraftPick
{
    /**
     * Handle a draft pick that has received a picked player.
     */
    public function handle(DraftPickMade $event): void
    {
        $draftPick = DraftPick::query()
            ->with(['draft', 'platformTeam'])
            ->find($event->draftPickId);

        if (! $draftPick instanceof DraftPick) {
            return;
        }

        $draft = $draftPick->draft;

        if (! $draft instanceof Draft || $draft->platform_league_id === null) {
            return;
        }

        $platformLeague = PlatformLeague::query()->find($draft->platform_league_id);

        if (! $platformLeague instanceof PlatformLeague) {
            return;
        }

        $platform = (string) ($draft->platform ?: $platformLeague->platform);
        $playerId = $draftPick->player_id
            ? (int) $draftPick->player_id
            : $this->playerIdForProviderPlayer($platform, (string) ($draftPick->provider_player_id ?? ''));
        $platformTeamId = $draftPick->platform_team_id
            ? (int) $draftPick->platform_team_id
            : $this->platformTeamId($platformLeague, (string) ($draftPick->provider_team_id ?? ''));

        if ($platform === '' || $playerId === null || $platformTeamId === null) {
            return;
        }

        $providerPlayerId = trim((string) ($draftPick->provider_player_id ?? '')) ?: null;
        $now = now();

        DB::transaction(function () use ($platformLeague, $platformTeamId, $playerId, $platform, $providerPlayerId, $now): void {
            $leagueTeamIds = PlatformTeam::query()
                ->where('platform_league_id', $platformLeague->id)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($leagueTeamIds === []) {
                return;
            }

            DB::table('platform_roster_memberships')
                ->whereIn('platform_team_id', $leagueTeamIds)
                ->where('player_id', $playerId)
                ->where('platform', $platform)
                ->whereNull('ends_at')
                ->where('platform_team_id', '!=', $platformTeamId)
                ->update([
                    'ends_at' => $now,
                    'updated_at' => $now,
                ]);

            $openMembership = DB::table('platform_roster_memberships')
                ->where('platform_team_id', $platformTeamId)
                ->where('player_id', $playerId)
                ->where('platform', $platform)
                ->whereNull('ends_at')
                ->first();

            if ($openMembership !== null) {
                DB::table('platform_roster_memberships')
                    ->where('id', $openMembership->id)
                    ->update([
                        'platform_player_id' => $providerPlayerId,
                        'updated_at' => $now,
                    ]);

                return;
            }

            DB::table('platform_roster_memberships')->insert([
                'platform_team_id' => $platformTeamId,
                'player_id' => $playerId,
                'platform' => $platform,
                'platform_player_id' => $providerPlayerId,
                'slot' => null,
                'status' => null,
                'eligibility' => null,
                'metadata' => null,
                'starts_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Resolve a provider player id to a canonical player id.
     */
    private function playerIdForProviderPlayer(string $platform, string $providerPlayerId): ?int
    {
        $providerPlayerId = trim($providerPlayerId);

        if ($platform === '' || $providerPlayerId === '') {
            return null;
        }

        if ($platform === 'fantrax') {
            $playerId = FantraxPlayer::query()
                ->where('fantrax_id', $providerPlayerId)
                ->value('player_id');

            if ($playerId !== null) {
                return (int) $playerId;
            }
        }

        $playerId = PlayerExternalIdentity::query()
            ->where('provider', $platform)
            ->where('provider_player_id', $providerPlayerId)
            ->whereNotNull('player_id')
            ->value('player_id');

        if ($playerId !== null) {
            return (int) $playerId;
        }

        $playerId = DB::table('platform_player_ids')
            ->where('platform', $platform)
            ->where('platform_player_id', $providerPlayerId)
            ->value('player_id');

        return $playerId !== null ? (int) $playerId : null;
    }

    /**
     * Resolve the platform team row for a provider team id in the draft league.
     */
    private function platformTeamId(PlatformLeague $platformLeague, string $providerTeamId): ?int
    {
        $providerTeamId = trim($providerTeamId);

        if ($providerTeamId === '') {
            return null;
        }

        $teamId = PlatformTeam::query()
            ->where('platform_league_id', $platformLeague->id)
            ->where('platform_team_id', $providerTeamId)
            ->value('id');

        return $teamId !== null ? (int) $teamId : null;
    }
}
