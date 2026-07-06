{{-- resources/views/leagues.blade.php --}}
@php
    /** @var \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection $leagues */
    /** @var \App\Models\PlatformLeague|null $activeLeague */
    $active = $activeLeague ?? $leagues->first();
@endphp

<x-leagues-hub-layout
    :leagues="$leagues"
    :league-options="$leagueOptions ?? $leagues"
    :active-league-id="$active?->id"
    :initial-league="['slug' => (string) ($active?->id ?? ''), 'name' => (string) ($active?->name ?? '')]"
>
    @include('leagues._panel', [
        'league' => $active,
        'teams' => $teams ?? [],
        'drafting' => $drafting ?? [],
        'scoringCategories' => $scoringCategories ?? [],
        'scoringAlignmentCategories' => $scoringAlignmentCategories ?? [],
        'manualScoringMappings' => $manualScoringMappings ?? [],
        'availableStatFields' => $availableStatFields ?? [],
        'searchPlayers' => $searchPlayers ?? [],
        'scoringSettingsUpdateUrl' => $scoringSettingsUpdateUrl ?? '',
        'leagueStatsPayloadUrl' => $leagueStatsPayloadUrl ?? '',
        'playersPayloadUrl' => $playersPayloadUrl ?? '',
        'isScoringFullyMapped' => $isScoringFullyMapped ?? false,
        'canShowLeagueStats' => $canShowLeagueStats ?? false,
        'canManageLeague' => $canManageLeague ?? false,
    ])
</x-leagues-hub-layout>
