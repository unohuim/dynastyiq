@php
    $modelRunId = $modelRunId ?? $pregameBuild?->model_run_id;
    $pregameStatus = $pregameBuild?->status;
    $pregameActive = in_array($pregameStatus, ['queued', 'running', 'pausing'], true);
    $pregameLocked = $pregameActive || $pregameStatus === 'paused';
    $pregameFailed = $pregameBuild?->status === 'failed';
    $pregameStopped = $pregameStatus === 'stopped';
    $canViewPregame = $canViewPregame ?? ($pregameBuild?->canViewImpacts() ?? false);
    $pregameStages = $pregameBuild?->stageProgress();
    $pregameRetryable = $pregameFailed && in_array(data_get($pregameBuild?->options, 'pipeline_version'), [
        \App\Services\NhlPregameContextOrchestrator::RESULTS_PIPELINE, \App\Services\NhlPregameContextOrchestrator::CHECKPOINT_PIPELINE,
    ], true);
@endphp
<div data-pregame-active="{{ $pregameActive ? '1' : '0' }}" data-pregame-locked="{{ $pregameLocked ? '1' : '0' }}" data-pregame-viewable="{{ $canViewPregame ? '1' : '0' }}" role="status" aria-live="polite">
    @if($pregameBuild)
        <span @class([
            'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset',
            'bg-blue-50 text-blue-700 ring-blue-200' => in_array($pregameStatus, ['queued', 'running', 'pausing'], true),
            'bg-amber-50 text-amber-800 ring-amber-200' => $pregameStatus === 'paused',
            'bg-red-50 text-red-700 ring-red-200' => $pregameFailed,
            'bg-gray-100 text-gray-700 ring-gray-200' => $pregameStopped,
            'bg-emerald-50 text-emerald-700 ring-emerald-200' => ! $pregameActive && ! $pregameFailed && ! $pregameStopped && $pregameStatus !== 'paused',
        ])>{{ match ($pregameStatus) {
            'queued' => 'Building',
            'running' => 'Building',
            'pausing' => 'Pausing',
            'paused' => 'Pregame paused',
            'stopped' => 'Pregame stopped',
            'failed' => 'Pregame failed',
            default => 'Pregame completed',
        } }}</span>
        <div class="mt-1 text-xs text-gray-500">
            Pregame · {{ number_format($pregameBuild->completed_games ?? 0) }} / {{ number_format($pregameBuild->total_games ?? 0) }} games
            @if($pregameBuild->status === 'queued') · Queued @endif
            @if($pregameStatus === 'paused') · Resume when ready @endif
            @if($pregameBuild->blocked_games) · {{ number_format($pregameBuild->blocked_games) }} excluded @endif
            @if($pregameBuild->failed_games) · {{ number_format($pregameBuild->failed_games) }} failed @endif
        </div>
        @if($pregameStages)
            <div class="mt-1 text-xs text-gray-500">
                League averages: {{ $pregameStages['league'] }}/{{ $pregameStages['total'] }} days ·
                Context, rankings &amp; deltas: {{ $pregameStages['context'] }}/{{ $pregameStages['total'] }}
            </div>
        @endif
        @if(isset($pregameStages['timings']))
            <div class="mt-1 text-xs text-gray-500">
                Work time · League {{ number_format($pregameStages['timings']['league'], 0) }}s ·
                Context {{ number_format($pregameStages['timings']['context'], 0) }}s ·
                Comparisons {{ number_format($pregameStages['timings']['comparisons'], 0) }}s
            </div>
        @endif
        @if($pregameBuild->current_game_date)
            <div class="mt-1 text-xs text-gray-500">{{ $pregameBuild->current_game_date->format('Y-m-d') }}</div>
        @endif
        @if($pregameFailed && $pregameBuild->last_error)
            <div class="mt-1 max-w-xs whitespace-normal text-xs text-red-700">{{ $pregameBuild->last_error }}</div>
        @endif
        @if($modelRunId && (in_array($pregameStatus, ['queued', 'running', 'pausing', 'paused'], true) || $pregameRetryable))
            <div class="mt-2 flex flex-wrap gap-2">
                @if(in_array($pregameStatus, ['queued', 'running'], true))
                    <form method="POST" action="{{ route('admin.nhl-sat-models.context.pause', $modelRunId) }}" data-pregame-run-control-form>
                        @csrf
                        <button type="submit" class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:opacity-60">Pause</button>
                    </form>
                @elseif($pregameStatus === 'pausing')
                    <span class="text-xs text-gray-500">Pausing after claimed games</span>
                    <form method="POST" action="{{ route('admin.nhl-sat-models.context.resume', $modelRunId) }}" data-pregame-run-control-form>
                        @csrf
                        <button type="submit" class="rounded border border-blue-200 bg-blue-50 px-2 py-1 text-xs font-medium text-blue-800 transition-colors hover:bg-blue-100 disabled:opacity-60">Resume</button>
                    </form>
                @elseif($pregameStatus === 'paused' || $pregameRetryable)
                    <form method="POST" action="{{ route('admin.nhl-sat-models.context.resume', $modelRunId) }}" data-pregame-run-control-form>
                        @csrf
                        <button type="submit" class="rounded border border-blue-200 bg-blue-50 px-2 py-1 text-xs font-medium text-blue-800 transition-colors hover:bg-blue-100 disabled:opacity-60">{{ $pregameRetryable ? 'Retry unfinished' : 'Resume' }}</button>
                    </form>
                @endif
                @if(! $pregameRetryable)
                    <form method="POST" action="{{ route('admin.nhl-sat-models.context.stop', $modelRunId) }}" data-pregame-run-control-form>
                        @csrf
                        <button type="submit" class="rounded border border-red-200 bg-white px-2 py-1 text-xs font-medium text-red-700 transition-colors hover:bg-red-50 disabled:opacity-60">Stop</button>
                    </form>
                @endif
            </div>
        @endif
    @endif
</div>
