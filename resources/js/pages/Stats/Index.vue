<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { mountStatsPage } from '../stats-page.js';

const props = defineProps({
  initialPayload: {
    type: Object,
    required: true,
  },
  apiUrl: {
    type: String,
    required: true,
  },
  connectedLeagues: {
    type: Array,
    default: () => [],
  },
  perspectives: {
    type: Array,
    default: () => [],
  },
  selectedPerspective: {
    type: String,
    default: '',
  },
  mobileBreakpoint: {
    type: Number,
    default: 640,
  },
});

const container = ref(null);
let shell = null;

onMounted(() => {
  shell = mountStatsPage(container.value, {
    initialPayload: props.initialPayload,
    apiUrl: props.apiUrl,
    connectedLeagues: props.connectedLeagues,
    perspectives: props.perspectives,
    selectedPerspective: props.selectedPerspective,
    mobileBreakpoint: props.mobileBreakpoint,
  });
});

onBeforeUnmount(() => {
  if (container.value) {
    container.value.replaceChildren();
    delete container.value.dataset.statsMounted;
  }

  shell = null;
});
</script>

<template>
  <div class="stats-view">
    <noscript>
      <div class="mx-auto max-w-7xl px-4 py-6">
        <div class="rounded-md bg-white p-4 text-sm text-gray-700 shadow">
          JavaScript is required to view stats.
        </div>
      </div>
    </noscript>

    <div id="stats-page" ref="container" class="mx-auto max-w-[1680px]"></div>
  </div>
</template>
