<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import ToggleSwitch from '../../components/ToggleSwitch.vue';
import GameCard from './GameCard.vue';

const props = defineProps({
  initialPayload: { type: Object, required: true }, payloadUrl: { type: String, required: true },
  canManageGameSync: { type: Boolean, default: false }, gameSyncSchedule: { type: Object, default: null },
  gameSyncScheduleUrl: { type: String, default: null },
});
const payload = ref(props.initialPayload);
const date = ref(props.initialPayload.meta.date);
const loading = ref(false);
const drawerOpen = ref(false);
const toggleSaving = ref(false);
const frequencySaving = ref(false);
const error = ref('');
const enabled = ref(Boolean(props.gameSyncSchedule?.enabled));
const initialSeconds = Number(props.gameSyncSchedule?.lanes?.today?.interval_seconds ?? 60);
const hours = ref(Math.floor(initialSeconds / 3600));
const minutes = ref(Math.floor((initialSeconds % 3600) / 60));
const seconds = ref(initialSeconds % 60);
const intervalSeconds = computed(() => Math.max(60, (hours.value * 3600) + (minutes.value * 60) + seconds.value));
const splitTimer = (value) => ({ hours: Math.floor(value / 3600), minutes: Math.floor((value % 3600) / 60), seconds: value % 60 });
const pregame = reactive(splitTimer(props.gameSyncSchedule?.timing?.within_one_hour_seconds ?? 900));
const live = reactive(splitTimer(props.gameSyncSchedule?.timing?.live_seconds ?? 300));
const startBeforeMinutes = ref(props.gameSyncSchedule?.timing?.start_before_minutes ?? 30);
const timerSeconds = (timer) => Math.max(60, Number(timer.hours) * 3600 + Number(timer.minutes) * 60 + Number(timer.seconds));
const formattedDate = computed(() => new Intl.DateTimeFormat(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(`${date.value}T12:00:00`)));
let frequencySaveTimer = null;
const shiftDate = (days) => { const value = new Date(`${date.value}T12:00:00Z`); value.setUTCDate(value.getUTCDate() + days); changeDate(value.toISOString().slice(0, 10)); };
async function changeDate(value) {
  date.value = value; loading.value = true; error.value = '';
  try {
    const url = new URL(props.payloadUrl, window.location.origin); url.searchParams.set('date', value);
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Unable to load games.');
    payload.value = await response.json(); window.history.replaceState({}, '', `/games?date=${value}`);
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to load games.';
  } finally {
    loading.value = false;
  }
}
async function persistSchedule(nextEnabled, nextInterval) {
  const response = await fetch(props.gameSyncScheduleUrl, { method: 'PUT', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ enabled: nextEnabled, intervals: { today: nextInterval }, timing: { start_before_minutes: startBeforeMinutes.value, within_one_hour_seconds: timerSeconds(pregame), live_seconds: timerSeconds(live) } }) });
  if (!response.ok) throw new Error('Unable to update game synchronization.');
}
async function toggleSync(nextEnabled) {
  const previousEnabled = enabled.value;
  enabled.value = nextEnabled; toggleSaving.value = true; error.value = '';
  try {
    await persistSchedule(nextEnabled, intervalSeconds.value);
  } catch (exception) {
    enabled.value = previousEnabled;
    error.value = exception instanceof Error ? exception.message : 'Unable to update game synchronization.';
  } finally {
    toggleSaving.value = false;
  }
}
watch([hours, minutes, seconds, startBeforeMinutes, pregame, live], () => {
  window.clearTimeout(frequencySaveTimer);
  frequencySaveTimer = window.setTimeout(async () => {
    frequencySaving.value = true; error.value = '';
    try {
      await persistSchedule(enabled.value, intervalSeconds.value);
    } catch (exception) {
      error.value = exception instanceof Error ? exception.message : 'Unable to update sync frequency.';
    } finally {
      frequencySaving.value = false;
    }
  }, 500);
});
onBeforeUnmount(() => window.clearTimeout(frequencySaveTimer));
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div><h1 class="text-2xl font-semibold text-gray-950">NHL Games</h1><p class="mt-1 text-sm text-gray-600">Current anticipated lineups for {{ formattedDate }}.</p></div>
      <div class="flex items-end gap-2">
        <button type="button" aria-label="Previous game date" class="flex size-10 items-center justify-center rounded-md border border-gray-300 bg-white" @click="shiftDate(-1)">←</button>
        <label class="text-sm font-medium text-gray-700">Game date<input v-model="date" type="date" class="mt-1 block h-10 rounded-md border-gray-300 text-sm" @change="changeDate(date)"></label>
        <button type="button" aria-label="Next game date" class="flex size-10 items-center justify-center rounded-md border border-gray-300 bg-white" @click="shiftDate(1)">→</button>
        <button v-if="canManageGameSync" type="button" aria-label="Game sync settings" class="flex size-10 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50" @click="drawerOpen = true">
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.12.4.36.75.7 1 .32.25.7.39 1.1.4h.09v4h-.09a1.7 1.7 0 0 0-1.8.6Z"/></svg>
        </button>
      </div>
    </div>
    <p v-if="loading" class="mb-4 text-sm text-gray-500">Loading games…</p>
    <div v-if="payload.games.length" class="grid gap-5 lg:grid-cols-2"><GameCard v-for="game in payload.games" :key="game.nhl_game_id" :game="game" /></div>
    <div v-else class="rounded-lg border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-600">No NHL games are scheduled for this date.</div>

    <Teleport to="body"><div v-if="drawerOpen" class="fixed inset-0 z-[100]" role="dialog" aria-modal="true"><button class="absolute inset-0 bg-gray-950/40" aria-label="Close settings" @click="drawerOpen = false"></button><aside class="absolute inset-y-0 right-0 w-full max-w-md overflow-y-auto bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><div><h2 class="text-lg font-semibold">Game settings</h2><p class="mt-1 text-sm text-gray-500">NHL gamecenter synchronization</p></div><button class="text-2xl text-gray-500" @click="drawerOpen = false">×</button></div><div class="mt-8 space-y-6"><div class="flex items-center justify-between gap-4"><span class="text-sm font-medium text-gray-900">Sync</span><ToggleSwitch :model-value="enabled" :disabled="toggleSaving" label="Sync" @update:model-value="toggleSync" /></div><fieldset><div class="flex items-center justify-between"><legend class="text-sm font-semibold">General</legend><span v-if="frequencySaving" class="text-xs text-gray-500">Saving…</span></div><div class="mt-3 grid grid-cols-3 gap-3"><label class="text-xs text-gray-600">Hours<input v-model.number="hours" type="number" min="0" max="24" class="mt-1 w-full rounded-md border-gray-300"></label><label class="text-xs text-gray-600">Minutes<input v-model.number="minutes" type="number" min="0" max="59" class="mt-1 w-full rounded-md border-gray-300"></label><label class="text-xs text-gray-600">Seconds<input v-model.number="seconds" type="number" min="0" max="59" class="mt-1 w-full rounded-md border-gray-300"></label></div><p class="mt-2 text-xs text-gray-500">{{ intervalSeconds }} base seconds</p></fieldset><fieldset v-for="timer in [{ label: 'Within 1 hour of puck drop', value: pregame }, { label: 'Live', value: live }]" :key="timer.label">
            <legend class="text-sm font-semibold">{{ timer.label }}</legend>
            <div class="mt-3 grid grid-cols-3 gap-3">
              <label v-for="unit in ['hours', 'minutes', 'seconds']" :key="unit" class="text-xs capitalize text-gray-600">{{ unit }}
                <input v-model.number="timer.value[unit]" type="number" min="0" :max="unit === 'hours' ? 24 : 59" class="mt-1 w-full rounded-md border-gray-300">
              </label>
            </div>
          </fieldset>
          <label class="block text-sm font-medium text-gray-700">Start syncing minutes before puck drop
            <input v-model.number="startBeforeMinutes" type="number" min="0" max="1440" class="mt-2 block w-full rounded-md border-gray-300">
          </label>
          <p class="text-xs text-gray-500">No scheduled syncing before this window. Changes save automatically.</p>
          <p v-if="error" class="text-sm text-red-600">{{ error }}</p></div></aside></div></Teleport>
  </div>
</template>
