<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ links: { type: Array, required: true }, view: { type: String, required: true } });
defineEmits(['start', 'finish', 'failed']);
</script>

<template>
    <nav aria-label="Statistics categories" class="flex shrink-0 border-b-2 border-amber-400/25 bg-[#03131f]/75 px-3">
        <Link v-for="item in links" :key="item.view" :href="item.href" :aria-current="view === item.view ? 'page' : undefined" preserve-scroll class="relative min-w-16 rounded-t px-3 py-2 text-xs transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" :class="view === item.view ? 'font-bold text-white after:absolute after:inset-x-2 after:bottom-0 after:h-1 after:rounded-t after:bg-amber-400' : 'text-slate-300 hover:text-sky-300'" @start="$emit('start', item.view)" @finish="$emit('finish')" @error="$emit('failed', item.view)">{{ item.label }}</Link>
    </nav>
</template>
