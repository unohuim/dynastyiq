<script setup>
import { onMounted, ref } from 'vue';

const props = defineProps({ gameId: { type: Number, required: true }, team: { type: String, required: true } });
const emit = defineEmits(['close', 'submitted']);
const dialog = ref(null);
const text = ref('');
const saving = ref(false);
const error = ref('');
onMounted(() => dialog.value.showModal());

function handleBackdropClick(event) {
  if (event.target === dialog.value && !saving.value) {
    emit('close');
  }
}

async function submit() {
  if (saving.value || !text.value.trim()) return;
  saving.value = true;
  error.value = '';
  try {
    const response = await fetch(`/games/${props.gameId}/lineup`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body: JSON.stringify({ team_abbrev: props.team, text: text.value }),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.errors?.text?.[0] ?? data.message ?? 'Unable to process this lineup.');
    if (!data.lineup) throw new Error('The text did not produce a reported lineup.');
    emit('submitted', data.lineup);
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Unable to process this lineup.';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" :aria-label="`Add ${team} lineup`" class="m-auto w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6 shadow-xl backdrop:bg-gray-950/40" @cancel="saving ? $event.preventDefault() : emit('close')" @click="handleBackdropClick">
      <form class="space-y-4" @submit.prevent="submit">
        <label class="sr-only" for="manual-lineup-text">{{ team }} lineup text</label>
        <textarea id="manual-lineup-text" v-model="text" autofocus required maxlength="20000" rows="14" :disabled="saving" :placeholder="`Paste the ${team} lineup here…`" class="block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <button type="submit" :disabled="saving || !text.trim()" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-500 disabled:opacity-50 motion-reduce:transition-none">{{ saving ? 'Processing…' : 'Submit' }}</button>
      </form>
    </dialog>
  </Teleport>
</template>
