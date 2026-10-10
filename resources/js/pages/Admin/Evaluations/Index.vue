<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import {
    DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription,
    DropdownMenuRoot, DropdownMenuTrigger, DropdownMenuPortal, DropdownMenuContent, DropdownMenuItem,
} from 'reka-ui';

const props = defineProps({ evaluations: Object, filters: Object, models: Array });
const base = '/admin/nhl-sat-models/next-game';
const listing = ref(props.evaluations);
const filters = reactive({ q: '', status: '', sort: 'updated_at', direction: 'desc', ...props.filters });
const columns = [['name', 'Evaluation'], ['model', 'Model'], ['season_id', 'Project season'], ['status', 'Status'], ['progress', 'Preparation'], ['updated_at', 'Updated']];
const drawer = ref(false);
const deleting = ref(null);
const editing = ref(null);
const form = reactive({ name: '', model_run_id: '' });
const errors = ref({});
const error = ref('');
const notice = ref('');
const saving = ref(false);
const loading = ref(false);
const startButton = ref(null);
const selectedModel = computed(() => props.models.find(model => Number(model.id) === Number(form.model_run_id)));
const canCreate = computed(() => props.models.some(model => model.available));
let timer;
let requestId = 0;
let disposed = false;
let opener;
const statusLabel = row => row.active ? 'Building' : ({ ready: 'Ready', completed: 'Completed', failed: 'Failed' }[row.status] ?? row.status);
const season = value => value ? `${String(value).slice(0, 4)}–${String(value).slice(4)}` : '—';
const date = value => value ? new Date(value).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
const message = exception => exception.response?.data?.message ?? 'Unable to reach the server. Please try again.';

/** Poll only the small index payload; an older response cannot replace newer filters. */
async function refresh(page = listing.value.current_page, silent = false) {
    const id = ++requestId;
    if (!silent) loading.value = true;
    try {
        const response = await axios.get(base, { params: { ...filters, page }, headers: { Accept: 'application/json' } });
        if (!disposed && id === requestId) {
            listing.value = response.data.evaluations;
            error.value = '';
        }
    } catch (exception) {
        if (!disposed && id === requestId) error.value = message(exception);
    } finally {
        if (!disposed && id === requestId) loading.value = false;
    }
}

function sort(column) {
    filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
    filters.sort = column;
    refresh(1);
}

function openDrawer(row = null) {
    opener = row ? document.querySelector(`[data-evaluation-actions="${row.id}"]`) : document.activeElement;
    editing.value = row;
    form.name = row?.name ?? '';
    form.model_run_id = props.models.find(model => model.available)?.id ?? '';
    errors.value = {};
    drawer.value = true;
}

function closeDrawer(open) {
    if (!saving.value) drawer.value = open;
}

function restoreFocus(event) {
    event.preventDefault();
    (opener?.isConnected ? opener : startButton.value)?.focus();
}

async function save() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value) await axios.patch(`${base}/${editing.value.id}`, { name: form.name });
        else {
            await axios.post(base, { ...form });
            Object.assign(filters, { q: '', status: '', sort: 'updated_at', direction: 'desc' });
        }
        notice.value = editing.value ? 'Evaluation renamed.' : 'Evaluation created. Its initial estimates are being prepared.';
        drawer.value = false;
        await refresh(1);
    } catch (exception) {
        errors.value = exception.response?.data?.errors ?? { evaluation: [message(exception)] };
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        await axios.delete(`${base}/${deleting.value.id}`);
        deleting.value = null;
        notice.value = 'Evaluation deleted. Model projections were preserved.';
        await refresh(1);
    } catch (exception) {
        errors.value = { evaluation: [message(exception)] };
    } finally {
        saving.value = false;
    }
}

async function resume(row) {
    if (saving.value) return;
    saving.value = true;
    try {
        await axios.post(`${base}/${row.id}/resume`);
        notice.value = 'Preparation resumed from its last saved checkpoint.';
        await refresh();
    } catch (exception) {
        error.value = message(exception);
    } finally {
        saving.value = false;
    }
}

function confirmDelete(row) {
    opener = document.querySelector(`[data-evaluation-actions="${row.id}"]`);
    errors.value = {};
    deleting.value = row;
}

async function poll() {
    if (!document.hidden && !loading.value && !saving.value && listing.value.data.some(row => row.active)) await refresh(undefined, true);
    if (!disposed) timer = setTimeout(poll, 5000);
}
onMounted(() => { timer = setTimeout(poll, 5000); });
onBeforeUnmount(() => { disposed = true; clearTimeout(timer); });
</script>

<template>
    <Head title="Evaluations" />
    <main class="mx-auto max-w-7xl space-y-6 px-4 py-8 text-gray-900 sm:px-6">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-6">
            <div>
                <a href="/admin/nhl-sat-models" class="text-sm text-gray-500 underline underline-offset-4 hover:text-gray-900">SAT Models</a>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight">Evaluations</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600">A saved starting point for your next-game analysis. Prepare quarter and season estimates from a model’s existing projections.</p>
            </div>
            <button ref="startButton" :disabled="!canCreate" class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none" @click="openDrawer()"><span aria-hidden="true" class="text-xl font-normal">+</span> Start evaluation</button>
        </header>
        <p v-if="!canCreate" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Build SAT/60 and TOI/GP projections on a completed SAT model before starting an evaluation.</p>
        <p v-if="notice" role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ notice }}</p>
        <p v-if="error" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ error }} <button class="ml-2 underline" @click="refresh()">Retry</button></p>
        <section aria-label="Saved evaluations" class="space-y-4">
            <form class="flex flex-wrap items-center gap-3" @submit.prevent="refresh(1)">
                <input v-model="filters.q" type="search" maxlength="100" aria-label="Search evaluations" placeholder="Search evaluations or models…" class="min-h-11 min-w-60 flex-1 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <select v-model="filters.status" aria-label="Filter status" class="min-h-11 rounded-lg border-gray-300 text-sm" @change="refresh(1)"><option value="">All statuses</option><option value="building">Building</option><option value="ready">Ready</option><option value="failed">Failed</option><option value="completed">Completed</option></select>
                <button :disabled="loading" class="min-h-11 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium transition-colors duration-150 hover:bg-gray-50 disabled:opacity-50 motion-reduce:transition-none">{{ loading ? 'Loading…' : 'Search' }}</button>
            </form>
            <div class="relative overflow-x-auto rounded-xl border border-gray-200 bg-white" :aria-busy="loading">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-500"><tr>
                        <th v-for="[key, label] in columns" :key="key" class="whitespace-nowrap px-4 py-3 font-medium" :aria-sort="filters.sort === key ? (filters.direction === 'asc' ? 'ascending' : 'descending') : 'none'"><button class="inline-flex min-h-8 items-center gap-2 hover:text-gray-900" @click="sort(key)">{{ label }} <span aria-hidden="true">{{ filters.sort === key ? (filters.direction === 'asc' ? '↑' : '↓') : '↕' }}</span></button></th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in listing.data" :key="row.id" class="transition-colors duration-150 hover:bg-gray-50/70 motion-reduce:transition-none">
                            <td class="min-w-48 px-4 py-5"><p class="font-semibold text-gray-950">{{ row.name }}</p><p class="mt-1 text-xs text-gray-500">#{{ row.id }}</p><p v-if="row.error" class="mt-2 max-w-sm text-xs text-red-700">{{ row.error }}</p></td>
                            <td class="px-4 py-5 text-gray-600">{{ row.model_name }}</td>
                            <td class="whitespace-nowrap px-4 py-5 tabular-nums text-gray-600">{{ season(row.season_id) }}</td>
                            <td class="px-4 py-5"><span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium" :class="row.active ? 'bg-blue-50 text-blue-800' : row.status === 'failed' ? 'bg-red-50 text-red-800' : 'bg-emerald-50 text-emerald-800'"><span aria-hidden="true" class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ statusLabel(row) }}</span></td>
                            <td class="min-w-44 px-4 py-5"><p class="tabular-nums text-gray-700">{{ row.completed.toLocaleString() }} / {{ row.total.toLocaleString() }} {{ row.unit }}</p><p class="mt-1 text-xs text-gray-500">{{ row.active ? 'Updates automatically' : row.status === 'ready' ? 'Initial estimates saved' : row.status === 'failed' ? 'Needs attention' : 'Historical evaluation' }}</p></td>
                            <td class="whitespace-nowrap px-4 py-5 text-xs text-gray-500">{{ date(row.updated_at) }}</td>
                            <td class="px-4 py-5 text-right">
                                <DropdownMenuRoot>
                                    <DropdownMenuTrigger :data-evaluation-actions="row.id" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-xl text-gray-500 hover:bg-gray-100" :aria-label="`Actions for ${row.name}`">⋯</DropdownMenuTrigger>
                                    <DropdownMenuPortal><DropdownMenuContent align="end" :side-offset="6" class="z-40 min-w-44 rounded-lg border border-gray-200 bg-white p-1 text-sm shadow-lg">
                                        <DropdownMenuItem class="cursor-pointer rounded px-3 py-2 outline-none data-[highlighted]:bg-gray-100" @select="openDrawer(row)">Rename</DropdownMenuItem>
                                        <DropdownMenuItem v-if="row.legacy_url" as-child><a :href="row.legacy_url" class="block rounded px-3 py-2 outline-none data-[highlighted]:bg-gray-100">View results</a></DropdownMenuItem>
                                        <DropdownMenuItem v-if="row.can_resume" :disabled="saving" class="cursor-pointer rounded px-3 py-2 outline-none data-[highlighted]:bg-gray-100 data-[disabled]:opacity-40" @select="resume(row)">Resume preparation</DropdownMenuItem>
                                        <DropdownMenuItem :disabled="row.active || saving" class="cursor-pointer rounded px-3 py-2 text-red-700 outline-none data-[highlighted]:bg-red-50 data-[disabled]:cursor-not-allowed data-[disabled]:opacity-40" @select="confirmDelete(row)">Delete</DropdownMenuItem>
                                    </DropdownMenuContent></DropdownMenuPortal>
                                </DropdownMenuRoot>
                            </td>
                        </tr>
                        <tr v-if="!listing.data.length"><td colspan="7" class="px-6 py-16 text-center"><p class="font-medium text-gray-800">{{ filters.q || filters.status ? 'No matching evaluations' : 'Your analysis starts here' }}</p><p class="mt-2 text-sm text-gray-500">{{ filters.q || filters.status ? 'Try another name or status.' : 'Start an evaluation to prepare your first set of estimates.' }}</p></td></tr>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Evaluation pages" class="flex items-center justify-between text-sm text-gray-600"><span>{{ listing.total }} evaluations · Page {{ listing.current_page }} of {{ listing.last_page }}</span><div class="flex gap-2"><button :disabled="listing.current_page <= 1 || loading" class="rounded-lg border border-gray-300 px-3 py-2 disabled:opacity-40" @click="refresh(listing.current_page - 1)">Previous</button><button :disabled="listing.current_page >= listing.last_page || loading" class="rounded-lg border border-gray-300 px-3 py-2 disabled:opacity-40" @click="refresh(listing.current_page + 1)">Next</button></div></nav>
        </section>
        <DialogRoot :open="drawer" @update:open="closeDrawer">
            <DialogPortal force-mount>
                <Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DialogOverlay v-if="drawer" force-mount class="fixed inset-0 z-50 bg-gray-950/35" /></Transition>
                <Transition enter-active-class="transition-transform duration-500 ease-out motion-reduce:transition-none" leave-active-class="transition-transform duration-300 ease-in motion-reduce:transition-none" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
                    <DialogContent v-if="drawer" force-mount class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col bg-white shadow-2xl" @close-auto-focus="restoreFocus">
                        <form class="flex h-full min-h-0 flex-col" @submit.prevent="save">
                            <header class="flex items-center justify-between border-b border-gray-200 px-6 py-5"><DialogTitle class="text-lg font-semibold">{{ editing ? 'Rename evaluation' : 'Start evaluation' }}</DialogTitle><button type="button" :disabled="saving" aria-label="Close drawer" class="rounded-lg px-3 py-2 text-xl text-gray-500 hover:bg-gray-100" @click="closeDrawer(false)">×</button></header>
                            <div class="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                                <DialogDescription class="text-sm leading-6 text-gray-600">{{ editing ? 'Only the name changes. Saved estimates and the model remain unchanged.' : 'Quarter and season estimates start from saved model projections. No next-game simulations run yet.' }}</DialogDescription>
                                <label class="block text-sm font-medium">Name<input v-model="form.name" autofocus required maxlength="160" class="mt-2 w-full rounded-lg border-gray-300" placeholder="e.g. October outlook" /></label>
                                <template v-if="!editing">
                                    <label class="block text-sm font-medium">SAT model<select v-model="form.model_run_id" aria-label="SAT model" required class="mt-2 w-full rounded-lg border-gray-300"><option value="" disabled>Select a model</option><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }}{{ model.available ? '' : ' — projections required' }}</option></select></label>
                                    <div v-if="selectedModel" class="space-y-3 rounded-lg bg-gray-50 p-4 text-sm"><div><p class="text-xs text-gray-500">Train seasons</p><p class="mt-1 font-medium">{{ selectedModel.training_seasons.map(season).join(', ') }}</p></div><div><p class="text-xs text-gray-500">Project season</p><p class="mt-1 font-medium">{{ season(selectedModel.season_id) }}</p></div><p v-if="!selectedModel.available" role="alert" class="text-amber-800">{{ selectedModel.reason }}</p></div>
                                    <p class="text-xs leading-5 text-gray-500">EV, PP, PK and All stay separate. Missing strength values remain unavailable. Preparation runs one player at a time on the projections queue.</p>
                                </template>
                                <p v-for="(messages, key) in errors" :key="key" role="alert" class="text-sm text-red-700">{{ Array.isArray(messages) ? messages[0] : messages }}</p>
                            </div>
                            <footer class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4"><button type="button" :disabled="saving" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium" @click="closeDrawer(false)">Cancel</button><button :disabled="saving || (!editing && !selectedModel?.available)" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50 motion-reduce:transition-none">{{ saving ? 'Saving…' : editing ? 'Save name' : 'Create evaluation' }}</button></footer>
                        </form>
                    </DialogContent>
                </Transition>
            </DialogPortal>
        </DialogRoot>
        <DialogRoot :open="!!deleting" @update:open="value => { if (!value && !saving) deleting = null; }">
            <DialogPortal><DialogOverlay class="fixed inset-0 z-50 bg-gray-950/35" /><DialogContent class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 space-y-4 rounded-xl bg-white p-6 shadow-xl" @close-auto-focus="restoreFocus"><DialogTitle class="text-lg font-semibold">Delete evaluation?</DialogTitle><DialogDescription class="text-sm text-gray-600">Delete “{{ deleting?.name }}” and its saved evaluation data? This cannot be undone. The SAT model is preserved.</DialogDescription><p v-for="(messages, key) in errors" :key="key" role="alert" class="text-sm text-red-700">{{ messages[0] }}</p><div class="flex justify-end gap-3"><button :disabled="saving" class="rounded-lg border px-4 py-2 text-sm" @click="deleting = null">Cancel</button><button :disabled="saving" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="remove">{{ saving ? 'Deleting…' : 'Delete evaluation' }}</button></div></DialogContent></DialogPortal>
        </DialogRoot>
    </main>
</template>
