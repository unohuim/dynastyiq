<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DraftPickMade;
use App\Events\FantraxDraftPickToast;
use App\Models\Draft;
use App\Models\DraftNotificationSetting;
use App\Models\DraftPick;
use App\Models\FantraxPlayer;
use App\Models\PlatformTeam;
use App\Models\Player;
use App\Models\PlayerExternalIdentity;
use App\Models\SocialAccount;
use App\Models\Stat;
use App\Services\DraftPickCardRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnnounceFantraxDraftPick implements ShouldQueue
{
    public function handle(DraftPickMade $event): void
    {
        $draftPick = DraftPick::query()
            ->with(['draft.notificationSettings', 'platformTeam', 'player'])
            ->find($event->draftPickId);

        if (! $draftPick instanceof DraftPick) {
            return;
        }

        $context = $this->context($draftPick);

        if ($context === null) {
            return;
        }

        Cache::lock('draft-pick-announcement:' . $draftPick->id, 30)->block(5, function () use ($draftPick, $context): void {
            $lockedDraftPick = DraftPick::query()
                ->with(['draft.notificationSettings', 'platformTeam', 'player'])
                ->find($draftPick->id);

            if (! $lockedDraftPick instanceof DraftPick || $lockedDraftPick->announced_at !== null) {
                return;
            }

            $message = $this->message($lockedDraftPick, $context);
            $discordPayload = $this->discordPayload($lockedDraftPick, $message, $context);

            $eventPayload = DraftPickMade::fromDraftPick($lockedDraftPick)->pick;

            foreach ($context['user_ids'] as $userId) {
                FantraxDraftPickToast::dispatch((int) $userId, $message, $eventPayload);
            }

            $channelId = (string) data_get($context['notification_settings'], 'discord_channel_id', '');

            if ($channelId !== '' && ! $this->postDiscordMessage($channelId, $discordPayload)) {
                return;
            }

            $this->claimDraftPick($lockedDraftPick);
        });
    }

    private function claimDraftPick(DraftPick $draftPick): void
    {
        DraftPick::query()
            ->whereKey($draftPick->id)
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]);
    }

    /**
     * @param array<string,mixed> $legacyPivotMeta
     *
     * @return array<string,mixed>
     */
    private function notificationSettings(Draft $draft, array $legacyPivotMeta): array
    {
        $settings = $draft->notificationSettings;

        if (! $settings instanceof DraftNotificationSetting) {
            $legacyChannel = data_get($legacyPivotMeta, 'draft_notifications.discord_channel');
            $channelId = is_array($legacyChannel) ? trim((string) ($legacyChannel['id'] ?? '')) : '';
            $channelName = is_array($legacyChannel) ? trim((string) ($legacyChannel['name'] ?? '')) : '';
            $notificationOptions = $this->draftNotificationOptions([], $legacyPivotMeta);

            $settings = DraftNotificationSetting::query()->updateOrCreate(
                ['draft_id' => $draft->id],
                [
                    'discord_channel_id' => $channelId !== '' ? $channelId : null,
                    'discord_channel_name' => $channelName !== '' ? $channelName : null,
                    'enabled' => $channelId !== '',
                    'settings' => array_merge([
                        'source' => $channelId !== '' ? 'legacy_community_league_meta' : 'draft_pick_listener',
                    ], $notificationOptions),
                ],
            );
        }

        $notificationOptions = $this->draftNotificationOptions(
            is_array($settings->settings) ? $settings->settings : [],
            $legacyPivotMeta,
        );

        return [
            'discord_channel_id' => $settings->enabled ? $settings->discord_channel_id : null,
            'discord_channel_name' => $settings->discord_channel_name,
            'enabled' => (bool) $settings->enabled,
            'announce_otc' => $notificationOptions['announce_otc'],
            'announce_on_deck' => $notificationOptions['announce_on_deck'],
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $legacyPivotMeta
     *
     * @return array{announce_otc:bool,announce_on_deck:bool}
     */
    private function draftNotificationOptions(array $settings, array $legacyPivotMeta): array
    {
        return [
            'announce_otc' => array_key_exists('announce_otc', $settings)
                ? (bool) $settings['announce_otc']
                : (bool) data_get($legacyPivotMeta, 'draft_notifications.announce_otc', true),
            'announce_on_deck' => array_key_exists('announce_on_deck', $settings)
                ? (bool) $settings['announce_on_deck']
                : (bool) data_get($legacyPivotMeta, 'draft_notifications.announce_on_deck', false),
        ];
    }

    /**
     * @return array{team_name:string,player_name:string,position:string|null,avatar_url:string|null,team_abbrev:string|null,stats:array<int,array<string,mixed>>,stat_headers:array<int,string>,stat_keys:array<int,string>,drafting_owner:array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null},otc_owner:array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null,on_deck_owner:array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null,notification_settings:array<string,mixed>,user_ids:array<int,int>}|null
     */
    private function context(DraftPick $draftPick): ?array
    {
        $draft = $draftPick->draft;

        if (! $draft instanceof Draft) {
            return null;
        }

        $platformLeagueId = $draft->platform_league_id ? (int) $draft->platform_league_id : null;

        if ($platformLeagueId === null) {
            return null;
        }

        $row = DB::table('league_platform_league')
            ->join('organization_leagues', 'organization_leagues.league_id', '=', 'league_platform_league.league_id')
            ->where('league_platform_league.platform_league_id', $platformLeagueId)
            ->where('league_platform_league.status', 'active')
            ->select([
                'league_platform_league.league_id',
                'organization_leagues.organization_id',
                'organization_leagues.meta as pivot_meta',
            ])
            ->first();

        $pivotMeta = is_string($row?->pivot_meta) && $row->pivot_meta !== ''
            ? json_decode($row->pivot_meta, true)
            : [];
        $notificationSettings = $this->notificationSettings($draft, is_array($pivotMeta) ? $pivotMeta : []);
        $providerTeamId = $draftPick->provider_team_id ? (string) $draftPick->provider_team_id : null;
        $teamName = $draftPick->platformTeam?->name ?: PlatformTeam::query()
            ->where('platform_league_id', $platformLeagueId)
            ->where('platform_team_id', $providerTeamId)
            ->value('name');
        $providerPlayerId = $draftPick->provider_player_id ? (string) $draftPick->provider_player_id : null;
        $playerName = $draftPick->player?->full_name ?: FantraxPlayer::query()
            ->where('fantrax_id', $providerPlayerId)
            ->value('name');
        $playerDetails = $this->playerDetails($draftPick);
        $organizationId = $draft->organization_id
            ? (int) $draft->organization_id
            : ($row?->organization_id ? (int) $row->organization_id : null);
        $userIds = $organizationId
            ? DB::table('organization_user')
                ->where('organization_id', $organizationId)
                ->pluck('user_id')
                ->map(static fn (mixed $userId): int => (int) $userId)
                ->values()
                ->all()
            : [];

        return [
            'team_name' => (string) ($teamName ?: $providerTeamId ?: 'Unknown team'),
            'player_name' => (string) ($playerName ?: $playerDetails['name'] ?: $providerPlayerId ?: 'Unknown player'),
            'position' => $playerDetails['position'],
            'avatar_url' => $playerDetails['avatar_url'],
            'stats' => $playerDetails['stats'],
            'stat_headers' => $playerDetails['stat_headers'],
            'stat_keys' => $playerDetails['stat_keys'],
            'team_abbrev' => $playerDetails['team_abbrev'],
            'drafting_owner' => $this->teamOwnerDiscordContext($platformLeagueId, $providerTeamId, (string) ($teamName ?: $providerTeamId ?: 'Unknown team'), $draftPick->platform_team_id ? (int) $draftPick->platform_team_id : null),
            'otc_owner' => $this->nextOtcOwnerContext($draftPick),
            'on_deck_owner' => $this->nextOnDeckOwnerContext($draftPick),
            'notification_settings' => $notificationSettings,
            'user_ids' => $userIds,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function message(DraftPick $draftPick, array $context): string
    {
        $pickLabel = $draftPick->overall_pick ? (string) $draftPick->overall_pick : 'unknown';
        $draftingOwner = $context['drafting_owner']['mention'] ?? $context['drafting_owner']['label'];
        $notificationSettings = is_array($context['notification_settings'] ?? null)
            ? $context['notification_settings']
            : [];
        $otcOwnerContext = $context['otc_owner'] ?? null;
        $otcOwner = (bool) ($notificationSettings['announce_otc'] ?? false) && is_array($otcOwnerContext)
            ? $this->ownerAnnouncementLabel($otcOwnerContext)
            : null;
        $onDeckOwnerContext = $context['on_deck_owner'] ?? null;
        $onDeckOwner = (bool) ($notificationSettings['announce_on_deck'] ?? false) && is_array($onDeckOwnerContext)
            ? $this->ownerAnnouncementLabel($onDeckOwnerContext)
            : null;
        $message = "{$draftingOwner} ({$context['team_name']}) selects {$context['player_name']} with pick {$pickLabel}.";

        if ($otcOwner) {
            $message .= " {$otcOwner} is now OTC.";
        }

        if ($onDeckOwner) {
            $message .= " {$onDeckOwner} is on deck.";
        }

        return $message;
    }

    /**
     * @param array<string,mixed> $ownerContext
     */
    private function ownerAnnouncementLabel(array $ownerContext): string
    {
        $teamName = trim((string) ($ownerContext['team_name'] ?? $ownerContext['label'] ?? 'Unknown team'));
        $mention = trim((string) ($ownerContext['mention'] ?? ''));

        return $mention !== '' ? "{$mention} ({$teamName})" : $teamName;
    }

    /**
     * @return array{content:string,allowed_mentions:array{parse:array<int,string>,users:array<int,string>},card:array<string,mixed>}
     */
    private function discordPayload(DraftPick $draftPick, string $message, array $context): array
    {
        $mentionUserIds = collect([
            $context['drafting_owner']['discord_user_id'] ?? null,
            (bool) data_get($context, 'notification_settings.announce_otc')
                ? data_get($context, 'otc_owner.discord_user_id')
                : null,
            (bool) data_get($context, 'notification_settings.announce_on_deck')
                ? data_get($context, 'on_deck_owner.discord_user_id')
                : null,
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
     * @return array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}
     */
    private function teamOwnerDiscordContext(int $platformLeagueId, ?string $providerTeamId, string $fallbackLabel, ?int $platformTeamId = null): array
    {
        if (! $providerTeamId && ! $platformTeamId) {
            return ['label' => $fallbackLabel, 'team_name' => $fallbackLabel, 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $team = $platformTeamId
            ? PlatformTeam::query()->find($platformTeamId, ['id', 'name'])
            : PlatformTeam::query()
                ->where('platform_league_id', $platformLeagueId)
                ->where('platform_team_id', $providerTeamId)
                ->first(['id', 'name']);

        if (! $team instanceof PlatformTeam) {
            return ['label' => $fallbackLabel, 'team_name' => $fallbackLabel, 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $userId = DB::table('league_user_teams')
            ->where('platform_league_id', $platformLeagueId)
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->value('user_id');

        if (! $userId) {
            return ['label' => (string) ($team->name ?: $fallbackLabel), 'team_name' => (string) ($team->name ?: $fallbackLabel), 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $account = SocialAccount::query()
            ->where('provider', 'discord')
            ->where('user_id', (int) $userId)
            ->first(['provider_user_id', 'nickname', 'name', 'avatar']);

        if (! $account instanceof SocialAccount) {
            return ['label' => (string) ($team->name ?: $fallbackLabel), 'team_name' => (string) ($team->name ?: $fallbackLabel), 'mention' => null, 'discord_user_id' => null, 'avatar_url' => null];
        }

        $discordUserId = (string) $account->provider_user_id;

        return [
            'label' => (string) ($account->nickname ?: $account->name ?: $team->name ?: $fallbackLabel),
            'team_name' => (string) ($team->name ?: $fallbackLabel),
            'mention' => $discordUserId !== '' ? '<@' . $discordUserId . '>' : null,
            'discord_user_id' => $discordUserId !== '' ? $discordUserId : null,
            'avatar_url' => $account->avatar ? (string) $account->avatar : null,
        ];
    }

    /**
     * @return array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null
     */
    private function nextOtcOwnerContext(DraftPick $draftPick): ?array
    {
        return $this->upcomingOwnerContext($draftPick, 0);
    }

    /**
     * @return array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null
     */
    private function nextOnDeckOwnerContext(DraftPick $draftPick): ?array
    {
        return $this->upcomingOwnerContext($draftPick, 1);
    }

    /**
     * @return array{label:string,team_name:string,mention:string|null,discord_user_id:string|null,avatar_url:string|null}|null
     */
    private function upcomingOwnerContext(DraftPick $draftPick, int $offset): ?array
    {
        $draft = $draftPick->draft;
        $platformLeagueId = $draft?->platform_league_id ? (int) $draft->platform_league_id : null;

        if (! $draft instanceof Draft || $platformLeagueId === null) {
            return null;
        }

        $query = DraftPick::query()
            ->where('draft_id', $draft->id)
            ->whereNull('provider_player_id')
            ->orderByRaw('overall_pick is null')
            ->orderBy('overall_pick')
            ->orderBy('round')
            ->orderBy('pick_in_round');

        if ($draftPick->overall_pick) {
            $query->where('overall_pick', '>', $draftPick->overall_pick);
        }

        $nextPick = $query
            ->skip(max(0, $offset))
            ->first(['platform_team_id', 'provider_team_id']);

        if (! $nextPick instanceof DraftPick || (! $nextPick->provider_team_id && ! $nextPick->platform_team_id)) {
            return null;
        }

        $teamName = (string) ($nextPick->platformTeam?->name ?: PlatformTeam::query()
            ->where('platform_league_id', $platformLeagueId)
            ->where('platform_team_id', $nextPick->provider_team_id)
            ->value('name') ?: $nextPick->provider_team_id);

        return $this->teamOwnerDiscordContext(
            $platformLeagueId,
            $nextPick->provider_team_id ? (string) $nextPick->provider_team_id : null,
            $teamName,
            $nextPick->platform_team_id ? (int) $nextPick->platform_team_id : null,
        );
    }

    /**
     * @return array{name:string|null,position:string|null,avatar_url:string|null,team_abbrev:string|null,stats:array<int,array<string,mixed>>,stat_headers:array<int,string>,stat_keys:array<int,string>}
     */
    private function playerDetails(DraftPick $draftPick): array
    {
        $providerPlayerId = $draftPick->provider_player_id ? (string) $draftPick->provider_player_id : '';
        $fantraxPlayer = FantraxPlayer::query()
            ->with('player:id,full_name,position,pos_type,head_shot_url')
            ->where('fantrax_id', $providerPlayerId)
            ->first();
        $identity = PlayerExternalIdentity::query()
            ->with('player:id,full_name,position,pos_type,head_shot_url')
            ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
            ->where('provider_player_id', $providerPlayerId)
            ->first();
        $player = $draftPick->player ?? $fantraxPlayer?->player ?? $identity?->player;
        $playerId = $draftPick->player_id ?: $fantraxPlayer?->player_id ?: $identity?->player_id;
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

    private function footerText(DraftPick $draftPick): string
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
    private function postDiscordMessage(string $channelId, array $payload): bool
    {
        $token = (string) config('apiurls.discord-bot.key');

        if ($token === '') {
            Log::warning('Draft pick Discord announcement skipped because bot token is missing.', [
                'channel_id' => $channelId,
            ]);

            return false;
        }

        $cardPath = app(DraftPickCardRenderer::class)->render(is_array($payload['card'] ?? null) ? $payload['card'] : []);

        try {
            if ($cardPath) {
                $cardContents = file_get_contents($cardPath);

                if ($cardContents !== false) {
                    $response = $this->sendDiscordMessage($channelId, $token, $payload, $cardContents);
                } else {
                    $response = $this->sendDiscordMessage($channelId, $token, $payload);
                }
            } else {
                $response = $this->sendDiscordMessage($channelId, $token, $payload);
            }
        } catch (Throwable $exception) {
            Log::warning('Draft pick Discord announcement failed.', [
                'channel_id' => $channelId,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            if ($cardPath && file_exists($cardPath)) {
                @unlink($cardPath);
            }
        }

        if (! $response->successful()) {
            Log::warning('Draft pick Discord announcement returned an error response.', [
                'channel_id' => $channelId,
                'status' => $response->status(),
                'response' => str($response->body())->limit(500)->toString(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sendDiscordMessage(string $channelId, string $token, array $payload, ?string $cardContents = null): Response
    {
        $response = $this->discordRequest($channelId, $token, $payload, $cardContents);

        if ($response->status() !== 429) {
            return $response;
        }

        $retryAfter = (float) ($response->json('retry_after') ?? 0.5);
        usleep((int) max(100000, ($retryAfter + 0.15) * 1000000));

        return $this->discordRequest($channelId, $token, $payload, $cardContents);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function discordRequest(string $channelId, string $token, array $payload, ?string $cardContents = null): Response
    {
        $request = Http::withHeaders(['Authorization' => 'Bot ' . $token])
            ->acceptJson();

        if ($cardContents === null) {
            return $request->post(
                'https://discord.com/api/v10/channels/' . $channelId . '/messages',
                $this->discordTextPayload($payload),
            );
        }

        return $request
            ->asMultipart()
            ->attach('files[0]', $cardContents, 'draft-pick-card.png')
            ->post('https://discord.com/api/v10/channels/' . $channelId . '/messages', [
                'payload_json' => json_encode($this->discordTextPayload($payload), JSON_UNESCAPED_SLASHES),
            ]);
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
