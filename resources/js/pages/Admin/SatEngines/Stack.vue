<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import axios from 'axios';
import { baseUrl } from './engine-ui';

const props = defineProps({ stack: Object, engines: Array, models: Array, analysis: { type: Object, default: null } });
const name = ref(props.stack.name);
const productionModelRunId = ref(props.stack.production_model_run_id ?? '');
const engineId = ref('');
const action = useForm({});
const predictionRows = ref([]);
const predictionMember = ref(null);
const predictionDate = ref('');
const predictionError = ref('');
const predicting = ref(false);
const predictionSort = ref({ key: 'game', direction: 1 });
let predictionRequest = null;
const sortedPredictions = computed(() => [...predictionRows.value].sort((a, b) => {
    const key = predictionSort.value.key;
    const first = a[key];
    const second = b[key];
    if (first == null) return second == null ? 0 : 1;
    if (second == null) return -1;
    return (typeof first === 'string' ? first.localeCompare(second) : Number(first) - Number(second)) * predictionSort.value.direction;
}));
const predictionColumns = [
    ['game', 'Game'], ['model', 'Model'], ['score', 'Predicted score'], ['spread', 'Spread'],
    ['skater', 'Skater confidence'], ['goalie', 'Goalie confidence'],
    ['internal', 'Internal confidence'], ['presentation', 'Presentation confidence'], ['qualified', 'Pick qualified'],
];
const sortPredictions = key => {
    predictionSort.value = { key, direction: predictionSort.value.key === key ? -predictionSort.value.direction : 1 };
};
const cancelPredictions = () => {
    predictionRequest?.abort();
    predictionRequest = null;
    predicting.value = false;
};
const predictToday = async member => {
    cancelPredictions();
    const request = new AbortController();
    predictionRequest = request;
    predictionMember.value = member;
    predictionRows.value = [];
    predictionError.value = '';
    predictionDate.value = '';
    predicting.value = true;
    const endpoint = `${baseUrl}/stacks/${props.stack.id}/members/${member.id}/predictions`;
    try {
        const { data } = await axios.get(`${endpoint}/today`, { signal: request.signal });
        if (request.signal.aborted) return;
        predictionDate.value = data.date;
        predictionRows.value = data.games.flatMap(game => ['production', 'test'].map(source => ({ id: game.nhl_game_id,
            key: `${game.nhl_game_id}-${source}`, source,
            model: source === 'production' ? 'Production' : 'Test/Train',
            modelName: props.models.find(model => Number(model.id) === Number(source === 'production'
                ? props.stack.production_model_run_id ?? member.engine.model_run_id : member.engine.test_model_run_id))?.name ?? 'Unavailable',
            game: `${game.away_team_abbrev} @ ${game.home_team_abbrev}`, status: 'Waiting',
            score: null, spread: null, skater: null, goalie: null, internal: null, presentation: null, qualified: null, error: null })));
        for (const row of predictionRows.value) {
            if (request.signal.aborted) break;
            row.status = 'Predicting';
            try {
                const { data: result } = await axios.post(`${endpoint}/${row.id}`, { model_source: row.source }, { signal: request.signal });
                if (request.signal.aborted) break;
                const prediction = result.prediction;
                row.status = result.prediction_available ? 'Calculated' : 'Unavailable';
                row.internal = result.internal_confidence;
                row.skater = result.skater_confidence;
                row.goalie = result.goalie_confidence;
                row.modelName = result.model_name ?? `Model #${result.model_run_id}`;
                row.presentation = prediction?.confidence_score ?? null;
                row.spread = prediction ? Math.abs(Number(prediction.goal_differential)) : null;
                row.score = prediction ? `${prediction.predicted_score.away} – ${prediction.predicted_score.home}` : null;
                row.qualified = result.prediction_available ? result.pick_qualified : null;
                row.error = result.reason;
            } catch (error) {
                if (request.signal.aborted) break;
                row.status = 'Failed';
                row.error = error.response?.data?.message ?? 'Prediction failed. Click Predict today to retry.';
            }
        }
    } catch (error) {
        if (!request.signal.aborted) predictionError.value = error.response?.data?.message ?? 'Could not load today’s games. Try again.';
    } finally {
        if (predictionRequest === request) {
            predictionRequest = null;
            predicting.value = false;
        }
    }
};
watch(() => JSON.stringify(props.stack), () => {
    cancelPredictions();
    predictionMember.value = null;
    predictionRows.value = [];
});
onBeforeUnmount(cancelPredictions);
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
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-gray-200 pb-5"><div><Link :href="`${baseUrl}?tab=stacks`" class="text-sm font-medium text-indigo-700">SAT Engines / Stacks</Link><h1 class="mt-2 text-2xl font-semibold text-gray-950">{{ stack.name }}<span v-if="stack.is_default" class="ml-2 text-base font-normal text-gray-500">(default)</span></h1><p class="mt-1 text-sm text-gray-600">Every Engine participates in the explicit priority order below.</p></div><div class="flex gap-2"><button v-if="!stack.is_default" type="button" :disabled="!stack.members.length || action.processing" class="rounded-lg border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-50 disabled:opacity-50 motion-reduce:transition-none" @click="makeDefault">Make default</button><button type="button" :disabled="action.processing" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700 transition-colors duration-150 hover:bg-red-50 disabled:opacity-50 motion-reduce:transition-none" @click="deleteStack">Delete stack</button></div></header>
        <p v-for="(error, key) in action.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><form class="flex flex-wrap items-end gap-3" @submit.prevent="saveStack"><label class="min-w-64 flex-1 text-sm font-medium text-gray-800">Stack name<input v-model="name" required maxlength="160" class="mt-1 w-full rounded-lg border-gray-300" /></label><label class="min-w-64 flex-1 text-sm font-medium text-gray-800">Production SAT Model<select v-model="productionModelRunId" class="mt-1 w-full rounded-lg border-gray-300"><option value="">Each Engine's own model</option><option v-for="model in models.filter(model => model.status === 'complete')" :key="model.id" :value="model.id">{{ model.name }}</option></select><span class="mt-1 block text-xs font-normal text-gray-500">Overrides every member only while this stack makes predictions.</span></label><button :disabled="action.processing" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition-colors duration-150 hover:bg-gray-50 disabled:opacity-50 motion-reduce:transition-none">Save stack</button></form></section>
        <section class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm"><table class="w-full text-left text-sm"><thead class="border-b border-gray-200 bg-gray-50 text-xs font-medium uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-3">Priority</th><th class="px-4 py-3">Engine</th><th class="px-4 py-3">Effective model</th><th class="px-4 py-3 text-right">Test record</th><th class="px-4 py-3 text-right">Win %</th><th class="px-4 py-3 text-right">Coverage</th><th class="px-4 py-3 text-right">Added record</th><th class="px-4 py-3 text-right">Stack impact</th><th class="px-4 py-3"><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="(member, index) in stack.members" :key="member.id" class="border-b border-gray-100 last:border-0"><td class="px-4 py-3 font-medium tabular-nums text-gray-500">{{ index + 1 }}</td><td class="px-4 py-3 font-medium text-gray-900">{{ member.engine.name }}</td><td class="px-4 py-3 text-gray-600">{{ effectiveModelName(member.engine.model_run_id) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ member.engine.discovery_metrics ? `${member.engine.discovery_metrics.wins}–${member.engine.discovery_metrics.losses}` : '—' }}</td><td class="px-4 py-3 text-right tabular-nums">{{ member.engine.discovery_win_pct == null ? '—' : `${Number(member.engine.discovery_win_pct).toFixed(1)}%` }}</td><td class="px-4 py-3 text-right tabular-nums">{{ member.engine.discovery_coverage_pct == null ? '—' : `${Number(member.engine.discovery_coverage_pct).toFixed(1)}%` }}</td><td class="px-4 py-3 text-right tabular-nums">{{ analysis?.candidates[index] ? `${analysis.candidates[index].stack_wins}–${analysis.candidates[index].stack_losses}` : '—' }}</td><td class="px-4 py-3 text-right tabular-nums">{{ analysis?.candidates[index] ? `${Number(analysis.candidates[index].stack_win_pct).toFixed(1)}% / ${Number(analysis.candidates[index].stack_coverage_pct).toFixed(1)}%` : '—' }}</td><td class="px-4 py-3"><div class="flex justify-end gap-2"><button type="button" :disabled="predicting || action.processing" class="whitespace-nowrap rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:opacity-50 motion-reduce:transition-none" @click="predictToday(member)">Predict today</button><button type="button" :disabled="index === 0 || action.processing" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40" aria-label="Move earlier" @click="reorder(index, index - 1)">↑</button><button type="button" :disabled="index === stack.members.length - 1 || action.processing" class="rounded border border-gray-300 px-2 py-1 text-xs disabled:opacity-40" aria-label="Move later" @click="reorder(index, index + 1)">↓</button><button type="button" :disabled="action.processing" class="text-sm text-red-700 underline" @click="removeMember(member)">Remove</button></div></td></tr></tbody></table><p v-if="!stack.members.length" class="px-5 py-10 text-center text-sm text-gray-500">This stack has no Engines.</p></section>
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm"><h2 class="text-base font-semibold text-gray-950">Add an existing Engine</h2><form class="mt-4 flex flex-wrap gap-2" @submit.prevent="addEngine"><select v-model="engineId" required class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm"><option disabled value="">Select an Engine</option><option v-for="engine in engines.filter(engine => !stack.members.some(member => member.engine_id === engine.id))" :key="engine.id" :value="engine.id">{{ engine.name }}</option></select><button :disabled="action.processing" class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Add Engine</button></form></section>
        <section v-if="predictionMember" class="rounded-xl border border-gray-200 bg-white shadow-sm" aria-labelledby="predictions-title" :aria-busy="predicting">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                <div><h2 id="predictions-title" class="text-lg font-semibold">Predict today · {{ predictionMember.engine.name }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ predictionDate }} · America/Toronto · Production versus saved Test/Train model · Same Engine settings</p>
                    <p class="mt-1 text-xs text-gray-500">Selected Engine only. Scores are away–home; spread is the absolute goal difference.</p></div>
                <button v-if="predicting" type="button" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" @click="cancelPredictions">Stop remaining games</button>
            </div>
            <p v-if="predictionError" role="alert" class="px-5 pb-4 text-sm text-red-700">{{ predictionError }}</p>
            <p role="status" aria-live="polite" class="px-5 pb-4 text-sm text-gray-500">{{ predicting ? 'Calculating one game at a time…' : 'Request stopped or finished.' }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm tabular-nums">
                    <thead class="border-y border-gray-200 bg-gray-50"><tr>
                        <th v-for="[key, label] in predictionColumns" :key="key" scope="col" :aria-sort="predictionSort.key === key ? (predictionSort.direction === 1 ? 'ascending' : 'descending') : 'none'">
                            <button type="button" class="whitespace-nowrap px-4 py-3 font-medium text-gray-600" @click="sortPredictions(key)">{{ label }} ↕</button>
                        </th><th scope="col" class="px-4 py-3">Status</th>
                    </tr></thead>
                    <tbody><tr v-for="row in sortedPredictions" :key="row.key" class="border-b border-gray-100" :class="row.source === 'test' ? 'bg-gray-50' : ''">
                        <th scope="row" class="whitespace-nowrap px-4 py-3 font-medium">{{ row.game }}</th>
                        <td class="whitespace-nowrap px-4 py-3">{{ row.model }}<span class="block text-xs text-gray-500">{{ row.modelName }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3">{{ row.score ?? '—' }}</td>
                        <td class="px-4 py-3">{{ row.spread == null ? '—' : row.spread.toFixed(4) }}</td>
                        <td class="px-4 py-3">{{ row.skater == null ? '—' : Number(row.skater).toFixed(2) + '%' }}</td>
                        <td class="px-4 py-3">{{ row.goalie == null ? '—' : Number(row.goalie).toFixed(2) + '%' }}</td>
                        <td class="px-4 py-3">{{ row.internal == null ? '—' : row.internal + '%' }}</td>
                        <td class="px-4 py-3">{{ row.presentation == null ? '—' : Number(row.presentation).toFixed(1) + '%' }}</td>
                        <td class="px-4 py-3 font-medium">{{ row.qualified == null ? '—' : row.qualified ? 'Yes' : 'No' }}</td>
                        <td class="max-w-xs px-4 py-3 text-xs text-gray-600">{{ row.status }}<span v-if="row.error" class="mt-1 block text-red-700">{{ row.error }}</span></td>
                    </tr></tbody>
                </table>
                <p class="p-5 text-xs text-gray-500">Skater and goalie confidence are the two-team averages before weighting. Internal confidence = rounded (70% skater + 30% goalie). Test/Train uses today's lineups with the saved test model, not an old discovery result.</p>
                <p v-if="!predicting && !predictionError && !predictionRows.length" class="p-5 text-sm text-gray-500">No games scheduled today.</p>
            </div>
        </section>
    </div>
</template>
