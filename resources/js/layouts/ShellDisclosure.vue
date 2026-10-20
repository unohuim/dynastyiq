<script setup>
import { computed, ref, useId } from 'vue';
const props = defineProps({ label: String, defaultOpen: Boolean, modelValue: { type: Boolean, default: undefined } });
const emit = defineEmits(['update:modelValue']);
const localOpen = ref(props.defaultOpen);
const open = computed({
    get: () => props.modelValue ?? localOpen.value,
    set: value => { localOpen.value = value; emit('update:modelValue', value); },
});
const id = useId();
</script>

<template>
    <section>
        <button type="button" :aria-expanded="open" :aria-controls="id" class="flex min-h-11 w-full items-center justify-between rounded-lg px-3 text-left text-sm font-medium transition-colors duration-150 hover:bg-gray-100 motion-reduce:transition-none" @click="open = !open">
            {{ label }}
            <svg aria-hidden="true" class="h-4 w-4 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="{ 'rotate-180': open }" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m6 9 6 6 6-6" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        <div :id="id" :inert="!open" :aria-hidden="!open" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="open ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
            <div class="min-h-0 overflow-hidden"><div class="space-y-4 px-3 py-3"><slot /></div></div>
        </div>
    </section>
</template>
