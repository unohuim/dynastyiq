<script setup>
import { computed, ref, useId, watch } from 'vue';

const props = defineProps({
    title: { type: String, required: true }, rows: { type: Array, default: () => [] },
    tabs: { type: Array, required: true }, href: { type: String, default: '/stats' },
    entity: { type: String, default: 'Player' }, empty: { type: String, default: 'No completed stats are available yet.' },
    hideGp: { type: Boolean, default: false }, columns: { type: Array, default: () => [] },
});
const selected = ref(props.tabs[0]?.key);
const tabId = useId();
const sortKey = ref('metric');
const reversed = ref(false);
const tab = computed(() => props.tabs.find(item => item.key === selected.value) ?? props.tabs[0]);
const extras = computed(() => props.columns.filter(column => column.key !== selected.value));
const rows = computed(() => [...props.rows].filter(row => Number.isFinite(row.stats?.[selected.value]))
    .sort((a, b) => {
        const key = sortKey.value === 'metric' ? selected.value : sortKey.value;
        const order = key === 'name' ? a.name.localeCompare(b.name)
            : key === 'gp' ? b.gp - a.gp
                : (sortKey.value === 'metric' && tab.value.low ? 1 : -1) * ((a.stats[key] ?? -Infinity) - (b.stats[key] ?? -Infinity));
        return (reversed.value ? -order : order) || String(a.id).localeCompare(String(b.id));
    }).slice(0, 5));
watch(selected, () => { sortKey.value = 'metric'; reversed.value = false; });
const sort = key => { reversed.value = sortKey.value === key ? !reversed.value : false; sortKey.value = key; };
const formatted = (value, column = tab.value) => !Number.isFinite(value) ? '—' : column.format === 'time'
    ? `${Math.floor(value / 60)}:${String(Math.floor(value % 60)).padStart(2, '0')}`
    : column.format === 'save' ? (value / 100).toFixed(3).replace(/^0/, '')
        : column.format === 'percent' ? `${value.toFixed(1)}%`
            : column.format === 'decimal' ? value.toFixed(2) : String(Math.round(value));
const ariaSort = key => sortKey.value !== key ? 'none'
    : ((key === 'name' || (key === 'metric' && tab.value.low)) !== reversed.value ? 'ascending' : 'descending');
function navigate(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const index = props.tabs.findIndex(item => item.key === selected.value);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? props.tabs.length - 1
        : (index + (event.key === 'ArrowRight' ? 1 : -1) + props.tabs.length) % props.tabs.length;
    selected.value = props.tabs[next].key;
    event.currentTarget.parentElement.querySelectorAll('button')[next].focus();
}
</script>

<template>
    <section class="flex min-h-0 min-w-0 flex-col rounded-lg border border-[#143144] bg-[#041724] p-3 text-slate-100">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h2 class="text-xs font-bold">{{ title }}</h2>
            <a :href="href" class="shrink-0 rounded text-[10px] text-sky-400 hover:text-sky-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400">View all ›</a>
        </div>
        <div role="tablist" :aria-label="`${title} metric`" class="mb-1 grid grid-flow-col auto-cols-fr gap-1">
            <button v-for="item in tabs" :key="item.key" :id="`${tabId}-${item.key}`" :aria-controls="`${tabId}-panel`" type="button" role="tab" :aria-selected="selected === item.key" :tabindex="selected === item.key ? 0 : -1"
                class="min-h-7 rounded px-1 text-[10px] transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none"
                :class="selected === item.key ? 'bg-blue-600 text-white' : 'bg-[#0b2537] text-slate-300 hover:bg-[#12364b]'" @click="selected = item.key" @keydown="navigate">{{ item.label }}</button>
        </div>
        <div :id="`${tabId}-panel`" role="tabpanel" :aria-labelledby="`${tabId}-${selected}`" class="min-h-0 flex-1 overflow-auto">
            <table v-if="rows.length" class="w-full table-fixed text-left text-[10px] tabular-nums">
                <thead class="border-b border-[#143144] text-[9px] font-normal text-slate-400"><tr>
                    <th class="w-4 py-1 font-normal">#</th>
                    <th :aria-sort="ariaSort('name')" class="font-normal"><button type="button" class="text-left hover:text-white" @click="sort('name')">{{ entity }}</button></th>
                    <th v-if="!hideGp" :aria-sort="ariaSort('gp')" class="w-6 text-right font-normal"><button type="button" class="hover:text-white" @click="sort('gp')">GP</button></th>
                    <th :aria-sort="ariaSort('metric')" class="text-right font-normal" :class="extras.length ? 'w-9' : 'w-14'"><button type="button" class="hover:text-white" @click="sort('metric')">{{ tab.heading || tab.label }}</button></th>
                    <th v-for="column in extras" :key="column.key" :aria-sort="ariaSort(column.key)" class="w-9 text-right font-normal"><button type="button" class="hover:text-white" @click="sort(column.key)">{{ column.label }}</button></th>
                </tr></thead>
                <tbody><tr v-for="(row, index) in rows" :key="row.id" class="h-7 border-b border-[#123043]/60 last:border-0">
                    <td class="text-slate-400">{{ index + 1 }}</td>
                    <td><a :href="row.url" :target="row.external ? '_blank' : undefined" :rel="row.external ? 'noopener noreferrer' : undefined" class="flex min-w-0 items-center gap-1.5 rounded hover:text-sky-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400" :title="row.name"><img v-if="row.logo || row.avatar" :src="row.logo || row.avatar" alt="" class="h-5 w-5 shrink-0 object-contain" loading="lazy" /><span class="truncate">{{ row.name }}</span></a></td>
                    <td v-if="!hideGp" class="text-right text-slate-400">{{ row.gp ?? '—' }}</td>
                    <td class="text-right font-semibold">{{ formatted(row.stats[selected]) }}</td>
                    <td v-for="column in extras" :key="column.key" class="text-right" :class="column.key === 'corsi' && row.stats.corsi < 0 ? 'text-red-400' : 'text-slate-300'">{{ formatted(row.stats[column.key], column) }}</td>
                </tr></tbody>
            </table>
            <p v-else class="flex min-h-32 items-center justify-center px-2 text-center text-xs text-slate-400">{{ empty }}</p>
        </div>
    </section>
</template>
