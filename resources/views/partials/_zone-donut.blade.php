@php
    $size = (int)($size ?? 136);
    $sum  = max(1, ($dz + $oz + $nz));
    $oPct = $oz / $sum;
    $nPct = $nz / $sum;
    $dPct = $dz / $sum;

    $oDeg = round($oPct * 360, 1);
    $nDeg = round($nPct * 360, 1);
    $dDeg = max(0, 360 - $oDeg - $nDeg); // remainder

    // Colors: O=orange, N=blue, D=emerald
    $style = "background-image: conic-gradient(#f97316 0 {$oDeg}deg, #3b82f6 {$oDeg}deg " . ($oDeg+$nDeg) . "deg, #10b981 " . ($oDeg+$nDeg) . "deg 360deg); width:{$size}px; height:{$size}px;";
@endphp

<div class="relative rounded-full flex items-center justify-center" style="{{ $style }}">
    <div class="absolute inset-1 bg-white rounded-full shadow-inner"></div>
    <div class="relative z-10 flex flex-col items-center justify-center text-center">
        <div class="text-xs font-medium tracking-tight">ZNS</div>
        <div class="text-[10px] text-gray-500">
            O {{ (int)$oz }} · N {{ (int)$nz }} · D {{ (int)$dz }}
        </div>
    </div>
</div>
