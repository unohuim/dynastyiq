<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ gameId: { type: Number, required: true }, team: { type: String, required: true }, goalie: { type: Object, default: null } });
const emit = defineEmits(['selected']);
const root = ref(null);
const trigger = ref(null);
const search = ref(null);
const open = ref(false);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const query = ref('');
const goalies = ref([]);
const controller = new AbortController();
const panelId = computed(() => `goalies-${props.gameId}-${props.team}`);
const filtered = computed(() => goalies.value.filter((goalie) => String(goalie.name ?? '').toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
const status = computed(() => props.goalie?.selection_source === 'manual_starter_override' ? 'Manual · Expected' : (props.goalie?.status ?? 'Select starter').replace(/^./, (letter) => letter.toUpperCase()));

async function toggle() {
  if (saving.value) return;
  open.value = !open.value;
  if (!open.value) return;
  error.value = ''; query.value = ''; loading.value = true;
  await nextTick(); search.value?.focus();
  try {
    const response = await fetch(`/games/${props.gameId}/goalies?team_abbrev=${encodeURIComponent(props.team)}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message ?? 'Unable to load goalies.');
    goalies.value = Array.isArray(data.goalies) ? data.goalies : [];
  } catch (exception) {
    if (exception.name !== 'AbortError') error.value = exception.message;
  } finally { loading.value = false; }
}
async function select(goalie) {
  if (saving.value) return;
  saving.value = true; error.value = '';
  try {
    const response = await fetch(`/games/${props.gameId}/starting-goalie`, {
      method: 'POST', signal: controller.signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ team_abbrev: props.team, player_id: goalie.player_id }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.errors?.player_id?.[0] ?? data.message ?? 'Unable to save starter.');
    emit('selected', data); open.value = false; trigger.value?.focus();
  } catch (exception) {
    if (exception.name !== 'AbortError') error.value = exception.message;
  } finally { saving.value = false; }
}
function close() { if (!saving.value) { open.value = false; trigger.value?.focus(); } }
function outside(event) { if (!root.value?.contains(event.target) && !saving.value) open.value = false; }
function navigate(event) {
  if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
  event.preventDefault();
  const options = [...root.value.querySelectorAll('[role="option"]')];
  const index = options.indexOf(document.activeElement);
  options[(index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length]?.focus();
}
onMounted(() => document.addEventListener('pointerdown', outside));
onBeforeUnmount(() => { controller.abort(); document.removeEventListener('pointerdown', outside); });
</script>

<template>
  <div ref="root" class="relative" :class="{ 'z-50': open }" @keydown.esc.stop.prevent="close" @keydown="navigate">
    <button ref="trigger" type="button" :aria-label="`Choose ${team} starting goalie`" :aria-expanded="open" :aria-controls="panelId" aria-haspopup="listbox" class="flex items-center gap-3 rounded-lg text-right transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" @click="toggle">
      <span><span class="block max-w-36 truncate text-sm font-semibold">{{ goalie?.name ?? 'Choose goalie' }}</span><span class="mt-1 inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold" :class="goalie?.status === 'confirmed' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-sky-200 bg-sky-50 text-sky-700'">{{ status }}<svg class="size-3 transition-transform duration-300 motion-reduce:transition-none" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="m5 7 5 5 5-5H5Z" /></svg></span></span>
      <img v-if="goalie?.avatar_url" :src="goalie.avatar_url" :alt="goalie.name" class="size-12 rounded-full border border-gray-200 object-cover">
      <span v-else class="flex size-12 items-center justify-center rounded-full border border-gray-200 bg-gray-50 text-sm font-semibold text-gray-400" aria-hidden="true">G</span>
    </button>
    <Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" leave-active-class="transition-opacity duration-75 motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0">
      <div v-if="open" :id="panelId" class="absolute right-0 top-full z-50 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-3 text-left shadow-xl" :aria-busy="loading || saving">
        <p class="mb-2 text-sm font-semibold text-gray-900">{{ team }} starting goalie</p>
        <input ref="search" v-model="query" type="search" :aria-label="`Search ${team} goalies`" placeholder="Search goalies…" :disabled="saving" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <p v-if="error" role="alert" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <p v-if="loading || saving" role="status" class="py-3 text-sm text-gray-500">{{ saving ? 'Saving starter…' : 'Loading goalies…' }}</p>
        <div v-if="!loading" role="listbox" :aria-label="`${team} goalies`" class="mt-2 max-h-64 space-y-1 overflow-y-auto">
          <button v-for="option in filtered" :key="option.player_id" type="button" role="option" :aria-selected="option.nhl_player_id != null && option.nhl_player_id === goalie?.nhl_player_id" :disabled="saving" class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors duration-150 hover:bg-indigo-50 focus-visible:bg-indigo-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-50 motion-reduce:transition-none" @click="select(option)">
            <img v-if="option.avatar_url" :src="option.avatar_url" alt="" class="size-9 rounded-full bg-gray-50 object-cover">
            <span><span class="block text-sm font-medium text-gray-900">{{ option.name }}</span><span class="text-xs text-gray-500">{{ option.league ?? 'Team prospect' }}{{ option.nhl_player_id == null ? ' · NHL ID unavailable' : '' }}</span></span>
          </button>
          <p v-if="!filtered.length" class="py-3 text-sm text-gray-500">No matching goalies.</p>
        </div>
      </div>
    </Transition>
  </div>
</template>
