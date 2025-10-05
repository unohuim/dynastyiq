{{-- resources/views/leagues.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $leagues */
    /** @var \App\Models\PlatformLeague|null $activeLeague */
    $active = $activeLeague ?? $leagues->first();
@endphp

<x-leagues-hub-layout
    :leagues="$leagues"
    :active-league-id="$active?->id"
    :initial-league="['slug' => (string) ($active?->id ?? ''), 'name' => (string) ($active?->name ?? '')]"
>
    @include('leagues._panel', ['league' => $active])
</x-leagues-hub-layout>
