{{-- resources/views/partials/_ring-set-page3.blade.php (FULL REPLACE) --}}
<div class="grid grid-cols-2 gap-4 justify-items-center">
    @include('partials._ring', [
        'chip' => [
            'abbr'    => 'PIM',
            'for'     => $pimF,
            'ag'      => $pimA,
            'reverse' => true,
            // ensure a visible red sliver when >0 but %≈0
            'minSweepDeg' => 10,
        ],
        'displayMode' => $displayMode ?? 'counts',
    ])
    @include('partials._ring', [
        'chip' => [
            'abbr'    => 'Penalties',
            'for'     => $pensF,
            'ag'      => $pensA,
            'reverse' => true,
            'minSweepDeg' => 10,
        ],
        'displayMode' => $displayMode ?? 'counts',
    ])
</div>
