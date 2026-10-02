<script setup>
import { computed, nextTick, onMounted, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Pagination from './Pagination.vue';
import { active, baseUrl, number, qualified } from './engine-ui';

const props = defineProps({ run: Object, candidates: Object, candidate: Object, games: Object, engines: Array, models: Array, candidateFilters: { type: Object, default: () => ({}) }, candidateSort: { type: Object, default: () => ({ key: 'targets', direction: 'desc' }) } });
const adoption = useForm({ engine_id: '', name: '', test_model_run_id: props.models[0]?.id ?? '', model_run_id: props.models[0]?.id ?? '' });
const cancellation = useForm({});
const creation = useForm({ name: '', test_model_run_id: props.models[0]?.id ?? '', model_run_id: props.models[0]?.id ?? '' });
const creationDialog = ref(null);
const creationName = ref(null);
const creationCandidate = ref(null);
const canAdopt = computed(() => active(props.run.status) || props.run.status === 'complete');
const filterDrawerOpen = ref(false);
const candidateFilters = ref({ ...props.candidateFilters });
const candidateQuery = (overrides = {}) => ({ ...candidateFilters.value, sort: props.candidateSort.key, direction: props.candidateSort.direction, ...overrides });
const loadCandidates = (overrides = {}) => router.get(`${baseUrl}/runs/${props.run.id}`, candidateQuery(overrides), { preserveState: true, preserveScroll: true, only: ['candidates', 'candidateFilters', 'candidateSort'] });
const sortCandidates = key => loadCandidates({ sort: key, direction: props.candidateSort.key === key && props.candidateSort.direction === 'desc' ? 'asc' : 'desc' });
const sortIcon = key => props.candidateSort.key !== key ? '↕' : props.candidateSort.direction === 'desc' ? '↓' : '↑';
const sortLabel = key => props.candidateSort.key !== key ? 'Not sorted' : `Sorted ${props.candidateSort.direction === 'desc' ? 'descending' : 'ascending'}`;
const applyFilters = () => { filterDrawerOpen.value = false; loadCandidates({}); };
const clearFilters = () => { candidateFilters.value = {}; filterDrawerOpen.value = false; loadCandidates({}); };
const filterCount = computed(() => Object.values(candidateFilters.value).filter(value => value !== null && value !== undefined && value !== '').length);
let creationTrigger = null;
const openCreation = async (row, event) => {
    if (!canAdopt.value || !row.metrics) return;
    creationTrigger = event.currentTarget;
    creationCandidate.value = { id: row.id, settings: { ...row.settings } };
    creation.name = ''; creation.test_model_run_id = props.models[0]?.id ?? ''; creation.model_run_id = props.models[0]?.id ?? '';
    creation.clearErrors();
    await nextTick();
    creationDialog.value.showModal();
    creationName.value.focus();
};
const closeCreation = () => {
    if (creation.processing) return;
    creationDialog.value.close();
    creationCandidate.value = null;
    creationTrigger?.focus();
};
const createEngine = () => {
    if (!canAdopt.value || !creationCandidate.value || creation.processing) return;
    creation.post(`${baseUrl}/runs/${props.run.id}/candidates/${creationCandidate.value.id}/apply`);
};
let timer;
let refreshing = false;
onMounted(() => {
    timer = setInterval(() => {
        if (active(props.run.status) && !refreshing) {
            refreshing = true;
            router.reload({ only: ['run', 'candidates', 'candidate', 'games'], onFinish: () => { refreshing = false; } });
        }
    }, 5000);
});
onBeforeUnmount(() => clearInterval(timer));
const adopt = () => adoption.transform(data => ({ ...data, engine_id: data.engine_id || null }))
    .post(`${baseUrl}/runs/${props.run.id}/candidates/${props.candidate.id}/apply`);
</script>

<template>
    <Head :title="`Engine evaluation ${run.id}`" />
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6">
        <header class="border-b border-gray-200 pb-4"><Link :href="baseUrl" class="text-sm text-indigo-700">SAT Engines</Link><h1 class="mt-2 text-2xl font-semibold">Evaluation {{ run.id }} · {{ run.kind === 'build' ? 'Test' : run.kind }}</h1><p class="mt-2 text-sm">{{ run.definition.model_name }} · Test season {{ run.definition.test_season }} · {{ run.game_count }} selected games</p></header>
        <section aria-live="polite" class="space-y-2">
            <p class="text-sm font-medium">{{ run.status }} · Predictions {{ run.predictions_completed }}/{{ run.prediction_count }} · {{ run.definition.automatic_search ? 'Splits evaluated' : run.definition.confidence_search === 'automatic' ? 'Weight/gap searches' : 'Candidates' }} {{ run.candidates_completed }}/{{ run.candidate_count }}</p>
            <p v-if="run.definition.automatic_search" class="text-sm text-gray-600">Search stage {{ run.definition.automatic_search.stage + 1 }} of 3 · broad search, then two refinements. Work totals grow as promising splits are refined.</p>
            <progress class="h-2 w-full" :value="Number(run.predictions_completed) + Number(run.candidates_completed)" :max="Number(run.prediction_count) + Number(run.candidate_count)" aria-label="Evaluation progress" />
            <p v-if="run.error" role="alert" class="text-sm text-red-700">{{ run.error }}</p>
            <button v-if="active(run.status)" :disabled="cancellation.processing" class="rounded border border-gray-300 px-3 py-2 text-sm" @click="cancellation.post(`${baseUrl}/runs/${run.id}/cancel`)">Cancel run</button>
        </section>
        <p class="text-sm text-gray-600">Targets: {{ run.definition.desired_win_pct }}% wins, at least {{ run.definition.min_coverage_pct }}% coverage. Coverage is qualified picks / eligible games. Excluded games are shown separately. Discovery results are measured on the selected sample.</p>
        <p v-if="run.definition.automatic_search" class="text-sm text-gray-600">Offense and matching defense are searched independently from 0–200%, initially every 25 points. Three promising splits are refined at 5-point and then 1-point intervals. All distinct confidence/score-gap selections are evaluated for each tested split, with gaps from 0–10 goals. Results retain the best win-rate/coverage trade-offs. This staged search does not evaluate every possible weight pair.</p>
        <p v-else-if="run.definition.confidence_search === 'automatic'" class="text-sm text-gray-600">All confidence ranges are evaluated. Ranges that select the same games are combined. For each weight/gap combination, results retain the best win-rate/coverage trade-offs; a discarded range has no advantage in either measure.</p>
        <section class="space-y-4 border-t border-gray-200 pt-6" aria-labelledby="candidates-title">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 id="candidates-title" class="text-lg font-semibold">Candidates</h2><p class="mt-1 text-sm text-gray-600">Sort every metric or narrow the persisted candidate set.</p></div><button type="button" class="rounded border border-gray-300 px-3 py-2 text-sm transition-colors duration-150 hover:bg-gray-50 motion-reduce:transition-none" @click="filterDrawerOpen = true">Filters<span v-if="filterCount" class="ml-2 rounded-full bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-800">{{ filterCount }}</span></button></div>
        <div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-left text-sm">
            <thead><tr class="border-b"><th class="p-2"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Offense. ${sortLabel('offense')}`" @click="sortCandidates('offense')">O % <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('offense') }}</span></button> / <button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Defense. ${sortLabel('defense')}`" @click="sortCandidates('defense')">D % <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('defense') }}</span></button></th><th class="p-2"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Confidence. ${sortLabel('confidence_min')}`" @click="sortCandidates('confidence_min')">Confidence <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('confidence_min') }}</span></button></th><th class="p-2"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Gap. ${sortLabel('gap')}`" @click="sortCandidates('gap')">Gap &gt; <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('gap') }}</span></button></th><th class="p-2">All record</th><th class="p-2">Pick record</th><th class="p-2 text-right"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Win percentage. ${sortLabel('win_pct')}`" @click="sortCandidates('win_pct')">Win % <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('win_pct') }}</span></button></th><th class="p-2 text-right"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Coverage percentage. ${sortLabel('coverage_pct')}`" @click="sortCandidates('coverage_pct')">Coverage % <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('coverage_pct') }}</span></button></th><th class="p-2 text-right"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Eligible games. ${sortLabel('eligible')}`" @click="sortCandidates('eligible')">Eligible / excluded <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('eligible') }}</span></button></th><th class="p-2"><button type="button" class="inline-flex items-center gap-1 font-medium" :aria-label="`Target status. ${sortLabel('targets')}`" @click="sortCandidates('targets')">Targets <span aria-hidden="true" class="text-xs text-gray-500">{{ sortIcon('targets') }}</span></button></th><th class="p-2">Details</th></tr></thead>
            <tbody><tr v-for="row in candidates.data" :key="row.id" class="border-b" :class="candidate?.id === row.id ? 'bg-indigo-50' : ''"><td class="p-2">{{ row.settings.offense }} / {{ row.settings.defense }}</td><td class="p-2">{{ row.settings.confidence_min }}–{{ row.settings.confidence_max }}</td><td class="p-2">{{ row.settings.gap }}</td><td class="p-2">{{ row.metrics ? `${row.metrics.all_wins}–${row.metrics.all_losses}` : '—' }}</td><td class="p-2">{{ row.metrics ? `${row.metrics.wins}–${row.metrics.losses}` : '—' }}</td><td class="p-2 text-right tabular-nums">{{ number(row.win_pct, 1) }}</td><td class="p-2 text-right tabular-nums">{{ number(row.coverage_pct, 1) }}</td><td class="p-2 text-right">{{ row.metrics ? `${row.metrics.eligible} / ${row.metrics.excluded}` : '—' }}</td><td class="p-2">{{ row.metrics ? (row.meets_targets ? 'Met' : 'Not met') : 'Pending' }}</td><td class="p-2"><Link :href="`${baseUrl}/runs/${run.id}?candidate=${row.id}`" preserve-scroll class="text-indigo-700 underline">View</Link><button v-if="canAdopt && row.metrics" type="button" class="ml-3 rounded border border-gray-300 px-3 py-1 text-sm transition-colors duration-150 hover:bg-gray-50 motion-reduce:transition-none" @click="openCreation(row, $event)">Create engine</button></td></tr></tbody>
        </table><p v-if="!candidates.data.length" class="py-10 text-center text-sm text-gray-500">No candidates match these filters. Clear filters to view the full discovery set.</p><Pagination :links="candidates.links" /></div>
        </section>
        <section v-if="candidate" class="space-y-4 border-t border-gray-200 pt-6">
            <h2 class="text-lg font-semibold">Selected: {{ candidate.settings.offense }}O / {{ candidate.settings.defense }}D · Confidence {{ candidate.settings.confidence_min }}–{{ candidate.settings.confidence_max }} · Gap &gt; {{ candidate.settings.gap }}</h2>
            <table v-if="candidate.metrics" class="text-sm"><caption class="mb-2 text-left text-gray-600">Totals for all eligible games, including games that do not qualify as picks</caption><thead><tr><th class="px-4 py-2 text-left">Totals</th><th class="px-4 py-2 text-right">SAT</th><th class="px-4 py-2 text-right">SOG</th><th class="px-4 py-2 text-right">Goals</th></tr></thead><tbody><tr><th class="px-4 py-2 text-left font-normal">Predicted</th><td v-for="key in ['pred_sat', 'pred_sog', 'pred_goals']" :key="key" class="px-4 py-2 text-right tabular-nums">{{ number(candidate.metrics[key]) }}</td></tr><tr><th class="px-4 py-2 text-left font-normal">Actual</th><td v-for="key in ['actual_sat', 'actual_sog', 'actual_goals']" :key="key" class="px-4 py-2 text-right tabular-nums">{{ number(candidate.metrics[key]) }}</td></tr></tbody></table>
            <form v-if="canAdopt && candidate.metrics" class="space-y-3" @submit.prevent="adopt">
                <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm">Save candidate to<select v-model="adoption.engine_id" class="mt-1 w-full rounded border-gray-300"><option value="">New engine</option><option v-for="engine in engines" :key="engine.id" :value="engine.id">{{ engine.name }}</option></select></label><label v-if="!adoption.engine_id" class="text-sm">New engine name<input v-model="adoption.name" required maxlength="160" class="mt-1 w-full rounded border-gray-300" /></label><label v-if="!adoption.engine_id" class="text-sm">Test Model<select v-model="adoption.test_model_run_id" class="mt-1 w-full rounded border-gray-300"><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }}</option></select></label><label v-if="!adoption.engine_id" class="text-sm">Production Model<select v-model="adoption.model_run_id" class="mt-1 w-full rounded border-gray-300"><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }}</option></select></label></div>
                <p class="text-sm text-gray-600">Applying replaces only the selected engine’s settings. Previous runs remain unchanged. It does not start a test or change the prediction API.</p>
                <p v-for="(error, key) in adoption.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
                <button :disabled="adoption.processing" class="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">{{ adoption.engine_id ? 'Apply to engine' : 'Create engine from candidate' }}</button>
            </form>
            <div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-left text-sm"><thead><tr class="border-b"><th class="p-2">Date / game</th><th class="p-2">Predicted score</th><th class="p-2">Actual score</th><th class="p-2">Confidence</th><th class="p-2">Gap</th><th class="p-2">Pick / result</th><th class="p-2">SAT P / A</th><th class="p-2">SOG P / A</th><th class="p-2">Status</th></tr></thead><tbody><tr v-for="game in games.data" :key="game.id" class="border-b"><td class="p-2">{{ game.game.date }} · {{ game.game.away }}–{{ game.game.home }}<span class="block text-xs text-gray-500">{{ game.nhl_game_id }}</span></td><td class="p-2">{{ number(game.prediction?.away.total_goalie_adjusted_xgf_per_game, 2) }}–{{ number(game.prediction?.home.total_goalie_adjusted_xgf_per_game, 2) }}</td><td class="p-2">{{ game.game.away_goals }}–{{ game.game.home_goals }}</td><td class="p-2">{{ number(game.confidence) }}</td><td class="p-2">{{ number(game.gap, 4) }}</td><td class="p-2">{{ qualified(game, candidate.settings) ? `${game.prediction.winner} · ${game.correct ? 'Win' : 'Loss'}` : 'No pick' }}</td><td class="p-2">{{ number(game.pred_sat) }} / {{ number(game.actual_sat) }}</td><td class="p-2">{{ number(game.pred_sog) }} / {{ number(game.actual_sog) }}</td><td class="max-w-sm whitespace-normal p-2">{{ game.reason ?? game.status }}</td></tr></tbody></table><Pagination :links="games.links" /></div>
        </section>
        <dialog ref="creationDialog" aria-labelledby="create-engine-title" class="w-full max-w-lg rounded-lg border border-gray-200 bg-white p-6 shadow-lg backdrop:bg-black/40" @cancel.prevent="closeCreation">
            <form v-if="creationCandidate" class="space-y-4" @submit.prevent="createEngine">
                <h2 id="create-engine-title" class="text-lg font-semibold">Create engine</h2>
                <p class="text-sm">{{ run.definition.model_name }} · {{ creationCandidate.settings.offense }}O / {{ creationCandidate.settings.defense }}D · Confidence {{ creationCandidate.settings.confidence_min }}–{{ creationCandidate.settings.confidence_max }} · Gap &gt; {{ creationCandidate.settings.gap }}</p>
                <div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm">Engine name<input ref="creationName" v-model="creation.name" required maxlength="160" :disabled="creation.processing" class="mt-1 w-full rounded border-gray-300" /></label><label class="block text-sm">Test Model<select v-model="creation.test_model_run_id" :disabled="creation.processing" class="mt-1 w-full rounded border-gray-300"><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }}</option></select></label><label class="block text-sm">Production Model<select v-model="creation.model_run_id" :disabled="creation.processing" class="mt-1 w-full rounded border-gray-300"><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }}</option></select></label></div>
                <p class="text-sm text-gray-600">Saves these exact settings. Discovery can continue while you use this engine.</p>
                <p v-if="!canAdopt" role="alert" class="text-sm text-red-700">This run has failed or been cancelled. Engine creation is unavailable.</p>
                <p v-for="(error, key) in creation.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
                <div class="flex justify-end gap-3"><button type="button" :disabled="creation.processing" class="rounded border border-gray-300 px-4 py-2 text-sm transition-colors duration-150 motion-reduce:transition-none" @click="closeCreation">Cancel</button><button :disabled="creation.processing || !canAdopt" class="rounded bg-gray-900 px-4 py-2 text-sm text-white transition-colors duration-150 disabled:opacity-50 motion-reduce:transition-none">{{ creation.processing ? 'Creating…' : 'Create engine' }}</button></div>
            </form>
        </dialog>
        <div v-if="filterDrawerOpen" class="fixed inset-0 z-40" role="presentation">
            <button type="button" class="absolute inset-0 w-full bg-black/30 transition-opacity duration-200 motion-reduce:transition-none" aria-label="Close filters" @click="filterDrawerOpen = false"></button>
            <aside class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-xl transition-transform duration-500 ease-out motion-reduce:transition-none" role="dialog" aria-modal="true" aria-labelledby="candidate-filters-title">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4"><div><h2 id="candidate-filters-title" class="text-lg font-semibold">Filter candidates</h2><p class="mt-1 text-sm text-gray-600">All filters apply to the stored discovery results.</p></div><button type="button" class="rounded p-2 text-gray-600 transition-colors duration-150 hover:bg-gray-100 motion-reduce:transition-none" aria-label="Close filters" @click="filterDrawerOpen = false">×</button></div>
                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5"><label class="block text-sm">Target status<select v-model="candidateFilters.target_status" class="mt-1 w-full rounded border-gray-300"><option value="">Any</option><option value="met">Met</option><option value="not_met">Not met</option><option value="pending">Pending</option></select></label><div v-for="(label, key) in { min_coverage: 'Coverage %', min_win_pct: 'Win %', min_offense: 'Offense %', min_defense: 'Defense %', min_confidence: 'Confidence', min_gap: 'Score gap', min_eligible: 'Eligible games', min_excluded: 'Excluded games' }" :key="key" class="grid grid-cols-2 gap-3"><label class="col-span-2 text-sm font-medium">{{ label }}</label><label class="text-xs text-gray-600">Minimum<input v-model="candidateFilters[key]" type="number" min="0" class="mt-1 w-full rounded border-gray-300 text-sm" /></label><label class="text-xs text-gray-600">Maximum<input v-model="candidateFilters[key.replace('min_', 'max_')]" type="number" min="0" class="mt-1 w-full rounded border-gray-300 text-sm" /></label></div></div>
                <div class="flex justify-between border-t border-gray-200 px-5 py-4"><button type="button" class="text-sm text-gray-700 underline" @click="clearFilters">Clear all</button><button type="button" class="rounded bg-gray-900 px-4 py-2 text-sm text-white" @click="applyFilters">Apply filters</button></div>
            </aside>
        </div>
    </div>
</template>
