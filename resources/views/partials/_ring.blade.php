{{-- resources/views/partials/_ring.blade.php --}}
@php
    // Inputs
    $for   = (int)($chip['for'] ?? 0);
    $ag    = (int)($chip['ag']  ?? 0);
    $abbr  = (string)($chip['abbr'] ?? '');
    $split = (string)($chip['split'] ?? '');
    $pct   = ($for + $ag) > 0 ? ($for / ($for + $ag)) * 100.0 : 0.0;

    // Keep the same box; increase only the radius by 20%
    $BOX        = 168;         // container stays the same size
    $BASE_R     = 74;          // previous radius baseline
    $RADIUS     = (int) floor(min($BASE_R * 1.20, ($BOX / 2) - 6)); // +20%, safe margin
    $STROKE     = 4;           // thin stroke (unchanged)
    $CIRC       = 2 * M_PI * $RADIUS;
    $DASH       = $CIRC * (max(0, min(100, $pct)) / 100);

    // Slight lift to tighten vertical alignment
    $liftMain   = 16;
    $liftSmall  = 2;

    // Colors: green good, amber average, red bad
    $arc = $pct >= 66 ? '#22c55e' : ($pct >= 40 ? '#fbbf24' : '#ef4444');
@endphp

<div class="relative" style="width:{{ $BOX }}px;height:{{ $BOX }}px">
    <svg class="scale-[1.28]" viewBox="0 0 {{ $BOX }} {{ $BOX }}" class="block">
        <g transform="translate({{ $BOX/2 }}, {{ $BOX/2 }}) rotate(-90)">
            <circle r="{{ $RADIUS }}" cx="0" cy="0" fill="none"
                    stroke="#e5e7eb" stroke-width="{{ $STROKE }}" />
            <circle r="{{ $RADIUS }}" cx="0" cy="0" fill="none"
                    stroke="{{ $arc }}" stroke-width="{{ $STROKE }}"
                    stroke-linecap="round"
                    stroke-dasharray="{{ $DASH }},{{ $CIRC }}" />
        </g>
    </svg>

    <div class="absolute inset-0 flex flex-col items-center justify-center text-center"
         style="transform: translateY(-{{ $liftMain }}px);">
        <div class="text-[10px] leading-3 text-gray-500 font-medium mb-1">{{ $abbr }}</div>

        <div class="text-2xl font-bold tabular-nums text-gray-900 -mt-0.5">
            {{ $for }}<span class="text-gray-400">/</span>{{ $ag }}
        </div>

        <div class="text-[10px] text-gray-500 mt-0.5 tabular-nums"
             style="transform: translateY(-{{ $liftSmall }}px);">
            {{ round($pct) }}%
        </div>

        @if($split !== '')
            <div class="text-[10px] text-gray-400 -mt-0.5">
                @if($split === 'O/D')
                    <span class="text-emerald-600/80">O</span>/<span>D</span>
                @else
                    {{ $split }}
                @endif
            </div>
        @endif
    </div>
</div>
