<script setup>
import PlayerAvatar from './PlayerAvatar.vue';

defineProps({
  name: {
    type: String,
    default: '',
  },
  avatarUrl: {
    type: String,
    default: '',
  },
  rowClass: {
    type: String,
    default: 'hover:bg-gray-50',
  },
});
</script>

<template>
  <div class="flex h-12 min-w-0 items-center justify-end gap-2 border-t px-4 py-2 text-right text-xs text-gray-600 transition-colors" :class="rowClass">
    <template v-if="name">
      <span class="min-w-0 truncate font-medium" :title="name">{{ name }}</span>
      <PlayerAvatar :avatar-url="avatarUrl" :name="name" />
    </template>
  </div>
</template>
