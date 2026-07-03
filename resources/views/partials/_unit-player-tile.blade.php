<div class="w-[5.75rem] min-w-0 rounded-lg border border-blue-100 bg-gray-50 px-2 py-2 text-center">
    <div class="mx-auto flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-white text-xs font-semibold text-gray-500 ring-1 ring-gray-200">
        @if(! empty($player['avatar_url']))
            <img src="{{ $player['avatar_url'] }}" alt="" loading="lazy" class="h-full w-full object-cover">
        @else
            {{ $player['initials'] ?? '?' }}
        @endif
    </div>
    <div class="mt-1 truncate text-[11px] font-medium leading-4 text-gray-700" title="{{ $player['name'] ?? $player['short_name'] ?? '' }}">
        {{ $player['short_name'] ?? $player['name'] ?? '' }}
    </div>
</div>
