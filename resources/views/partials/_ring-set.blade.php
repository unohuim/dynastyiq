{{-- resources/views/partials/_ring.blade.php (update: support reverse scoring e.g., Penalties) --}}
@php
    $for     = (int)($chip['for'] ?? 0);
    $ag      = (int)($chip['ag']  ?? 0);
    $abbr    = (string)($chip['abbr'] ?? '');
    $split   = (string)($chip['split'] ?? '');
    $isZones = (bool)($chip['isZones'] ?? false);
    $reverse = (bool)($chip['reverse'] ?? false); // NEW

    $total   = max(0, $for + $ag);
    $share   = $total > 0
        ? ($reverse ? ($ag / $total) : ($for / $total))   // NEW: invert when requested
        : 0.0;

    $r        = 36;
    $circ     = 2 * M_PI * $r;
    $clamped  = max(0, min(100, $share * 100));
    $offset   = $circ - ($circ * ($clamped / 100));

    $arc = $clamped >= 66 ? '#22c55e' : ($clamped >= 40 ? '#fbbf24' : '#ef4444');
    $oColor = ($isZones && $arc !== '#22c55e') ? $arc : '#059669';
@endphp

<div class="flex flex-col items-center">
    <div class="relative aspect-square w-32 sm:w-40">
        <svg viewBox="0 0 100 100" class="block w-full h-full">
            <g transform="rotate(-90 50 50)">
                <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="2.5" />
                <circle
                    cx="50" cy="50" r="{{ $r }}" fill="none"
                    stroke="{{ $arc }}" stroke-width="2.5" stroke-linecap="round"
                    stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $offset }}"
                />
            </g>
        </svg>

        <div class="absolute inset-0 grid place-items-center">
            <div class="text-center leading-tight">
                <div class="text-[10px] text-gray-500 font-medium">{{ $abbr }}</div>
                <div class="text-2xl sm:text-3xl font-bold tabular-nums text-gray-900">
                    {{ $for }}<span class="text-gray-400">/</span>{{ $ag }}
                </div>
                <div class="text-[10px] text-gray-500 tabular-nums">{{ round($clamped) }}%</div>
                @if($split !== '')
                    <div class="text-[10px] text-gray-400">
                        @if($split === 'O/D')
                            <span style="color: {{ $oColor }}; opacity:.85;">O</span>/<span>D</span>
                        @else
                            {{ $split }}
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>