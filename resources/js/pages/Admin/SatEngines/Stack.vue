<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { baseUrl, gapLabel } from './engine-ui';

const props = defineProps({ stack: Object, engines: Array, models: Array, analysis: { type: Object, default: null } });
const name = ref(props.stack.name);
const productionModelRunId = ref(props.stack.production_model_run_id ?? '');
const engineId = ref('');
const action = useForm({});
const predictionSections = ref([]);
const predicting = ref(false);
const stopRequested = ref(false);
let predictionRequest = null;
const savesUrl = '/admin/admin-engine-game-predictions';
const saves = ref([]);
const selectedSave = ref('');
const saveName = ref('');
const saveError = ref('');
const saveStatus = ref('');
const saving = ref(false);
const loadingSaves = ref(false);
const deletePending = ref(false);
const capturedStack = ref(null);
const restoredName = ref('');
const busy = computed(() => predicting.value || saving.value || action.processing);
const copy = value => JSON.parse(JSON.stringify(value));
const messageFor = error => Object.values(error.response?.data?.errors ?? {}).flat()[0]
    ?? error.response?.data?.message ?? 'Request failed. Your results are still on this page.';
const refreshSaves = async () => {
    loadingSaves.value = true;
    try { saves.value = (await axios.get(savesUrl)).data.saves; }
    catch (error) { saveError.value = messageFor(error); }
    finally { loadingSaves.value = false; }
};
const snapshot = () => copy({ version: 2, stack: capturedStack.value, sections: predictionSections.value });
const saveNamed = async () => {
    saving.value = true;
    saveError.value = '';
    try {
        const { data } = await axios.post(savesUrl, { name: saveName.value, snapshot: snapshot() });
        saveStatus.value = `Saved ${data.name}`;
        saveName.value = '';
        await refreshSaves();
    } catch (error) { saveError.value = messageFor(error); }
    finally { saving.value = false; }
};
const restoreSave = async () => {
    if (predicting.value) return;
    saving.value = true;
    saveError.value = '';
    try {
        const { data } = await axios.get(`${savesUrl}/${selectedSave.value}`);
        const saved = data.snapshot;
        if (![1, 2].includes(saved?.version)) throw new Error('This autosave has no results yet.');
        predictionSections.value = saved.version === 2 ? saved.sections : [{
            member: saved.member, date: saved.date, rows: saved.rows, sort: saved.sort,
            open: true, error: '', status: 'Stopped',
        }];
        for (const section of predictionSections.value) {
            if (['Waiting', 'Predicting'].includes(section.status)) section.status = 'Stopped';
            for (const row of section.rows) {
                if (['Waiting', 'Predicting'].includes(row.status)) row.status = 'Stopped';
            }
        }
        capturedStack.value = saved.stack;
        restoredName.value = data.name;
        saveStatus.value = `Restored ${data.name}; no games recalculated.`;
    } catch (error) { saveError.value = error.response ? messageFor(error) : error.message; }
    finally { saving.value = false; }
};
const deleteSave = async () => {
    saving.value = true;
    saveError.value = '';
    try {
        await axios.delete(`${savesUrl}/${selectedSave.value}`);
        selectedSave.value = '';
        deletePending.value = false;
        saveStatus.value = 'Save deleted.';
        await refreshSaves();
    } catch (error) { saveError.value = messageFor(error); }
    finally { saving.value = false; }
};
onMounted(refreshSaves);
const sortedPredictions = section => section.rows.filter(row => row.source === 'production').sort((a, b) => {
    const key = section.sort.key;
    const first = a[key];
    const second = b[key];
    if (first == null) return second == null ? 0 : 1;
    if (second == null) return -1;
    return (typeof first === 'string' ? first.localeCompare(second) : Number(first) - Number(second)) * section.sort.direction;
});
const predictionColumns = [
    ['game', 'Game'], ['model', 'Model'], ['score', 'Predicted score'], ['spread', 'Spread'],
    ['skater', 'Skater confidence'], ['goalie', 'Goalie confidence'],
    ['internal', 'Internal confidence'], ['presentation', 'Presentation confidence'], ['qualified', 'Qualifies in model'],
];
const sortPredictions = (section, key) => {
    section.sort = { key, direction: section.sort.key === key ? -section.sort.direction : 1 };
};
// Let the in-flight request finish before another batch may begin; aborting a browser
// request does not necessarily stop PHP from calculating that game.
const cancelPredictions = () => { stopRequested.value = true; };
const newSection = member => ({
    member: { id: member.id, engine: { id: member.engine.id, name: member.engine.name,
        settings: { ...copy(member.engine.settings), diagnostic_confidence_upper_tolerance: 1 },
        model_run_id: member.engine.model_run_id, test_model_run_id: member.engine.test_model_run_id } },
    date: null, rows: [], sort: { key: 'game', direction: 1 }, open: true, error: '', status: 'Waiting',
});
const predictMembers = async (members, stackMode = false) => {
    if (busy.value || !members.length) return;
    const request = new AbortController();
    predictionRequest = request;
    predicting.value = true;
    stopRequested.value = false;
    const stackContext = { id: props.stack.id, name: props.stack.name, production_model_run_id: props.stack.production_model_run_id };
    if (capturedStack.value && JSON.stringify(capturedStack.value) !== JSON.stringify(stackContext)) predictionSections.value = [];
    capturedStack.value = stackContext;
    restoredName.value = '';
    saveError.value = '';
    saveStatus.value = '';
    const engineIds = [];
    for (const member of members) {
        const section = newSection(member);
        const index = predictionSections.value.findIndex(item => item.member.engine.id === member.engine.id);
        if (index === -1) predictionSections.value.push(section);
        else predictionSections.value.splice(index, 1, section);
        engineIds.push(member.engine.id);
    }
    const pending = engineIds.map(id => predictionSections.value.find(section => section.member.engine.id === id));
    let autosave = null;
    const sessionToken = crypto.randomUUID();
    const active = () => predictionRequest === request && !request.signal.aborted;
    const checkpoint = async () => {
        if (!active() || !predictionSections.value.length) return;
        const captured = snapshot();
        try {
            if (!autosave) autosave = (await axios.post(`${savesUrl}/autosave`, { session_token: sessionToken })).data;
            await axios.post(savesUrl, { autosave_id: autosave.id, session_token: sessionToken, snapshot: captured });
            if (active()) saveStatus.value = `Saved ${autosave.name}`;
        } catch (error) { if (active()) saveError.value = messageFor(error); }
    };
    try {
        await checkpoint();
        if (stackMode) {
            const endpoint = `${baseUrl}/stacks/${stackContext.id}/predictions`;
            try {
                const { data } = await axios.get(`${endpoint}/today`, { signal: request.signal });
                if (!active()) return;
                for (const section of pending) {
                    section.date = data.date;
                    section.status = 'Predicting';
                    section.rows = data.games.map(game => ({ id: game.nhl_game_id,
                        key: `${game.nhl_game_id}-production`, source: 'production', model: 'Production',
                        modelName: null, game: `${game.away_team_abbrev} @ ${game.home_team_abbrev}`, status: 'Waiting',
                        score: null, spread: null, skater: null, goalie: null, internal: null, presentation: null,
                        qualified: null, error: null, pickedBy: null }));
                }
                await checkpoint();
                for (const game of data.games) {
                    if (!active() || stopRequested.value) break;
                    const rows = pending.map(section => section.rows.find(row => row.id === game.nhl_game_id));
                    for (const row of rows) row.status = 'Predicting';
                    try {
                        const { data: result } = await axios.post(`${endpoint}/${game.nhl_game_id}`, {}, { signal: request.signal });
                        if (!active()) break;
                        const attempts = result.attempts ?? [];
                        const winner = attempts.find(attempt => attempt.prediction_available && attempt.pick_qualified);
                        const winnerIndex = pending.findIndex(section => Number(section.member.engine.id) === Number(winner?.engine_id));
                        for (const [index, section] of pending.entries()) {
                            const row = rows[index];
                            const attempt = attempts.find(item => Number(item.engine_id) === Number(section.member.engine.id));
                            if (!attempt) {
                                if (winner && winnerIndex >= 0 && index > winnerIndex) {
                                    row.status = 'Skipped';
                                    row.pickedBy = winner.engine_name;
                                } else {
                                    row.status = 'Unavailable';
                                    row.error = 'No Engine attempt was returned for this game.';
                                }
                                continue;
                            }
                            const prediction = attempt.prediction;
                            row.status = attempt.prediction_available ? 'Calculated' : 'Unavailable';
                            row.internal = attempt.internal_confidence;
                            row.skater = attempt.skater_confidence;
                            row.goalie = attempt.goalie_confidence;
                            row.modelName = props.models.find(model => Number(model.id) === Number(attempt.model_run_id))?.name
                                ?? `Model #${attempt.model_run_id}`;
                            row.presentation = prediction?.confidence_score ?? null;
                            row.spread = attempt.qualification_spread;
                            row.score = prediction ? `${prediction.predicted_score.away} – ${prediction.predicted_score.home}` : null;
                            row.qualified = attempt.prediction_available ? attempt.pick_qualified : null;
                            row.error = attempt.reason ?? null;
                        }
                    } catch (error) {
                        if (!active()) break;
                        for (const row of rows) {
                            row.status = 'Failed';
                            row.error = messageFor(error);
                        }
                    }
                    await checkpoint();
                }
                for (const section of pending) {
                    section.status = stopRequested.value ? 'Stopped'
                        : section.rows.some(row => row.status === 'Failed') ? 'Failed' : 'Calculated';
                }
            } catch (error) {
                if (active()) {
                    for (const section of pending) {
                        section.error = messageFor(error);
                        section.status = 'Failed';
                    }
                }
            }
        } else for (const section of pending) {
            if (!active() || stopRequested.value) break;
            section.status = 'Predicting';
            const endpoint = `${baseUrl}/stacks/${stackContext.id}/members/${section.member.id}/predictions`;
            try {
                const { data } = await axios.get(`${endpoint}/today`, { signal: request.signal });
                if (!active()) break;
                section.date = data.date;
                section.rows = data.games.map(game => ({ id: game.nhl_game_id,
                    key: `${game.nhl_game_id}-production`, source: 'production', model: 'Production',
                    modelName: props.models.find(model => Number(model.id) === Number(
                        stackContext.production_model_run_id ?? section.member.engine.model_run_id))?.name ?? 'Unavailable',
                    game: `${game.away_team_abbrev} @ ${game.home_team_abbrev}`, status: 'Waiting',
                    score: null, spread: null, skater: null, goalie: null, internal: null, presentation: null, qualified: null, error: null }));
                await checkpoint();
                for (const row of section.rows) {
                    if (!active() || stopRequested.value) break;
                    row.status = 'Predicting';
                    try {
                        const { data: result } = await axios.post(`${endpoint}/${row.id}`, { model_source: 'production' }, { signal: request.signal });
                        if (!active()) break;
                        const prediction = result.prediction;
                        row.status = result.prediction_available ? 'Calculated' : 'Unavailable';
                        row.internal = result.internal_confidence;
                        row.skater = result.skater_confidence;
                        row.goalie = result.goalie_confidence;
                        row.modelName = result.model_name ?? `Model #${result.model_run_id}`;
                        row.presentation = prediction?.confidence_score ?? null;
                        row.spread = result.qualification_spread ?? (prediction ? Math.abs(Number(prediction.goal_differential)) : null);
                        row.score = prediction ? `${prediction.predicted_score.away} – ${prediction.predicted_score.home}` : null;
                        row.qualified = result.prediction_available ? result.pick_qualified : null;
                        row.error = result.reason ?? null;
                    } catch (error) {
                        if (!active()) break;
                        row.status = 'Failed';
                        row.error = messageFor(error);
                    }
                    await checkpoint();
                }
                section.status = stopRequested.value ? 'Stopped'
                    : section.rows.some(row => row.status === 'Failed') ? 'Failed' : 'Calculated';
            } catch (error) {
                if (!active()) break;
                section.error = messageFor(error);
                section.status = 'Failed';
            }
            await checkpoint();
        }
    } finally {
        if (active()) {
            for (const section of pending) {
                if (['Waiting', 'Predicting'].includes(section.status)) section.status = 'Stopped';
                for (const row of section.rows) {
                    if (['Waiting', 'Predicting'].includes(row.status)) row.status = 'Stopped';
                }
            }
            await checkpoint();
            await refreshSaves();
            predictionRequest = null;
            predicting.value = false;
        }
    }
};
const predictToday = member => predictMembers([member]);
const predictStackToday = () => predictMembers(props.stack.members, true);
watch(() => JSON.stringify(props.stack), () => {
    stopRequested.value = true;
    // Keep the current request occupied until it finishes, but clear obsolete UI.
    predictionSections.value = [];
});
onBeforeUnmount(() => { predictionRequest?.abort(); predictionRequest = null; });
const saveStack = () => action.transform(() => ({ name: name.value, production_model_run_id: productionModelRunId.value || null })).patch(`${baseUrl}/stacks/${props.stack.id}`);
const addEngine = () => action.transform(() => ({ engine_id: engineId.value })).post(`${baseUrl}/stacks/${props.stack.id}/members`);
const removeMember = member => action.delete(`${baseUrl}/stacks/${props.stack.id}/members/${member.id}`);
const deleteStack = () => action.delete(`${baseUrl}/stacks/${props.stack.id}`);
const effectiveModelName = engineModelRunId => {
    const modelRunId = props.stack.production_model_run_id ?? engineModelRunId;
    return props.models.find(model => Number(model.id) === Number(modelRunId))?.name ?? `Model #${modelRunId}`;
};
const makeDefault = () => action.post(`${baseUrl}/stacks/${props.stack.id}/default`);
const reorder = (from, to) => {
    const ids = props.stack.members.map(member => member.id);
    ids.splice(to, 0, ids.splice(from, 1)[0]);
    action.transform(() => ({ member_ids: ids })).put(`${baseUrl}/stacks/${props.stack.id}/members/order`);
};
</script>

<template>
    <Head :title="stack.name" />
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-5"><div><Link :href="`${baseUrl}?tab=stacks`" class="text-sm font-medium text-indigo-700">SAT Engines / Stacks</Link><h1 class="mt-2 text-2xl font-semibold text-gray-950">{{ stack.name }}<span v-if="stack.is_default" class="ml-2 text-base font-normal text-gray-500">(default)</span></h1><p class="mt-1 text-sm text-gray-600">Every Engine participates in the explicit priority order below.</p></div><div class="flex gap-2"><button v-if="!stack.is_default" type="button" :disabled="!stack.members.length || action.processing" class="rounded-lg border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-50 disabled:opacity-50 motion-reduce:transition-none" @click="makeDefault">Make default</button><button type="button" :disabled="busy || !stack.members.length" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50 motion-reduce:transition-none" @click="predictStackToday">Predict today</button><button type="button" :disabled="busy" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 transition-colors duration-150 hover:bg-red-50 disabled:opacity-50 motion-reduce:transition-none" @click="deleteStack">Delete stack</button></div></header>
        <p v-for="(error, key) in action.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><form class="flex flex-wrap items-end gap-3" @submit.prevent="saveStack"><label class="min-w-64 flex-1 text-sm font-medium text-gray-800">Stack name<input v-model="name" required maxlength="160" class="mt-1 w-full rounded-lg border-gray-300" /></label><label class="min-w-64 flex-1 text-sm font-medium text-gray-800">Production SAT Model<select v-model="productionModelRunId" class="mt-1 w-full rounded-lg border-gray-300"><option value="">Each Engine's own model</option><option v-for="model in models.filter(model => model.status === 'complete')" :key="model.id" :value="model.id">{{ model.name }}</option></select><span class="mt-1 block text-xs font-normal text-gray-500">Overrides every member only while this stack makes predictions.</span></label><button :disabled="action.processing" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition-colors duration-150 hover:bg-gray-50 disabled:opacity-50 motion-reduce:transition-none">Save stack</button></form></section>
        <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm"><table class="w-full text-left text-sm"><thead class="border-b border-gray-200 bg-gray-50 text-xs font-medium uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-3">Priority</th><th class="px-4 py-3">Engine</th><th class="px-4 py-3">Effective model</th><th class="px-4 py-3 text-right">Test record</th><th class="px-4 py-3 text-right">Win %</th><th class="px-4 py-3 text-right">Coverage</th><th class="px-4 py-3 text-right">Added record</th><th class="px-4 py-3 text-right">Stack impact</th><th class="px-4 py-3"><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="(member, index) in stack.members" :key="member.id" class="border-b border-gray-100 last:border-0"><td class="px-4 py-3 font-medium tabular-nums text-gray-500">{{ index + 1 }}</td><td class="px-4 py-3 font-medium text-gray-900">{{ member.engine.name }}</td><td class="px-4 py-3 text-gray-600">{{ effectiveModelName(member.engine.model_run_id) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ member.engine.discovery_metrics ? `${member.engine.discovery_metrics.wins}–${member.engine.discovery_metrics.losses}` : '—' }}</td><td class="px-4 py-3 text-right tabular-nums">{{ member.engine.discovery_win_pct == null ? '—' : `${Number(member.engine.discovery_win_pct).toFixed(1)}%` }}</td><td class="px-4 py-3 text-right tabular-nums">{{ member.engine.discovery_coverage_pct == null ? '—' : `${Number(member.engine.discovery_coverage_pct).toFixed(1)}%` }}</td><td class="px-4 py-3 text-right tabular-nums">{{ analysis?.candidates[index] ? `${analysis.candidates[index].stack_wins}–${analysis.candidates[index].stack_losses}` : '—' }}</td><td class="px-4 py-3 text-right tabular-nums">{{ analysis?.candidates[index] ? `${Number(analysis.candidates[index].stack_win_pct).toFixed(1)}% / ${Number(analysis.candidates[index].stack_coverage_pct).toFixed(1)}%` : '—' }}</td><td class="px-4 py-3"><div class="flex justify-end gap-2"><button type="button" :disabled="predicting || action.processing" class="whitespace-nowrap rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:opacity-50 motion-reduce:transition-none" @click="predictToday(member)">Predict today</button><button type="button" :disabled="index === 0 || action.processing" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40" aria-label="Move earlier" @click="reorder(index, index - 1)">↑</button><button type="button" :disabled="index === stack.members.length - 1 || action.processing" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40" aria-label="Move later" @click="reorder(index, index + 1)">↓</button><button type="button" :disabled="action.processing" class="text-sm text-red-700 underline" @click="removeMember(member)">Remove</button></div></td></tr></tbody></table><p v-if="!stack.members.length" class="px-5 py-10 text-center text-sm text-gray-500">This stack has no Engines.</p></section>
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><h2 class="text-base font-semibold text-gray-950">Add an existing Engine</h2><form class="mt-4 flex flex-wrap gap-2" @submit.prevent="addEngine"><select v-model="engineId" required class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm"><option disabled value="">Select an Engine</option><option v-for="engine in engines.filter(engine => !stack.members.some(member => member.engine_id === engine.id))" :key="engine.id" :value="engine.id">{{ engine.name }}</option></select><button :disabled="action.processing" class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Add Engine</button></form></section>
        <section class="rounded-xl border border-gray-200 bg-white p-5" aria-labelledby="saved-predictions-title">
            <h2 id="saved-predictions-title" class="text-lg font-semibold text-gray-900">Saved engine game predictions</h2>
            <p class="mt-1 text-sm text-gray-600">Private to you · 4 rotating autosaves · {{ saves.filter(save => save.autosave_slot === null).length }}/50 named saves. Named saves are never automatically overwritten.</p>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <label class="min-w-56 flex-1 text-sm font-medium text-gray-700">Saved results
                    <select v-model="selectedSave" :disabled="loadingSaves || saving || predicting" class="mt-1 w-full rounded-lg border-gray-300" @change="deletePending = false">
                        <option value="">{{ loadingSaves ? 'Loading saves…' : 'Choose a save' }}</option>
                        <option v-for="save in saves" :key="save.id" :value="save.id">{{ save.name }} · {{ new Date(save.updated_at).toLocaleString() }}</option>
                    </select>
                </label>
                <button type="button" :disabled="!selectedSave || saving || predicting" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium transition-colors duration-150 hover:bg-gray-50 disabled:opacity-50 motion-reduce:transition-none" @click="restoreSave">Restore</button>
                <button type="button" :disabled="!selectedSave || saving || predicting" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 transition-colors duration-150 hover:bg-red-50 disabled:opacity-50 motion-reduce:transition-none" @click="deletePending = true">Delete</button>
            </div>
            <div v-if="deletePending" class="mt-3 flex flex-wrap items-center gap-3 text-sm" role="alert">
                <span>Delete this saved copy? This cannot be undone.</span>
                <button type="button" :disabled="saving" class="font-semibold text-red-700 underline disabled:opacity-50" @click="deleteSave">Confirm delete</button>
                <button type="button" class="text-gray-600 underline" @click="deletePending = false">Cancel</button>
            </div>
            <p v-if="!loadingSaves && !saves.length" class="mt-3 text-sm text-gray-500">No saved predictions yet. Choose Predict today on an Engine to begin.</p>
            <form v-if="predictionSections.length" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="saveNamed">
                <label class="min-w-56 flex-1 text-sm font-medium text-gray-700">Save current results as
                    <input v-model="saveName" required maxlength="120" class="mt-1 w-full rounded-lg border-gray-300" placeholder="Name this snapshot" />
                </label>
                <button :disabled="saving || !saveName.trim()" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 disabled:opacity-50 motion-reduce:transition-none">{{ saving ? 'Saving…' : 'Save named copy' }}</button>
            </form>
            <p v-if="saveError" role="alert" class="mt-3 text-sm text-red-700">{{ saveError }}</p>
            <p v-if="saveStatus" role="status" aria-live="polite" class="mt-3 text-sm text-gray-600">{{ saveStatus }}</p>
            <button v-if="predicting" type="button" :disabled="stopRequested" class="mt-3 rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:opacity-50" @click="cancelPredictions">{{ stopRequested ? 'Finishing current request…' : 'Stop remaining predictions' }}</button>
        </section>
        <section v-for="section in predictionSections" :key="section.member.engine.id" class="rounded-xl border border-gray-200 bg-white shadow-sm" :aria-labelledby="`predictions-title-${section.member.engine.id}`" :aria-busy="section.status === 'Predicting'">
            <h2 :id="`predictions-title-${section.member.engine.id}`">
                <button type="button" class="flex w-full items-center justify-between gap-3 rounded-xl p-5 text-left transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-indigo-600 motion-reduce:transition-none"
                    :aria-expanded="section.open" :aria-controls="`predictions-panel-${section.member.engine.id}`" @click="section.open = !section.open">
                    <span class="text-lg font-semibold">{{ section.member.engine.name }}<span class="ml-2 text-sm font-normal text-gray-500">{{ section.status }} · {{ section.rows.filter(row => row.status === 'Calculated').length }}/{{ section.rows.length }} calculated</span></span>
                    <span aria-hidden="true" class="transition-transform duration-300 ease-out motion-reduce:transition-none" :class="section.open ? 'rotate-180' : ''">⌄</span>
                </button>
            </h2>
            <div :id="`predictions-panel-${section.member.engine.id}`" :inert="!section.open" :aria-hidden="!section.open" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="section.open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
            <div class="min-h-0 overflow-hidden">
            <div class="p-5 pt-0">
                <div>
                    <p class="mt-1 text-sm text-gray-600">{{ section.date ?? 'Waiting for games' }} · America/Toronto · Production model</p>
                    <p class="mt-2 inline-flex rounded-lg bg-indigo-50 px-3 py-1.5 text-sm font-semibold tabular-nums text-indigo-800">Defined confidence range: {{ section.member.engine.settings?.confidence_min ?? '—' }}%–{{ section.member.engine.settings?.confidence_max ?? '—' }}%</p>
                    <p v-if="section.member.engine.settings?.diagnostic_confidence_upper_tolerance" class="mt-1 text-xs text-gray-500">Qualification allows +1 percentage point above the defined upper limit (maximum 100%).</p>
                    <p class="mt-2 text-sm font-semibold tabular-nums text-indigo-800">Required spread: {{ gapLabel(section.member.engine.settings ?? { gap: null }) }}</p>
                    <p v-if="restoredName" class="mt-2 text-sm text-gray-600">Saved snapshot: {{ restoredName }} · {{ capturedStack?.name }} · Historical results, not recalculated</p>
                    <p class="mt-1 text-xs text-gray-500">Production determines qualification and outcome. Saved snapshots retain the rules used when captured. Scores are away–home; spread uses this Engine's saved units. Games picked earlier show that Engine's name without another prediction.</p></div>
            </div>
            <p v-if="section.error" role="alert" class="px-5 pb-4 text-sm text-red-700">{{ section.error }}</p>
            <p role="status" aria-live="polite" class="px-5 pb-4 text-sm text-gray-500">{{ section.status === 'Predicting' ? 'Calculating one game at a time…' : section.status }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead class="border-y border-gray-200 bg-gray-50"><tr>
                        <th v-for="[key, label] in predictionColumns" :key="key" scope="col" :aria-sort="section.sort.key === key ? (section.sort.direction === 1 ? 'ascending' : 'descending') : 'none'">
                            <button type="button" class="whitespace-nowrap px-4 py-3 font-medium text-gray-600" @click="sortPredictions(section, key)">{{ label }} ↕</button>
                        </th><th scope="col" class="px-4 py-3">Status</th>
                    </tr></thead>
                    <tbody><tr v-for="row in sortedPredictions(section)" :key="row.key" class="border-b border-gray-100" :class="row.source === 'test' ? 'bg-gray-50' : ''">
                        <th scope="row" class="whitespace-nowrap px-4 py-3 font-medium">{{ row.game }}</th>
                        <td v-if="row.status === 'Skipped'" colspan="9" class="px-4 py-3 text-sm font-medium text-indigo-700">Picked by {{ row.pickedBy }}</td>
                        <template v-else>
                        <td class="whitespace-nowrap px-4 py-3">{{ row.model }}<span class="block text-xs text-gray-500">{{ row.modelName }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3">{{ row.score ?? '—' }}</td>
                        <td class="px-4 py-3">{{ row.spread == null ? '—' : row.spread.toFixed(4) + (section.member.engine.settings?.gap_unit === 'percent' ? '%' : '') }}</td>
                        <td class="px-4 py-3">{{ row.skater == null ? '—' : Number(row.skater).toFixed(2) + '%' }}</td>
                        <td class="px-4 py-3">{{ row.goalie == null ? '—' : Number(row.goalie).toFixed(2) + '%' }}</td>
                        <td class="px-4 py-3">{{ row.internal == null ? '—' : row.internal + '%' }}</td>
                        <td class="px-4 py-3">{{ row.presentation == null ? '—' : Number(row.presentation).toFixed(1) + '%' }}</td>
                        <td class="px-4 py-3 font-medium">{{ row.qualified == null ? '—' : row.qualified ? 'Yes' : 'No' }}</td>
                        <td class="max-w-xs px-4 py-3 text-xs text-gray-600">{{ row.status }}<span v-if="row.error" class="mt-1 block text-red-700">{{ row.error }}</span></td>
                        </template>
                    </tr></tbody>
                </table>
                <p class="p-5 text-xs text-gray-500">Skater and goalie confidence are the two-team averages before weighting. Internal confidence = rounded (70% skater + 30% goalie).</p>
                <p v-if="section.status === 'Calculated' && !section.error && !section.rows.length" class="p-5 text-sm text-gray-500">No game results assigned to this Engine.</p>
            </div>
            </div></div>
        </section>
    </div>
</template>
