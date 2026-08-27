<script setup>
import { ref } from 'vue';
import { deferAvatarImageSrc } from '../../../components/StatsPage/stats-utils.js';

const props = defineProps({
  avatarUrl: {
    type: String,
    default: '',
  },
  name: {
    type: String,
    default: '',
  },
});

const failed = ref(false);

function initials(name = '') {
  return String(name)
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || '?';
}

function mountAvatar(img) {
  if (!img) return;

  deferAvatarImageSrc(img, props.avatarUrl);
}
</script>

<template>
  <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-1 ring-gray-200">
    <img
      v-if="avatarUrl && !failed"
      :ref="mountAvatar"
      alt=""
      loading="lazy"
      class="h-7 w-7 rounded-full object-cover"
      @error="failed = true"
    />
    <template v-else>{{ initials(name) }}</template>
  </span>
</template>
