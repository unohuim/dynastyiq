<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import SettingsFields from './SettingsFields.vue';
import Pagination from './Pagination.vue';
import { baseUrl } from './engine-ui';

const props = defineProps({ engines: Object, models: Array, defaults: Object });
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
    </div>
</template>
