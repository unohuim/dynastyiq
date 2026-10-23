<script setup>
defineProps({
    label: { type: String, default: 'statistics' },
    failed: { type: Boolean, default: false },
    reloading: { type: Boolean, default: false },
    hero: { type: Boolean, default: false },
    message: { type: String, default: '' },
});
defineEmits(['retry']);
</script>

<template>
    <section class="rounded-lg border border-[#233342] bg-[#101923] p-4 text-slate-200" :role="failed ? 'alert' : 'status'" :aria-busy="!failed || reloading" :aria-label="`${failed ? 'Unable to load' : 'Loading'} ${label}`">
        <div class="flex items-center gap-2 text-xs">
            <svg v-if="!failed || reloading" viewBox="0 0 24 24" fill="none" class="h-4 w-4 animate-spin text-sky-400 motion-reduce:animate-none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" opacity=".25" /><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
            <p>{{ failed ? `Unable to load ${label}. Please try again.` : (message || `Loading ${label}…`) }}</p>
            <button v-if="failed" type="button" :disabled="reloading" class="ml-auto rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 disabled:opacity-50" @click="$emit('retry')">{{ reloading ? 'Retrying…' : 'Retry' }}</button>
        </div>
        <div v-if="!failed" class="mt-3 space-y-3 motion-safe:animate-pulse" aria-hidden="true">
            <div v-for="row in hero ? 2 : 8" :key="row" class="grid grid-cols-[minmax(100px,2fr)_repeat(5,minmax(0,1fr))] gap-4 border-b border-slate-700/30 py-2"><div v-for="cell in 6" :key="cell" class="h-3 rounded bg-slate-700/60" /></div>
        </div>
    </section>
</template>
