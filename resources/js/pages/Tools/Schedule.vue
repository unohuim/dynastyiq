<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({ schedule: { type: Object, required: true } });
const activeTab = ref('weekly');
const tabs = [{ key: 'weekly', label: 'Weekly' }, { key: 'off-nights', label: 'Off Nights' }];
const loading = ref(false);
const error = ref('');
const offNightColumns = [
    { key: 'team', label: 'Team' },
    { key: 'this_week_gp', label: 'This Week' },
    { key: 'next_two_weeks_gp', label: 'Next 2 Weeks' },
    { key: 'this_month_gp', label: 'This Month' },
    { key: 'off_night_gp', label: 'Season' },
];
const sortKey = ref('off_night_gp');
const sortDescending = ref(true);
const offNightTeams = computed(() => [...props.schedule.off_nights.teams].sort((a, b) => {
    const alphabetical = (a.abbrev || a.name).localeCompare(b.abbrev || b.name, 'en', { sensitivity: 'base' });
    if (sortKey.value === 'team') return sortDescending.value ? -alphabetical : alphabetical;
    const left = a[sortKey.value];
    const right = b[sortKey.value];
    if (left == null || right == null) return left == null && right == null ? alphabetical : left == null ? 1 : -1;
    const difference = sortDescending.value ? right - left : left - right;
    return difference || alphabetical;
}));

function sortOffNights(key) {
    sortDescending.value = sortKey.value === key ? !sortDescending.value : key !== 'team';
    sortKey.value = key;
}

function sortLabel(column) {
    const descending = sortKey.value === column.key ? !sortDescending.value : column.key !== 'team';
    return `Sort ${column.label} ${descending ? 'descending' : 'ascending'}`;
}

function changeTab(event, index) {
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
        : event.key === 'ArrowRight' ? (index + 1) % tabs.length
            : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : null;
    if (next === null) return;
    event.preventDefault();
    activeTab.value = tabs[next].key;
    event.currentTarget.parentElement.querySelectorAll('[role=tab]')[next].focus();
}

const weekCache = new Map();
const easternCalendar = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit' });
let pollTimer;
let pollAttempts = 0;
let disposed = false;

function cacheKey(url) {
    return new URL(url, 'https://dynastyiq.invalid').searchParams.get('week') || 'current';
}

function pollPreparation() {
    clearTimeout(pollTimer);
    if (disposed || props.schedule.prepared !== false) return;
    if (pollAttempts >= 30) {
        if (!props.schedule.teams.length) error.value = 'Schedule preparation is taking longer than expected. Try this week again shortly.';
        return;
    }
    pollTimer = setTimeout(() => {
        if (loading.value) { pollPreparation(); return; }
        pollAttempts++;
        router.reload({ only: ['schedule'], preserveState: true, preserveScroll: true, onFinish: pollPreparation });
    }, 2000);
}

watch(() => props.schedule, snapshot => {
    if (snapshot.prepared !== false && snapshot.week_start) {
        const entry = { payload: snapshot, at: Date.now(), offBuild: snapshot.off_nights.built_at, day: easternCalendar.format(new Date()) };
        weekCache.set(snapshot.week_start, entry);
        if (snapshot.is_current_week) weekCache.set('current', entry);
        while (weekCache.size > 16) weekCache.delete(weekCache.keys().next().value);
        pollAttempts = 0;
    }
    pollPreparation();
}, { immediate: true });

onBeforeUnmount(() => { disposed = true; clearTimeout(pollTimer); weekCache.clear(); });

function navigate(url) {
    if (loading.value) return;
    error.value = '';
    const cached = weekCache.get(cacheKey(url));
    if (cached && cached.day === easternCalendar.format(new Date()) && Date.now() - cached.at < 300000 && cached.offBuild === props.schedule.off_nights.built_at) {
        router.push({ url, props: current => ({ ...current, schedule: cached.payload }), preserveState: true, preserveScroll: true });
        return;
    }
    pollAttempts = 0;
    router.get(url, {}, {
        only: ['schedule'],
        preserveState: true, preserveScroll: true,
        onStart: () => { loading.value = true; error.value = ''; },
        onError: () => { error.value = 'Unable to load this week. Please try again.'; },
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <main class="mx-auto flex h-full min-h-0 w-full max-w-7xl flex-col overflow-hidden px-2 py-5 min-[420px]:px-3 sm:px-6 sm:py-7" :aria-busy="loading || (schedule.prepared === false && !schedule.teams.length)">
        <header class="flex shrink-0 flex-wrap items-center justify-between gap-4">
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
        <div class="relative mt-4 shrink-0">
            <div role="tablist" aria-label="Schedule view" class="flex gap-1 border-b border-gray-200">
                <button v-for="(tab, index) in tabs" :id="`schedule-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :aria-selected="activeTab === tab.key" :aria-controls="`schedule-panel-${tab.key}`" :tabindex="activeTab === tab.key ? 0 : -1" class="min-h-11 border-b-2 px-4 text-sm font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" :class="activeTab === tab.key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800'" @click="activeTab = tab.key" @keydown="changeTab($event, index)">{{ tab.label }}</button>
            </div>
            <div class="absolute bottom-3 right-0 max-w-[40%] truncate text-right text-xs" :title="error || undefined" role="status" aria-live="polite">
                <span v-if="error" class="text-red-700">{{ error }}</span>
                <span v-else-if="loading" class="text-gray-500">Loading schedule…</span>
                <span v-else-if="schedule.prepared === false && !schedule.teams.length" class="text-gray-500">Preparing schedule…</span>
            </div>
        </div>
        <section v-if="activeTab === 'weekly'" id="schedule-panel-weekly" role="tabpanel" class="flex min-h-0 flex-1 flex-col" aria-labelledby="schedule-tab-weekly">
            <div class="min-h-0 max-w-full flex-1 overscroll-y-none [overflow-anchor:none] overflow-x-hidden overflow-y-auto lg:overflow-x-auto rounded-lg border border-gray-200 bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" role="region" aria-label="Weekly NHL team schedule" tabindex="0">
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
        <section v-else id="schedule-panel-off-nights" role="tabpanel" class="flex min-h-0 flex-1 flex-col" aria-labelledby="schedule-tab-off-nights">
            <div class="min-h-0 max-w-full flex-1 overscroll-y-none [overflow-anchor:none] overflow-x-hidden overflow-y-auto rounded-lg border border-gray-200 bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" role="region" aria-label="Off-night NHL team schedule" tabindex="0">
                <table class="w-full table-fixed border-collapse text-left text-[9px] min-[420px]:text-[10px] sm:text-xs lg:text-sm">
                    <caption class="sr-only">{{ schedule.off_nights.label }}. Each team's remaining off-night count.</caption>
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50 text-[8px] text-gray-600 min-[420px]:text-[9px] sm:text-[11px] lg:text-xs">
                            <th v-for="column in offNightColumns" :key="column.key" scope="col" class="sticky top-0 z-20 bg-gray-50 px-1 py-1 font-normal sm:px-3" :class="column.key === 'team' ? 'text-left' : 'text-right'" :aria-sort="sortKey === column.key ? (sortDescending ? 'descending' : 'ascending') : undefined">
                                <button type="button" class="inline-flex min-h-6 max-w-full items-center gap-0.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" :aria-label="sortLabel(column)" :title="schedule.off_nights.period_ends?.[column.key] ? `${schedule.off_nights.from_date} through ${schedule.off_nights.period_ends[column.key]} (inclusive)` : undefined" @click="sortOffNights(column.key)"><span>{{ column.label }}</span><svg v-if="sortKey === column.key" aria-hidden="true" class="h-3 w-3 shrink-0 transition-transform duration-150 motion-reduce:transition-none" :class="sortDescending ? '' : 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-5-5 5 5 5-5" /></svg></button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="team in offNightTeams" :key="team.id">
                            <th scope="row" class="px-1 py-1 font-normal sm:px-3" :title="team.name">{{ team.abbrev || team.name }}</th>
                            <td v-for="column in offNightColumns.slice(1)" :key="column.key" class="px-1 py-1 text-right font-normal tabular-nums text-gray-900 sm:px-3">{{ team[column.key] ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 shrink-0 text-xs text-gray-600">Off nights: 50% or fewer league teams playing that day (8 or fewer games in a 32-team league).</p>
            <p v-if="schedule.prepared !== false && !schedule.off_nights.game_count" class="mt-4 shrink-0 text-sm text-gray-500">No remaining games available for this season.</p>
        </section>
        <p v-if="schedule.prepared !== false && activeTab === 'weekly' && (!schedule.teams.length || !schedule.game_count)" class="mt-4 shrink-0 text-sm text-gray-500">No games available for this week.</p>
        <p v-if="activeTab === 'weekly'" class="mt-3 shrink-0 text-xs text-gray-500">{{ schedule.teams.length }} teams · {{ schedule.game_count }} games · All times Eastern · @ Away · vs Home</p>
        <p v-else class="mt-3 shrink-0 text-xs text-gray-500">{{ schedule.off_nights.teams.length }} teams · {{ schedule.off_nights.game_count }} remaining games · @ Away · vs Home</p>
    </main>
</template>
