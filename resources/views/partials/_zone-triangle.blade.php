@php
    $box = (int)($box ?? 136);
    $sum = max(1, ($dz + $oz + $nz));
    $d = $dz / $sum; $o = $oz / $sum; $n = $nz / $sum;

    // pad 8px; scale to box
    $pad = 8;
    $w = $box; $h = $box;
    $A = [$w/2, $pad];         // N top
    $B = [$w-$pad, $h-$pad];   // O bottom-right
    $C = [$pad, $h-$pad];      // D bottom-left

    $x = $d*$C[0] + $o*$B[0] + $n*$A[0];
    $y = $d*$C[1] + $o*$B[1] + $n*$A[1];
@endphp

<svg viewBox="0 0 {{ $box }} {{ $box }}" class="w-full h-full">
    <polygon points="{{ $A[0] }},{{ $A[1] }} {{ $B[0] }},{{ $B[1] }} {{ $C[0] }},{{ $C[1] }}" class="fill-white stroke-gray-300" stroke-width="2"/>
    <line x1="{{ $A[0] }}" y1="{{ $A[1] }}" x2="{{ ($B[0]+$C[0])/2 }}" y2="{{ $B[1] }}" class="stroke-gray-200" stroke-width="1"/>
    <line x1="{{ $C[0] + ($B[0]-$C[0])/3 }}" y1="{{ $C[1]-($C[1]-$A[1])/3 }}" x2="{{ $C[0] + 2*($B[0]-$C[0])/3 }}" y2="{{ $C[1]-2*($C[1]-$A[1])/3 }}" class="stroke-gray-200" stroke-width="1"/>
    <circle cx="{{ $x }}" cy="{{ $y }}" r="4.5" class="fill-indigo-500 stroke-white" stroke-width="1.5"/>
    <text x="{{ $C[0] }}" y="{{ $C[1] + 10 }}" class="fill-gray-500 text-[9px]">D</text>
    <text x="{{ $B[0] }}" y="{{ $B[1] + 10 }}" class="fill-gray-500 text-[9px]" text-anchor="end">O</text>
    <text x="{{ $A[0] }}" y="{{ $A[1] - 4 }}" class="fill-gray-500 text-[9px]" text-anchor="middle">N</text>
</svg>
