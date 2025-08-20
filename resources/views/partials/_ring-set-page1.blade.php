{{-- resources/views/partials/_ring-set-page1.blade.php (NEW) --}}
<div class="grid grid-cols-3 gap-4">
    {{-- Top row: Shot Att, Shots, Goals --}}
    @include('partials._ring', ['chip' => ['abbr'=>'Shot Att.','for'=>$satFor,'ag'=>$satAg]])
    @include('partials._ring', ['chip' => ['abbr'=>'Shots','for'=>$shotsFor,'ag'=>$shotsAg]])
    @include('partials._ring', ['chip' => ['abbr'=>'Goals','for'=>$goalsFor,'ag'=>$goalsAg]])

    {{-- Bottom row: Hits, Blocks, Faceoffs --}}
    @include('partials._ring', ['chip' => ['abbr'=>'Hits','for'=>$hitsF,'ag'=>$hitsA]])
    @include('partials._ring', ['chip' => ['abbr'=>'Blocks','for'=>$blocksF,'ag'=>$blocksA]])
    @include('partials._ring', ['chip' => ['abbr'=>'Faceoffs','for'=>$fow,'ag'=>$fol,'split'=>'W/L']])
</div>