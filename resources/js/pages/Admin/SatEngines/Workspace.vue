<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import SettingsFields from './SettingsFields.vue';
import Pagination from './Pagination.vue';
import { baseUrl, discoveryDefaults, runPayload } from './engine-ui';

const props = defineProps({ engine: Object, models: Array, defaults: Object, teams: Array, runs: Object });
const editing = useForm({ name: props.engine?.name ?? '', test_model_run_id: props.engine?.test_model_run_id ?? props.models[0]?.id ?? '', model_run_id: props.engine?.model_run_id ?? props.models[0]?.id ?? '', settings: { ...(props.engine?.settings ?? props.defaults) }, notes: props.engine?.notes ?? '' });
const evaluation = useForm({ ...discoveryDefaults(), engine_id: props.engine?.id ?? null, model_run_id: props.engine?.test_model_run_id ?? props.models[0]?.id ?? '', kind: props.engine ? 'build' : 'discovery' });
const gameIdsText = ref('');
watch(() => props.engine?.test_model_run_id, value => { if (value) evaluation.model_run_id = value; });
const confirmDelete = ref(false);
const deletion = useForm({});
const model = computed(() => props.models.find(item => Number(item.id) === Number(evaluation.model_run_id)));
const start = () => evaluation.transform(data => runPayload(data, gameIdsText.value)).post(`${baseUrl}/runs`);
</script>

<template>
    <Head :title="engine?.name ?? 'Discover SAT engine settings'" />
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6">
        <header class="border-b border-gray-200 pb-4"><Link :href="baseUrl" class="text-sm text-indigo-700">SAT Engines</Link><h1 class="mt-2 text-2xl font-semibold">{{ engine?.name ?? 'Discover engine settings' }}</h1></header>
        <form v-if="engine && evaluation.kind === 'build'" class="space-y-4" @submit.prevent="editing.put(`${baseUrl}/${engine.id}`)">
            <h2 class="text-lg font-semibold">Engine settings</h2>
            <div class="grid gap-4 sm:grid-cols-3"><label class="text-sm">Name<input v-model="editing.name" required maxlength="160" class="mt-1 w-full rounded border-gray-300" /></label><label class="text-sm">Test Model<select v-model="editing.test_model_run_id" class="mt-1 w-full rounded border-gray-300"><option v-for="item in models" :key="item.id" :value="item.id">{{ item.name }} · {{ item.status }}</option></select></label><label class="text-sm">Production Model<select v-model="editing.model_run_id" class="mt-1 w-full rounded border-gray-300"><option v-for="item in models" :key="item.id" :value="item.id">{{ item.name }} · {{ item.status }}</option></select></label></div>
            <SettingsFields v-model:settings="editing.settings" />
            <label class="block text-sm">Notes<textarea v-model="editing.notes" class="mt-1 w-full rounded border-gray-300" maxlength="2000" /></label>
            <p v-for="(error, key) in editing.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
            <button :disabled="editing.processing" class="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">Save settings</button>
            <p v-if="editing.recentlySuccessful" role="status" class="text-sm text-green-700">Settings saved.</p>
        </form>
        <form class="space-y-4 border-t border-gray-200 pt-6" @submit.prevent="start">
            <h2 class="text-lg font-semibold">{{ evaluation.kind === 'build' ? 'Test engine' : 'Discover settings' }}</h2>
            <p class="text-sm text-gray-600">Test evaluates saved engine settings. Discovery finds offense/defense percentages, confidence ranges and score gaps using your selected games and targets. Results describe this historical sample, not a guaranteed future win rate.</p>
            <div class="grid gap-4 sm:grid-cols-3">
                <label v-if="engine" class="text-sm">Process<select v-model="evaluation.kind" class="mt-1 w-full rounded border-gray-300"><option value="build">Test saved settings</option></select></label>
                <label class="text-sm">Model<select v-model="evaluation.model_run_id" :disabled="!!engine" required class="mt-1 w-full rounded border-gray-300"><option disabled value="">Select model</option><option v-for="item in models" :key="item.id" :value="item.id">{{ item.name }} · {{ item.status }}</option></select></label>
                <p class="self-end py-2 text-sm">Test season: {{ model?.target_season_id ?? 'not configured' }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="text-sm">Game scope<select v-model="evaluation.scope.mode" class="mt-1 w-full rounded border-gray-300"><option value="season">Entire test season</option><option value="games">Number of games</option><option value="days">Number of game days</option><option value="selected">Specific game IDs</option></select></label>
                <label v-if="['games', 'days'].includes(evaluation.scope.mode)" class="text-sm">Count<input v-model.number="evaluation.scope.count" type="number" min="1" max="3000" required class="mt-1 w-full rounded border-gray-300" /></label>
                <label v-if="['games', 'days'].includes(evaluation.scope.mode)" class="text-sm">Selection<select v-model="evaluation.scope.selection" class="mt-1 w-full rounded border-gray-300"><option value="first">First</option><option value="last">Last</option><option value="random">Random</option></select></label>
                <label v-if="evaluation.scope.mode === 'selected'" class="text-sm">NHL game IDs, separated by commas<input v-model="gameIdsText" required class="mt-1 w-full rounded border-gray-300" /></label>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <label class="text-sm">Starting date (optional)<input v-model="evaluation.scope.start_date" type="date" class="mt-1 w-full rounded border-gray-300" /></label>
                <label class="text-sm">Ending date (optional)<input v-model="evaluation.scope.end_date" type="date" class="mt-1 w-full rounded border-gray-300" /></label>
            </div>
            <fieldset><legend class="text-sm font-medium">Teams — none selected means entire league</legend><div class="mt-2 flex max-h-40 flex-wrap gap-3 overflow-y-auto"><label v-for="team in teams" :key="team" class="flex items-center gap-2 text-sm"><input v-model="evaluation.scope.teams" type="checkbox" :value="team" class="rounded border-gray-300" />{{ team }}</label></div></fieldset>
            <p class="text-sm text-gray-500">First, Last and Random select from games matching your season, dates and teams. Game-day selection includes every matching game on each selected date. Random samples are saved for the run so every split uses the same games. A matchup involving two selected teams counts once. If fewer games or days are available than requested, all matching ones are used.</p>
            <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm">Desired win %<input v-model.number="evaluation.desired_win_pct" type="number" min="0" max="100" step="0.1" required class="mt-1 w-full rounded border-gray-300" /></label><label class="text-sm">Minimum game coverage %<input v-model.number="evaluation.min_coverage_pct" type="number" min="0.1" max="100" step="0.1" required class="mt-1 w-full rounded border-gray-300" /></label></div>
            <p v-if="evaluation.kind === 'discovery'" class="text-sm text-gray-600">Discovery searches automatically, then refines promising offense/defense splits. Confidence ranges and score gaps are evaluated from the resulting predictions. Strong candidates below your coverage target are shown separately with their shortfall. You can save discovered settings to an engine.</p>
            <p v-for="(error, key) in evaluation.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
            <button :disabled="evaluation.processing || !model?.target_season_id || model?.status !== 'complete'" class="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">{{ evaluation.processing ? 'Starting…' : evaluation.kind === 'build' ? 'Test engine' : 'Discover settings' }}</button>
        </form>
        <section class="border-t border-gray-200 pt-6"><h2 class="text-lg font-semibold">Evaluation history</h2><ul class="mt-3 divide-y"><li v-for="run in runs.data" :key="run.id" class="py-3"><Link :href="`${baseUrl}/runs/${run.id}`" class="text-sm text-indigo-700 underline">Run {{ run.id }} · {{ run.kind === 'build' ? 'Test' : run.kind }} · {{ run.status }} · {{ run.game_count }} games</Link></li></ul><Pagination :links="runs.links" /></section>
        <section v-if="engine" class="border-t border-gray-200 pt-6"><button type="button" class="text-sm text-red-700" @click="confirmDelete = !confirmDelete">Delete engine</button><div v-if="confirmDelete" class="mt-3 space-y-3"><p class="text-sm">Delete this engine? Completed evaluation history will remain.</p><button :disabled="deletion.processing" class="rounded border border-red-700 px-3 py-2 text-sm text-red-700" @click="deletion.delete(`${baseUrl}/${engine.id}`)">Confirm delete</button></div><p v-for="(error, key) in deletion.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p></section>
    </div>
</template>
