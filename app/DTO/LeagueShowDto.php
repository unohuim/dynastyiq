<?php

declare(strict_types=1);

namespace App\DTO;

final class LeagueShowDto
{
    private array $header;

    private array $platform;

    private array $discord;

    private array $teams;

    private array $drafting;

    private array $sidebar;

    private array $fantraxModal;

    private array $meta;

    public function __construct(
        array $header,
        array $platform,
        array $discord,
        array $teams,
        array $drafting,
        array $sidebar,
        array $fantraxModal,
        array $meta
    ) {
        $this->header = $header;
        $this->platform = $platform;
        $this->discord = $discord;
        $this->teams = $teams;
        $this->drafting = $drafting;
        $this->sidebar = $sidebar;
        $this->fantraxModal = $fantraxModal;
        $this->meta = $meta;
    }

    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'platform' => $this->platform,
            'discord' => $this->discord,
            'teams' => $this->teams,
            'drafting' => $this->drafting,
            'sidebar' => $this->sidebar,
            'fantrax_modal' => $this->fantraxModal,
            'meta' => $this->meta,
        ];
    }
}
