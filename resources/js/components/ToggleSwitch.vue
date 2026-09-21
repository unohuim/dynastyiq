<script setup>
const props = defineProps({
  modelValue: { type: Boolean, required: true },
  disabled: { type: Boolean, default: false },
  label: { type: String, required: true },
  describedBy: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

function toggle() {
  if (!props.disabled) {
    emit('update:modelValue', !props.modelValue);
  }
}
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="modelValue"
    :aria-label="label"
    :aria-describedby="describedBy"
    :disabled="disabled"
    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border-2 border-transparent transition-colors duration-200 ease-out focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 motion-reduce:transition-none disabled:cursor-not-allowed disabled:opacity-60"
    :class="modelValue ? 'bg-indigo-600' : 'bg-gray-300'"
    @click="toggle"
  >
    <span
      aria-hidden="true"
      class="pointer-events-none inline-block size-5 rounded-full bg-white shadow-sm ring-0 transition-transform duration-200 ease-out motion-reduce:transition-none"
      :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
    ></span>
  </button>
</template>
