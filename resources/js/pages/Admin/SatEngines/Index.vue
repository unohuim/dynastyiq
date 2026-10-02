<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import SettingsFields from './SettingsFields.vue';
import Pagination from './Pagination.vue';
import { baseUrl } from './engine-ui';

const props = defineProps({ engines: Object, runs: Object, models: Array, defaults: Object });
const activeTab = usePage().url.includes('tab=discoveries') ? 'discoveries' : 'engines';
const form = useForm({ name: '', test_model_run_id: props.models[0]?.id ?? '', model_run_id: props.models[0]?.id ?? '', settings: { ...props.defaults }, notes: '' });
const defaultSelection = useForm({});
</script>

<template>
    <Head title="SAT Engines" />
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4">
            <div><h1 class="text-2xl font-semibold text-gray-900">SAT Engines</h1><p class="mt-1 text-sm text-gray-600">Manage prediction settings and discover candidates against a model’s test season.</p></div>
            <Link :href="`${baseUrl}/discover`" class="rounded bg-gray-900 px-4 py-2 text-sm text-white">Discover settings</Link>
        </header>
        <nav aria-label="SAT engine workspace" class="flex gap-6 border-b border-gray-200">
            <Link :href="baseUrl" :class="activeTab === 'engines' ? 'border-b-2 border-indigo-600 pb-3 text-sm font-medium text-indigo-700' : 'pb-3 text-sm text-gray-600 transition-colors duration-150 hover:text-gray-900 motion-reduce:transition-none'">Engines <span class="ml-1 text-xs tabular-nums">{{ engines.total ?? engines.data.length }}</span></Link>
            <Link :href="`${baseUrl}?tab=discoveries`" :class="activeTab === 'discoveries' ? 'border-b-2 border-indigo-600 pb-3 text-sm font-medium text-indigo-700' : 'pb-3 text-sm text-gray-600 transition-colors duration-150 hover:text-gray-900 motion-reduce:transition-none'">Discoveries <span class="ml-1 text-xs tabular-nums">{{ runs.total ?? runs.data.length }}</span></Link>
        </nav>
        <template v-if="activeTab === 'engines'">
        <form class="space-y-4" @submit.prevent="form.post(baseUrl)">
            <h2 class="text-lg font-semibold">Create engine</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="text-sm">Name<input v-model="form.name" required maxlength="160" class="mt-1 w-full rounded border-gray-300" /></label>
                <label class="text-sm">Test Model<select v-model="form.test_model_run_id" required class="mt-1 w-full rounded border-gray-300"><option disabled value="">Select a model</option><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }} · {{ model.status }}</option></select></label>
                <label class="text-sm">Production Model<select v-model="form.model_run_id" required class="mt-1 w-full rounded border-gray-300"><option disabled value="">Select a model</option><option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }} · {{ model.status }}</option></select></label>
            </div>
            <SettingsFields v-model:settings="form.settings" />
            <label class="block text-sm">Notes<textarea v-model="form.notes" maxlength="2000" class="mt-1 w-full rounded border-gray-300" /></label>
            <p v-for="(error, key) in form.errors" :key="key" role="alert" class="text-sm text-red-700">{{ error }}</p>
            <button :disabled="form.processing" class="rounded bg-gray-900 px-4 py-2 text-sm text-white disabled:opacity-50">Create engine</button>
        </form>
        <section class="border-t border-gray-200 pt-6">
            <p v-for="(error, key) in defaultSelection.errors" :key="key" role="alert" class="mb-3 text-sm text-red-700">{{ error }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">Engine</th><th class="p-3">Test Model</th><th class="p-3">Production Model</th><th class="p-3">Offense / defense</th><th class="p-3">Confidence</th><th class="p-3">Gap &gt;</th><th class="p-3">Default</th></tr></thead>
                    <tbody><tr v-for="engine in engines.data" :key="engine.id" class="border-b"><td class="p-3"><Link :href="`${baseUrl}/${engine.id}`" class="font-medium text-indigo-700 underline">{{ engine.name }}</Link></td><td class="p-3">{{ models.find(model => model.id === engine.test_model_run_id)?.name ?? engine.test_model_run_id }}</td><td class="p-3">{{ models.find(model => model.id === engine.model_run_id)?.name ?? engine.model_run_id }}</td><td class="p-3">{{ engine.settings.offense }}% / {{ engine.settings.defense }}%</td><td class="p-3">{{ engine.settings.confidence_min }}–{{ engine.settings.confidence_max }}</td><td class="p-3">{{ engine.settings.gap }}</td><td class="p-3"><span v-if="engine.is_default" class="font-medium text-gray-900">Default</span><button v-else type="button" :disabled="defaultSelection.processing" class="rounded border border-gray-300 px-3 py-1 text-sm transition-colors duration-150 hover:bg-gray-50 disabled:opacity-50 motion-reduce:transition-none" @click="defaultSelection.post(`${baseUrl}/${engine.id}/default`)">Make default</button></td></tr></tbody>
                </table>
                <p v-if="!engines.data.length" class="py-6 text-sm text-gray-500">No engines yet.</p>
            </div>
            <Pagination :links="engines.links" />
        </section>
        </template>
        <section v-else class="space-y-4">
            <div class="flex items-end justify-between gap-4"><div><h2 class="text-lg font-semibold text-gray-900">Discovery history</h2><p class="mt-1 text-sm text-gray-600">Saved historical evaluations remain available after completion.</p></div><Link :href="`${baseUrl}/discover`" class="rounded border border-gray-300 px-3 py-2 text-sm text-gray-800 transition-colors duration-150 hover:bg-gray-50 motion-reduce:transition-none">New discovery</Link></div>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b border-gray-200 text-xs font-medium uppercase tracking-wide text-gray-500"><th class="p-3">Run</th><th class="p-3">Type</th><th class="p-3">Model</th><th class="p-3">Scope</th><th class="p-3 text-right">Games</th><th class="p-3">Status</th><th class="p-3">Started</th><th class="p-3"><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="run in runs.data" :key="run.id" class="border-b border-gray-100"><td class="p-3 font-medium">#{{ run.id }}</td><td class="p-3 capitalize">{{ run.kind }}</td><td class="p-3">{{ run.definition?.model_name ?? run.model_run_id }}</td><td class="p-3">{{ run.definition?.scope?.mode ?? '—' }}</td><td class="p-3 text-right tabular-nums">{{ run.game_count }}</td><td class="p-3"><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">{{ run.status }}</span></td><td class="p-3 text-gray-600">{{ run.created_at }}</td><td class="p-3 text-right"><Link :href="`${baseUrl}/runs/${run.id}`" class="text-indigo-700 underline">View results</Link></td></tr></tbody></table><p v-if="!runs.data.length" class="py-10 text-center text-sm text-gray-500">No discovery or test runs have been saved yet.</p></div>
            <Pagination :links="runs.links" />
        </section>
    </div>
</template>
