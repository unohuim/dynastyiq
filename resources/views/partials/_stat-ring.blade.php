@php
    // Props (plain include)
    $value     = (int)($value     ?? 0);
    $other     = (int)($other     ?? 0);
    $label     = (string)($label  ?? '');
    $size      = (int)($size      ?? 48);      // px diameter
    $thickness = (int)($thickness ?? 8);       // px ring thickness
    // Accept Tailwind color name or hex; fallback to provided value
    $colorName = (string)($color ?? 'emerald');
    $map = [
        'emerald' => '#10B981', 'rose' => '#F43F5E', 'sky' => '#0EA5E9',
        'amber' => '#F59E0B',   'slate'=> '#64748B', 'gray' => '#6B7280',
    ];
    $hex = $map[$colorName] ?? $colorName;

    $total = max(1, $value + $other);
    $pct   = round($value / $total * 100);
    $deg   = $pct * 3.6;
@endphp

<div class="relative rounded-full"
     style="width:{{ $size }}px; height:{{ $size }}px; --deg:{{ $deg }}deg; --c:{{ $hex }};">
  <div class="absolute inset-0 rounded-full
              before:content-[''] before:absolute before:inset-0 before:rounded-full
              before:bg-[conic-gradient(var(--c)_var(--deg),theme(colors.gray.200)_0)]
              before:shadow-[inset_0_0_0_1px_rgba(0,0,0,.05)]"
       style="mask:radial-gradient(farthest-side,transparent calc(100% - {{ $thickness }}px),#000 0);
              -webkit-mask:radial-gradient(farthest-side,transparent calc(100% - {{ $thickness }}px),#000 0);">
  </div>
  <div class="absolute inset-0 grid place-items-center text-center leading-tight">
      @if($label !== '')
          <div class="text-[10px] font-semibold tracking-wide opacity-70">{{ $label }}</div>
      @endif
      <div class="text-sm font-bold tabular-nums">{{ $value }}</div>
      <div class="text-[10px] opacity-70 tabular-nums">{{ $pct }}%</div>
  </div>
</div>
