<script setup>
defineProps({ settings: { type: Object, required: true } });
const emit = defineEmits(['update:settings']);
const fields = [
    ['offense', 'Offense %', 200, 1], ['defense', 'Matching defense %', 200, 1],
    ['confidence_min', 'Confidence minimum', 100, 1], ['confidence_max', 'Confidence maximum', 100, 1],
    ['gap', 'Score gap greater than', 10, 0.000001],
];
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <label v-for="[key, label, max, step] in fields" :key="key" class="block text-sm text-gray-700">
            {{ label }}
            <input :value="settings[key]" type="number" min="0" :max="max" :step="step" required class="mt-1 w-full rounded border-gray-300" @input="emit('update:settings', { ...settings, [key]: Number($event.target.value) })" />
        </label>
    </div>
</template>
