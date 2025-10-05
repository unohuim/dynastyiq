<?php

declare(strict_types=1);

namespace App\ViewModels;

use App\DTO\LeagueShowDto;
use App\Models\League;
use App\Models\Organization;
use Illuminate\Support\Collection;

final class LeagueShowViewModel
{
    private Organization $community;

    private League $league;

    private Collection $communities;

    private Collection $guilds;

    /** @var array<int, array{id:string,name:string,owner_avatar_url:string|null}> */
    private array $teams;

    private bool $fantraxConnected;

    /** @var array<int, array{name:string,platform_league_id:string,sport:string}> */
    private array $fantraxOptions;

    private int $mobileBreakpoint;

    public function __construct(
        Organization $community,
        League $league,
        Collection $communities,
        Collection $guilds,
        array $teams,
        bool $fantraxConnected,
        array $fantraxOptions,
        int $mobileBreakpoint
    ) {
        $this->community = $community;
        $this->league = $league;
        $this->communities = $communities;
        $this->guilds = $guilds;
        $this->teams = $teams;
        $this->fantraxConnected = $fantraxConnected;
        $this->fantraxOptions = $fantraxOptions;
        $this->mobileBreakpoint = $mobileBreakpoint;
    }

    public function toDto(): LeagueShowDto
    {
        $platformConnected = filled($this->league->platform) && filled($this->league->platform_league_id);

        $header = [
            'title' => (string) ($this->league->name ?? 'League'),
            'can_edit' => true,
        ];

        $platform = [
            'title' => $platformConnected ? (string) ($this->league->name ?? 'League') : 'Fantasy platform',
            'status_text' => $platformConnected ? 'Fantrax connected' : 'Not connected',
            'status_class' => $platformConnected ? 'text-emerald-600' : 'text-slate-500',
            'subtext' => $platformConnected ? ('ID: ' . (string) $this->league->platform_league_id) : '—',
            'connected' => $platformConnected,
            'action_label' => $platformConnected ? 'Manage' : 'Connect Fantrax',
        ];

        $selectedGuild = $this->guilds->first();
        $discord = [
            'title' => $selectedGuild?->discord_guild_name ?? 'Discord',
            'status_text' => $selectedGuild ? 'Discord connected' : 'Not connected',
            'status_class' => $selectedGuild ? 'text-emerald-600' : 'text-slate-500',
            'connected' => (bool) $selectedGuild,
            'can_change' => $this->guilds->count() > 1,
            'avatar_url' => null,
            'action_url' => route('organizations.leagues.store', ['organization' => $this->community->id, 'league' => $this->league->id]),
            'current_id' => $selectedGuild->id ?? null,
            'options' => $this->guilds->map(function ($g) use ($selectedGuild): array {
                return [
                    'id' => $g->id,
                    'name' => $g->discord_guild_name ?? ('Server ' . $g->discord_guild_id),
                    'avatar_url' => null,
                    'selected' => $selectedGuild && $g->id === $selectedGuild->id,
                ];
            })->values()->all(),
        ];

        $teams = array_map(static function (array $t): array {
            return [
                'id' => (string) $t['id'],
                'name' => (string) $t['name'],
                'owner_avatar_url' => $t['owner_avatar_url'] ?? null,
            ];
        }, $this->teams);

        $sidebar = $this->communities->map(function (Organization $org): array {
            return [
                'id' => $org->id,
                'name' => (string) $org->name,
                'url' => '#',                    // no server route; click is JS-handled
                'active' => $org->id === $this->community->id,
                'dataset' => [                   // give JS what it needs
                    'organization_id' => (string) $org->id,
                ],
            ];
        })->values()->all();

        $fantraxModal = [
            'connected' => $this->fantraxConnected,
            'options' => $this->fantraxOptions,
            'initial_name' => (string) ($this->league->name ?? ''),
            'action_url' => route('organizations.leagues.store', ['organization' => $this->community->id, 'league' => $this->league->id]),
        ];

        $meta = [
            'mobile_breakpoint' => $this->mobileBreakpoint,
        ];

        return new LeagueShowDto(
            $header,
            $platform,
            $discord,
            $teams,
            $sidebar,
            $fantraxModal,
            $meta
        );
    }
}
