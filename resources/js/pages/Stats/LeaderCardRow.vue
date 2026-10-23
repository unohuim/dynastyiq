<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    href: { type: String, required: true },
    cards: { type: Array, default: () => [] },
});

const formatted = card => {
    if (!Number.isFinite(card.value)) return '—';
    if (['gax', 'gsax'].includes(card.key)) return `${card.value > 0 ? '+' : ''}${card.value.toFixed(2)}`;
    if (['ev_sv_pct', 'quality_start_pct'].includes(card.key)) return `${card.value.toFixed(2)}%`;
    if (['win_pct', 'cf_pct'].includes(card.key)) return `${card.value.toFixed(1)}%`;
    if (['g_per_gp', 'pts_per_gp'].includes(card.key)) return card.value.toFixed(2);
    return String(Math.round(card.value));
};
</script>

<template>
    <section class="min-w-0" :aria-label="title">
        <h2 class="mb-1 flex items-center text-[11px] font-semibold">
            <Link :href="href" class="inline-flex items-center gap-1 rounded text-slate-200 transition-colors duration-150 hover:text-sky-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none">
                {{ title }}
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3" aria-hidden="true"><path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 0 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
            </Link>
        </h2>
        <ul tabindex="0" :aria-label="`${title} metric leaders`" class="grid grid-flow-col auto-cols-[calc((100%_-_1rem)/3)] gap-2 overflow-x-auto overscroll-x-contain snap-x snap-mandatory pb-1 [scrollbar-width:thin] [scrollbar-color:#28516a_#071923] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400">
            <li v-for="card in cards" :key="card.key" class="min-w-0 snap-start">
                <component :is="card.entity?.external ? 'a' : Link" :href="card.entity?.url || href"
                    :target="card.entity?.external ? '_blank' : undefined" :rel="card.entity?.external ? 'noopener noreferrer' : undefined"
                    :aria-label="`${card.label}: ${formatted(card)}, ${card.entity?.name || 'unavailable'}`"
                    class="flex h-[100px] min-w-0 flex-col overflow-hidden rounded-md border border-[#17374a] bg-[#071923] px-2 py-1.5 transition-colors duration-150 hover:border-sky-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none">
                    <div class="flex min-w-0 items-center justify-between gap-1"><span class="truncate text-[9px] font-semibold text-sky-300">{{ card.label }}</span><img v-if="card.entity?.image" :src="card.entity.image" alt="" class="h-7 w-7 shrink-0 object-contain" loading="lazy" /></div>
                    <p class="mt-auto text-lg font-bold leading-6 tabular-nums">{{ formatted(card) }}</p>
                    <p class="truncate text-[9px] font-semibold leading-4 text-slate-100" :title="card.entity?.name">{{ card.entity?.short_name || card.entity?.name || 'Unavailable' }}</p>
                    <p class="truncate text-[8px] leading-3 text-slate-400">{{ card.entity?.subtitle || '—' }}</p>
                </component>
            </li>
        </ul>
    </section>
</template>
