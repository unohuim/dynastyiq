<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({ schedule: { type: Object, required: true } });
const loading = ref(false);
const error = ref('');

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
    <main class="mx-auto w-full max-w-7xl px-3 py-5 sm:px-6 sm:py-7" :aria-busy="loading">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Tools</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900">Schedule</h1>
                <p class="mt-1 text-sm text-gray-600">{{ schedule.label }}</p>
            </div>
            <nav aria-label="Schedule week" class="flex items-center gap-1 rounded-lg border border-gray-200 bg-white p-1">
                <button type="button" aria-label="Previous week" :disabled="loading" class="flex h-11 w-11 items-center justify-center rounded-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="navigate(schedule.previous_url)">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
                </button>
                <button type="button" :disabled="loading || schedule.is_current_week" class="h-11 rounded-md px-3 text-sm font-medium text-gray-700 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:text-gray-400 motion-reduce:transition-none" @click="navigate(schedule.current_url)">This Week</button>
                <button type="button" aria-label="Next week" :disabled="loading" class="flex h-11 w-11 items-center justify-center rounded-md text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40 motion-reduce:transition-none" @click="navigate(schedule.next_url)">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
                </button>
            </nav>
        </header>
        <div class="mt-3 h-5 text-xs" role="status" aria-live="polite">
            <span v-if="error" class="text-red-700">{{ error }}</span>
            <span v-else-if="loading" class="text-gray-500">Loading schedule…</span>
        </div>
        <div class="mt-2 max-w-full overflow-x-auto rounded-lg border border-gray-200 bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" role="region" aria-label="Weekly NHL team schedule" tabindex="0">
            <table class="w-full min-w-[920px] border-collapse text-left text-sm">
                <caption class="sr-only">{{ schedule.label }}. Teams by Monday through Sunday, followed by total games played or scheduled.</caption>
                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50 text-gray-600">
                        <th scope="col" class="sticky left-0 z-20 w-36 bg-gray-50 px-3 py-3 text-xs font-semibold sm:w-44">Team</th>
                        <th v-for="day in schedule.days" :key="day.date" scope="col" class="min-w-24 px-3 py-3 text-xs font-semibold" :class="day.is_today ? 'bg-indigo-50 text-indigo-700' : ''">
                            <span class="block">{{ day.label }}</span><span class="mt-0.5 block text-[11px] font-normal">{{ day.display_date }}</span>
                        </th>
                        <th scope="col" class="sticky right-0 z-20 w-20 bg-gray-50 px-3 py-3 text-center text-xs font-semibold">Total GP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="team in schedule.teams" :key="team.id" class="group">
                        <th scope="row" class="sticky left-0 z-10 bg-white px-3 py-2 text-gray-800">
                            <div class="flex min-h-10 items-center gap-2">
                                <img v-if="team.logo" :src="team.logo" alt="" loading="lazy" class="h-8 w-8 shrink-0 object-contain" />
                                <span class="truncate font-semibold" :title="team.name">{{ team.abbrev || team.name }}</span>
                                <span class="sr-only">{{ team.name }}</span>
                            </div>
                        </th>
                        <td v-for="day in schedule.days" :key="day.date" class="px-3 py-2 align-middle" :class="day.is_today ? 'bg-indigo-50/40' : ''">
                            <ul v-if="team.days[day.date]?.length" class="space-y-2">
                                <li v-for="game in team.days[day.date]" :key="game.id">
                                    <a :href="game.href" class="inline-flex min-h-10 flex-col justify-center rounded text-gray-800 transition-colors duration-150 hover:text-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" :aria-label="`${team.abbrev} ${game.venue === 'home' ? 'home versus' : 'away at'} ${game.opponent}, ${day.label} ${day.display_date}, ${game.status || game.time} Eastern`">
                                        <span class="whitespace-nowrap font-medium"><span class="mr-1 text-xs font-normal text-gray-400">{{ game.venue === 'home' ? 'vs' : '@' }}</span>{{ game.opponent }}</span>
                                        <span class="mt-0.5 text-[11px]" :class="game.status ? 'text-amber-700' : 'text-gray-500'">{{ game.status || game.time }}</span>
                                    </a>
                                </li>
                            </ul>
                            <template v-else><span aria-hidden="true" class="text-gray-300">—</span><span class="sr-only">No game</span></template>
                        </td>
                        <td class="sticky right-0 z-10 bg-white px-3 py-2 text-center font-semibold tabular-nums text-gray-900">{{ team.total_gp }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="!schedule.teams.length || !schedule.game_count" class="mt-4 text-sm text-gray-500">No games available for this week.</p>
        <p class="mt-3 text-xs text-gray-500">{{ schedule.teams.length }} teams · {{ schedule.game_count }} games · All times Eastern · @ Away · vs Home</p>
    </main>
</template>
