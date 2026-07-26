{{-- resources/views/leagues/public-roster.blade.php --}}
<x-guest-layout>
    <meta name="robots" content="noindex,nofollow">

    <div class="min-h-screen bg-slate-100">
        <div class="mx-auto flex min-h-screen max-w-7xl flex-col px-4 py-6 sm:px-6 lg:px-8">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shared Roster</div>
                    <h1 class="mt-1 truncate text-2xl font-semibold text-slate-950">{{ $team->name }}</h1>
                </div>
                <div class="flex shrink-0 items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
                    <div class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                        @if (! empty($team->logo_url))
                            <img src="{{ $team->logo_url }}" alt="" class="h-full w-full object-cover">
                        @else
                            {{ collect(explode(' ', $team->name ?? ''))->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') ?: '?' }}
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-slate-900">{{ $league->name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ ucfirst((string) $league->platform) }} roster view</div>
                    </div>
                </div>
            </div>

            <div class="min-h-[calc(100vh-8rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                @include('leagues._panel', [
                    'league' => $league,
                    'teams' => $teams,
                    'drafting' => $drafting,
                    'scoringCategories' => $scoringCategories,
                    'scoringAlignmentCategories' => $scoringAlignmentCategories,
                    'manualScoringMappings' => $manualScoringMappings,
                    'availableStatFields' => $availableStatFields,
                    'scoringMappingOptions' => $scoringMappingOptions,
                    'searchPlayers' => $searchPlayers,
                    'scoringSettingsUpdateUrl' => $scoringSettingsUpdateUrl,
                    'capSettingsUpdateUrl' => $capSettingsUpdateUrl,
                    'capProjectionsUpdateUrl' => $capProjectionsUpdateUrl,
                    'leagueStatsPayloadUrl' => $leagueStatsPayloadUrl,
                    'leagueStatsPerspectives' => $leagueStatsPerspectives,
                    'selectedLeagueStatsPerspective' => $selectedLeagueStatsPerspective,
                    'playersPayloadUrl' => $playersPayloadUrl,
                    'playersFreeAgentsPayloadUrl' => $playersFreeAgentsPayloadUrl,
                    'teamLogoSyncUrl' => $teamLogoSyncUrl,
                    'leagueShape' => $leagueShape,
                    'customCap' => $customCap,
                    'salaryCap' => $salaryCap,
                    'capLimitsBySeason' => $capLimitsBySeason,
                    'capAdjustmentsByTeam' => $capAdjustmentsByTeam,
                    'maxActiveBuyouts' => $maxActiveBuyouts,
                    'maxActiveRetentions' => $maxActiveRetentions,
                    'buyoutExtraPayoutYear' => $buyoutExtraPayoutYear,
                    'retentionExtraPayoutYear' => $retentionExtraPayoutYear,
                    'leagueSettingsSource' => $leagueSettingsSource,
                    'canEditLeagueSettings' => $canEditLeagueSettings,
                    'fantraxContractCodes' => $fantraxContractCodes,
                    'fantraxContractCodeDefinitions' => $fantraxContractCodeDefinitions,
                    'isScoringFullyMapped' => $isScoringFullyMapped,
                    'canShowLeagueStats' => $canShowLeagueStats,
                    'canManageLeague' => $canManageLeague,
                    'publicLockedTeamId' => $publicLockedTeamId,
                    'initialLeagueTab' => $initialLeagueTab,
                ])
            </div>
        </div>
    </div>
</x-guest-layout>
