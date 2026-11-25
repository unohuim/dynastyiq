<script setup>
import { computed, inject, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription } from 'reka-ui';

const props = defineProps({ subscriptions: { type: Array, default: () => [] }, apiClients: { type: Array, default: () => [] },
    eventOptions: { type: Array, default: () => [] }, storeUrl: { type: String, required: true } });
const notify = inject('notify', () => {});
const rows = ref([...props.subscriptions]);
watch(() => props.subscriptions, value => { rows.value = [...value]; });
const search = ref('');
const status = ref('all');
const client = ref('all');
const editor = ref(false);
const editing = ref(null);
const removing = ref(null);
const saving = ref(false);
const pending = ref(new Set());
const secrets = ref({});
const errors = ref({});
const failure = ref('');
const empty = () => ({ name: '', receiving_url: '', api_client_id: '', events: ['prediction.available'], is_enabled: false });
const form = ref(empty());
const enabled = computed(() => rows.value.filter(row => row.is_enabled).length);
const visible = computed(() => rows.value.filter(row =>
    (status.value === 'all' || row.is_enabled === (status.value === 'enabled'))
    && (client.value === 'all' || String(row.api_client_id) === client.value)
    && [row.name, row.client_name, row.receiving_url].join(' ').toLowerCase().includes(search.value.trim().toLowerCase())
).sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id));
const label = event => props.eventOptions.find(option => option.value === event)?.label ?? event;
const message = error => error.response?.data?.message ?? 'The change could not be saved. Please try again.';

function open(row = null) {
    editing.value = row;
    form.value = row ? { name: row.name, receiving_url: row.receiving_url, api_client_id: row.api_client_id,
        events: [...row.events], is_enabled: row.is_enabled } : empty();
    errors.value = {};
    failure.value = '';
    editor.value = true;
}

function replace(row) {
    rows.value = [...rows.value.filter(item => item.id !== row.id), row];
}

async function save() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    failure.value = '';
    try {
        const response = editing.value ? await axios.put(editing.value.update_url, form.value) : await axios.post(props.storeUrl, form.value);
        replace(response.data.subscription);
        editor.value = false;
        secrets.value = {};
        notify({ type: 'success', message: editing.value ? 'Webhook updated.' : 'Webhook created.' });
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        failure.value = message(error);
    } finally { saving.value = false; }
}

async function toggle(row) {
    if (pending.value.has(row.id)) return;
    pending.value.add(row.id);
    try {
        const response = await axios.patch(row.status_url, { is_enabled: !row.is_enabled });
        replace(response.data.subscription);
        notify({ type: 'success', message: response.data.subscription.is_enabled ? 'Webhook enabled.' : 'Webhook disabled.' });
    } catch (error) { notify({ type: 'error', message: message(error) }); }
    finally { pending.value.delete(row.id); }
}

async function reveal(row) {
    if (secrets.value[row.id]) { delete secrets.value[row.id]; return; }
    if (pending.value.has(row.id)) return;
    pending.value.add(row.id);
    try { secrets.value[row.id] = (await axios.get(row.secret_url)).data.secret; }
    catch (error) { notify({ type: 'error', message: message(error) }); }
    finally { pending.value.delete(row.id); }
}

async function copy(row) {
    try { await navigator.clipboard.writeText(secrets.value[row.id]); notify({ type: 'success', message: 'Signing secret copied.' }); }
    catch { notify({ type: 'error', message: 'Unable to copy. Select and copy the secret below.' }); }
}

async function destroy() {
    if (saving.value || !removing.value) return;
    saving.value = true;
    try {
        const id = removing.value.id;
        await axios.delete(removing.value.delete_url);
        rows.value = rows.value.filter(row => row.id !== id);
        delete secrets.value[id];
        removing.value = null;
        editor.value = false;
        editing.value = null;
        notify({ type: 'success', message: 'Webhook deleted.' });
    } catch (error) { notify({ type: 'error', message: message(error) }); }
    finally { saving.value = false; }
}
</script>

<template>
    <Head title="Webhooks" />
    <section aria-label="Webhooks" class="mx-auto w-full max-w-7xl px-3 py-5 text-slate-100 sm:px-6 sm:py-7">
        <header class="flex items-center justify-between gap-4">
            <div><p class="text-[10px] font-semibold uppercase tracking-[.2em] text-sky-400">Administration</p><h1 class="mt-1 text-2xl font-semibold tracking-tight">Webhooks</h1></div>
            <button type="button" class="rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-150 hover:bg-sky-500 disabled:opacity-50 motion-reduce:transition-none" :disabled="!apiClients.length" @click="open()">+ Add Webhook</button>
        </header>
        <p class="mt-2 text-sm text-slate-400">Manage partner subscriptions. Notification delivery is not connected yet.</p>
        <div class="mt-5 grid grid-cols-3 gap-2 sm:gap-4">
            <div class="rounded-xl border border-white/10 bg-[#171D23] p-3 sm:p-4"><p class="text-xs text-slate-400">Subscriptions</p><p class="mt-1 text-2xl font-semibold">{{ rows.length }}</p></div>
            <div class="rounded-xl border border-white/10 bg-[#171D23] p-3 sm:p-4"><p class="text-xs text-slate-400">Enabled</p><p class="mt-1 text-2xl font-semibold text-emerald-400">{{ enabled }}</p></div>
            <div class="rounded-xl border border-white/10 bg-[#171D23] p-3 sm:p-4"><p class="text-xs text-slate-400">Disabled</p><p class="mt-1 text-2xl font-semibold">{{ rows.length - enabled }}</p></div>
        </div>
        <p v-if="!apiClients.length" class="mt-4 text-sm text-amber-200">Create an API client in Admin Control Panel before adding a subscription.</p>
        <section aria-label="Webhook inventory" class="mt-5 overflow-hidden rounded-xl border border-white/10 bg-[#171D23]">
            <div class="grid gap-2 border-b border-white/10 p-3 sm:grid-cols-[1fr_160px_200px] sm:p-4">
                <input v-model="search" aria-label="Search webhooks" placeholder="Search name, subscriber or URL…" type="search" class="min-w-0 rounded-lg border-white/10 bg-[#11171D] text-sm placeholder:text-slate-500 focus:border-sky-500 focus:ring-sky-500" />
                <div class="grid grid-cols-2 gap-2 sm:contents">
                    <select v-model="status" aria-label="Filter by status" class="min-w-0 rounded-lg border-white/10 bg-[#11171D] text-sm focus:border-sky-500 focus:ring-sky-500"><option value="all">All statuses</option><option value="enabled">Enabled</option><option value="disabled">Disabled</option></select>
                    <select v-model="client" aria-label="Filter by subscriber" class="min-w-0 rounded-lg border-white/10 bg-[#11171D] text-sm focus:border-sky-500 focus:ring-sky-500"><option value="all">All subscribers</option><option v-for="item in apiClients" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select>
                </div>
            </div>
            <div v-if="!visible.length" class="px-5 py-12 text-center"><p class="font-medium">{{ rows.length ? 'No matching webhooks' : 'No webhooks yet' }}</p><p class="mt-2 text-sm text-slate-400">{{ rows.length ? 'Try another search or filter.' : 'Add a receiving URL and choose which events to subscribe to.' }}</p></div>
            <div v-else>
                <table class="hidden w-full table-fixed text-left text-sm md:table">
                    <thead class="bg-white/[.03] text-[11px] uppercase tracking-wider text-slate-400"><tr><th class="w-[22%] px-4 py-3">Subscription</th><th class="w-[31%] px-4 py-3">Receiving URL</th><th class="w-[22%] px-4 py-3">Events</th><th class="px-4 py-3">Status</th><th class="w-20 px-4 py-3">Manage</th></tr></thead>
                    <tbody class="divide-y divide-white/10"><template v-for="row in visible" :key="row.id"><tr :data-webhook-id="row.id">
                        <td class="px-4 py-4 align-top"><p class="break-words font-medium">{{ row.name }}</p><p class="mt-1 text-xs text-slate-400">{{ row.client_name }}<span v-if="row.client_revoked" class="ml-1 text-amber-300">· key revoked</span></p></td>
                        <td class="break-all px-4 py-4 align-top font-mono text-xs text-slate-300">{{ row.receiving_url }}</td>
                        <td class="px-4 py-4 align-top"><span v-for="event in row.events" :key="event" class="mb-1 inline-block rounded-md bg-sky-400/10 px-2 py-1 text-xs text-sky-300">{{ label(event) }}</span></td>
                        <td class="px-4 py-4 align-top"><button type="button" role="switch" :aria-checked="row.is_enabled" :aria-label="`Enable ${row.name}`" :disabled="pending.has(row.id)" class="rounded-full px-2.5 py-1 text-xs font-medium transition-colors duration-150 disabled:opacity-50 motion-reduce:transition-none" :class="row.is_enabled ? 'bg-emerald-400/10 text-emerald-300' : 'bg-white/5 text-slate-400'" @click="toggle(row)">{{ row.is_enabled ? 'Enabled' : 'Disabled' }}</button></td>
                        <td class="px-4 py-4 align-top"><button type="button" :aria-label="`Edit ${row.name}`" class="text-sky-300 hover:text-sky-200" @click="open(row)">Edit</button></td>
                    </tr></template></tbody>
                </table>
                <div class="divide-y divide-white/10 md:hidden"><article v-for="row in visible" :key="row.id" :data-webhook-card="row.id" class="p-4">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h2 class="break-words text-sm font-semibold">{{ row.name }}</h2><p class="mt-1 text-xs text-slate-400">{{ row.client_name }}<span v-if="row.client_revoked" class="text-amber-300"> · key revoked</span></p></div><button type="button" role="switch" :aria-checked="row.is_enabled" :aria-label="`Enable ${row.name}`" :disabled="pending.has(row.id)" class="shrink-0 rounded-full px-2.5 py-1 text-xs transition-colors duration-150 disabled:opacity-50 motion-reduce:transition-none" :class="row.is_enabled ? 'bg-emerald-400/10 text-emerald-300' : 'bg-white/5 text-slate-400'" @click="toggle(row)">{{ row.is_enabled ? 'Enabled' : 'Disabled' }}</button></div>
                    <p class="mt-3 break-all font-mono text-xs text-slate-300">{{ row.receiving_url }}</p><div class="mt-3 flex flex-wrap items-center gap-2"><span v-for="event in row.events" :key="event" class="rounded-md bg-sky-400/10 px-2 py-1 text-[11px] text-sky-300">{{ label(event) }}</span><button type="button" :aria-label="`Edit ${row.name}`" class="ml-auto px-2 py-1 text-xs text-sky-300" @click="open(row)">Edit</button></div>
                </article></div>
            </div>
            <footer class="border-t border-white/10 px-4 py-3 text-xs text-slate-500">{{ visible.length }} of {{ rows.length }} subscriptions</footer>
        </section>

        <DialogRoot :open="editor" @update:open="value => { if (!saving) { editor = value; if (!value) secrets = {}; } }">
            <DialogPortal><Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DialogOverlay class="fixed inset-0 z-[60] bg-black/70" /></Transition>
                <Transition enter-active-class="transition-[opacity,transform] duration-300 ease-out motion-reduce:transition-none" leave-active-class="transition-[opacity,transform] duration-200 ease-in motion-reduce:transition-none" enter-from-class="translate-y-4 opacity-0" leave-to-class="translate-y-4 opacity-0">
                    <DialogContent class="fixed left-1/2 top-1/2 z-[61] max-h-[90dvh] w-[calc(100%_-_1.5rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl border border-white/15 bg-[#171D23] p-5 text-slate-100 shadow-2xl focus:outline-none sm:p-6" @interact-outside="event => { if (saving) event.preventDefault(); }" @escape-key-down="event => { if (saving) event.preventDefault(); }">
                        <div class="flex justify-between gap-3"><DialogTitle class="text-lg font-semibold">{{ editing ? 'Edit Webhook' : 'Add Webhook' }}</DialogTitle><button type="button" aria-label="Close webhook editor" :disabled="saving" class="text-slate-400 hover:text-white disabled:opacity-50" @click="editor = false; secrets = {}">✕</button></div>
                        <DialogDescription class="mt-1 text-xs text-slate-400">Store a partner subscription. Delivery will be connected separately.</DialogDescription>
                        <form class="mt-5 space-y-4" @submit.prevent="save">
                            <label class="block text-xs font-medium">Name<input v-model="form.name" required maxlength="120" class="mt-1.5 block w-full rounded-lg border-white/10 bg-[#11171D] text-sm focus:border-sky-500 focus:ring-sky-500" /><span v-if="errors.name" class="mt-1 block text-red-300">{{ errors.name[0] }}</span></label>
                            <label class="block text-xs font-medium">Subscriber / API client<select v-model="form.api_client_id" required class="mt-1.5 block w-full rounded-lg border-white/10 bg-[#11171D] text-sm focus:border-sky-500 focus:ring-sky-500"><option value="" disabled>Select a subscriber</option><option v-for="item in apiClients" :key="item.id" :value="item.id">{{ item.name }}{{ item.revoked ? ' (key revoked)' : '' }}</option></select><span v-if="errors.api_client_id" class="mt-1 block text-red-300">{{ errors.api_client_id[0] }}</span></label>
                            <label class="block text-xs font-medium">Receiving URL<input v-model="form.receiving_url" type="url" required maxlength="2048" placeholder="https://partner.example/webhooks/diq" class="mt-1.5 block w-full rounded-lg border-white/10 bg-[#11171D] text-sm focus:border-sky-500 focus:ring-sky-500" /><span v-if="errors.receiving_url" class="mt-1 block text-red-300">{{ errors.receiving_url[0] }}</span></label>
                            <fieldset><legend class="text-xs font-medium">Events</legend><label v-for="event in eventOptions" :key="event.value" class="mt-2 flex items-center gap-2 text-sm text-slate-300"><input v-model="form.events" type="checkbox" :value="event.value" class="rounded border-white/20 bg-[#11171D] text-sky-500 focus:ring-sky-500" />{{ event.label }}</label><p v-if="errors.events || errors['events.0']" class="mt-1 text-xs text-red-300">{{ (errors.events ?? errors['events.0'])[0] }}</p></fieldset>
                            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_enabled" type="checkbox" class="rounded border-white/20 bg-[#11171D] text-sky-500 focus:ring-sky-500" />Enabled</label>
                            <section v-if="editing" class="rounded-lg border border-white/10 p-3"><div class="flex items-center justify-between gap-2"><h3 class="text-xs font-medium text-slate-300">Signing secret</h3><button type="button" :disabled="pending.has(editing.id)" class="text-xs text-sky-300 disabled:opacity-50" @click="reveal(editing)">{{ secrets[editing.id] ? 'Hide secret' : 'Reveal secret' }}</button></div><template v-if="secrets[editing.id]"><code class="mt-2 block select-all break-all text-xs text-slate-300">{{ secrets[editing.id] }}</code><button type="button" class="mt-2 text-xs text-sky-300" @click="copy(editing)">Copy secret</button></template><p v-else class="mt-2 text-xs text-slate-500">Encrypted in storage. Reveal only when needed.</p></section>
                            <p v-if="failure" role="alert" class="rounded-lg bg-red-500/10 p-3 text-xs text-red-300">{{ failure }}</p>
                            <div class="flex items-center justify-between gap-3 border-t border-white/10 pt-4"><button v-if="editing" type="button" class="text-sm text-red-300 hover:text-red-200" :disabled="saving" @click="removing = editing">Delete</button><span v-else /><div class="flex gap-2"><button type="button" :disabled="saving" class="rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-300 disabled:opacity-50" @click="editor = false; secrets = {}">Cancel</button><button type="submit" :disabled="saving" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold disabled:opacity-50">{{ saving ? 'Saving…' : 'Save Webhook' }}</button></div></div>
                        </form>
                    </DialogContent>
                </Transition>
            </DialogPortal>
        </DialogRoot>
        <DialogRoot :open="!!removing" @update:open="value => { if (!value && !saving) removing = null; }"><DialogPortal><DialogOverlay class="fixed inset-0 z-[70] bg-black/75" /><DialogContent class="fixed left-1/2 top-1/2 z-[71] w-[calc(100%_-_2rem)] max-w-sm -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-white/15 bg-[#171D23] p-5 text-slate-100 shadow-2xl" @interact-outside="event => { if (saving) event.preventDefault(); }" @escape-key-down="event => { if (saving) event.preventDefault(); }"><DialogTitle class="font-semibold">Delete webhook?</DialogTitle><DialogDescription class="mt-2 break-words text-sm text-slate-400">Remove {{ removing?.name }}? This cannot be undone.</DialogDescription><div class="mt-5 flex justify-end gap-2"><button type="button" :disabled="saving" class="rounded-lg border border-white/10 px-3 py-2 text-sm" @click="removing = null">Keep Webhook</button><button type="button" :disabled="saving" class="rounded-lg bg-red-600 px-3 py-2 text-sm disabled:opacity-50" @click="destroy">{{ saving ? 'Deleting…' : 'Delete Webhook' }}</button></div></DialogContent></DialogPortal></DialogRoot>
    </section>
</template>
