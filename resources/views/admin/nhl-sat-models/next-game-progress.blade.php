@if($evaluation)
    @php
        $work = $evaluation->inputs['_work'] ?? [];
        $stage = $work['stage'] ?? ($evaluation->inputs ? 'evaluate' : 'initialize');
        $stageLabels = ['initialize' => 'Checking source data', 'baselines' => 'Freezing player baselines', 'games' => 'Loading game list', 'evaluate' => 'Evaluating games'];
        $stalled = $evaluation->status !== 'failed' && $evaluation->canResume();
    @endphp
    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" aria-label="Evaluation progress"
        data-progress-status="{{ $evaluation->status }}"
        data-progress-revision="{{ $evaluation->completed_games }}:{{ $evaluation->excluded_games }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">Evaluation #{{ $evaluation->id }} · {{ $evaluation->season_id }}</h2>
            <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-medium" role="status">{{ $stalled ? 'Stalled' : ucfirst($evaluation->status) }}</span>
        </div>
        <p class="mt-2 text-sm font-medium">{{ $stageLabels[$stage] ?? $stage }}</p>
        @if($stage === 'baselines')
            <p class="mt-2 text-sm text-gray-600">{{ $work['baseline_players_done'] ?? 0 }} / {{ $work['baseline_players_total'] ?? 0 }} player baselines saved</p>
            <progress class="mt-3 h-2 w-full" value="{{ $work['baseline_players_done'] ?? 0 }}" max="{{ max(1, $work['baseline_players_total'] ?? 0) }}" aria-label="Player baselines saved"></progress>
        @elseif($stage === 'games')
            <p class="mt-2 text-sm text-gray-600">{{ $work['games_loaded'] ?? 0 }} games loaded · 100 per checkpoint</p>
        @else
            <p class="mt-2 text-sm text-gray-600">{{ $evaluation->completed_games }} evaluated · {{ $evaluation->excluded_games }} excluded · {{ $evaluation->total_games }} total games</p>
            <progress class="mt-3 h-2 w-full" value="{{ $evaluation->completed_games + $evaluation->excluded_games }}" max="{{ max(1, $evaluation->total_games) }}" aria-label="Processed games"></progress>
        @endif
        @if(isset($work['current_game']))<p class="mt-2 text-sm text-gray-600">Game {{ $work['current_game'] }} · {{ $work['players_done'] ?? 0 }} skaters saved</p>@endif
        <p class="mt-3 text-xs text-gray-600">Last activity: {{ $evaluation->updated_at?->diffForHumans() }} · {{ $work['checkpoints_completed'] ?? 0 }} checkpoints saved. One player per job; no season-wide job burst.</p>
        @if($stalled)<p class="mt-3 text-sm text-amber-800">No recent worker activity. Resume continues from the last saved checkpoint.</p>@endif
        @if($evaluation->last_error)<p class="mt-3 text-sm text-red-700">{{ $evaluation->last_error }}</p>@endif
        @if($evaluation->canResume())
            <form method="POST" action="{{ route('admin.nhl-sat-models.next-game.resume', $evaluation) }}" data-evaluation-action class="mt-3">
                @csrf
                <button class="min-h-10 rounded-md border border-gray-300 px-4 text-sm font-semibold transition-colors hover:bg-gray-50">Resume remaining work</button>
            </form>
        @endif
        <p class="mt-3 text-xs text-gray-600">Baseline frozen: {{ $evaluation->inputs['baseline_frozen_at'] ?? 'Pending' }}. Progress updates automatically; Apply filters refreshes result tables.</p>
    </section>
@endif
