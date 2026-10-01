<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ links: { type: Array, default: () => [] } });
</script>

<template>
    <nav aria-label="Pagination" class="mt-4 flex flex-wrap gap-2">
        <template v-for="(link, index) in links" :key="index">
            <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded border px-3 py-1 text-sm transition-colors duration-150 motion-reduce:transition-none" :class="link.active ? 'bg-gray-900 text-white' : 'bg-white text-gray-700'" :aria-current="link.active ? 'page' : undefined">{{ link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»') }}</Link>
            <span v-else class="px-3 py-1 text-sm text-gray-400">{{ link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»') }}</span>
        </template>
    </nav>
</template>
