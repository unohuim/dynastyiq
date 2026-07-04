<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FantraxDraftPickMade;
use App\Events\FantraxDraftPickToast;
use App\Models\FantraxDraftPick;
use App\Models\FantraxPlayer;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\SocialAccount;
use App\Models\Stat;
use App\Services\DraftPickCardRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnnounceFantraxDraftPick implements ShouldQueue
{
    public function handle(FantraxDraftPickMade $event): void
    {
        $draftPick = FantraxDraftPick::query()->find($event->draftPickId);

        if (! $draftPick instanceof FantraxDraftPick) {
            return;
        }

        $context = $this->context($draftPick);

        if ($context === null) {
            return;
        }

        if (! $this->claimDraftPick($draftPick)) {
            return;
        }

        $message = $this->message($draftPick, $context);
        $discordPayload = $this->discordPayload($draftPick, $message, $context);

        foreach ($context['user_ids'] as $userId) {
            FantraxDraftPickToast::dispatch((int) $userId, $message, $event->pick);
        }

        $channelId = (string) data_get($context['pivot_meta'], 'draft_notifications.discord_channel.id', '');

        if ($channelId !== '') {
            $this->postDiscordMessage($channelId, $discordPayload);
        }
    }

    private function claimDraftPick(FantraxDraftPick $draftPick): bool
    {
        return FantraxDraftPick::query()
            ->whereKey($draftPick->id)
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]) === 1;
    }

    /**
     * @return array{team_name:string,player_name:string,position:string|null,avatar_url:string|null,team_abbrev:string|null,stats:array<int,array<string,mixed>>,stat_headers:array<int,string>,stat_keys:array<int,string>,drafting_owner:array{label:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null},otc_owner:array{label:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null,pivot_meta:array<string,mixed>,user_ids:array<int,int>}|null
     */
    private function context(FantraxDraftPick $draftPick): ?array
    {
        $row = DB::table('league_platform_league')
            ->join('organization_leagues', 'organization_leagues.league_id', '=', 'league_platform_league.league_id')
            ->where('league_platform_league.platform_league_id', $draftPick->platform_league_id)
            ->select([
                'league_platform_league.league_id',
                'organization_leagues.organization_id',
                'organization_leagues.meta as pivot_meta',
            ])
            ->first();

        if (! $row) {
            return null;
        }

        $pivotMeta = is_string($row->pivot_meta) && $row->pivot_meta !== ''
            ? json_decode($row->pivot_meta, true)
            : [];
        $teamName = PlatformTeam::query()
            ->where('platform_league_id', $draftPick->platform_league_id)
            ->where('platform_team_id', $draftPick->fantrax_team_id)
            ->value('name');
        $playerName = FantraxPlayer::query()
            ->where('fantrax_id', $draftPick->fantrax_player_id)
            ->value('name');
        $playerDetails = $this->playerDetails((string) $draftPick->fantrax_player_id);
        $userIds = DB::table('organization_user')
            ->where('organization_id', $row->organization_id)
            ->pluck('user_id')
            ->map(static fn (mixed $userId): int => (int) $userId)
            ->values()
            ->all();

        return [
            'team_name' => (string) ($teamName ?: $draftPick->fantrax_team_id ?: 'Unknown team'),
            'player_name' => (string) ($playerName ?: $playerDetails['name'] ?: $draftPick->fantrax_player_id ?: 'Unknown player'),
            'position' => $playerDetails['position'],
            'avatar_url' => $playerDetails['avatar_url'],
            'stats' => $playerDetails['stats'],
            'stat_headers' => $playerDetails['stat_headers'],
            'stat_keys' => $playerDetails['stat_keys'],
            'team_abbrev' => $playerDetails['team_abbrev'],
            'drafting_owner' => $this->teamOwnerDiscordContext($draftPick->platform_league_id, $draftPick->fantrax_team_id, (string) ($teamName ?: $draftPick->fantrax_team_id ?: 'Unknown team')),
            'otc_owner' => $this->nextOtcOwnerContext($draftPick),
            'pivot_meta' => is_array($pivotMeta) ? $pivotMeta : [],
            'user_ids' => $userIds,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function message(FantraxDraftPick $draftPick, array $context): string
    {
        $pickLabel = $draftPick->overall_pick ? (string) $draftPick->overall_pick : 'unknown';
        $draftingOwner = $context['drafting_owner']['mention'] ?? $context['drafting_owner']['label'];
        $otcOwnerContext = $context['otc_owner'] ?? null;
        $otcOwner = is_array($otcOwnerContext)
            ? ($otcOwnerContext['mention'] ?? $otcOwnerContext['label'])
            : null;
        $message = "{$draftingOwner} ({$context['team_name']}) selects {$context['player_name']} with pick {$pickLabel}.";

        if ($otcOwner) {
            $message .= " {$otcOwner} is now OTC.";
        }

        return $message;
    }

    /**
     * @return array{content:string,allowed_mentions:array{parse:array<int,string>,users:array<int,string>},card:array<string,mixed>}
     */
    private function discordPayload(FantraxDraftPick $draftPick, string $message, array $context): array
    {
        $mentionUserIds = collect([
            $context['drafting_owner']['discord_user_id'] ?? null,
            data_get($context, 'otc_owner.discord_user_id'),
        ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'content' => $message,
            'allowed_mentions' => [
                'parse' => [],
                'users' => $mentionUserIds,
            ],
            'card' => [
                'overall_pick' => $draftPick->overall_pick,
                'round' => $draftPick->round,
                'pick_in_round' => $draftPick->pick_in_round,
                'player_name' => $context['player_name'],
                'position' => $context['position'],
                'avatar_url' => $context['avatar_url'],
                'team_name' => $context['team_name'],
                'team_abbrev' => $context['team_abbrev'],
                'drafting_owner_avatar_url' => $context['drafting_owner']['avatar_url'],
                'stats' => $context['stats'],
                'stat_headers' => $context['stat_headers'],
                'stat_keys' => $context['stat_keys'],
            ],
        ];
    }

    /**
     * @return array{label:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}
     */
    private function teamOwnerDiscordContext(int $platformLeagueId, ?string $fantraxTeamId, string $fallbackLabel): array
    {
        if (! $fantraxTeamId) {
            return ['label' => $fallbackLabel, 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $team = PlatformTeam::query()
            ->where('platform_league_id', $platformLeagueId)
            ->where('platform_team_id', $fantraxTeamId)
            ->first(['id', 'name']);

        if (! $team instanceof PlatformTeam) {
            return ['label' => $fallbackLabel, 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $userId = DB::table('league_user_teams')
            ->where('platform_league_id', $platformLeagueId)
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->value('user_id');

        if (! $userId) {
            return ['label' => (string) ($team->name ?: $fallbackLabel), 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $account = SocialAccount::query()
            ->where('provider', 'discord')
            ->where('user_id', (int) $userId)
            ->first(['provider_user_id', 'nickname', 'name', 'avatar']);

        if (! $account instanceof SocialAccount) {
            return ['label' => (string) ($team->name ?: $fallbackLabel), 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $discordUserId = (string) $account->provider_user_id;

        return [
            'label' => (string) ($account->nickname ?: $account->name ?: $team->name ?: $fallbackLabel),
            'mention' => $discordUserId !== '' ? '<@' . $discordUserId . '>' : null,
            'discord_user_id' => $discordUserId !== '' ? $discordUserId : null,
            'avatar_url' => $account->avatar ? (string) $account->avatar : null,
        ];
    }

    /**
     * @return array{label:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null
     */
    private function nextOtcOwnerContext(FantraxDraftPick $draftPick): ?array
    {
        $query = FantraxDraftPick::query()
            ->where('platform_league_id', $draftPick->platform_league_id)
            ->whereNull('fantrax_player_id')
            ->orderByRaw('overall_pick is null')
            ->orderBy('overall_pick')
            ->orderBy('round')
            ->orderBy('pick_in_round');

        if ($draftPick->overall_pick) {
            $query->where('overall_pick', '>', $draftPick->overall_pick);
        }

        $nextPick = $query->first(['fantrax_team_id']);

        if (! $nextPick instanceof FantraxDraftPick || ! $nextPick->fantrax_team_id) {
            return null;
        }

        $teamName = (string) (PlatformTeam::query()
            ->where('platform_league_id', $draftPick->platform_league_id)
            ->where('platform_team_id', $nextPick->fantrax_team_id)
            ->value('name') ?: $nextPick->fantrax_team_id);

        return $this->teamOwnerDiscordContext($draftPick->platform_league_id, $nextPick->fantrax_team_id, $teamName);
    }

    /**
     * @return array{name:string|null,position:string|null,avatar_url:string|null,team_abbrev:string|null,stats:array<int,array<string,mixed>>,stat_headers:array<int,string>,stat_keys:array<int,string>}
     */
    private function playerDetails(string $fantraxPlayerId): array
    {
        $fantraxPlayer = FantraxPlayer::query()
            ->with('player:id,full_name,position,pos_type,head_shot_url')
            ->where('fantrax_id', $fantraxPlayerId)
            ->first();
        $identity = PlayerExternalIdentity::query()
            ->with('player:id,full_name,position,pos_type,head_shot_url')
            ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
            ->where('provider_player_id', $fantraxPlayerId)
            ->first();
        $player = $fantraxPlayer?->player ?? $identity?->player;
        $playerId = $fantraxPlayer?->player_id ?: $identity?->player_id;
        $isGoalie = $this->isGoalie($player, $fantraxPlayer?->position, $identity?->position);

        $stats = $playerId ? $this->recentSeasonStats((int) $playerId) : [];

        return [
            'name' => $fantraxPlayer?->name ?: $identity?->display_name ?: $player?->full_name,
            'position' => $player?->position ?: $fantraxPlayer?->position ?: $identity?->position,
            'avatar_url' => $player?->head_shot_url,
            'team_abbrev' => $stats[0]['team_abbrev'] ?? null,
            'stats' => $stats,
            'stat_headers' => $isGoalie ? ['GP', 'W', 'SV', 'SV%'] : ['GP', 'G', 'A', 'PTS'],
            'stat_keys' => $isGoalie ? ['gp', 'wins', 'saves', 'sv_pct'] : ['gp', 'g', 'a', 'pts'],
        ];
    }

    private function isGoalie(?Player $player, ?string ...$providerPositions): bool
    {
        $positions = [
            $player?->position,
            $player?->pos_type,
            ...$providerPositions,
        ];

        return collect($positions)
            ->filter()
            ->contains(static fn (string $position): bool => strtoupper(trim($position)) === 'G');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentSeasonStats(int $playerId): array
    {
        return Stat::query()
            ->where('player_id', $playerId)
            ->orderByDesc('season_id')
            ->orderByDesc('gp')
            ->orderByDesc('updated_at')
            ->get(['season_id', 'league_abbrev', 'nhl_team_abbrev', 'team_name', 'gp', 'g', 'a', 'pts', 'wins', 'saves', 'sv_pct', 'updated_at'])
            ->groupBy(static fn (Stat $stat): string => (string) $stat->season_id)
            ->sortKeysDesc()
            ->take(2)
            ->map(static function ($seasonStats): array {
                $stat = $seasonStats
                    ->sortByDesc(static fn (Stat $stat): int => (int) $stat->gp)
                    ->first();

                return [
                    'season_id' => (string) $stat->season_id,
                    'league_abbrev' => (string) $stat->league_abbrev,
                    'team_abbrev' => $stat->nhl_team_abbrev ? (string) $stat->nhl_team_abbrev : null,
                    'team_name' => $stat->team_name ? (string) $stat->team_name : null,
                    'gp' => (int) $stat->gp,
                    'g' => (int) $stat->g,
                    'a' => (int) $stat->a,
                    'pts' => (int) $stat->pts,
                    'wins' => $stat->wins === null ? null : (int) $stat->wins,
                    'saves' => $stat->saves === null ? null : (int) $stat->saves,
                    'sv_pct' => $stat->sv_pct === null ? null : (float) $stat->sv_pct,
                ];
            })
            ->values()
            ->all();
    }

    private function footerText(FantraxDraftPick $draftPick): string
    {
        $parts = [];

        if ($draftPick->round) {
            $parts[] = 'Round ' . $draftPick->round;
        }

        if ($draftPick->pick_in_round) {
            $parts[] = 'Pick ' . $draftPick->pick_in_round;
        }

        if ($draftPick->overall_pick) {
            $parts[] = 'Overall #' . $draftPick->overall_pick;
        }

        return $parts !== [] ? implode(' | ', $parts) : 'Draft pick';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function postDiscordMessage(string $channelId, array $payload): void
    {
        $token = (string) config('apiurls.discord-bot.key');

        if ($token === '') {
            Log::warning('Fantrax draft pick Discord announcement skipped because bot token is missing.', [
                'channel_id' => $channelId,
            ]);

            return;
        }

        $cardPath = app(DraftPickCardRenderer::class)->render(is_array($payload['card'] ?? null) ? $payload['card'] : []);

        try {
            if ($cardPath) {
                $cardContents = file_get_contents($cardPath);

                if ($cardContents !== false) {
                    $imageResponse = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                        ->acceptJson()
                        ->asMultipart()
                        ->attach('files[0]', $cardContents, 'draft-pick-card.png')
                        ->post('https://discord.com/api/v10/channels/' . $channelId . '/messages', [
                            'payload_json' => json_encode([
                                'content' => '',
                                'allowed_mentions' => [
                                    'parse' => [],
                                    'users' => [],
                                ],
                            ], JSON_UNESCAPED_SLASHES),
                        ]);

                    if (! $imageResponse->successful()) {
                        Log::warning('Fantrax draft pick Discord image announcement returned an error response.', [
                            'channel_id' => $channelId,
                            'status' => $imageResponse->status(),
                            'response' => str($imageResponse->body())->limit(500)->toString(),
                        ]);
                    }

                    $response = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                        ->acceptJson()
                        ->post('https://discord.com/api/v10/channels/' . $channelId . '/messages', $this->discordTextPayload($payload));
                } else {
                    $response = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                        ->acceptJson()
                        ->post('https://discord.com/api/v10/channels/' . $channelId . '/messages', $this->discordTextPayload($payload));
                }
            } else {
                $response = Http::withHeaders(['Authorization' => 'Bot ' . $token])
                    ->acceptJson()
                    ->post('https://discord.com/api/v10/channels/' . $channelId . '/messages', $this->discordTextPayload($payload));
            }
        } catch (Throwable $exception) {
            Log::warning('Fantrax draft pick Discord announcement failed.', [
                'channel_id' => $channelId,
                'exception' => $exception->getMessage(),
            ]);

            return;
        } finally {
            if ($cardPath && file_exists($cardPath)) {
                @unlink($cardPath);
            }
        }

        if (! $response->successful()) {
            Log::warning('Fantrax draft pick Discord announcement returned an error response.', [
                'channel_id' => $channelId,
                'status' => $response->status(),
                'response' => str($response->body())->limit(500)->toString(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array{content:string,allowed_mentions:array<string,mixed>}
     */
    private function discordTextPayload(array $payload): array
    {
        return [
            'content' => (string) ($payload['content'] ?? ''),
            'allowed_mentions' => is_array($payload['allowed_mentions'] ?? null)
                ? $payload['allowed_mentions']
                : [
                    'parse' => [],
                    'users' => [],
                ],
        ];
    }

}
