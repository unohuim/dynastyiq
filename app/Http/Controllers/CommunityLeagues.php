<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FantraxPlayer;
use App\Models\DiscordServer;
use App\Jobs\SyncFantraxDraftStateJob;
use App\Models\PlatformLeague;
use App\Models\PlatformTeam;
use App\Models\PlayerExternalIdentity;
use App\Models\Stat;
use App\Services\FantraxDraftingWindow;
use App\Traits\HasAPITrait;
use App\ViewModels\LeagueShowViewModel;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CommunityLeagues extends Controller
{
    use HasAPITrait;

    public function show(int $cId, int $lId): View
    {
        $user = Auth::user();

        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with(['discordServers', 'leagues'])
            ->findOrFail($cId);

        $league = $community->leagues()
            ->withPivot(['discord_server_id', 'meta'])
            ->findOrFail($lId);

        $communities = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->orderBy('organizations.name')
            ->get();

        $fantraxConnected = $user->fantraxSecret()->exists();

        $fantraxOptions = [];
        if ($fantraxConnected) {
            $fantraxOptions = PlatformLeague::query()
                ->select('platform_leagues.name', 'platform_leagues.platform_league_id', 'platform_leagues.sport')
                ->join('league_user_teams as lut', 'lut.platform_league_id', '=', 'platform_leagues.id')
                ->where('lut.user_id', $user->id)
                ->where('lut.is_active', true)
                ->where('platform_leagues.platform', 'fantrax')
                ->whereDoesntHave('league.organization')
                ->orderBy('platform_leagues.name')
                ->get()
                ->unique('platform_league_id')
                ->map(static function ($row): array {
                    return [
                        'name' => (string) $row->name,
                        'platform_league_id' => (string) $row->platform_league_id,
                        'sport' => (string) $row->sport,
                    ];
                })
                ->values()
                ->all();
        }

        $platformLeague = $league->primaryPlatformLeague();
        $platformLeagueId = $platformLeague?->platform_league_id;
        $isFantraxLeague = $league->getPlatformAttribute() === 'fantrax' && filled($platformLeagueId);

        $leagueInfo = [];
        $teams = [];
        if ($isFantraxLeague) {
            try {
                $resp = $this->getAPIData('fantrax', 'league_info', [
                    'leagueId' => (string) $platformLeagueId,
                ]);

                $leagueInfo = is_array($resp) ? $resp : [];
                $apiTeams = $leagueInfo['teamInfo'] ?? [];
                $teams = collect(is_array($apiTeams) ? $apiTeams : [])
                    ->filter(static fn (mixed $team): bool => is_array($team))
                    ->map(function (array $team): array {
                        return [
                            'id' => (string) ($team['id'] ?? ''),
                            'name' => (string) ($team['name'] ?? ''),
                            'owner_avatar_url' => null,
                            'logo_url' => $this->teamLogoUrl($team),
                        ];
                    })
                    ->values()
                    ->all();
            } catch (RequestException $e) {
                $teams = [];
            }
        }

        $draftResults = [];
        $draftPickInfo = [];
        $draftError = null;

        if ($isFantraxLeague && $platformLeague instanceof PlatformLeague) {
            $draftState = $platformLeague->fantraxDraftState()->first();

            if ($draftState && is_array($draftState->raw_draft_results)) {
                $draftResults = $draftState->raw_draft_results;
                $draftPickInfo = is_array($draftState->raw_draft_pick_info) ? $draftState->raw_draft_pick_info : [];
            } else {
                try {
                    $resp = $this->getAPIData('fantrax', 'draft_results', [
                        'leagueId' => (string) $platformLeagueId,
                    ]);
                    $draftResults = is_array($resp) ? $resp : [];
                } catch (Throwable $e) {
                    $draftError = $e;
                }

                try {
                    $resp = $this->getAPIData('fantrax', 'draft_picks', [
                        'leagueId' => (string) $platformLeagueId,
                    ]);
                    $draftPickInfo = is_array($resp) ? $resp : [];
                } catch (Throwable $e) {
                    $draftPickInfo = [];
                }

                if ($draftError === null && $draftResults !== []) {
                    SyncFantraxDraftStateJob::dispatch((int) $platformLeague->id, $draftResults, $draftPickInfo);
                }
            }
        }

        $draftingWindow = app(FantraxDraftingWindow::class);
        $playerNamesByFantraxId = $isFantraxLeague
            ? $this->fantraxDraftPlayerMap($draftingWindow->fantraxPlayerIds($draftResults))
            : [];
        $draftTeamMetaByFantraxId = $isFantraxLeague
            ? $this->fantraxDraftTeamMap((int) $platformLeague?->id)
            : [];
        $teams = collect($teams)
            ->map(static function (array $team) use ($draftTeamMetaByFantraxId): array {
                $teamMeta = $draftTeamMetaByFantraxId[(string) ($team['id'] ?? '')] ?? [];

                return array_merge($team, [
                    'owner_avatar_url' => $teamMeta['owner_avatar_url'] ?? null,
                    'logo_url' => $teamMeta['logo_url'] ?? ($team['logo_url'] ?? null),
                ]);
            })
            ->all();
        $drafting = $isFantraxLeague
            ? $draftingWindow->normalize(
                $leagueInfo,
                $draftResults,
                $draftError,
                null,
                $playerNamesByFantraxId,
                $draftTeamMetaByFantraxId,
                $draftPickInfo
            )
            : $draftingWindow->normalize([], []);
        $drafting['config'] = $this->draftingConfig($community, $league);

        $vm = new LeagueShowViewModel(
            community: $community,
            league: $league,
            communities: $communities,
            guilds: $community->discordServers,
            teams: $teams,
            drafting: $drafting,
            fantraxConnected: $fantraxConnected,
            fantraxOptions: $fantraxOptions,
            mobileBreakpoint: (int) config('viewports.mobile', 768)
        );

        $payload = $vm->toDto()->toArray();
        $payload['header']['can_export_fantrax_aav'] = $isFantraxLeague;
        $payload['header']['fantrax_aav_export_url'] = route('community.leagues.fantrax-aav-export', [
            'c_id' => $community->id,
            'l_id' => $league->id,
        ]);

        return view('communities.leagues.show', [
            'vm' => $payload,
        ]);
    }

    /**
     * Stream a Fantrax salary upload file for commissioner-managed leagues.
     */
    public function exportFantraxAav(int $cId, int $lId): StreamedResponse
    {
        $user = Auth::user();
        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->findOrFail($cId);
        $league = $community->leagues()
            ->findOrFail($lId);
        $platformLeague = $league->primaryPlatformLeague();

        abort_unless($platformLeague instanceof PlatformLeague && $platformLeague->platform === 'fantrax', 404);

        $filename = 'fantrax-aav-' . str($league->name ?: 'league')->slug('-')->toString() . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            $rowNumber = 1;
            $players = FantraxPlayer::query()
                ->leftJoin('players', 'players.id', '=', 'fantrax_players.player_id')
                ->select([
                    'fantrax_players.fantrax_id',
                    'fantrax_players.name',
                    'fantrax_players.team',
                    'fantrax_players.position',
                    'players.team_abbrev as linked_team_abbrev',
                ])
                ->selectSub(function ($query): void {
                    $query->from('contracts')
                        ->join('contract_seasons', 'contract_seasons.contract_id', '=', 'contracts.id')
                        ->whereColumn('contracts.player_id', 'fantrax_players.player_id')
                        ->whereNotNull('contract_seasons.cap_hit')
                        ->orderByDesc('contract_seasons.season_key')
                        ->select('contract_seasons.cap_hit')
                        ->limit(1);
                }, 'current_cap_hit')
                ->orderBy('fantrax_players.name')
                ->orderBy('fantrax_players.fantrax_id')
                ->cursor();

            foreach ($players as $player) {
                fwrite($handle, $this->fantraxUploadCsvRow([
                    '*' . (string) $player->fantrax_id . '*',
                    (string) $rowNumber++,
                    $this->fantraxUploadName((string) ($player->name ?? '')),
                    $this->fantraxUploadTeam((string) ($player->team ?: $player->linked_team_abbrev ?: '')),
                    $this->fantraxUploadPosition((string) ($player->position ?? '')),
                    (string) (int) ($player->current_cap_hit ?: 750000),
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function updateDraftSettings(Request $request, int $cId, int $lId): JsonResponse
    {
        $user = Auth::user();
        $community = $user->organizations()
            ->whereNotNull('organizations.settings')
            ->whereNull('organizations.deleted_at')
            ->with('discordServers')
            ->findOrFail($cId);
        $league = $community->leagues()
            ->withPivot(['discord_server_id', 'meta'])
            ->findOrFail($lId);

        $data = $request->validate([
            'draft_channel_id' => ['nullable', 'string', 'max:64'],
            'draft_channel_name' => ['nullable', 'string', 'max:100'],
        ]);

        $channelId = trim((string) ($data['draft_channel_id'] ?? ''));
        $channelName = $this->normalizeDiscordChannelName($data['draft_channel_name'] ?? '');
        $discordServer = $this->selectedDiscordServer($community, $league);
        $meta = $this->pivotMeta($league);

        if ($channelId === '' && $channelName === '') {
            data_forget($meta, 'draft_notifications.discord_channel');
        } elseif ($discordServer) {
            $channels = $this->discordTextChannels($discordServer);
            $channel = collect($channels)->first(static fn (array $option): bool => $channelId !== ''
                ? (string) $option['id'] === $channelId
                : strtolower((string) $option['name']) === strtolower($channelName));

            if (! $channel && $channelName !== '') {
                $channel = $this->createDiscordTextChannel($discordServer, $channelName);
            }

            if ($channel) {
                data_set($meta, 'draft_notifications.discord_channel', [
                    'id' => (string) $channel['id'],
                    'name' => (string) $channel['name'],
                ]);
            } else {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not find or create that Discord channel.',
                ], 422);
            }
        } else {
            return response()->json([
                'ok' => false,
                'message' => 'Connect a Discord server before selecting a draft channel.',
            ], 422);
        }

        DB::table('organization_leagues')
            ->where('organization_id', $community->id)
            ->where('league_id', $league->id)
            ->update([
                'meta' => $meta === [] ? null : json_encode($meta),
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok' => true,
            'channel' => data_get($meta, 'draft_notifications.discord_channel'),
        ]);
    }

    /**
     * Format a Fantrax salary-upload CSV row exactly like the blank Fantrax template.
     *
     * @param array<int, string> $fields
     */
    private function fantraxUploadCsvRow(array $fields): string
    {
        return collect($fields)
            ->map(static fn (string $field): string => '"' . str_replace('"', '""', $field) . '"')
            ->implode(',') . "\n";
    }

    /**
     * Normalize a stored Fantrax name for salary-upload display.
     */
    private function fantraxUploadName(string $name): string
    {
        $name = trim($name);

        if (! str_contains($name, ',')) {
            return $name;
        }

        [$last, $first] = array_map('trim', explode(',', $name, 2));

        return trim($first . ' ' . $last);
    }

    /**
     * Normalize a stored Fantrax team for salary-upload display.
     */
    private function fantraxUploadTeam(string $team): string
    {
        $team = trim($team);

        return in_array(strtoupper($team), ['', 'N/A', '(N/A)'], true) ? '' : $team;
    }

    /**
     * Normalize a stored Fantrax position into the Fantrax salary-upload position bucket.
     */
    private function fantraxUploadPosition(string $position): string
    {
        $positions = collect(preg_split('/[,\s\/]+/', strtoupper(trim($position))) ?: [])
            ->map(static fn (string $value): string => match ($value) {
                'C', 'L', 'LW', 'R', 'RW', 'W', 'FWD', 'FORWARD', 'SKT', 'SKATER' => 'F',
                'LD', 'RD' => 'D',
                default => $value,
            })
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        if ($positions->isEmpty()) {
            return '';
        }

        if ($positions->contains('G')) {
            return 'G';
        }

        if ($positions->contains('F') && $positions->contains('D')) {
            return 'F,D';
        }

        if ($positions->contains('D')) {
            return 'D';
        }

        return 'F';
    }

    /**
     * Build a display map for drafted Fantrax player IDs from local identity tables.
     *
     * @param array<int,string> $fantraxPlayerIds
     *
     * @return array<string,array<string,mixed>>
     */
    private function fantraxDraftPlayerMap(array $fantraxPlayerIds): array
    {
        $fantraxPlayerIds = collect($fantraxPlayerIds)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($fantraxPlayerIds === []) {
            return [];
        }

        $map = [];

        $fantraxPlayers = FantraxPlayer::query()
            ->with('player:id,full_name,nhl_id,position,head_shot_url')
            ->whereIn('fantrax_id', $fantraxPlayerIds)
            ->get();
        $playerIds = $fantraxPlayers
            ->pluck('player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values()
            ->all();
        $latestStatsByPlayerId = $this->latestStatsByPlayerId($playerIds);

        $fantraxPlayers->each(static function (FantraxPlayer $fantraxPlayer) use (&$map, $latestStatsByPlayerId): void {
            $playerId = $fantraxPlayer->player_id ? (int) $fantraxPlayer->player_id : null;
            $latestStats = $playerId ? ($latestStatsByPlayerId[$playerId] ?? null) : null;

            $map[(string) $fantraxPlayer->fantrax_id] = [
                'name' => $fantraxPlayer->name ?: $fantraxPlayer->player?->full_name,
                'player_id' => $playerId,
                'nhl_id' => $fantraxPlayer->player?->nhl_id ? (int) $fantraxPlayer->player->nhl_id : null,
                'position' => $fantraxPlayer->player?->position ?: $fantraxPlayer->position,
                'league_abbrev' => $latestStats?->league_abbrev,
                'team_abbrev' => $latestStats?->nhl_team_abbrev,
                'avatar_url' => $fantraxPlayer->player?->head_shot_url,
                'stats' => [
                    'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                    'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                    'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                    'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                ],
            ];
        });

        $externalIdentities = PlayerExternalIdentity::query()
            ->with('player:id,full_name,nhl_id,position,head_shot_url')
            ->where('provider', PlayerExternalIdentity::PROVIDER_FANTRAX)
            ->whereIn('provider_player_id', $fantraxPlayerIds)
            ->get();
        $identityPlayerIds = $externalIdentities
            ->pluck('player_id')
            ->filter()
            ->map(static fn (mixed $playerId): int => (int) $playerId)
            ->unique()
            ->values()
            ->all();
        $identityLatestStatsByPlayerId = $this->latestStatsByPlayerId($identityPlayerIds);

        $externalIdentities->each(
            function (PlayerExternalIdentity $identity) use (&$map, $identityLatestStatsByPlayerId): void {
                $fantraxId = (string) $identity->provider_player_id;
                $existing = $map[$fantraxId] ?? [];
                $playerId = $identity->player_id ? (int) $identity->player_id : null;
                $latestStats = $playerId ? ($identityLatestStatsByPlayerId[$playerId] ?? null) : null;

                $map[$fantraxId] = [
                    'name' => $existing['name']
                        ?? $identity->display_name
                        ?? $identity->player?->full_name,
                    'player_id' => $existing['player_id']
                        ?? $playerId,
                    'nhl_id' => $existing['nhl_id']
                        ?? ($identity->player?->nhl_id ? (int) $identity->player->nhl_id : null),
                    'position' => $existing['position'] ?? $identity->position ?? $identity->player?->position,
                    'league_abbrev' => $existing['league_abbrev'] ?? $latestStats?->league_abbrev,
                    'team_abbrev' => $existing['team_abbrev'] ?? $latestStats?->nhl_team_abbrev,
                    'avatar_url' => $existing['avatar_url'] ?? $identity->player?->head_shot_url,
                    'stats' => $this->hasResolvedStats($existing['stats'] ?? null)
                        ? $existing['stats']
                        : [
                            'gp' => $latestStats?->gp !== null ? (int) $latestStats->gp : null,
                            'g' => $latestStats?->g !== null ? (int) $latestStats->g : null,
                            'a' => $latestStats?->a !== null ? (int) $latestStats->a : null,
                            'pts' => $latestStats?->pts !== null ? (int) $latestStats->pts : null,
                        ],
                ];
            }
        );

        return $map;
    }

    /**
     * Build settings data for the draft settings drawer.
     *
     * @return array<string,mixed>
     */
    private function draftingConfig(mixed $community, mixed $league): array
    {
        $discordServer = $this->selectedDiscordServer($community, $league);
        $selectedChannel = data_get($this->pivotMeta($league), 'draft_notifications.discord_channel');
        $channelOptions = $this->discordTextChannelOptions($discordServer);
        $channels = $channelOptions['channels'];

        if (is_array($selectedChannel)) {
            $selectedChannelId = (string) ($selectedChannel['id'] ?? '');
            $hasSelectedChannel = collect($channels)
                ->contains(static fn (array $channel): bool => (string) $channel['id'] === $selectedChannelId);

            if ($channelOptions['status'] === 'loaded' && ! $hasSelectedChannel) {
                $selectedChannel = null;
            }
        }

        return [
            'action_url' => route('community.leagues.draft-settings.update', [
                'c_id' => $community->id,
                'l_id' => $league->id,
            ]),
            'discord_connected' => $discordServer !== null,
            'channels' => collect($channels)->sortBy('name')->values()->all(),
            'channels_status' => $channelOptions['status'],
            'channels_message' => $channelOptions['message'],
            'selected_channel' => is_array($selectedChannel) ? $selectedChannel : null,
        ];
    }

    private function selectedDiscordServer(mixed $community, mixed $league): ?DiscordServer
    {
        $discordServerId = $league->pivot?->discord_server_id;

        return $community->discordServers
            ->first(static fn (DiscordServer $server): bool => (int) $server->id === (int) $discordServerId)
            ?? $community->discordServers->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function pivotMeta(mixed $league): array
    {
        $meta = $league->pivot?->meta ?? null;

        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<int,array{id:string,name:string}>
     */
    private function discordTextChannels(DiscordServer $discordServer): array
    {
        return $this->discordTextChannelOptions($discordServer)['channels'];
    }

    /**
     * @return array{channels:array<int,array{id:string,name:string}>,status:string,message:string|null}
     */
    private function discordTextChannelOptions(?DiscordServer $discordServer): array
    {
        $token = (string) config('apiurls.discord-bot.key');

        if (! $discordServer) {
            return [
                'channels' => [],
                'status' => 'not_connected',
                'message' => 'Connect a Discord server first.',
            ];
        }

        if ($token === '') {
            return [
                'channels' => [],
                'status' => 'missing_bot_token',
                'message' => 'Discord channels could not be loaded because the DIQ bot token is not configured.',
            ];
        }

        try {
            $response = Http::withHeaders($this->discordBotHeaders($token))
                ->acceptJson()
                ->get('https://discord.com/api/v10/guilds/' . $discordServer->discord_guild_id . '/channels');

            if (! $response->successful()) {
                return [
                    'channels' => [],
                    'status' => 'discord_error',
                    'message' => 'Discord returned ' . $response->status() . ' while loading channels for this server.',
                ];
            }

            $channels = collect($response->json())
                ->filter(static fn (mixed $channel): bool => is_array($channel) && (int) ($channel['type'] ?? -1) === 0)
                ->map(static fn (array $channel): array => [
                    'id' => (string) ($channel['id'] ?? ''),
                    'name' => (string) ($channel['name'] ?? ''),
                ])
                ->filter(static fn (array $channel): bool => $channel['id'] !== '' && $channel['name'] !== '')
                ->values()
                ->all();

            return [
                'channels' => $channels,
                'status' => $channels === [] ? 'empty' : 'loaded',
                'message' => $channels === [] ? 'No text channels were returned for this Discord server.' : null,
            ];
        } catch (Throwable) {
            return [
                'channels' => [],
                'status' => 'discord_error',
                'message' => 'Discord channels could not be loaded for this server.',
            ];
        }
    }

    /**
     * @return array{id:string,name:string}|null
     */
    private function createDiscordTextChannel(DiscordServer $discordServer, string $channelName): ?array
    {
        $token = (string) config('apiurls.discord-bot.key');
        $guildId = (string) $discordServer->discord_guild_id;

        if ($token === '' || $guildId === '' || $channelName === '') {
            return null;
        }

        try {
            $channels = $this->discordGuildChannels($discordServer);
            $placement = $this->draftChannelPlacement($channels);
            $payload = array_filter([
                'name' => $channelName,
                'type' => 0,
                'parent_id' => $placement['parent_id'],
                'position' => $placement['position'],
            ], static fn (mixed $value): bool => $value !== null);

            $response = Http::withHeaders($this->discordBotHeaders($token))
                ->acceptJson()
                ->post('https://discord.com/api/v10/guilds/' . $guildId . '/channels', $payload);

            if (! $response->successful()) {
                return null;
            }

            $channel = $response->json();

            return [
                'id' => (string) ($channel['id'] ?? ''),
                'name' => (string) ($channel['name'] ?? $channelName),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,string>
     */
    private function discordBotHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bot ' . $token,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function discordGuildChannels(DiscordServer $discordServer): array
    {
        $token = (string) config('apiurls.discord-bot.key');

        if ($token === '') {
            return [];
        }

        try {
            $response = Http::withHeaders($this->discordBotHeaders($token))
                ->acceptJson()
                ->get('https://discord.com/api/v10/guilds/' . $discordServer->discord_guild_id . '/channels');

            return $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $channels
     *
     * @return array{parent_id:string|null,position:int|null}
     */
    private function draftChannelPlacement(array $channels): array
    {
        $textChannels = collect($channels)
            ->filter(static fn (mixed $channel): bool => is_array($channel) && (int) ($channel['type'] ?? -1) === 0);
        $categories = collect($channels)
            ->filter(static fn (mixed $channel): bool => is_array($channel) && (int) ($channel['type'] ?? -1) === 4);

        $category = $categories->first(static function (array $channel): bool {
            $name = strtolower(trim((string) ($channel['name'] ?? '')));

            return in_array($name, ['text channels', 'text channel', 'text'], true);
        });
        $parentId = is_array($category) ? (string) ($category['id'] ?? '') : '';

        if ($parentId === '') {
            $firstTextChannel = $textChannels->first();
            $parentId = is_array($firstTextChannel) ? (string) ($firstTextChannel['parent_id'] ?? '') : '';
        }

        $position = null;

        if ($parentId !== '') {
            $position = $textChannels
                ->filter(static fn (array $channel): bool => (string) ($channel['parent_id'] ?? '') === $parentId)
                ->map(static fn (array $channel): int => (int) ($channel['position'] ?? 0))
                ->max();
            $position = is_int($position) ? $position + 1 : null;
        }

        return [
            'parent_id' => $parentId !== '' ? $parentId : null,
            'position' => $position,
        ];
    }

    private function normalizeDiscordChannelName(mixed $value): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9-_]+/', '-', ltrim((string) $value, '#')) ?? ''), '-');
    }

    /**
     * @param array<string,mixed> $team
     */
    private function teamLogoUrl(array $team): ?string
    {
        foreach (['logoUrl', 'logo_url', 'avatarUrl', 'avatar_url', 'imageUrl', 'image_url', 'iconUrl', 'icon_url'] as $key) {
            $value = data_get($team, $key);

            if (filled($value) && is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Determine whether a stored draft stats payload includes at least one rendered value.
     */
    private function hasResolvedStats(mixed $stats): bool
    {
        if (! is_array($stats)) {
            return false;
        }

        return collect(['gp', 'g', 'a', 'pts'])
            ->contains(static fn (string $key): bool => ($stats[$key] ?? null) !== null);
    }

    /**
     * Return the most-used league stat snapshot from each player's latest available season.
     *
     * @param array<int,int> $playerIds
     *
     * @return array<int,Stat>
     */
    private function latestStatsByPlayerId(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        return Stat::query()
            ->whereIn('player_id', $playerIds)
            ->orderByDesc('season_id')
            ->orderByDesc('gp')
            ->orderByDesc('updated_at')
            ->get(['player_id', 'league_abbrev', 'nhl_team_abbrev', 'gp', 'g', 'a', 'pts', 'season_id', 'updated_at'])
            ->groupBy(static fn (Stat $stat): int => (int) $stat->player_id)
            ->mapWithKeys(static function ($playerStats): array {
                $latestSeasonId = $playerStats->max('season_id');
                $stat = $playerStats
                    ->where('season_id', $latestSeasonId)
                    ->sortByDesc(static fn (Stat $stat): int => (int) $stat->gp)
                    ->first();

                return $stat ? [(int) $stat->player_id => $stat] : [];
            })
            ->all();
    }

    /**
     * Build drafting team owner avatar metadata keyed by Fantrax platform team id.
     *
     * @return array<string,array{owner_avatar_url:string|null}>
     */
    private function fantraxDraftTeamMap(?int $platformLeagueId): array
    {
        if (! $platformLeagueId) {
            return [];
        }

        return PlatformTeam::query()
            ->where('platform_league_id', $platformLeagueId)
            ->with(['users' => static function ($query): void {
                $query->wherePivot('is_active', true)
                    ->select('users.id')
                    ->with(['socialAccounts' => static function ($query): void {
                        $query->select('id', 'user_id', 'avatar')
                            ->where('provider', 'discord');
                    }]);
            }])
            ->get(['id', 'platform_team_id'])
            ->mapWithKeys(static function (PlatformTeam $team): array {
                $avatar = null;

                foreach ($team->users as $user) {
                    $avatar = optional($user->socialAccounts->first())->avatar;

                    if (filled($avatar)) {
                        break;
                    }
                }

                return [
                    (string) $team->platform_team_id => [
                        'owner_avatar_url' => filled($avatar) ? (string) $avatar : null,
                    ],
                ];
            })
            ->all();
    }
}
