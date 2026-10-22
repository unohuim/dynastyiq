<script setup>
defineProps({ title: { type: String, required: true }, href: { type: String, default: null } });
</script>

<template>
    <section class="flex min-h-0 min-w-0 flex-col rounded-lg border border-slate-200/80 bg-white p-2.5 shadow-sm">
        <div class="mb-2 flex shrink-0 items-center justify-between gap-3">
            <h2 class="text-sm font-bold tracking-tight text-slate-950">{{ title }}</h2>
            <a v-if="href" :href="href" class="shrink-0 rounded text-xs font-medium text-blue-600 hover:text-blue-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">View all <span aria-hidden="true">›</span></a>
        </div>
        <slot />
    </section>
</template>
