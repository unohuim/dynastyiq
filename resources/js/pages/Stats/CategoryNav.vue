<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';
const props = defineProps({ links: { type: Array, required: true }, view: { type: String, required: true } });
defineEmits(['start', 'finish', 'failed']);
const open = ref(false);
const toggle = ref(null);
const menuId = useId();
const activeLabel = computed(() => props.links.find(item => item.view === props.view)?.label || 'Statistics');
watch(() => props.view, () => { open.value = false; });
const close = () => { open.value = false; toggle.value?.focus(); };
const tableViews = ['skaters', 'goalies', 'prospects'];
const tableProps = ['view', 'selectedSeason', 'leaders', 'initialPayload', 'apiUrl', 'connectedLeagues', 'perspectives', 'selectedPerspective', 'mobileBreakpoint', 'statsCacheMissing'];
</script>

<template>
    <nav aria-label="Statistics categories" :class="view === 'leaders' ? 'flex min-w-0 shrink-0 overflow-x-auto border-b-2 border-amber-400/25 bg-[#03131f]/75 px-3' : 'relative min-w-0 shrink-0 border-b border-[#143144] bg-[#03131f] sm:flex sm:overflow-x-auto sm:border-b-2 sm:border-amber-400/25 sm:px-3'" @keydown.esc.stop.prevent="close" @focusout="event => { if (!event.currentTarget.contains(event.relatedTarget)) open = false; }">
        <template v-if="view !== 'leaders'">
            <button ref="toggle" type="button" class="flex h-11 w-full items-center justify-between gap-2 px-3 text-sm font-semibold text-white focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-400 sm:hidden" :aria-expanded="open" :aria-controls="menuId" @click="open = !open"><span class="flex items-center gap-2"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5 text-slate-300" aria-hidden="true"><path d="M4 19h16M7 15V9m5 6V5m5 10v-4" stroke-linecap="round" /></svg>{{ activeLabel }}</span><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" class="h-4 w-4 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="open ? 'rotate-180' : ''" aria-hidden="true"><path d="m5 7.5 5 5 5-5" stroke-width="1.5" /></svg></button>
            <button v-if="open" type="button" class="fixed inset-0 z-40 bg-black/20 sm:hidden" aria-label="Close statistics categories" @click="close" />
            <Transition enter-active-class="transition-[opacity,transform] duration-200 ease-out motion-reduce:transition-none" leave-active-class="transition-[opacity,transform] duration-100 ease-in motion-reduce:transition-none" enter-from-class="-translate-y-1 opacity-0" leave-to-class="-translate-y-1 opacity-0">
            <div v-if="open" :id="menuId" class="absolute inset-x-0 top-full z-50 border-b border-[#244258] bg-[#071d2c] py-1 shadow-xl sm:hidden">
                <Link v-for="item in links" :key="item.view" :href="item.href" :aria-current="view === item.view ? 'page' : undefined" preserve-scroll :preserve-state="tableViews.includes(view) && tableViews.includes(item.view)" :only="tableViews.includes(view) && tableViews.includes(item.view) ? tableProps : []" class="block px-4 py-2.5 text-xs focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-400" :class="view === item.view ? 'bg-blue-600 font-semibold text-white' : 'text-slate-200 hover:bg-[#102b3e]'" @click="open = false" @start="$emit('start', item.view)" @finish="$emit('finish')" @error="$emit('failed', item.view)">{{ item.label }}</Link>
            </div>
            </Transition>
        </template>
        <Link v-for="item in links" :key="item.view" :href="item.href" :aria-current="view === item.view ? 'page' : undefined" preserve-scroll :preserve-state="tableViews.includes(view) && tableViews.includes(item.view)" :only="tableViews.includes(view) && tableViews.includes(item.view) ? tableProps : []" class="relative min-w-16 shrink-0 rounded-t px-3 py-2 text-xs transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" :class="[view !== 'leaders' ? 'hidden sm:block' : '', view === item.view ? 'font-bold text-white after:absolute after:inset-x-2 after:bottom-0 after:h-1 after:rounded-t after:bg-amber-400' : 'text-slate-300 hover:text-sky-300']" @start="$emit('start', item.view)" @finish="$emit('finish')" @error="$emit('failed', item.view)">{{ item.label }}</Link>
    </nav>
</template>
