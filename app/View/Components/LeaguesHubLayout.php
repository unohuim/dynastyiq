<?php
// app/View/Components/LeaguesHubLayout.php

declare(strict_types=1);

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

final class LeaguesHubLayout extends Component
{
    public Collection $leagues;
    public int $mobileBreakpoint;
    public ?array $initial;
    public int|string|null $activeId;
    public bool $onHub;

    public function __construct(
        mixed $leagues = null,
        int $mobileBreakpoint = 768,
        ?array $initialLeague = null,
        int|string|null $activeLeagueId = null
    ) {
        $normalized = collect($leagues ?? [])->map(static function ($l) {
            if ($l instanceof Model) {
                $id   = $l->getKey();
                $name = (string) $l->getAttribute('name');
                $slug = (string) ($l->getAttribute('slug') ?? $id);
            } else {
                $a    = (array) $l;
                $id   = $a['id'] ?? ($a['platform_league_id'] ?? null);
                $name = (string) ($a['name'] ?? '');
                $slug = (string) ($a['slug'] ?? $id);
            }

            return (object) [
                'id'         => $id,
                'slug'       => $slug,
                'name'       => $name,
                'short_name' => $name,
                'href'       => route('leagues.index', ['active' => $id]),
                'active'     => false,
            ];
        });

        $first          = $normalized->first();
        $requested      = request()->has('active') ? request()->input('active') : null;
        $this->activeId = $requested ?? $activeLeagueId ?? ($first->id ?? null);

        $this->leagues = $normalized->map(function ($l) {
            $l->active = (string) $l->id === (string) ($this->activeId ?? '');
            return $l;
        });

        $this->mobileBreakpoint = $mobileBreakpoint;
        $this->initial = $initialLeague ?: ($first
            ? ['slug' => (string) ($first->slug ?? ''), 'name' => (string) ($first->name ?? '')]
            : null);

        $this->onHub = request()->routeIs('leagues.index');
    }

    public function render(): View|Closure|string
    {
        return view('components.leagues-hub-layout', [
            'leagues'          => $this->leagues,
            'mobileBreakpoint' => $this->mobileBreakpoint,
            'initial'          => $this->initial,
            'activeId'         => $this->activeId,
            'onHub'            => $this->onHub,
        ]);
    }
}
