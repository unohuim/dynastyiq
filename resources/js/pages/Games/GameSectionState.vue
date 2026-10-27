<script setup>
defineProps({ name: { type: String, required: true }, title: { type: String, required: true }, state: { type: Object, required: true } });
defineEmits(['retry']);
</script>

<template>
  <section v-if="state.status !== 'ready'" :id="name" :aria-label="title" :aria-busy="state.status === 'loading'" class="mt-5 scroll-mt-5 rounded-xl border border-[#29404F] bg-[#172534] p-4 sm:p-6">
    <h2 class="text-sm font-semibold">{{ title }}</h2>
    <div v-if="state.status === 'error'" role="alert" class="mt-3 flex items-center justify-between gap-4 text-xs">
      <p class="text-[#B6C7D5]">{{ state.message || 'Unable to load this section.' }}</p>
      <button type="button" :aria-label="`Retry ${title}`" class="shrink-0 rounded-md border border-sky-400/40 px-3 py-1.5 font-semibold text-sky-300 transition-colors duration-150 hover:bg-sky-400/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" @click="$emit('retry')">Retry</button>
    </div>
    <div v-else role="status" class="mt-3 space-y-2">
      <p class="text-xs text-[#91A8BA]">Loading {{ title.toLowerCase() }}…</p>
      <div aria-hidden="true" class="space-y-2 animate-pulse motion-reduce:animate-none"><div class="h-3 w-3/4 rounded bg-[#29404F]" /><div class="h-3 w-1/2 rounded bg-[#29404F]" /></div>
    </div>
  </section>
</template>
