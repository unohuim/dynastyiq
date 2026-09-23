<script setup>
import { onMounted, reactive, ref } from 'vue';

defineProps({ team: { type: String, required: true }, state: { type: Object, required: true } });
const emit = defineEmits(['close']);
const dialog = ref(null);
const expanded = reactive(new Set());
onMounted(() => dialog.value?.showModal());
const toggle = (id) => expanded.has(id) ? expanded.delete(id) : expanded.add(id);
const localTime = (value) => {
  const date = new Date(value);
  return value && Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium', timeStyle: 'long',
  }).format(date) : 'Publication time unavailable';
};
const safeUrl = (value, image = false) => {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && (image ? url.hostname === 'pbs.twimg.com' : ['x.com', 'twitter.com'].includes(url.hostname)) ? url.href : null;
  } catch { return null; }
};
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" :aria-label="`${team} lineup search`" class="m-auto max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-gray-950/40" @cancel.prevent="emit('close')">
      <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-gray-200 bg-white p-5">
        <div>
          <h2 class="text-lg font-semibold text-gray-950">{{ team }} lineup search</h2>
          <p role="status" class="mt-1 text-sm" :class="state.error ? 'text-red-600' : 'text-gray-600'">{{ state.busy ? (state.review?.activity ?? state.message) : state.message }}</p>
          <p class="mt-1 text-xs text-gray-500">{{ state.review?.posts?.length ?? 0 }} posts reviewed · Closing this window does not cancel the job.</p>
        </div>
        <button type="button" aria-label="Close lineup search" class="rounded-md p-2 text-gray-500 transition-colors duration-150 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" @click="emit('close')">✕</button>
      </header>
      <div class="divide-y divide-gray-100">
        <p v-if="!state.review?.posts?.length" class="p-5 text-sm text-gray-500">{{ state.busy ? 'Reviewed X posts will appear here as the search progresses.' : 'No X posts were reviewed in this attempt. NHL boxscore evidence may have supplied the lineup.' }}</p>
        <section v-for="post in state.review?.posts ?? []" :key="post.id" class="px-5 py-4">
          <button type="button" class="flex w-full items-start gap-3 rounded-md text-left focus-visible:ring-2 focus-visible:ring-indigo-500" :aria-expanded="expanded.has(post.id)" :aria-controls="`lineup-post-${team}-${post.id}`" @click="toggle(post.id)">
            <svg aria-hidden="true" class="mt-1 size-4 shrink-0 text-gray-500 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="{ 'rotate-180': expanded.has(post.id) }" viewBox="0 0 20 20" fill="currentColor"><path d="m5.3 7.3 4.7 4.7 4.7-4.7 1.1 1.1-5.8 5.8-5.8-5.8z" /></svg>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-gray-900">{{ post.author }} <span class="font-normal text-gray-500">@{{ post.handle }}</span></span>
              <span class="mt-1 block text-xs text-gray-500">{{ localTime(post.published_at) }} · {{ post.context }}</span>
              <span class="mt-2 block text-sm text-gray-600">{{ post.reason }}</span>
            </span>
            <span class="rounded-full border px-2 py-0.5 text-xs font-medium" :class="post.decision === 'approved' ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 bg-gray-50 text-gray-600'">{{ post.decision === 'approved' ? 'Accepted' : 'Rejected' }}</span>
          </button>
          <div :id="`lineup-post-${team}-${post.id}`" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="expanded.has(post.id) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'" :inert="!expanded.has(post.id)">
            <div class="overflow-hidden"><div class="space-y-4 pt-4">
              <p class="whitespace-pre-wrap break-words text-sm text-gray-800">{{ post.text }}</p>
              <a v-if="safeUrl(post.url)" :href="safeUrl(post.url)" target="_blank" rel="noopener noreferrer" class="inline-block text-sm text-indigo-600 hover:underline">View original post ↗</a>
              <template v-for="(media, index) in post.media ?? []" :key="index"><img v-if="safeUrl(media.url ?? media.preview_image_url, true)" :src="safeUrl(media.url ?? media.preview_image_url, true)" :alt="media.alt_text ?? 'Post attachment'" loading="lazy" class="max-h-96 max-w-full rounded-md object-contain"></template>
              <div v-for="(ocr, index) in post.ocr ?? []" :key="index" class="rounded-md bg-gray-50 p-3 text-sm">
                <h3 class="font-semibold">Image text · {{ ocr.status }}</h3>
                <p v-if="ocr.reason" class="mt-1 text-gray-600">{{ ocr.reason }}</p>
                <p class="mt-2 whitespace-pre-wrap break-words">{{ ocr.text ?? 'No text extracted.' }}</p>
              </div>
              <div><h3 class="text-sm font-semibold">Interpreted lineup</h3><pre class="mt-2 whitespace-pre-wrap break-words text-xs text-gray-700">{{ post.lineup_text }}</pre></div>
              <div><h3 class="text-sm font-semibold">Player matching</h3><p v-if="!post.players?.length" class="mt-2 text-sm text-gray-500">No lineup slots were extracted.</p><ul class="mt-2 space-y-1 text-sm text-gray-700"><li v-for="(player, index) in post.players ?? []" :key="index">{{ player.line_key }} · {{ player.player_name ?? player.name }} · {{ player.resolution_status ?? (player.nhl_player_id ? 'resolved' : 'unresolved') }}<span v-if="player.nhl_player_id"> · NHL {{ player.nhl_player_id }}</span></li></ul><template v-if="post.matched_players?.length"><h4 class="mt-3 text-xs font-semibold text-gray-600">Recognized players</h4><ul class="mt-1 space-y-1 text-xs text-gray-600"><li v-for="(player, index) in post.matched_players" :key="index">{{ player.name ?? player.player_name }}<span v-if="player.nhl_player_id"> · NHL {{ player.nhl_player_id }}</span></li></ul></template></div>
            </div></div>
          </div>
        </section>
      </div>
    </dialog>
  </Teleport>
</template>
