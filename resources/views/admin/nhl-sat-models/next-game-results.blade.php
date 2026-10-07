@php
    $percent = fn ($value) => $value === null ? '—' : number_format((float) $value, 1).'%';
@endphp
<div class="space-y-5" data-evaluation-active="{{ $evaluation && in_array($evaluation->status, \App\Models\NhlNextGameEvaluation::ACTIVE_STATUSES, true) ? '1' : '0' }}">
    @if(!$evaluation)
        <p class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600">Start an evaluation to compare the baseline with recent-game estimates.</p>
    @else
        <div data-evaluation-progress>@include('admin.nhl-sat-models.next-game-progress')</div>
        <aside class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
            <p>EV, PP and PK use strength-specific training history as the baseline; stored static bucket projections are all-strength only. Recent windows use prior appearances, including last season at the start of this season.</p>
            <p class="mt-2">This measures bucket rates for skaters who played. Actual ice time is used only to score errors—not as a prediction input. The 50/50 blends are experiments, not validated formulas.</p>
        </aside>
        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="p-5"><h2 class="font-semibold">Method comparison · {{ strtoupper($strength) }}</h2><p class="mt-1 text-xs text-gray-600">Lowest error first. Error = sum of absolute bucket/game errors ÷ actual attempts. Player thresholds use that calculation per player. Zero-attempt players have no percentage, but their errors count toward total error. {{ $missing }} appearances lack a baseline and are excluded from every method.</p></div>
            <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-y bg-gray-50 text-xs text-gray-600"><tr><th class="p-3">Method</th><th class="p-3">Total error %</th><th class="p-3">Players &lt;10%</th><th class="p-3">Players &lt;20%</th><th class="p-3">Measured players</th><th class="p-3">Fallback appearances</th></tr></thead><tbody class="divide-y">
                @forelse($summary as $row)<tr><td class="p-3 font-medium">{{ $methods[$row->method] }}</td><td class="p-3">{{ $percent($row->error_pct) }}</td><td class="p-3">{{ $percent($row->under_10) }}</td><td class="p-3">{{ $percent($row->under_20) }}</td><td class="p-3">{{ $row->measured_players }}</td><td class="p-3">{{ $row->fallbacks }} / {{ $row->appearances }}</td></tr>@empty<tr><td colspan="6" class="p-5 text-gray-500">No scored results yet.</td></tr>@endforelse
            </tbody></table></div>
        </section>
        @if($players && $players->isNotEmpty())
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"><h2 class="p-5 font-semibold">Players · {{ $methods[$method] }} · lowest error first</h2><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-y bg-gray-50"><tr><th class="p-3">Player</th><th class="p-3">Appearances</th><th class="p-3">Actual SAT</th><th class="p-3">Error %</th></tr></thead><tbody class="divide-y">@foreach($players as $player)<tr><td class="p-3">{{ $player->player_name }}</td><td class="p-3">{{ $player->games }}</td><td class="p-3">{{ $player->actual_sat }}</td><td class="p-3">{{ $percent($player->error_pct) }}</td></tr>@endforeach</tbody></table></div><div class="p-4" data-evaluation-links>{{ $players->links() }}</div></section>
        @endif
        @if($games->isNotEmpty())
            <section class="rounded-lg border border-gray-200 bg-white p-5"><h2 class="font-semibold">Inspect individual buckets · latest 30 matching appearances</h2><div class="mt-3 flex flex-wrap gap-2" data-evaluation-links>@foreach($games as $game)<a class="rounded border px-2 py-1 text-xs text-blue-700 underline" href="{{ request()->fullUrlWithQuery(['evaluation' => $evaluation->id, 'result' => $game->id]) }}">{{ $game->player_name }} · {{ $game->nhl_game_id }}</a>@endforeach</div>
                @if($detail)
                    @php
                        $bucketData = json_decode($detail->buckets, true);
                        $rates = $bucketData['rates'][$method] ?? [];
                        $actual = $bucketData['actual'] ?? [];
                        $keys = array_unique([...array_keys($rates), ...array_keys($actual)]);
                        sort($keys);
                    @endphp
                    <h3 class="mt-5 font-semibold">{{ $detail->player_name }} · {{ $detail->nhl_game_id }} · {{ strtoupper($strength) }}</h3><p class="mt-1 text-xs text-gray-600">{{ $detail->toi_seconds }} seconds played · {{ $detail->prior_games }} prior appearances · baseline source: {{ str_replace('_', ' ', $detail->baseline_source) }}</p>
                    <div class="mt-3 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead><tr><th class="p-2">Bucket</th><th class="p-2">Predicted SAT/60</th><th class="p-2">Actual SAT/60</th><th class="p-2">Actual SAT</th></tr></thead><tbody class="divide-y">@foreach($keys as $key)<tr><td class="p-2">{{ $key }}</td><td class="p-2">{{ isset($bucketData['rates'][$method]) ? number_format($rates[$key] ?? 0, 3) : '—' }}</td><td class="p-2">{{ number_format(($actual[$key] ?? 0) * 3600 / $detail->toi_seconds, 3) }}</td><td class="p-2">{{ $actual[$key] ?? 0 }}</td></tr>@endforeach</tbody></table></div>
                @endif
            </section>
        @endif
        @if($excluded->isNotEmpty())<section class="rounded-lg border border-amber-200 bg-amber-50 p-5"><h2 class="font-semibold">Excluded games</h2><ul class="mt-2 list-inside list-disc text-sm">@foreach($excluded as $reason)<li>{{ $reason->games }} games: {{ $reason->error }}</li>@endforeach</ul></section>@endif
    @endif
</div>
