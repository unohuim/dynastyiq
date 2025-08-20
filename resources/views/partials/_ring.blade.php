{{-- resources/views/partials/_ring.blade.php --}}
@php
    $for     = (int)($chip['for'] ?? 0);
    $ag      = (int)($chip['ag']  ?? 0);
    $abbr    = (string)($chip['abbr'] ?? '');
    $split   = (string)($chip['split'] ?? '');
    $isZones = (bool)($chip['isZones'] ?? false);

    $total = max(0, $for + $ag);
    $r = 36; $circ = 2 * M_PI * $r;

    $reversedMetrics = ['PIM','Penalties'];

    $drawPct = 0;
    $arc     = '#e5e7eb';
    $pctText = '';

    if ($total === 0) {
        // Absolute 0/0 → gray
        $drawPct = 0;
        $arc     = '#e5e7eb';
        $pctText = '0%';
    } elseif (in_array($abbr, $reversedMetrics)) {
        // Reversed: look at opponent's share
        $oppShare = $ag / $total;
        $clamped  = round($oppShare * 100);

        if ($oppShare == 1) {
            // Opp took all → full green
            $drawPct = 100;
            $arc     = '#22c55e';
        } elseif ($oppShare == 0) {
            // We took all → full red
            $drawPct = 100;
            $arc     = '#ef4444';
        } else {
            // Partial split → gradient
            $drawPct = $clamped;
            $arc     = $clamped >= 66 ? '#22c55e' : ($clamped >= 40 ? '#fbbf24' : '#ef4444');
        }

        $pctText = $clamped . '% opp';
    } else {
        // Normal metrics
        $share   = $for / $total;
        $clamped = round($share * 100);

        if ($for === 0) {
            $drawPct = 6; // tiny red arc
            $arc     = '#ef4444';
        } else {
            $drawPct = $clamped;
            $arc     = $clamped >= 66 ? '#22c55e' : ($clamped >= 40 ? '#fbbf24' : '#ef4444');
        }

        $pctText = $clamped . '%';
    }

    $oColor = ($isZones && $arc !== '#22c55e') ? $arc : '#059669';
    $offset = $circ - ($circ * ($drawPct / 100));
@endphp

<div class="flex flex-col items-center">
    <div class="relative aspect-square w-32 sm:w-40">
        <svg viewBox="0 0 100 100" class="block w-full h-full">
            <g transform="rotate(-90 50 50)">
                <circle cx="50" cy="50" r="{{ $r }}" fill="none" stroke="#e5e7eb" stroke-width="2.5" />
                @if($drawPct > 0)
                    <circle
                        cx="50" cy="50" r="{{ $r }}" fill="none"
                        stroke="{{ $arc }}" stroke-width="2.5" stroke-linecap="round"
                        stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $offset }}"
                    />
                @endif
            </g>
        </svg>

        <div class="absolute inset-0 grid place-items-center">
            <div class="text-center leading-tight">
                <div class="text-xs text-gray-600 font-semibold">{{ $abbr }}</div>
                <div class="text-xl sm:text-2xl font-semibold tabular-nums text-gray-800">
                    {{ $for }}<span class="text-gray-400">/</span>{{ $ag }}
                </div>
                <div class="text-[10px] text-gray-500 tabular-nums">{{ $pctText }}</div>
                @if($split !== '')
                    <div class="text-[10px] text-gray-400">
                        @if($split === 'O/D')
                            <span style="color: {{ $oColor }}; opacity:.9;">O</span>/<span>D</span>
                        @else
                            {{ $split }}
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
