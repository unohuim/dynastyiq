<script>
import adminHub from '../../admin/admin-hub';

export default {
    name: 'AdminDashboard',
    props: {
        imports: { type: Array, default: () => [] },
        users: { type: Array, default: () => [] },
        activity: { type: Object, default: () => ({}) },
        hasPlayers: { type: Boolean, default: false },
        hasFantraxPlayers: { type: Boolean, default: false },
        urls: { type: Object, required: true },
    },
    data() {
        return adminHub({
            imports: this.imports,
            users: this.users,
            activity: this.activity,
            hasPlayers: this.hasPlayers,
            hasFantrax: this.hasFantraxPlayers,
            ...this.urls,
        });
    },
    computed: {
        playerImports() {
            return (this.imports ?? []).filter((item) => item.group === 'player');
        },
        platformImports() {
            return (this.imports ?? []).filter((item) => item.group === 'platform');
        },
    },
    mounted() {
        this.init();
    },
    beforeUnmount() {
        this.stopGameImportPoll?.();
        Object.keys(this.progressPollers ?? {}).forEach((key) => this.stopImportProgressPoll?.(key));
    },
};
</script>

<template>
    <div class="bg-gray-100">
        <header class="bg-white shadow">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-1">
                    <h2 class="text-xl font-semibold leading-tight text-gray-900">Admin Control Panel</h2>
                    <p class="text-sm text-gray-600">Review operational triage work and run supported data imports.</p>
                </div>
            </div>
        </header>
<div class="py-6">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" @click.capture="handleAdminHubClick($event)">
        <div class="border-b border-gray-200">
            <div class="flex flex-wrap items-center gap-6">
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.player-imports"
                    @click="setTab('imports')"
                    :class="activeTab === 'imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Player Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.game-imports"
                    @click="setTab('game-imports')"
                    :class="activeTab === 'game-imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Game Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.platform-imports"
                    @click="setTab('platform-imports')"
                    :class="activeTab === 'platform-imports' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Platform Imports
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.users"
                    @click="setTab('users')"
                    :class="activeTab === 'users' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Users
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.activity"
                    @click="setTab('activity')"
                    :class="activeTab === 'activity' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Activity
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.game-validations"
                    @click="setTab('validations')"
                    :class="activeTab === 'validations' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Game Validations
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.shift-mismatches"
                    @click="setTab('shift-mismatches')"
                    :class="activeTab === 'shift-mismatches' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Shifts Mismatch
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.triage"
                    @click="setTab('triage')"
                    :class="activeTab === 'triage' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    Triage
                </button>
                <button
                    type="button"
                    class="border-b-2 px-0 pb-3 text-sm font-semibold"
                    data-track="admin.tab.api-keys"
                    @click="setTab('api-keys')"
                    :class="activeTab === 'api-keys' ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-800'"
                >
                    API Keys
                </button>
            </div>
        </div>

        <div class="py-4">
            <div v-show="activeTab === 'imports'" >
                <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
                    <div v-for="importItem in playerImports" :key="importItem.key" class="px-4 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900">{{ importItem.label }}</div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        Last run:
                                        <span v-text="formatLastRun(importItem.key)"></span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <template v-if="importItem.key === 'fantrax'">
                                        <button type="button" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60" @click="refreshFantraxLeagues()"
                                            :disabled="fantraxLeagueRefresh.running === true || streams['fantrax']?.running === true"
                                        >
                                            <span v-text="fantraxLeagueRefresh.running ? 'Refreshing...' : 'Refresh Leagues'"></span>
                                        </button>
                                    </template>
                                    <template v-if="importItem.key === 'nhl' && importItem.actions && importItem.actions.length">
                                        <details class="relative">
                                            <summary
                                                class="inline-flex cursor-pointer list-none items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                                                :disabled="streams[importItem.key]?.running === true"
                                                aria-haspopup="menu"
                                            >
                                                <span>Run Now</span>
                                                <svg class="ml-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                                </svg>
                                            </summary>
                                            <div
                                                class="absolute right-0 z-50 mt-2 w-40 border border-gray-200 bg-white py-1 shadow-lg"
                                                role="menu"
                                            >
                                                <button v-for="action in importItem.actions" :key="action.key"
                                                        type="button"
                                                        class="flex w-full items-center px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50"
                                                        @click="startImport(importItem.key, action.key)"
                                                        role="menuitem"
                                                    >
                                                        {{ action.label }}
                                                    </button>
                                                
                                            </div>
                                        </details>
                                    </template><template v-else>
                                        <button type="button" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60" @click="startImport(importItem.key)"
                                            :disabled="streams[importItem.key]?.running === true"
                                        >
                                            {{ importItem.key === 'fantrax' ? 'Import' : 'Run Now' }}
                                        </button>
                                    </template>
                                </div>
                            </div>

                            <div
                                class="mt-4 space-y-2"
                                v-show="shouldShowImportProgress(importItem.key)"
                                
                            >
                                <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                    <span v-text="importProgressText(importItem.key)"></span>
                                    <span v-text="`${importProgressPercentage(importItem.key)}%`"></span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        class="h-full rounded-full bg-indigo-600 transition-all duration-300"
                                        :style="`width: ${importProgressPercentage(importItem.key)}%`"
                                    ></div>
                                </div>
                                <div class="text-xs text-gray-500" v-text="importProgressDetailText(importItem.key)"></div>
                            </div>

                            <div class="mt-4 space-y-2">
                                <button
                                    type="button"
                                    class="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
                                    @click="toggleStream(importItem.key)"
                                >
                                    <span v-text="streams[importItem.key]?.open ? 'Hide Output' : 'Show Output'"></span>
                                </button>
                                <div
                                    class="h-40 overflow-y-auto bg-gray-950 p-3 font-mono text-xs text-green-200"
                                    v-show="streams[importItem.key]?.open"
                                >
                                    <template v-if="(streams[importItem.key]?.messages?.length ?? 0) === 0">
                                        <div class="text-gray-400">Awaiting output...</div>
                                    </template>
                                    <template v-for="(entry, idx) in streams[importItem.key]?.messages" :key="idx">
                                        <div class="whitespace-pre-wrap" v-text="entry.message"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    
                </div>
            </div>

            <div v-show="activeTab === 'platform-imports'" >
                <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
                    <div v-for="importItem in platformImports" :key="importItem.key" class="px-4 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900">{{ importItem.label }}</div>
                                    <div class="mt-1 text-sm text-gray-600">
                                        Last run:
                                        <span v-text="formatLastRun(importItem.key)"></span>
                                    </div>
                                </div>
                                <button type="button" class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60" @click="startImport(importItem.key)"
                                    :disabled="streams[importItem.key]?.running === true"
                                >
                                    Run Now
                                </button>
                            </div>

                            <div
                                class="mt-4 space-y-2"
                                v-show="shouldShowImportProgress(importItem.key)"
                                
                            >
                                <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                    <span v-text="importProgressText(importItem.key)"></span>
                                    <span v-text="`${importProgressPercentage(importItem.key)}%`"></span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        class="h-full rounded-full bg-indigo-600 transition-all duration-300"
                                        :style="`width: ${importProgressPercentage(importItem.key)}%`"
                                    ></div>
                                </div>
                                <div class="text-xs text-gray-500" v-text="importProgressDetailText(importItem.key)"></div>
                            </div>

                            <div class="mt-4 space-y-2">
                                <button
                                    type="button"
                                    class="text-sm font-semibold text-indigo-600 hover:text-indigo-700"
                                    @click="toggleStream(importItem.key)"
                                >
                                    <span v-text="streams[importItem.key]?.open ? 'Hide Output' : 'Show Output'"></span>
                                </button>
                                <div
                                    class="h-40 overflow-y-auto bg-gray-950 p-3 font-mono text-xs text-green-200"
                                    v-show="streams[importItem.key]?.open"
                                >
                                    <template v-if="(streams[importItem.key]?.messages?.length ?? 0) === 0">
                                        <div class="text-gray-400">Awaiting output...</div>
                                    </template>
                                    <template v-for="(entry, idx) in streams[importItem.key]?.messages" :key="idx">
                                        <div class="whitespace-pre-wrap" v-text="entry.message"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    
                </div>
            </div>

            <div v-show="activeTab === 'users'" >
                <div class="border-y border-gray-200 bg-white">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Users</h3>
                            <p class="mt-0.5 text-xs text-gray-500">DynastyIQ accounts with local session presence.</p>
                        </div>
                        <div class="text-xs text-gray-500">
                            <span v-text="formatNumber(users.length)"></span>
                            <span v-text="users.length === 1 ? 'user' : 'users'"></span>
                        </div>
                    </div>

                    <div class="divide-y divide-gray-200">
                        <template v-for="user in users" :key="user.id">
                            <div class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_10rem_9rem] sm:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <template v-if="user.avatar_url">
                                        <img
                                            :src="user.avatar_url"
                                            alt=""
                                            class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-gray-200"
                                            loading="lazy"
                                        >
                                    </template>
                                    <template v-if="!user.avatar_url">
                                        <span
                                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 ring-1 ring-gray-200"
                                            v-text="userInitials(user)"
                                        ></span>
                                    </template>

                                    <div class="min-w-0">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="truncate text-sm font-semibold text-gray-900" v-text="user.name"></span>
                                            <span
                                                v-show="user.email_verified"
                                                class="inline-flex shrink-0 items-center rounded-md bg-green-50 px-1.5 py-0.5 text-[10px] font-semibold text-green-700 ring-1 ring-green-600/20"
                                            >
                                                Verified
                                            </span>
                                        </div>
                                        <div class="truncate text-xs text-gray-500" v-text="user.email"></div>
                                        <div v-show="user.discord_name" class="truncate text-xs text-gray-500">
                                            Discord:
                                            <span v-text="user.discord_name"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-1">
                                    <template v-for="role in user.roles" :key="`${user.id}-${role}`">
                                        <span
                                            class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-600"
                                            v-text="role"
                                        ></span>
                                    </template>
                                    <span
                                        v-show="!user.roles || user.roles.length === 0"
                                        class="text-xs text-gray-400"
                                    >
                                        No roles
                                    </span>
                                </div>

                                <div class="text-left sm:text-right">
                                    <div class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold" :class="presenceClass(user)">
                                        <span class="h-2 w-2 rounded-full" :class="presenceDotClass(user)"></span>
                                        <span v-text="user.presence?.label ?? 'Offline'"></span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500" v-text="formatUserLastSeen(user)"></div>
                                </div>
                            </div>
                        </template>

                        <div v-show="users.length === 0" class="px-4 py-8 text-sm text-gray-500">
                            No users found.
                        </div>
                    </div>
                </div>
            </div>

            <div v-show="activeTab === 'activity'" >
                <div class="border-y border-gray-200 bg-white">
                    <div class="flex flex-col gap-1 border-b border-gray-200 px-4 py-3">
                        <h3 class="text-sm font-semibold text-gray-900">Activity</h3>
                        <p class="text-xs text-gray-500">First-party page views, engagement heartbeats, and explicitly tagged UI events.</p>
                    </div>

                    <div class="grid gap-px bg-gray-200 sm:grid-cols-3 lg:grid-cols-6">
                        <template v-for="item in activitySummaryItems()" :key="item.key">
                            <div class="bg-white px-4 py-3">
                                <div class="text-[11px] font-semibold uppercase text-gray-500" v-text="item.label"></div>
                                <div class="mt-1 text-lg font-semibold text-gray-900" v-text="formatNumber(item.value)"></div>
                            </div>
                        </template>
                    </div>

                    <div class="grid gap-px bg-gray-200 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]">
                        <div class="bg-white">
                            <div class="border-b border-gray-200 px-4 py-3">
                                <h4 class="text-xs font-semibold uppercase text-gray-500">Recent Events</h4>
                            </div>
                            <div class="divide-y divide-gray-200">
                                <template v-for="event in activity.recent_events ?? []" :key="event.id">
                                    <div class="grid gap-2 px-4 py-3 text-sm sm:grid-cols-[8rem_minmax(0,1fr)_11rem] sm:items-center">
                                        <div>
                                            <span class="inline-flex rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700" v-text="event.event_name"></span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="truncate text-gray-900" v-text="event.path || 'Unknown page'"></div>
                                            <div class="truncate text-xs text-gray-500" v-text="activityActor(event)"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 sm:text-right" v-text="formatDateTime(event.occurred_at)"></div>
                                    </div>
                                </template>
                                <div v-show="(activity.recent_events ?? []).length === 0" class="px-4 py-8 text-sm text-gray-500">
                                    No activity events recorded yet.
                                </div>
                            </div>
                        </div>

                        <div class="bg-white">
                            <div class="border-b border-gray-200 px-4 py-3">
                                <h4 class="text-xs font-semibold uppercase text-gray-500">Event Mix</h4>
                            </div>
                            <div class="divide-y divide-gray-200">
                                <template v-for="item in activityEventMix()" :key="item.name">
                                    <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                        <span class="truncate text-gray-700" v-text="item.name"></span>
                                        <span class="font-semibold text-gray-900" v-text="formatNumber(item.count)"></span>
                                    </div>
                                </template>
                                <div v-show="activityEventMix().length === 0" class="px-4 py-8 text-sm text-gray-500">
                                    No events in the last 7 days.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 bg-white">
                        <div class="border-b border-gray-200 px-4 py-3">
                            <h4 class="text-xs font-semibold uppercase text-gray-500">Recent Sessions</h4>
                        </div>
                        <div class="divide-y divide-gray-200">
                            <template v-for="session in activity.recent_sessions ?? []" :key="session.id">
                                <div class="grid gap-2 px-4 py-3 text-sm md:grid-cols-[minmax(0,1fr)_8rem_11rem] md:items-center">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-gray-900" v-text="activityActor(session)"></div>
                                        <div class="truncate text-xs text-gray-500" v-text="session.last_path || session.landing_path || 'Unknown page'"></div>
                                    </div>
                                    <div class="text-xs text-gray-600" v-text="formatDuration(session.engaged_seconds)"></div>
                                    <div class="text-xs text-gray-500 md:text-right" v-text="formatDateTime(session.last_seen_at)"></div>
                                </div>
                            </template>
                            <div v-show="(activity.recent_sessions ?? []).length === 0" class="px-4 py-8 text-sm text-gray-500">
                                No sessions recorded yet.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-show="activeTab === 'api-keys'" >
                <div class="border-y border-gray-200 bg-white">
                    <div class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">API Keys</h3>
                            <p class="mt-0.5 text-xs text-gray-500">Scoped server-to-server access for connected apps.</p>
                        </div>
                        <div class="text-xs text-gray-500">
                            <span v-text="formatNumber(apiKeys.items.length)"></span>
                            <span v-text="apiKeys.items.length === 1 ? 'key' : 'keys'"></span>
                        </div>
                    </div>

                    <div
                        v-show="apiKeys.error"
                        
                        class="border-b border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                        v-text="apiKeys.error"
                    ></div>

                    <form class="border-b border-gray-200 px-4 py-4" @submit.prevent="createApiKey()">
                        <div class="grid gap-4 lg:grid-cols-[minmax(14rem,0.8fr)_minmax(0,1fr)_auto] lg:items-end">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase text-gray-500">Name</span>
                                <input
                                    type="text"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    v-model="apiKeys.form.name"
                                    placeholder="gner8"
                                >
                            </label>

                            <div>
                                <div class="text-xs font-semibold uppercase text-gray-500">Scopes</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <template v-for="scope in apiKeys.availableScopes" :key="scope.value">
                                        <label class="inline-flex items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm">
                                            <input
                                                type="checkbox"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                v-model="apiKeys.form.scopes"
                                                :value="scope.value"
                                            >
                                            <span v-text="scope.label"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>

                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="apiKeys.creating"
                            >
                                <span v-text="apiKeys.creating ? 'Creating...' : 'Create Key'"></span>
                            </button>
                        </div>
                    </form>

                    <div
                        v-show="apiKeys.createdToken"
                        
                        class="border-b border-emerald-200 bg-emerald-50 px-4 py-4"
                    >
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-emerald-900">New token</div>
                                <code class="mt-1 block overflow-x-auto rounded-md bg-white px-3 py-2 font-mono text-xs text-emerald-900 ring-1 ring-emerald-200" v-text="apiKeys.createdToken"></code>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-emerald-300 bg-white text-emerald-700 shadow-sm hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="copyApiKeyToken(apiKeys.createdToken)"
                                :disabled="!apiKeys.createdToken"
                                aria-label="Copy API token"
                                title="Copy API token"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="9" y="9" width="11" height="11" rx="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                            </button>
                        </div>
                        <div v-show="apiKeys.copied"  class="mt-2 text-xs font-semibold text-emerald-700">
                            Copied
                        </div>
                    </div>

                    <div v-show="apiKeys.loading"  class="px-4 py-8 text-sm text-gray-500">
                        Loading API keys...
                    </div>

                    <div v-show="!apiKeys.loading" class="divide-y divide-gray-200">
                        <template v-for="key in apiKeys.items" :key="key.id">
                            <div class="grid gap-3 px-4 py-4 lg:grid-cols-[minmax(0,1fr)_14rem_10rem_9rem] lg:items-center">
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <span class="truncate text-sm font-semibold text-gray-900" v-text="key.name"></span>
                                        <span
                                            class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase ring-1"
                                            :class="apiKeyStatusClass(key)"
                                            v-text="key.status"
                                        ></span>
                                    </div>
                                    <div class="mt-1 truncate text-xs text-gray-500" v-text="key.slug"></div>
                                </div>

                                <div class="flex flex-wrap gap-1">
                                    <template v-for="scope in key.scopes" :key="`${key.id}-${scope}`">
                                        <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-[10px] font-semibold text-gray-700" v-text="apiKeyScopeLabel(scope)"></span>
                                    </template>
                                </div>

                                <div>
                                    <div class="text-[10px] font-semibold uppercase text-gray-500">Prefix</div>
                                    <code class="mt-1 block truncate font-mono text-xs text-gray-700" v-text="key.token_prefix"></code>
                                </div>

                                <div class="text-xs text-gray-500 lg:text-right">
                                    <div>Created <span v-text="formatDateTime(key.created_at)"></span></div>
                                    <div>Used <span v-text="formatDateTime(key.last_used_at)"></span></div>
                                </div>
                            </div>
                        </template>

                        <div v-show="apiKeys.items.length === 0" class="px-4 py-8 text-sm text-gray-500">
                            No API keys have been created.
                        </div>
                    </div>
                </div>
            </div>

            <div v-show="activeTab === 'game-imports'" >
                <div class="mb-4 border-y border-gray-200 bg-white px-4 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-900">NHL Schedule Refresh</h3>
                                <template v-if="gameImportLatestScheduleRefreshRun()">
                                    <span
                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                        :class="gameImportStatusClass(gameImportLatestScheduleRefreshRun().status)"
                                        v-text="String(gameImportLatestScheduleRefreshRun().status ?? 'queued').toUpperCase()"
                                    ></span>
                                </template>
                            </div>
                            <p class="mt-1 text-sm text-gray-600">
                                Refresh future schedule rows in nhl_games without running game imports.
                            </p>
                            <p class="mt-1 text-xs text-gray-500" v-text="gameImportScheduleRefreshRangeText()"></p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="submitGameImportScheduleRefresh()"
                            :disabled="gameImports.refreshingSchedule || gameImportScheduleRefreshActive()"
                        >
                            <span v-text="gameImportScheduleRefreshButtonText()"></span>
                        </button>
                    </div>

                    <template v-if="gameImportLatestScheduleRefreshRun()">
                        <div class="mt-3">
                            <div class="flex items-center justify-between gap-3 text-xs text-gray-600">
                                <span v-text="gameImportScheduleRefreshSummaryText(gameImportLatestScheduleRefreshRun())"></span>
                                <span v-text="`${gameImportProgressPercentage(gameImportLatestScheduleRefreshRun())}%`"></span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-200">
                                <div
                                    class="h-full rounded-full transition-[width,background-color] duration-300 ease-out"
                                    :class="gameImportLatestScheduleRefreshRun().status === 'failed' ? 'bg-red-500' : 'bg-indigo-600'"
                                    :style="`width: ${gameImportProgressPercentage(gameImportLatestScheduleRefreshRun())}%`"
                                ></div>
                            </div>
                            <div
                                v-show="gameImportLatestScheduleRefreshRun().progress?.last_error"
                                class="mt-1 truncate text-[11px] text-red-600"
                                v-text="gameImportLatestScheduleRefreshRun().progress?.last_error"
                            ></div>
                        </div>
                    </template>
                </div>

                <div class="border-y border-gray-200 bg-gray-50">
                    <div class="flex flex-col gap-4 px-4 py-5 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">NHL Game Import Pipeline</h3>
                            <p class="mt-1 max-w-3xl text-sm text-gray-600">
                                Discover games by date selection, then process scheduled pipeline stages through queued orchestration jobs.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="submitGameImportEmptyGames()"
                                :disabled="gameImports.emptyingGames"
                            >
                                <span v-text="gameImports.emptyingGames ? 'Queuing...' : 'Empty Games'"></span>
                            </button>
                            <div
                                class="relative inline-flex rounded-md shadow-sm"
                            >
                                <button
                                    type="button"
                                    class="relative inline-flex items-center justify-center rounded-l-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-10 disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="submitGameImportSeasonSync()"
                                    :disabled="gameImports.syncingSeason || !gameImports.selectedSeason"
                                >
                                    <span v-text="gameImports.syncingSeason ? 'Queuing...' : gameImportSeasonSyncButtonText()"></span>
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
                                        v-show="gameImports.seasonDropdownOpen"
                                        
                                        class="absolute right-0 z-20 mt-2 w-56 origin-top-right rounded-md bg-white p-0 shadow-lg outline outline-1 outline-black/5 transition duration-200 ease-out motion-reduce:transition-none"
                                        role="menu"
                                    >
                                        <div class="py-1">
                                            <template v-if="gameImportSeasonOptions().length === 0">
                                                <div class="px-4 py-2 text-sm text-gray-500">No imported seasons</div>
                                            </template>
                                            <template v-for="season in gameImportSeasonOptions()" :key="season.season">
                                                <button
                                                    type="button"
                                                    class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:bg-gray-100 focus:text-gray-900 focus:outline-none"
                                                    role="menuitem"
                                                    @click="selectGameImportSeason(season)"
                                                    v-text="season.label"
                                                ></button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="submitDuplicatePbpScan()"
                                :disabled="gameImports.scanningDuplicatePbp"
                            >
                                <span v-text="gameImports.scanningDuplicatePbp ? 'Queuing...' : 'DeDupe'"></span>
                            </button>
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
                        v-show="gameImports.error"
                        
                        class="border-t border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                        v-text="gameImports.error"
                    ></div>

                    <div
                        v-show="shouldShowGameImportSeasonSync()"
                        
                        class="border-t border-gray-200 bg-white px-4 py-3"
                    >
                        <template v-if="gameImportLatestSeasonSyncRun()">
                            <div>
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-xs font-semibold uppercase text-gray-500">Season Syncing</h4>
                                        <p class="mt-0.5 text-xs text-gray-600">
                                            <span v-text="gameImportLatestSeasonSyncRun().payload?.season_label ?? gameImportLatestSeasonSyncRun().payload?.season ?? 'Selected season'"></span>
                                            <span> season stats rollup</span>
                                        </p>
                                    </div>
                                    <button
                                        v-show="['completed', 'failed'].includes(gameImportLatestSeasonSyncRun().status)"
                                        
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
                                    <span v-text="gameImportSummaryText(gameImportLatestSeasonSyncRun())"></span>
                                    <span v-text="`${gameImportProgressPercentage(gameImportLatestSeasonSyncRun())}%`"></span>
                                </div>
                                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-200">
                                    <div
                                        class="h-full rounded-full transition-[width,background-color] duration-300 ease-out"
                                        :class="gameImportLatestSeasonSyncRun().status === 'failed' ? 'bg-red-500' : 'bg-indigo-600'"
                                        :style="`width: ${gameImportProgressPercentage(gameImportLatestSeasonSyncRun())}%`"
                                    ></div>
                                </div>
                                <div
                                    v-show="gameImportLatestSeasonSyncRun().progress?.last_error"
                                    class="mt-1 truncate text-[11px] text-red-600"
                                    v-text="gameImportLatestSeasonSyncRun().progress?.last_error"
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
                                    <span v-text="formatNumber(gameImports.sourceGaps.items.length)"></span>
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
                            v-show="isGameImportSourceGapsExpanded()"
                            
                            :id="sourceGapsAccordionId()"
                            class="mt-2"
                        >
                            <div v-show="gameImports.sourceGaps.loading" class="space-y-1">
                                <div class="h-9 animate-pulse rounded bg-gray-100"></div>
                                <div class="h-9 animate-pulse rounded bg-gray-100"></div>
                            </div>

                            <div
                                v-show="!gameImports.sourceGaps.loading && gameImports.sourceGaps.items.length === 0"
                                class="border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-500"
                            >
                                No missing NHL source records.
                            </div>

                            <div
                                v-show="!gameImports.sourceGaps.loading && gameImports.sourceGaps.items.length > 0"
                                class="space-y-1.5"
                            >
                                <template v-for="gap in gameImports.sourceGaps.items" :key="gap.game_id">
                                    <div class="rounded-md border border-gray-200 bg-white px-3 py-2 shadow-sm">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-xs font-semibold text-gray-900" v-text="gameImportGameLabel(gap)"></span>
                                                    <span class="text-[11px] text-gray-500" v-text="gameImportGameMeta(gap)"></span>
                                                </div>
                                                <div class="mt-0.5 text-[11px] text-amber-700" v-text="gameImportSourceGapSummaryText(gap)"></div>
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
                                            <template v-for="source in gap.sources" :key="`${gap.game_id}-${source.source}`">
                                                <div class="flex flex-col gap-1 text-[11px] sm:flex-row sm:items-center sm:justify-between">
                                                    <span
                                                        class="inline-flex w-fit rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800"
                                                        v-text="gameImportSourceStatusLabel(source)"
                                                    ></span>
                                                    <a
                                                        class="break-all text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-gray-900"
                                                        :href="source.url"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        v-text="source.url"
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

                        <div v-show="gameImports.loading" class="space-y-1.5">
                            <div class="h-10 animate-pulse rounded bg-white"></div>
                            <div class="h-10 animate-pulse rounded bg-white"></div>
                            <div class="h-10 animate-pulse rounded bg-white"></div>
                        </div>

                        <div v-show="!gameImports.loading && gameImportVisibleRuns().length === 0" class="bg-white px-3 py-4 text-center text-xs text-gray-500">
                            No game import runs have been queued yet.
                        </div>

                        <div v-show="!gameImports.loading && gameImportVisibleRuns().length > 0" class="space-y-1.5">
                            <template v-for="run in gameImportVisibleRuns()" :key="run.id">
                                <div
                                    class="rounded-md bg-white px-3 shadow-sm"
                                    :class="isGameImportRunCompacted(run) ? 'py-1.5' : 'py-2.5'"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="text-xs font-semibold text-gray-900" v-text="gameImportTitle(run)"></span>
                                                <span
                                                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                                    :class="gameImportBadgeClass(run)"
                                                    v-text="gameImportBadgeText(run)"
                                                ></span>
                                                <span
                                                    v-show="isGameImportRunCompacted(run)"
                                                    
                                                    class="text-[11px] text-gray-500"
                                                    v-text="gameImportCompactSummaryText(run)"
                                                ></span>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap items-center justify-end gap-1.5 text-xs text-gray-600 sm:text-right">
                                            <template v-if="canProcessGameImportRun(run)">
                                                <div class="relative">
                                                    <button
                                                        type="button"
                                                        class="inline-flex items-center justify-center gap-1 rounded-md bg-indigo-600 px-2 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                        :disabled="gameImportProcessBusy(run)"
                                                        :aria-expanded="isGameImportProcessMenuOpen(run) ? 'true' : 'false'"
                                                        @click.stop.prevent="toggleGameImportProcessMenu(run)"
                                                    >
                                                        <span v-text="gameImportProcessButtonText(run)"></span>
                                                        <svg class="h-3 w-3 text-indigo-100" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                                        </svg>
                                                    </button>
                                                    <div
                                                        v-show="isGameImportProcessMenuOpen(run)"
                                                        .opacity.duration.150ms
                                                        
                                                        class="absolute right-0 z-20 mt-1 w-32 overflow-hidden rounded-md border border-gray-200 bg-white py-1 text-left shadow-lg"
                                                    >
                                                        <button
                                                            type="button"
                                                            class="block w-full px-3 py-1.5 text-left text-[11px] font-semibold text-gray-700 hover:bg-gray-50"
                                                            @click.stop.prevent="processRefsStaffGameImports(run)"
                                                        >
                                                            Refs and Staff
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="block w-full px-3 py-1.5 text-left text-[11px] font-semibold text-gray-700 hover:bg-gray-50"
                                                            @click.stop.prevent="processShotFactsGameImports(run)"
                                                        >
                                                            Shots
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="block w-full px-3 py-1.5 text-left text-[11px] font-semibold text-gray-700 hover:bg-gray-50"
                                                            @click.stop.prevent="processFaceoffFactsGameImports(run)"
                                                        >
                                                            Faceoffs
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="block w-full px-3 py-1.5 text-left text-[11px] font-semibold text-gray-700 hover:bg-gray-50"
                                                            @click.stop.prevent="processFullGameImports(run)"
                                                        >
                                                            Full
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                            <template v-if="isDuplicatePbpRepairRun(run) && canRunDuplicatePbpDedupe(run)">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-2 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    :disabled="gameImports.dedupingDuplicatePbpRuns[run.id] === true"
                                                    @click.stop.prevent="runDuplicatePbpDedupe(run)"
                                                >
                                                    <span v-text="gameImports.dedupingDuplicatePbpRuns[run.id] === true ? 'Queuing...' : 'DeDupe'"></span>
                                                </button>
                                            </template>
                                            <template v-if="isDuplicatePbpRepairRun(run) && canRunDuplicatePbpRebuild(run)">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-2 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                    :disabled="gameImports.rebuildingDuplicatePbpRuns[run.id] === true"
                                                    @click.stop.prevent="runDuplicatePbpRebuild(run)"
                                                >
                                                    <span v-text="gameImports.rebuildingDuplicatePbpRuns[run.id] === true ? 'Queuing...' : 'Rebuild Affected'"></span>
                                                </button>
                                            </template>
                                            <template v-if="run.status !== 'completed' && (run.action !== 'discover' || run.processing_started)">
                                                <div>
                                                    <div><span v-text="formatNumber(run.queued_jobs)"></span> jobs queued</div>
                                                    <div><span v-text="formatNumber(run.date_count)"></span> dates</div>
                                                </div>
                                            </template>
                                            <div v-show="!isDuplicatePbpRepairRun(run)"  class="relative">
                                                <button
                                                    type="button"
                                                    class="inline-flex items-center justify-center gap-1 rounded-md border border-gray-300 bg-white px-2 py-1 text-[11px] font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                    :disabled="!canRerunGameImportRun(run) || gameImportRerunBusy(run)"
                                                    :aria-expanded="isGameImportRerunMenuOpen(run) ? 'true' : 'false'"
                                                    @click.stop.prevent="toggleGameImportRerunMenu(run)"
                                                >
                                                    <span v-text="gameImportRerunBusy(run) ? 'Queuing...' : 'Re Run'"></span>
                                                    <svg class="h-3 w-3 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                                    </svg>
                                                </button>
                                                <div
                                                    v-show="isGameImportRerunMenuOpen(run)"
                                                    .opacity.duration.150ms
                                                    
                                                    class="absolute right-0 z-20 mt-1 w-36 overflow-hidden rounded-md border border-gray-200 bg-white py-1 text-left shadow-lg"
                                                >
                                                    <button
                                                        type="button"
                                                        class="block w-full px-3 py-1.5 text-left text-[11px] font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400"
                                                        :disabled="!canRerunFailedOnlyGameImportRun(run)"
                                                        @click.stop.prevent="rerunFailedOnlyGameImportRun(run)"
                                                    >
                                                        Failed Only
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="block w-full px-3 py-1.5 text-left text-[11px] font-semibold text-gray-700 hover:bg-gray-50"
                                                        @click.stop.prevent="rerunFullGameImportRun(run)"
                                                    >
                                                        Full Run
                                                    </button>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                v-show="canDeleteGameImportRun(run)"
                                                
                                                class="inline-flex size-7 items-center justify-center rounded-full border border-red-200 bg-white text-red-600 shadow-sm transition-colors hover:border-red-300 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click.stop.prevent="deleteGameImportRun(run)"
                                                :disabled="gameImportDeleteBusy(run)"
                                                :aria-label="`Remove ${gameImportTitle(run)}`"
                                                title="Remove run"
                                            >
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clip-rule="evenodd" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div
                                        v-show="!isGameImportRunCompacted(run)"
                                        class="mt-2 border-t border-gray-200 pt-2"
                                    >
                                        <button
                                            type="button"
                                            class="flex w-full items-center justify-between gap-2 text-left"
                                            :aria-expanded="isGameImportRunExpanded(run) ? 'true' : 'false'"
                                            :aria-controls="gameImportAccordionId(run)"
                                            @click="toggleGameImportRun(run)"
                                        >
                                            <span class="text-xs text-gray-600" v-text="gameImportSummaryText(run)"></span>
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
                                                :style="`width: ${gameImportProgressPercentage(run)}%`"
                                            ></div>
                                        </div>
                                        <div
                                            v-show="run.progress?.last_error"
                                            class="mt-1 truncate text-[11px] text-red-600"
                                            v-text="run.progress?.last_error"
                                        ></div>

                                        <div
                                            v-show="isGameImportRunExpanded(run)"
                                            
                                            :id="gameImportAccordionId(run)"
                                            class="mt-2 space-y-2"
                                        >
                                            <div
                                                v-show="gameImportRunDetailText(run)"
                                                
                                                class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700"
                                            >
                                                <div class="font-semibold text-red-800">Run detail</div>
                                                <div class="mt-0.5 break-words" v-text="gameImportRunDetailText(run)"></div>
                                            </div>

                                            <template v-if="gameImportGames(run).length === 0">
                                                <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-500">
                                                    No game-level rows require attention.
                                                </div>
                                            </template>

                                            <template v-for="game in gameImportGames(run)" :key="game.game_id">
                                                <div class="border-t border-gray-100 pt-2">
                                                    <div class="flex items-start justify-between gap-2">
                                                        <div class="flex min-w-0 items-center gap-2">
                                                            <div class="truncate text-[11px] font-medium text-gray-900" v-text="gameImportGameLabel(game)"></div>
                                                            <div class="shrink-0 text-[11px] text-gray-500" v-text="gameImportGameMeta(game)"></div>
                                                        </div>
                                                        <div class="flex shrink-0 items-center gap-1.5">
                                                            <button
                                                                type="button"
                                                                v-show="canRerunStoppedGameImport(game)"
                                                                
                                                                class="inline-flex size-7 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                                @click="rerunStoppedGameImport(game, run)"
                                                                :disabled="gameImports.rerunningGames[game.game_id] === true"
                                                                :aria-label="`Rerun ${gameImportGameLabel(game)}`"
                                                            >
                                                                <svg class="h-3.5 w-3.5" :class="gameImports.rerunningGames[game.game_id] === true ? 'animate-spin' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                                    <path fill-rule="evenodd" d="M15.312 11.424a.75.75 0 0 1 .523.923 6.5 6.5 0 1 1-1.778-6.284l.198.198V4.25a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5h1.684l-.193-.193a5 5 0 1 0 1.393 4.094.75.75 0 0 1 .923-.523Z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                            <div class="text-[11px] font-medium text-gray-600" v-text="`${gameImportGameProgressPercentage(game)}%`"></div>
                                                        </div>
                                                    </div>
                                                    <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-200">
                                                        <div
                                                            class="h-full rounded-full transition-[width,background-color] duration-1000 ease-out"
                                                            :class="gameImportGameProgressClass(game)"
                                                            :style="`width: ${gameImportGameProgressPercentage(game)}%`"
                                                        ></div>
                                                    </div>
                                                    <div class="mt-1 text-[11px] text-gray-600" v-text="gameImportGameProgressText(game)"></div>
                                                    <div
                                                        v-show="game.last_error"
                                                        class="mt-1 truncate text-[11px] text-red-600"
                                                        v-text="game.last_error"
                                                    ></div>
                                                    <div
                                                        v-show="game.failure_category || game.sentry_event_id"
                                                        
                                                        class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-gray-500"
                                                    >
                                                        <span
                                                            v-show="game.error_stage"
                                                            class="rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-700"
                                                            v-text="game.error_stage"
                                                        ></span>
                                                        <span
                                                            v-show="game.failure_category"
                                                            class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700"
                                                            v-text="game.failure_category"
                                                        ></span>
                                                        <span
                                                            v-show="game.retryable !== null && game.retryable !== undefined"
                                                            class="rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-700"
                                                            v-text="game.retryable ? 'retryable' : 'not retryable'"
                                                        ></span>
                                                        <template v-if="game.sentry_url">
                                                            <a
                                                                class="font-medium text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900"
                                                                :href="game.sentry_url"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                Sentry event
                                                            </a>
                                                        </template>
                                                        <span
                                                            v-show="game.sentry_event_id && !game.sentry_url"
                                                            class="font-mono text-gray-500"
                                                            v-text="`Sentry ${game.sentry_event_id}`"
                                                        ></span>
                                                    </div>
                                                    <div
                                                        v-show="gameImportBlockedSources(game).length > 0"
                                                        
                                                        class="mt-1.5 space-y-1 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-800"
                                                    >
                                                        <template v-for="source in gameImportBlockedSources(game)" :key="`${game.game_id}-${source.source}`">
                                                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                                <span class="font-medium" v-text="gameImportSourceStatusLabel(source)"></span>
                                                                <a
                                                                    class="break-all text-amber-800 underline decoration-amber-300 underline-offset-2 hover:text-amber-950"
                                                                    :href="source.url"
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    v-text="source.url"
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

                            <div
                                v-show="gameImports.pagination.total > gameImports.pagination.perPage"
                                
                                class="flex items-center justify-between gap-3 pt-1.5"
                            >
                                <button
                                    type="button"
                                    class="inline-flex size-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!canLoadPreviousGameImportPage() || gameImports.loading"
                                    @click="loadPreviousGameImportPage()"
                                    aria-label="Previous orchestration page"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd" />
                                    </svg>
                                </button>

                                <div class="min-w-0 text-center text-[11px] text-gray-500" v-text="gameImportPaginationText()"></div>

                                <button
                                    type="button"
                                    class="inline-flex size-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="!canLoadNextGameImportPage() || gameImports.loading"
                                    @click="loadNextGameImportPage()"
                                    aria-label="Next orchestration page"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="gameImports.drawerOpen" class="fixed inset-0 z-50 overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="game-import-drawer-title">
                    <div class="absolute inset-0 bg-gray-900/50" @click="closeGameImportDrawer()"></div>
                    <div class="absolute inset-y-0 right-0 flex w-full max-w-lg bg-white shadow-xl">
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
                            <div>
                                <label for="game-import-date" class="block text-sm font-medium text-gray-700">Single date</label>
                                <input id="game-import-date" v-model="gameImports.form.date" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="game-import-start" class="block text-sm font-medium text-gray-700">Start date</label>
                                    <input id="game-import-start" v-model="gameImports.form.start" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label for="game-import-end" class="block text-sm font-medium text-gray-700">End date</label>
                                    <input id="game-import-end" v-model="gameImports.form.end" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="game-import-days" class="block text-sm font-medium text-gray-700">Days</label>
                                    <input id="game-import-days" v-model="gameImports.form.days" type="number" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label for="game-import-newdays" class="block text-sm font-medium text-gray-700">New days</label>
                                    <input id="game-import-newdays" v-model="gameImports.form.newdays" type="number" min="1" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>

                            <div>
                                <label for="game-import-season" class="block text-sm font-medium text-gray-700">Season</label>
                                <input id="game-import-season" v-model="gameImports.form.season" type="text" inputmode="numeric" placeholder="20252026" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
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
                                    <span v-text="gameImports.discovering ? 'Queuing...' : 'Discover'"></span>
                                </button>
                            </div>
                        </div>
                    </form>
                    </div>
                </div>
            </div>

            <div v-show="activeTab === 'triage'" >
                <div
                    ref="triageMount"
                    data-admin-triage-mount
                    class="min-h-64"
                >
                    <div
                        v-show="triageLoading"
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
                        v-show="triageError"
                        class="border-y border-gray-200 bg-white px-4 py-6 text-sm text-red-600"
                        v-text="triageError"
                    ></div>
                </div>
            </div>

            <div v-show="activeTab === 'validations'" >
                <div
                    ref="validationsMount"
                    data-admin-validations-mount
                    data-admin-validation-scope="validations"
                    class="min-h-64"
                >
                    <div
                        v-show="validationsLoading"
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
                        v-show="validationsError"
                        class="border-y border-gray-200 bg-white px-4 py-6 text-sm text-red-600"
                        v-text="validationsError"
                    ></div>
                </div>
            </div>

            <div v-show="activeTab === 'shift-mismatches'" >
                <div
                    ref="shiftMismatchesMount"
                    data-admin-shift-mismatches-mount
                    data-admin-validation-scope="shift-mismatches"
                    class="min-h-64"
                >
                    <div
                        v-show="shiftMismatchesLoading"
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
                        v-show="shiftMismatchesError"
                        class="border-y border-gray-200 bg-white px-4 py-6 text-sm text-red-600"
                        v-text="shiftMismatchesError"
                    ></div>
                </div>
            </div>

        </div>
    </div>
</div>

    </div>
</template>
