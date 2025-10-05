<?php

declare(strict_types=1);

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

final class CommunityHubLayout extends Component
{
    public Collection $communities;
    public int $mobileBreakpoint;

    /** @var array{slug:string|null,name:string|null}|null */
    public ?array $initial;

    public int|string|null $activeId;
    public bool $onHub;

    /**
     * @param mixed $communities
     * @param array{slug:string|null,name:string|null}|null $initialCommunity
     * @param int|string|null $activeCommunityId
     */
    public function __construct(
        mixed $communities = null,
        int $mobileBreakpoint = 768,
        ?array $initialCommunity = null,
        int|string|null $activeCommunityId = null
    ) {
        $normalized = collect($communities ?? [])->map(static function ($c) {
            $a = is_array($c) ? $c : (array) $c;

            $id = $a['id'] ?? ($a['organization_id'] ?? null);

            return (object) [
                'id'         => $id,
                'slug'       => $a['slug'] ?? $id,
                'name'       => $a['name'] ?? '',
                'short_name' => $a['short_name'] ?? null,
                'href'       => route('communities.index', ['active' => $id]),
                'active'     => (bool) ($a['active'] ?? false),
            ];
        });

        $first = $normalized->first();

        $requested = request()->has('active') ? request()->integer('active') : null;
        $this->activeId = $requested ?? $activeCommunityId ?? ($first->id ?? null);

        $this->communities = $normalized->map(function ($org) {
            $org->active = (int) ($org->id ?? 0) === (int) ($this->activeId ?? 0);
            return $org;
        });

        $this->mobileBreakpoint = $mobileBreakpoint;
        $this->initial = $initialCommunity ?? ($first
            ? ['slug' => (string) ($first->slug ?? ''), 'name' => (string) ($first->name ?? '')]
            : null);

        $this->onHub = request()->routeIs('communities.index');
    }

    public function render(): View|Closure|string
    {
        return view('components.community-hub-layout', [
            'communities' => $this->communities,
            'mobileBreakpoint' => $this->mobileBreakpoint,
            'initial' => $this->initial,
            'activeId' => $this->activeId,
            'onHub' => $this->onHub,
        ]);
    }
}
