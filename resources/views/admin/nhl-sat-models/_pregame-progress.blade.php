@php
    $pregameActive = in_array($pregameBuild?->status, ['queued', 'running'], true);
    $pregameFailed = $pregameBuild?->status === 'failed';
    $canViewPregame = $canViewPregame ?? ($pregameBuild?->canViewImpacts() ?? false);
@endphp
<div data-pregame-active="{{ $pregameActive ? '1' : '0' }}" data-pregame-viewable="{{ $canViewPregame ? '1' : '0' }}" role="status" aria-live="polite">
    @if($pregameBuild)
        <span @class([
            'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset',
            'bg-blue-50 text-blue-700 ring-blue-200' => $pregameActive,
            'bg-red-50 text-red-700 ring-red-200' => $pregameFailed,
            'bg-emerald-50 text-emerald-700 ring-emerald-200' => ! $pregameActive && ! $pregameFailed,
        ])>{{ $pregameActive ? 'Building' : ($pregameFailed ? 'Pregame failed' : 'Pregame completed') }}</span>
        <div class="mt-1 text-xs text-gray-500">
            Pregame · {{ number_format($pregameBuild->completed_games ?? 0) }} / {{ number_format($pregameBuild->total_games ?? 0) }} games
            @if($pregameBuild->status === 'queued') · Queued @endif
            @if($pregameBuild->blocked_games) · {{ number_format($pregameBuild->blocked_games) }} excluded @endif
            @if($pregameBuild->failed_games) · {{ number_format($pregameBuild->failed_games) }} failed @endif
        </div>
        @if($pregameBuild->current_game_date)
            <div class="mt-1 text-xs text-gray-500">{{ $pregameBuild->current_game_date->format('Y-m-d') }}</div>
        @endif
        @if($pregameFailed && $pregameBuild->last_error)
            <div class="mt-1 max-w-xs whitespace-normal text-xs text-red-700">{{ $pregameBuild->last_error }}</div>
        @endif
    @endif
</div>
