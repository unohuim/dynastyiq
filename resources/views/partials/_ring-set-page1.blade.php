{{-- resources/views/partials/_ring-set-page1.blade.php (NEW) --}}
<div class="grid grid-cols-3 gap-x-4 -gap-y-8">
    {{-- Top row: Shot Att, Shots, Goals --}}
    @include('partials._ring', ['chip' => ['abbr'=>'Corsi','for'=>$satFor,'ag'=>$satAg], 'displayMode' => $displayMode ?? 'counts'])
    @include('partials._ring', ['chip' => ['abbr'=>'Shots','for'=>$shotsFor,'ag'=>$shotsAg], 'displayMode' => $displayMode ?? 'counts'])
    @include('partials._ring', ['chip' => ['abbr'=>'Goals','for'=>$goalsFor,'ag'=>$goalsAg], 'displayMode' => $displayMode ?? 'counts'])

    {{-- Bottom row: Hits, Blocks, Faceoffs --}}
    @include('partials._ring', ['chip' => ['abbr'=>'Hits','for'=>$hitsF,'ag'=>$hitsA], 'displayMode' => $displayMode ?? 'counts'])
    @include('partials._ring', ['chip' => ['abbr'=>'Blocks','for'=>$blocksF,'ag'=>$blocksA], 'displayMode' => $displayMode ?? 'counts'])
    @include('partials._ring', ['chip' => ['abbr'=>'Faceoffs','for'=>$fow,'ag'=>$fol,'split'=>'W/L'], 'displayMode' => $displayMode ?? 'counts'])
</div>
