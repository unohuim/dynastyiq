@php
    $playerImports = collect($imports)->where('group', 'player')->values();
    $platformImports = collect($imports)->where('group', 'platform')->values();
@endphp

<div class="py-6">
    <div
        class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
        x-data="adminHub({
            imports: @js($imports),
            users: @js($users ?? []),
            hasPlayers: {{ $hasPlayers ? 'true' : 'false' }},
            hasFantrax: {{ $hasFantraxPlayers ? 'true' : 'false' }},
            triageUrl: @js(route('admin.player-triage', ['admin_panel' => 1, 'fragment' => 1])),
            validationsUrl: @js(route('admin.nhl-validations.index', ['admin_panel' => 1])),
            gameImportStatusUrl: @js(route('admin.nhl-game-imports.status')),
            gameImportSourceGapsUrl: @js(route('admin.nhl-game-imports.source-gaps')),
            gameImportGameRerunUrl: @js(url('/admin/nhl-game-imports/games')),
            gameImportDiscoverUrl: @js(route('admin.nhl-game-imports.discover')),
            gameImportProcessUrl: @js(route('admin.nhl-game-imports.process')),
            gameImportSeasonSyncUrl: @js(route('admin.nhl-game-imports.season-sync')),
        })"
        x-init="init()"
        x-cloak
    >
        <div class="border-b border-gray-200">
            <div class="flex flex-wrap items-center gap-6">
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('imports')"
                    :class="activeTab === 'imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Player Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('game-imports')"
                    :class="activeTab === 'game-imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Game Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('platform-imports')"
                    :class="activeTab === 'platform-imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Platform Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('users')"
                    :class="activeTab === 'users' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Users
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('validations')"
                    :class="activeTab === 'validations' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Game Validations
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    @click="setTab('triage')"
                    :class="activeTab === 'triage' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Triage
                </button>
            </div>
        </div>

        <div class="py-4">
            <div x-show="activeTab === 'imports'" x-cloak>
                <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
                    @foreach($playerImports as $import)
                        <div class="px-4 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900">{{ $import['label'] }}</div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        Last run:
                                        <span x-text="formatLastRun('{{ $import['key'] }}')"></span>
                                    </div>
                                </div>
                                <x-primary-button
                                    type="button"
                                    x-on:click="startImport('{{ $import['key'] }}')"
                                    x-bind:disabled="streams['{{ $import['key'] }}']?.running === true"
                                >
                                    Run Now
                                </x-primary-button>
                            </div>

                            <div
                                class="mt-4 space-y-2"
                                x-show="shouldShowImportProgress('{{ $import['key'] }}')"
                                x-cloak
                            >
                                <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                    <span x-text="importProgressText('{{ $import['key'] }}')"></span>
                                    <span x-text="`${importProgressPercentage('{{ $import['key'] }}')}%`"></span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        class="h-full rounded-full bg-indigo-600 transition-all duration-300"
                                        x-bind:style="`width: ${importProgressPercentage('{{ $import['key'] }}')}%`"
                                    ></div>
                                </div>
                                <div class="text-xs text-gray-500" x-text="importProgressDetailText('{{ $import['key'] }}')"></div>
                            </div>

                            <div class="mt-4 space-y-2">
                                <button
                                    type="button"
                                    class="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
                                    x-on:click="toggleStream('{{ $import['key'] }}')"
                                >
                                    <span x-text="streams['{{ $import['key'] }}']?.open ? 'Hide Output' : 'Show Output'"></span>
                                </button>
                                <div
                                    class="h-40 overflow-y-auto bg-gray-950 p-3 font-mono text-xs text-green-200"
                                    x-show="streams['{{ $import['key'] }}']?.open"
                                >
                                    <template x-if="(streams['{{ $import['key'] }}']?.messages?.length ?? 0) === 0">
                                        <div class="text-gray-400">Awaiting output...</div>
                                    </template>
                                    <template x-for="(entry, idx) in streams['{{ $import['key'] }}']?.messages" :key="idx">
                                        <div class="whitespace-pre-wrap" x-text="entry.message"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div x-show="activeTab === 'platform-imports'" x-cloak>
                <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
                    @foreach($platformImports as $import)
                        <div class="px-4 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900">{{ $import['label'] }}</div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        Last run:
                                        <span x-text="formatLastRun('{{ $import['key'] }}')"></span>
                                    </div>
                                </div>
                                <x-primary-button
                                    type="button"
                                    x-on:click="startImport('{{ $import['key'] }}')"
                                    x-bind:disabled="streams['{{ $import['key'] }}']?.running === true"
                                >
                                    Run Now
                                </x-primary-button>
                            </div>

                            <div
                                class="mt-4 space-y-2"
                                x-show="shouldShowImportProgress('{{ $import['key'] }}')"
                                x-cloak
                            >
                                <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                    <span x-text="importProgressText('{{ $import['key'] }}')"></span>
                                    <span x-text="`${importProgressPercentage('{{ $import['key'] }}')}%`"></span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        class="h-full rounded-full bg-indigo-600 transition-all duration-300"
                                        x-bind:style="`width: ${importProgressPercentage('{{ $import['key'] }}')}%`"
                                    ></div>
                                </div>
                                <div class="text-xs text-gray-500" x-text="importProgressDetailText('{{ $import['key'] }}')"></div>
                            </div>

                            <div class="mt-4 space-y-2">
                                <button
                                    type="button"
                                    class="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
                                    x-on:click="toggleStream('{{ $import['key'] }}')"
                                >
                                    <span x-text="streams['{{ $import['key'] }}']?.open ? 'Hide Output' : 'Show Output'"></span>
                                </button>
                                <div
                                    class="h-40 overflow-y-auto bg-gray-950 p-3 font-mono text-xs text-green-200"
                                    x-show="streams['{{ $import['key'] }}']?.open"
                                >
                                    <template x-if="(streams['{{ $import['key'] }}']?.messages?.length ?? 0) === 0">
                                        <div class="text-gray-400">Awaiting output...</div>
                                    </template>
                                    <template x-for="(entry, idx) in streams['{{ $import['key'] }}']?.messages" :key="idx">
                                        <div class="whitespace-pre-wrap" x-text="entry.message"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div x-show="activeTab === 'users'" x-cloak>
                <div class="border-y border-gray-200 bg-white">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Users</h3>
                            <p class="mt-0.5 text-xs text-gray-500">DynastyIQ accounts with local session presence.</p>
                        </div>
                        <div class="text-xs text-gray-500">
                            <span x-text="formatNumber(users.length)"></span>
                            <span x-text="users.length === 1 ? 'user' : 'users'"></span>
                        </div>
                    </div>

                    <div class="divide-y divide-gray-200">
                        <template x-for="user in users" :key="user.id">
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_10rem_9rem] sm:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <template x-if="user.avatar_url">
                                        <img
                                            :src="user.avatar_url"
                                            alt=""
                                            class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-gray-200"
                                            loading="lazy"
                                        >
                                    </template>
                                    <template x-if="!user.avatar_url">
                                        <span
                                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 ring-1 ring-gray-200"
                                            x-text="userInitials(user)"
                                        ></span>
                                    </template>

                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="truncate text-sm font-semibold text-gray-900" x-text="user.name"></span>
                                            <span
                                                x-show="user.email_verified"
                                                class="inline-flex shrink-0 items-center rounded-md bg-green-50 px-1.5 py-0.5 text-[10px] font-semibold text-green-700 ring-1 ring-green-600/20"
                                            >
                                                Verified
                                            </span>
                                        </div>
                                        <div class="truncate text-xs text-gray-500" x-text="user.email"></div>
                                        <div x-show="user.discord_name" class="truncate text-xs text-gray-500">
                                            Discord:
                                            <span x-text="user.discord_name"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-1">
                                    <template x-for="role in user.roles" :key="`${user.id}-${role}`">
                                        <span
                                            class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600"
                                            x-text="role"
                                        ></span>
                                    </template>
                                    <span
                                        x-show="!user.roles || user.roles.length === 0"
                                        class="text-xs text-gray-400"
                                    >
                                        No roles
                                    </span>
                                </div>

                                <div class="text-left sm:text-right">
                                    <div class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold" :class="presenceClass(user)">
                                        <span class="h-2 w-2 rounded-full" :class="presenceDotClass(user)"></span>
                                        <span x-text="user.presence?.label ?? 'Offline'"></span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500" x-text="formatUserLastSeen(user)"></div>
                                </div>
                            </div>
                        </template>

                        <div x-show="users.length === 0" class="px-4 py-8 text-sm text-gray-500">
                            No users found.
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="activeTab === 'game-imports'" x-cloak>
                <div class="border-y border-gray-200 bg-gray-50">
                    <div class="flex flex-col gap-4 px-4 py-5 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">NHL Game Import Pipeline</h3>
                            <p class="mt-1 max-w-3xl text-sm text-gray-600">
                                Discover games by date selection, then process scheduled pipeline stages through queued orchestration jobs.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <div
                                class="relative inline-flex rounded-md shadow-sm"
                                @click.outside="closeGameImportSeasonDropdown()"
                            >
                                <button
                                    type="button"
                                    class="relative inline-flex items-center justify-center rounded-l-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-10 disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="submitGameImportSeasonSync()"
                                    :disabled="gameImports.syncingSeason || !gameImports.selectedSeason"
                                >
                                    <span x-text="gameImports.syncingSeason ? 'Queuing...' : gameImportSeasonSyncButtonText()"></span>
                                </button>
                                <div class="relative -ml-px block">
                                    <button
                                        type="button"
                                        class="relative inline-flex min-h-[38px] items-center justify-center rounded-r-md bg-white px-2.5 py-2 text-sm text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-10"
                                        aria-haspopup="menu"
                                        :aria-expanded="gameImports.seasonDropdownOpen ? 'true' : 'false'"
                                        @click="toggleGameImportSeasonDropdown()"
                                    >
                                        <span class="sr-only">Open season options</span>
                                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="h-5 w-5">
                                            <path fill-rule="evenodd" clip-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" />
                                        </svg>
                                    </button>
                                    <div
                                        x-show="gameImports.seasonDropdownOpen"
                                        x-cloak
                                        class="absolute right-0 z-20 mt-2 w-56 origin-top-right rounded-md bg-white p-0 shadow-lg outline outline-1 outline-black/5 transition duration-200 ease-out motion-reduce:transition-none"
                                        role="menu"
                                    >
                                        <div class="py-1">
                                            <template x-if="gameImportSeasonOptions().length === 0">
                                                <div class="px-4 py-2 text-sm text-gray-500">No imported seasons</div>
                                            </template>
                                            <template x-for="season in gameImportSeasonOptions()" :key="season.season">
                                                <button
                                                    type="button"
                                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:bg-gray-100 focus:text-gray-900 focus:outline-none"
                                                    role="menuitem"
                                                    @click="selectGameImportSeason(season)"
                                                    x-text="season.label"
                                                ></button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="openGameImportDrawer()"
                            >
                                Discovery
                            </button>
                        </div>
                    </div>

                    <div
                        x-show="gameImports.error"
                        x-cloak
                        class="border-t border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                        x-text="gameImports.error"
                    ></div>

                    <div
                        x-show="shouldShowGameImportSeasonSync()"
                        x-cloak
                        class="border-t border-gray-200 bg-white px-4 py-3"
                    >
                        <template x-if="gameImportLatestSeasonSyncRun()">
                            <div>
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-xs font-semibold uppercase text-gray-500">Season Syncing</h4>
                                        <p class="mt-0.5 text-xs text-gray-600">
                                            <span x-text="gameImportLatestSeasonSyncRun().payload?.season_label ?? gameImportLatestSeasonSyncRun().payload?.season ?? 'Selected season'"></span>
                                            <span> season stats rollup</span>
                                        </p>
                                    </div>
                                    <button
                                        x-show="['completed', 'failed'].includes(gameImportLatestSeasonSyncRun().status)"
                                        x-cloak
                                        type="button"
                                        class="float-right rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                        @click="dismissGameImportSeasonSync()"
                                    >
                                        <span class="sr-only">Hide season sync</span>
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </div>
                                <div class="mt-2 flex items-center justify-between gap-3 text-xs text-gray-600">
                                    <span x-text="gameImportSummaryText(gameImportLatestSeasonSyncRun())"></span>
                                    <span x-text="`${gameImportProgressPercentage(gameImportLatestSeasonSyncRun())}%`"></span>
                                </div>
                                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        class="h-full rounded-full transition-[width,background-color] duration-300 ease-out"
                                        :class="gameImportLatestSeasonSyncRun().status === 'failed' ? 'bg-red-500' : 'bg-indigo-600'"
                                        x-bind:style="`width: ${gameImportProgressPercentage(gameImportLatestSeasonSyncRun())}%`"
                                    ></div>
                                </div>
                                <div
                                    x-show="gameImportLatestSeasonSyncRun().progress?.last_error"
                                    class="mt-1 truncate text-[11px] text-red-600"
                                    x-text="gameImportLatestSeasonSyncRun().progress?.last_error"
                                ></div>
                            </div>
                        </template>
                    </div>

                    <div class="border-t border-gray-200 bg-white px-4 py-3">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 text-left"
                            :aria-expanded="isGameImportSourceGapsExpanded() ? 'true' : 'false'"
                            :aria-controls="sourceGapsAccordionId()"
                            @click="toggleGameImportSourceGaps()"
                        >
                            <span>
                                <span class="text-xs font-semibold uppercase text-gray-500">Source Gaps</span>
                                <span class="mt-0.5 block text-xs text-gray-500">Games with provider feeds that are empty or unavailable.</span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="text-[11px] font-medium text-gray-500">
                                    <span x-text="formatNumber(gameImports.sourceGaps.items.length)"></span>
                                    <span> games</span>
                                </span>
                                <svg
                                    class="h-3.5 w-3.5 flex-none text-gray-400 transition-transform duration-300 ease-out motion-reduce:transition-none"
                                    :class="isGameImportSourceGapsExpanded() ? 'rotate-180' : ''"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </span>
                        </button>

                        <div
                            x-show="isGameImportSourceGapsExpanded()"
                            x-cloak
                            :id="sourceGapsAccordionId()"
                            class="mt-2"
                        >
                            <div x-show="gameImports.sourceGaps.loading" class="space-y-1">
                                <div class="h-9 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-9 animate-pulse rounded bg-gray-100"></div>
                            </div>

                            <div
                                x-show="!gameImports.sourceGaps.loading && gameImports.sourceGaps.items.length === 0"
                                class="border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-500"
                            >
                                No missing NHL source records.
                            </div>

                            <div
                                x-show="!gameImports.sourceGaps.loading && gameImports.sourceGaps.items.length > 0"
                                class="space-y-1.5"
                            >
                                <template x-for="gap in gameImports.sourceGaps.items" :key="gap.game_id">
                                    <div class="rounded-md border border-gray-200 bg-white px-3 py-2 shadow-sm">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-xs font-semibold text-gray-900" x-text="gameImportGameLabel(gap)"></span>
                                                    <span class="text-[11px] text-gray-500" x-text="gameImportGameMeta(gap)"></span>
                                                </div>
                                                <div class="mt-0.5 text-[11px] text-amber-700" x-text="gameImportSourceGapSummaryText(gap)"></div>
                                            </div>
                                            <button
                                                type="button"
                                                class="inline-flex size-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="rerunGameImportSourceGap(gap)"
                                                :disabled="gameImports.sourceGaps.rerunning[gap.game_id] === true"
                                                :aria-label="`Rerun ${gameImportGameLabel(gap)}`"
                                            >
                                                <svg class="h-4 w-4" :class="gameImports.sourceGaps.rerunning[gap.game_id] === true ? 'animate-spin' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M15.312 11.424a.75.75 0 0 1 .523.923 6.5 6.5 0 1 1-1.778-6.284l.198.198V4.25a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5h1.684l-.193-.193a5 5 0 1 0 1.393 4.094.75.75 0 0 1 .923-.523Z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div class="mt-2 space-y-1 border-t border-gray-100 pt-2">
                                            <template x-for="source in gap.sources" :key="`${gap.game_id}-${source.source}`">
                                                <div class="flex flex-col gap-1 text-[11px] sm:flex-row sm:items-center sm:justify-between">
                                                    <span
                                                        class="inline-flex w-fit rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800"
                                                        x-text="gameImportSourceStatusLabel(source)"
                                                    ></span>
                                                    <a
                                                        class="break-all text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-gray-900"
                                                        :href="source.url"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        x-text="source.url"
                                                    ></a>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 px-4 py-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h4 class="text-xs font-semibold uppercase text-gray-500">Recent Orchestrations</h4>
                            <div class="text-[11px] text-gray-500">Live updates enabled</div>
                        </div>

                        <div x-show="gameImports.loading" class="space-y-1.5">
                            <div class="h-10 animate-pulse rounded bg-white"></div>
                            <div class="h-10 animate-pulse rounded bg-white"></div>
                            <div class="h-10 animate-pulse rounded bg-white"></div>
                        </div>

                        <div x-show="!gameImports.loading && gameImportVisibleRuns().length === 0" class="bg-white px-3 py-4 text-center text-xs text-gray-500">
                            No game import runs have been queued yet.
                        </div>

                        <div x-show="!gameImports.loading && gameImportVisibleRuns().length > 0" class="space-y-1.5">
                            <template x-for="run in gameImportVisibleRuns()" :key="run.id">
                                <div
                                    class="rounded-md bg-white px-3 shadow-sm"
                                    :class="isGameImportRunCompacted(run) ? 'py-1.5' : 'py-2.5'"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="text-xs font-semibold text-gray-900" x-text="gameImportTitle(run)"></span>
                                                <span
                                                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                                    :class="gameImportBadgeClass(run)"
                                                    x-text="gameImportBadgeText(run)"
                                                ></span>
                                                <span
                                                    x-show="isGameImportRunCompacted(run)"
                                                    x-cloak
                                                    class="text-[11px] text-gray-500"
                                                    x-text="gameImportCompactSummaryText(run)"
                                                ></span>
                                            </div>
                                        </div>
                                        <div
                                            x-show="!isGameImportRunCompacted(run)"
                                            class="flex items-center text-xs text-gray-600 sm:text-right"
                                        >
                                            <template x-if="run.action === 'discover' && !run.processing_started">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-2 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    @click="processGameImports(run)"
                                                    :disabled="!canProcessGameImportRun(run)"
                                                >
                                                    <span x-text="gameImports.processing ? 'Queuing...' : 'Process'"></span>
                                                </button>
                                            </template>
                                            <template x-if="run.action !== 'discover' || run.processing_started">
                                                <div>
                                                    <div><span x-text="formatNumber(run.queued_jobs)"></span> jobs queued</div>
                                                    <div><span x-text="formatNumber(run.date_count)"></span> dates</div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <div
                                        x-show="!isGameImportRunCompacted(run)"
                                        class="mt-2 border-t border-gray-200 pt-2"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full items-center justify-between gap-2 text-left"
                                            :aria-expanded="isGameImportRunExpanded(run) ? 'true' : 'false'"
                                            :aria-controls="gameImportAccordionId(run)"
                                            @click="toggleGameImportRun(run)"
                                        >
                                            <span class="text-xs text-gray-600" x-text="gameImportSummaryText(run)"></span>
                                            <svg
                                                class="h-3.5 w-3.5 flex-none text-gray-400 transition-transform duration-300 ease-out motion-reduce:transition-none"
                                                :class="isGameImportRunExpanded(run) ? 'rotate-180' : ''"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                            </svg>
                                        </button>

                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200">
                                            <div
                                                class="h-full rounded-full bg-indigo-600 transition-[width] duration-300 ease-out"
                                                x-bind:style="`width: ${gameImportProgressPercentage(run)}%`"
                                            ></div>
                                        </div>
                                        <div
                                            x-show="run.progress?.last_error"
                                            class="mt-1 truncate text-[11px] text-red-600"
                                            x-text="run.progress?.last_error"
                                        ></div>

                                        <div
                                            x-show="isGameImportRunExpanded(run)"
                                            x-cloak
                                            :id="gameImportAccordionId(run)"
                                            class="mt-2 space-y-2"
                                        >
                                            <template x-if="gameImportGames(run).length === 0">
                                                <div class="text-xs text-gray-500">No games discovered yet.</div>
                                            </template>

                                            <template x-for="game in gameImportGames(run)" :key="game.game_id">
                                                <div class="border-t border-gray-100 pt-2">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <div class="flex min-w-0 items-center gap-2">
                                                            <div class="truncate text-[11px] font-medium text-gray-900" x-text="gameImportGameLabel(game)"></div>
                                                            <div class="shrink-0 text-[11px] text-gray-500" x-text="gameImportGameMeta(game)"></div>
                                                        </div>
                                                        <div class="flex shrink-0 items-center gap-1.5">
                                                            <button
                                                                type="button"
                                                                x-show="canRerunStoppedGameImport(game)"
                                                                x-cloak
                                                                class="inline-flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                                @click="rerunStoppedGameImport(game)"
                                                                :disabled="gameImports.rerunningGames[game.game_id] === true"
                                                                :aria-label="`Rerun ${gameImportGameLabel(game)}`"
                                                            >
                                                                <svg class="h-3.5 w-3.5" :class="gameImports.rerunningGames[game.game_id] === true ? 'animate-spin' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fill-rule="evenodd" d="M15.312 11.424a.75.75 0 0 1 .523.923 6.5 6.5 0 1 1-1.778-6.284l.198.198V4.25a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5h1.684l-.193-.193a5 5 0 1 0 1.393 4.094.75.75 0 0 1 .923-.523Z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                            <div class="text-[11px] font-medium text-gray-600" x-text="`${gameImportGameProgressPercentage(game)}%`"></div>
                                                        </div>
                                                    </div>
                                                    <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-200">
                                                        <div
                                                            class="h-full rounded-full transition-[width,background-color] duration-1000 ease-out"
                                                            :class="gameImportGameProgressClass(game)"
                                                            x-bind:style="`width: ${gameImportGameProgressPercentage(game)}%`"
                                                        ></div>
                                                    </div>
                                                    <div class="mt-1 text-[11px] text-gray-600" x-text="gameImportGameProgressText(game)"></div>
                                                    <div
                                                        x-show="game.last_error"
                                                        class="mt-1 truncate text-[11px] text-red-600"
                                                        x-text="game.last_error"
                                                    ></div>
                                                    <div
                                                        x-show="gameImportBlockedSources(game).length > 0"
                                                        x-cloak
                                                        class="mt-1.5 space-y-1 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-800"
                                                    >
                                                        <template x-for="source in gameImportBlockedSources(game)" :key="`${game.game_id}-${source.source}`">
                                                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                                <span class="font-medium" x-text="gameImportSourceStatusLabel(source)"></span>
                                                                <a
                                                                    class="break-all text-amber-800 underline decoration-amber-300 underline-offset-2 hover:text-amber-950"
                                                                    :href="source.url"
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    x-text="source.url"
                                                                ></a>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <x-ui.slide-over
                    show="gameImports.drawerOpen"
                    close-action="closeGameImportDrawer()"
                    title-id="game-import-drawer-title"
                    max-width="max-w-lg"
                >
                    <form
                        class="flex h-full w-full flex-col"
                        @submit.prevent="submitGameImportDiscover()"
                    >
                        <div class="border-b border-gray-200 px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 id="game-import-drawer-title" class="text-sm font-semibold text-gray-900">Discover Games</h3>
                                    <p class="mt-1 text-sm text-gray-600">Choose one command-style date option or a start/end range.</p>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                    @click="closeGameImportDrawer()"
                                >
                                    <span class="sr-only">Close</span>
                                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                            <x-ui.date-field
                                id="game-import-date"
                                label="Single date"
                                model="gameImports.form.date"
                            />

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-ui.date-field
                                    id="game-import-start"
                                    label="Start date"
                                    model="gameImports.form.start"
                                />
                                <x-ui.date-field
                                    id="game-import-end"
                                    label="End date"
                                    model="gameImports.form.end"
                                />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="game-import-days" class="block text-sm font-medium text-gray-700">Days</label>
                                    <input id="game-import-days" x-model="gameImports.form.days" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label for="game-import-newdays" class="block text-sm font-medium text-gray-700">New days</label>
                                    <input id="game-import-newdays" x-model="gameImports.form.newdays" type="number" min="1" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>

                            <div>
                                <label for="game-import-season" class="block text-sm font-medium text-gray-700">Season</label>
                                <input id="game-import-season" x-model="gameImports.form.season" type="text" inputmode="numeric" placeholder="20252026" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="border-t border-gray-200 px-5 py-4">
                            <div class="flex justify-end gap-2">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                                    @click="closeGameImportDrawer()"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="gameImports.discovering"
                                >
                                    <span x-text="gameImports.discovering ? 'Queuing...' : 'Discover'"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                </x-ui.slide-over>
            </div>

            <div x-show="activeTab === 'triage'" x-cloak>
                <div
                    x-ref="triageMount"
                    data-admin-triage-mount
                    class="min-h-64"
                >
                    <div
                        x-show="triageLoading"
                        class="border-y border-gray-200 bg-white px-4 py-10"
                    >
                        <div class="mx-auto max-w-3xl space-y-4">
                            <div class="h-4 w-32 animate-pulse rounded bg-gray-200"></div>
                            <div class="space-y-3">
                                <div class="h-16 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-16 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-16 animate-pulse rounded bg-gray-100"></div>
                            </div>
                        </div>
                    </div>

                    <div
                        x-show="triageError"
                        class="border-y border-gray-200 bg-white px-4 py-6 text-sm text-red-600"
                        x-text="triageError"
                    ></div>
                </div>
            </div>

            <div x-show="activeTab === 'validations'" x-cloak>
                <div
                    x-ref="validationsMount"
                    data-admin-validations-mount
                    class="min-h-64"
                >
                    <div
                        x-show="validationsLoading"
                        class="border-y border-gray-200 bg-white px-4 py-10"
                    >
                        <div class="mx-auto max-w-3xl space-y-4">
                            <div class="h-4 w-40 animate-pulse rounded bg-gray-200"></div>
                            <div class="space-y-3">
                                <div class="h-12 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-12 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-12 animate-pulse rounded bg-gray-100"></div>
                            </div>
                        </div>
                    </div>

                    <div
                        x-show="validationsError"
                        class="border-y border-gray-200 bg-white px-4 py-6 text-sm text-red-600"
                        x-text="validationsError"
                    ></div>
                </div>
            </div>
        </div>
    </div>
</div>
