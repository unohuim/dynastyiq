{{-- resources/views/partials/_triangle-zones.blade.php --}}
@php
    // Inputs: $oz, $dz, $nz
    $box = 180;

    $sum = (int)($dz + $oz + $nz);
    if ($sum <= 0) {
        $d = $o = $n = 1/3;
    } else {
        $d = $dz / $sum; $o = $oz / $sum; $n = $nz / $sum;
    }

    // Keep marker safely inside
    $eps = 0.05;
    $d = (1 - 3*$eps) * $d + $eps;
    $o = (1 - 3*$eps) * $o + $eps;
    $n = (1 - 3*$eps) * $n + $eps;

    // Geometry
    $pad = 18;
    $w = $box; $h = $box;

    $A = [$w/2, $pad];         // N (top)
    $B = [$w-$pad, $h-$pad];   // O (bottom-right)
    $C = [$pad, $h-$pad];      // D (bottom-left)

    // Barycentric -> inside triangle
    $x = $d*$C[0] + $o*$B[0] + $n*$A[0];
    $y = $d*$C[1] + $o*$B[1] + $n*$A[1];

    // Percent coords for HTML overlays
    $pct = function ($p) use ($box) { return ($p / $box) * 100; };
    $Ax = $pct($A[0]); $Ay = $pct($A[1]);
    $Bx = $pct($B[0]); $By = $pct($B[1]);
    $Cx = $pct($C[0]); $Cy = $pct($C[1]);
    $Px = $pct($x);   $Py = $pct($y);
@endphp

<div class="flex justify-center">
    {{-- 20% bigger than prior --}}
    <div class="relative w-[212px] sm:w-[269px] aspect-square">
        <svg viewBox="0 0 {{ $box }} {{ $box }}" class="w-full h-full">
            <defs>
                <linearGradient id="triFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#ffffff"/>
                    <stop offset="1" stop-color="#f8fafc"/>
                </linearGradient>
            </defs>

            <polygon
                points="{{ $A[0] }},{{ $A[1] }} {{ $B[0] }},{{ $B[1] }} {{ $C[0] }},{{ $C[1] }}"
                fill="url(#triFill)" stroke="#e5e7eb" stroke-width="2" />

            <line x1="{{ $A[0] }}" y1="{{ $A[1] }}" x2="{{ ($B[0]+$C[0])/2 }}" y2="{{ $B[1] }}" stroke="#edf2f7" stroke-width="1"/>
            <line x1="{{ ($A[0]+$C[0])/2 }}" y1="{{ ($A[1]+$C[1])/2 }}" x2="{{ $B[0] }}" y2="{{ $B[1] }}" stroke="#edf2f7" stroke-width="1"/>
            <line x1="{{ ($A[0]+$B[0])/2 }}" y1="{{ ($A[1]+$B[1])/2 }}" x2="{{ $C[0] }}" y2="{{ $C[1] }}" stroke="#edf2f7" stroke-width="1"/>

            <circle cx="{{ $x }}" cy="{{ $y }}" r="6" fill="#6366f1" stroke="#ffffff" stroke-width="2"/>
        </svg>

        {{-- Corner badges: slightly larger & stronger --}}
        <div class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full bg-gray-700 text-gray-100 text-[12px] font-semibold px-3 py-1.5 shadow-sm"
             style="left: {{ $Ax }}%; top: {{ $Ay }}%;">N</div>
        <div class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full bg-gray-700 text-gray-100 text-[12px] font-semibold px-3 py-1.5 shadow-sm"
             style="left: {{ $Cx }}%; top: {{ $Cy }}%;">D</div>
        <div class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full bg-gray-700 text-gray-100 text-[12px] font-semibold px-3 py-1.5 shadow-sm"
             style="left: {{ $Bx }}%; top: {{ $By }}%;">O</div>

        <div class="pointer-events-none absolute -translate-x-1/2 -translate-y-1/2"
             style="left: {{ $Px }}%; top: {{ $Py }}%;">
            <span class="block h-3.5 w-3.5 rounded-full bg-indigo-500 ring-2 ring-white"></span>
        </div>
    </div>
</div>

<div class="mt-3 text-center text-[11px] sm:text-xs font-semibold uppercase tracking-wide text-gray-700">Zone Starts</div>
