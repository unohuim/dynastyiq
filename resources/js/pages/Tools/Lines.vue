<script setup>
import { ref } from 'vue';

defineProps({ lines: { type: Object, required: true } });
const expanded = ref(new Set());
const strengths = [{ key: 'EV', label: 'Even Strength' }, { key: 'PP', label: 'Power Play' }, { key: 'PK', label: 'Penalty Kill' }];
const groups = [{ key: 'F', label: 'Forwards', emptyLabel: 'forward' }, { key: 'D', label: 'Defense', emptyLabel: 'defensive' }];
const unitGroups = [{ key: 'units', label: 'Units', emptyLabel: 'unit' }];
const spotColumns = { LW: 'col-start-1 col-span-2', C: 'col-start-3 col-span-2', RW: 'col-start-5 col-span-2', LD: 'col-start-2 col-span-2', RD: 'col-start-4 col-span-2' };

function toggle(id) {
    const next = new Set(expanded.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    expanded.value = next;
}
</script>

<template>
    <main class="mx-auto w-full max-w-7xl px-3 py-5 sm:px-6 sm:py-7">
        <header class="mb-5">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Tools</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900">Lines</h1>
            <p class="mt-1 text-sm text-gray-600">Each team's latest completed game · Ranked by shared time on ice</p>
        </header>
        <p v-if="!lines.teams.length" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-500">No teams available for this season.</p>
        <div v-else class="space-y-2">
            <section v-for="team in lines.teams" :key="team.id" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <h2>
                    <button :id="`lines-toggle-${team.id}`" type="button" class="flex min-h-14 w-full items-center gap-3 px-3 py-2 text-left transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 motion-reduce:transition-none sm:px-4" :aria-expanded="expanded.has(team.id)" :aria-controls="`lines-panel-${team.id}`" @click="toggle(team.id)">
                        <img v-if="team.logo" :src="team.logo" alt="" class="h-9 w-9 shrink-0 object-contain" loading="lazy" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-900">{{ team.name }}</span>
                            <span v-if="team.game" class="block text-[11px] text-gray-500">{{ team.game.date_label }} · {{ team.game.venue === 'home' ? 'vs' : '@' }} {{ team.game.opponent }}</span>
                            <span v-else class="block text-[11px] text-gray-500">No completed game this season</span>
                        </span>
                        <span class="text-xs text-gray-500">{{ team.abbrev }}</span>
                        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="expanded.has(team.id) ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
                    </button>
                </h2>
                <div :id="`lines-panel-${team.id}`" role="region" :aria-labelledby="`lines-toggle-${team.id}`" :aria-hidden="!expanded.has(team.id)" :inert="!expanded.has(team.id) || undefined" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="expanded.has(team.id) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
                    <div class="min-h-0 overflow-hidden">
                        <div class="border-t border-gray-100 px-3 py-3 sm:px-4">
                            <template v-if="team.game">
                                <div class="mb-3 flex items-center justify-between gap-3 text-xs text-gray-500">
                                    <span>Shared TOI · Players appear once per strength</span>
                                    <a :href="team.game.href" class="shrink-0 rounded text-indigo-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">View game</a>
                                </div>
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <section v-for="strength in strengths" :key="strength.key" :aria-label="`${team.abbrev} ${strength.label}`" class="min-w-0 rounded-md border border-gray-200">
                                        <h3 class="border-b border-gray-200 bg-gray-50 px-3 py-2 text-xs font-medium text-gray-700">{{ strength.label }} <span class="ml-1 text-[10px] text-gray-500">{{ strength.key }}</span></h3>
                                        <div v-for="group in strength.key === 'EV' ? groups : unitGroups" :key="group.key" class="px-3 py-2">
                                            <h4 class="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">{{ group.label }}</h4>
                                            <ol v-if="team.strengths[strength.key][group.key].length" class="divide-y divide-gray-100">
                                                <li v-for="(line, index) in team.strengths[strength.key][group.key]" :key="line.unit_id" class="py-2 text-xs">
                                                    <div class="mb-2 flex items-center justify-between gap-2">
                                                        <span class="text-[10px] text-gray-400">{{ group.key === 'units' ? strength.key : group.key }}{{ index + 1 }}</span>
                                                        <span class="tabular-nums text-gray-600" :aria-label="`${line.toi} shared time on ice`">{{ line.toi }}</span>
                                                    </div>
                                                    <div class="grid grid-cols-6 gap-x-1 gap-y-2" title="Display positions inferred from game faceoffs and listed positions">
                                                        <span v-for="player in line.players" :key="player.id" :data-slot="player.slot" :title="player.name" class="min-w-0 text-center" :class="[spotColumns[player.slot], ['LD', 'RD'].includes(player.slot) && group.key !== 'D' ? 'row-start-2' : 'row-start-1']">
                                                            <span class="block text-[9px] text-gray-400">{{ player.slot }}</span>
                                                            <span class="block truncate text-[11px] leading-5 text-gray-800">{{ player.short_name }}</span>
                                                        </span>
                                                    </div>
                                                </li>
                                            </ol>
                                            <p v-else class="py-2 text-xs text-gray-500">No {{ group.emptyLabel }} combinations available.</p>
                                        </div>
                                    </section>
                                </div>
                            </template>
                            <p v-else class="text-sm text-gray-500">No completed game available for this team this season.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</template>
