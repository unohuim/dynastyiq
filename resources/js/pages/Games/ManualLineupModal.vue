<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ gameId: { type: Number, required: true }, team: { type: String, required: true } });
const emit = defineEmits(['close', 'submitted']);
const dialog = ref(null);
const text = ref('');
const saving = ref(false);
const error = ref('');
const image = ref(null);
const preview = ref('');
const fileInput = ref(null);
const reading = ref(false);
const reviewedImage = ref(false);
const notice = ref('');
let extraction = null;
let extractionId = 0;
onMounted(() => dialog.value.showModal());
onBeforeUnmount(() => {
  extractionId++;
  extraction?.abort();
  if (preview.value) URL.revokeObjectURL(preview.value);
});

function removeImage() {
  if (saving.value) return;
  extractionId++;
  extraction?.abort();
  reading.value = false;
  reviewedImage.value = false;
  notice.value = '';
  if (preview.value) URL.revokeObjectURL(preview.value);
  preview.value = '';
  image.value = null;
  if (fileInput.value) fileInput.value.value = '';
}

async function selectImage(file) {
  if (saving.value || !file) return;
  if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 10 * 1024 * 1024) {
    error.value = 'Choose a JPEG or PNG image no larger than 10 MB.';
    if (fileInput.value) fileInput.value.value = '';
    return;
  }
  removeImage();
  image.value = file;
  preview.value = URL.createObjectURL(file);
  error.value = '';
  reading.value = true;
  const id = ++extractionId;
  extraction = new AbortController();
  try {
    const body = new FormData();
    body.append('team_abbrev', props.team);
    body.append('image', file, file.name || 'pasted-lineup.png');
    const response = await fetch(`/games/${props.gameId}/lineup/preview`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      body,
      signal: extraction.signal,
    });
    const data = await response.json().catch(() => ({}));
    if (id !== extractionId) return;
    if (!response.ok) throw new Error(data.errors?.image?.[0] ?? data.message ?? 'Unable to read this image.');
    text.value = data.text ?? '';
    reviewedImage.value = true;
    notice.value = 'Review and correct the interpreted lineup above, then Submit.';
  } catch (exception) {
    if (id === extractionId && exception.name !== 'AbortError') {
      error.value = exception.message || 'Unable to read this image.';
    }
  } finally {
    if (id === extractionId) reading.value = false;
  }
}

function pasteImage(event) {
  if (saving.value) return;
  const files = Array.from(event.clipboardData?.files ?? []);
  const file = files.find((file) => file.type.startsWith('image/'))
    ?? Array.from(event.clipboardData?.items ?? []).find((item) => item.kind === 'file' && item.type.startsWith('image/'))?.getAsFile();
  if (!file) return; // Ordinary text paste keeps the browser's native behavior.
  event.preventDefault();
  selectImage(file);
}

function handleBackdropClick(event) {
  if (event.target === dialog.value && !saving.value) {
    emit('close');
  }
}

async function submit() {
  if (saving.value || reading.value || !text.value.trim() || (image.value && !reviewedImage.value)) return;
  saving.value = true;
  error.value = '';
  try {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' };
    let body;
    if (image.value) {
      body = new FormData();
      body.append('team_abbrev', props.team);
      body.append('text', text.value);
      body.append('image', image.value, image.value.name || 'pasted-lineup.png');
      body.append('image_reviewed', '1');
    } else {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify({ team_abbrev: props.team, text: text.value });
    }
    const response = await fetch(`/games/${props.gameId}/lineup`, {
      method: 'POST',
      headers,
      body,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.errors?.image?.[0] ?? data.errors?.text?.[0] ?? data.message ?? 'Unable to process this lineup.');
    if (!data.lineup) throw new Error('The submission did not produce a reported lineup.');
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
    <dialog ref="dialog" :aria-label="`Add ${team} lineup`" class="m-auto max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl backdrop:bg-gray-950/40" @cancel="saving ? $event.preventDefault() : emit('close')" @click="handleBackdropClick">
      <form class="space-y-4" @submit.prevent="submit">
        <label class="sr-only" for="manual-lineup-text">{{ team }} lineup text</label>
        <textarea id="manual-lineup-text" v-model="text" autofocus required maxlength="20000" rows="10" :disabled="saving || reading" :placeholder="`Paste the ${team} lineup text or an image here…`" class="block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" @paste="pasteImage" />
        <p v-if="reading" role="status" class="text-sm text-gray-600">Reading image…</p>
        <p v-else-if="notice" role="status" class="text-sm text-gray-600">{{ notice }}</p>
        <div class="space-y-2">
          <label for="manual-lineup-image" class="block text-sm font-medium text-gray-700">Upload a lineup image</label>
          <input id="manual-lineup-image" ref="fileInput" type="file" accept="image/jpeg,image/png" :disabled="saving" class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:font-medium file:text-indigo-700 hover:file:bg-indigo-100 disabled:opacity-50" @change="selectImage($event.target.files?.[0])">
          <p class="text-xs text-gray-500">JPEG or PNG, up to 10 MB. Text, an image, or both.</p>
        </div>
        <div v-if="image" class="space-y-2 rounded-md border border-gray-200 p-3">
          <img :src="preview" alt="Selected lineup image" class="max-h-56 w-full object-contain">
          <div class="flex items-center justify-between gap-3 text-sm">
            <span class="truncate text-gray-600">{{ image.name || 'Pasted image' }}</span>
            <button type="button" :disabled="saving" class="shrink-0 text-indigo-600 transition-colors duration-150 hover:text-indigo-500 disabled:opacity-50 motion-reduce:transition-none" @click="removeImage">Remove image</button>
          </div>
        </div>
        <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
        <button type="submit" :disabled="saving || reading || !text.trim() || (image && !reviewedImage)" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-500 disabled:opacity-50 motion-reduce:transition-none">{{ saving ? 'Processing…' : 'Submit' }}</button>
      </form>
    </dialog>
  </Teleport>
</template>
