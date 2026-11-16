<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({ schedule: { type: Object, required: true } });
const activeTab = ref('weekly');
const tabs = [{ key: 'weekly', label: 'Weekly' }, { key: 'off-nights', label: 'Off Nights' }];
const loading = ref(false);
const error = ref('');
const totalsDescending = ref(true);
const offNightTeams = computed(() => [...props.schedule.off_nights.teams].sort((a, b) => {
    const difference = totalsDescending.value ? b.off_night_gp - a.off_night_gp : a.off_night_gp - b.off_night_gp;
    return difference || (a.abbrev || a.name).localeCompare(b.abbrev || b.name, 'en', { sensitivity: 'base' });
}));

function changeTab(event, index) {
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
        : event.key === 'ArrowRight' ? (index + 1) % tabs.length
            : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : null;
    if (next === null) return;
    event.preventDefault();
    activeTab.value = tabs[next].key;
    event.currentTarget.parentElement.querySelectorAll('[role=tab]')[next].focus();
}

function navigate(url) {
    if (loading.value) return;
    router.get(url, {}, {
        preserveState: true, preserveScroll: true,
        onStart: () => { loading.value = true; error.value = ''; },
        onError: () => { error.value = 'Unable to load this week. Please try again.'; },
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <main class="mx-auto w-full max-w-7xl px-2 py-5 min-[420px]:px-3 sm:px-6 sm:py-7" :aria-busy="loading">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Tools</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900">Schedule</h1>
                <p class="mt-1 text-sm text-gray-600">{{ activeTab === 'weekly' ? schedule.label : schedule.off_nights.label }}</p>
            </div>
            <nav v-if="activeTab === 'weekly'" aria-label="Schedule week" class="flex items-center gap-0 rounded-md border border-gray-200 bg-white p-0.5">
                <button type="button" aria-label="Previous week" :disabled="loading" class="flex h-7 w-7 items-center justify-center rounded-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="navigate(schedule.previous_url)">
                    <svg aria-hidden="true" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                </button>
                <button type="button" :disabled="loading || schedule.is_current_week" class="h-7 rounded px-2 text-[11px] font-medium text-gray-700 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:text-gray-400 motion-reduce:transition-none" @click="navigate(schedule.current_url)">This Week</button>
                <button type="button" aria-label="Next week" :disabled="loading" class="flex h-7 w-7 items-center justify-center rounded-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="navigate(schedule.next_url)">
                    <svg aria-hidden="true" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
                </button>
            </nav>
        </header>
        <div role="tablist" aria-label="Schedule view" class="mt-4 flex gap-1 border-b border-gray-200">
            <button v-for="(tab, index) in tabs" :id="`schedule-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :aria-selected="activeTab === tab.key" :aria-controls="`schedule-panel-${tab.key}`" :tabindex="activeTab === tab.key ? 0 : -1" class="min-h-11 border-b-2 px-4 text-sm font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" :class="activeTab === tab.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'" @click="activeTab = tab.key" @keydown="changeTab($event, index)">{{ tab.label }}</button>
        </div>
        <div class="mt-3 h-5 text-xs" role="status" aria-live="polite">
            <span v-if="error" class="text-red-700">{{ error }}</span>
            <span v-else-if="loading" class="text-gray-500">Loading schedule…</span>
        </div>
        <section v-if="activeTab === 'weekly'" id="schedule-panel-weekly" role="tabpanel" aria-labelledby="schedule-tab-weekly">
            <div class="mt-2 max-h-[60dvh] max-w-full overflow-x-hidden overflow-y-auto lg:overflow-x-auto rounded-lg border border-gray-200 bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" role="region" aria-label="Weekly NHL team schedule" tabindex="0">
                <table class="w-full table-fixed border-collapse text-center text-[9px] leading-tight min-[420px]:text-[10px] sm:text-xs lg:min-w-[920px] lg:table-auto lg:text-left lg:text-sm">
                    <caption class="sr-only">{{ schedule.label }}. Teams by Monday through Sunday, followed by total games played or scheduled.</caption>
                    <colgroup>
                        <col class="w-[10%] lg:w-44" />
                        <col v-for="day in schedule.days" :key="day.date" />
                        <col class="w-[7%] lg:w-20" />
                    </colgroup>
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-gray-600">
                            <th scope="col" class="sticky top-0 z-30 bg-gray-50 px-0.5 py-2 text-[8px] font-semibold min-[420px]:text-[9px] sm:text-[11px] lg:left-0 lg:px-3 lg:py-3 lg:text-xs">Team</th>
                            <th v-for="day in schedule.days" :key="day.date" scope="col" class="sticky top-0 z-20 px-0.5 py-2 text-[8px] font-semibold min-[420px]:text-[9px] sm:text-[11px] lg:min-w-24 lg:px-3 lg:py-3 lg:text-xs" :class="day.is_off_night ? 'bg-gray-50' : 'bg-gray-200'">
                                <span class="block">{{ day.label }}</span><span class="mt-0.5 block text-[7px] font-normal min-[420px]:text-[8px] sm:text-[10px] lg:text-[11px]">{{ day.display_date }}</span>
                            </th>
                            <th scope="col" class="sticky top-0 z-30 bg-gray-50 px-0.5 py-2 text-center text-[8px] font-semibold min-[420px]:text-[9px] sm:text-[11px] lg:right-0 lg:px-3 lg:py-3 lg:text-xs">Total GP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="team in schedule.teams" :key="team.id" class="group">
                            <th scope="row" class="bg-white px-0.5 py-1 text-gray-800 lg:sticky lg:left-0 lg:z-10 lg:px-3">
                                <div class="flex items-center justify-center lg:justify-start">
                                    <span class="truncate font-semibold" :title="team.name">{{ team.abbrev || team.name }}</span>
                                    <span class="sr-only">{{ team.name }}</span>
                                </div>
                            </th>
                            <td v-for="day in schedule.days" :key="day.date" class="px-0.5 py-1 align-middle lg:px-3" :class="day.is_off_night ? 'bg-white' : 'bg-gray-100'">
                                <ul v-if="team.days[day.date]?.length" class="space-y-1">
                                    <li v-for="game in team.days[day.date]" :key="game.id">
                                        <a :href="game.href" class="inline-flex min-h-6 w-full min-w-0 flex-col lg:w-auto justify-center rounded text-gray-800 transition-colors duration-150 hover:text-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" :aria-label="`${team.abbrev} ${game.venue === 'home' ? 'home versus' : 'away at'} ${game.opponent}, ${day.label} ${day.display_date}, ${game.status || game.time} Eastern`">
                                            <span class="whitespace-nowrap font-medium"><span class="mr-0.5 text-[8px] font-normal min-[420px]:text-[9px] lg:mr-1 lg:text-xs text-gray-400">{{ game.venue === 'home' ? 'vs' : '@' }}</span>{{ game.opponent }}</span>
                                            <span class="mt-0.5 break-words text-[7px] min-[420px]:text-[8px] sm:text-[10px] lg:text-[11px]" :class="game.status ? 'text-amber-700' : 'text-gray-500'">{{ game.status || game.time }}</span>
                                        </a>
                                    </li>
                                </ul>
                                <template v-else><span aria-hidden="true" class="text-gray-300">—</span><span class="sr-only">No game</span></template>
                            </td>
                            <td class="bg-white px-0.5 py-1 lg:sticky lg:right-0 lg:z-10 lg:px-3 text-center font-semibold tabular-nums text-gray-900">{{ team.total_gp }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section v-else id="schedule-panel-off-nights" role="tabpanel" aria-labelledby="schedule-tab-off-nights">
            <p class="mb-3 text-sm text-gray-600">Off nights: 50% or fewer league teams playing that day (8 or fewer games in a 32-team league).</p>
            <div class="max-h-[60dvh] max-w-full overflow-x-hidden overflow-y-auto rounded-lg border border-gray-200 bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" role="region" aria-label="Off-night NHL team schedule" tabindex="0">
                <table class="w-full border-collapse text-left text-sm">
                    <caption class="sr-only">{{ schedule.off_nights.label }}. Each team's remaining off-night count.</caption>
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-xs text-gray-600">
                            <th scope="col" class="sticky top-0 z-20 bg-gray-50 px-3 py-2">Team</th>
                            <th scope="col" class="sticky top-0 z-20 bg-gray-50 px-3 py-1 text-right" :aria-sort="totalsDescending ? 'descending' : 'ascending'"><button type="button" class="inline-flex min-h-6 items-center gap-1 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" :aria-label="totalsDescending ? 'Sort total ascending' : 'Sort total descending'" @click="totalsDescending = !totalsDescending">Total<svg aria-hidden="true" class="h-3 w-3 transition-transform duration-150 motion-reduce:transition-none" :class="totalsDescending ? '' : 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-5-5 5 5 5-5" /></svg></button></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="team in offNightTeams" :key="team.id">
                            <th scope="row" class="px-3 py-1.5 font-semibold" :title="team.name">{{ team.abbrev || team.name }}</th>
                            <td class="px-3 py-1.5 text-right font-semibold tabular-nums text-gray-900">{{ team.off_night_gp }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="!schedule.off_nights.game_count" class="mt-4 text-sm text-gray-500">No remaining games available for this season.</p>
        </section>
        <p v-if="activeTab === 'weekly' && (!schedule.teams.length || !schedule.game_count)" class="mt-4 text-sm text-gray-500">No games available for this week.</p>
        <p v-if="activeTab === 'weekly'" class="mt-3 text-xs text-gray-500">{{ schedule.teams.length }} teams · {{ schedule.game_count }} games · All times Eastern · @ Away · vs Home</p>
        <p v-else class="mt-3 text-xs text-gray-500">{{ schedule.off_nights.teams.length }} teams · {{ schedule.off_nights.game_count }} remaining games · @ Away · vs Home</p>
    </main>
</template>
