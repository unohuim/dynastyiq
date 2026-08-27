<script setup>
defineProps({
  cells: {
    type: Array,
    default: () => [],
  },
  gridCols: {
    type: String,
    required: true,
  },
  rowClass: {
    type: String,
    default: 'hover:bg-gray-50',
  },
});
</script>

<template>
  <div class="grid h-12 border-t px-4 py-2 text-sm transition-colors" :class="rowClass" :style="{ gridTemplateColumns: gridCols }">
    <div
      v-for="(cell, index) in cells"
      :key="index"
      :class="cell.className"
    >
      {{ cell.text }}
    </div>
  </div>
</template>
