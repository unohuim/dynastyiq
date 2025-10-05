<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class CardSection extends Component
{
    public bool $isAccordian = false;
    public int $mobileBreakpoint = 768;
    public string $title = '';
    public bool $open = true;

    public ?string $avatarUrl = null;
    public string $avatarAlt = '';
    public ?string $titleClass = null;
    public bool $centerWhenClosed = true;

    public function __construct(
        bool $isAccordian = false,
        ?bool $accordian = null,
        ?int $mobileBreakpoint = null,
        ?string $title = null,
        ?bool $open = null,
        ?string $avatarUrl = null,
        ?string $avatarAlt = null,
        ?string $titleClass = null,
        ?bool $centerWhenClosed = null
    ) {
        $this->isAccordian = $accordian ?? $isAccordian;
        if ($mobileBreakpoint !== null) $this->mobileBreakpoint = $mobileBreakpoint;
        if ($title !== null) $this->title = $title;
        if ($open !== null) $this->open = (bool) $open;

        $this->avatarUrl = $avatarUrl;
        $this->avatarAlt = $avatarAlt ?? ($this->title ?: 'Avatar');
        $this->titleClass = $titleClass;
        if ($centerWhenClosed !== null) $this->centerWhenClosed = (bool) $centerWhenClosed;
    }

    public function render(): View
    {
        return view('components.card-section');
    }
}
