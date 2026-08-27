<script setup>
import { computed, ref, watch } from 'vue';
import {
  SelectContent,
  SelectIcon,
  SelectItem,
  SelectItemIndicator,
  SelectItemText,
  SelectPortal,
  SelectRoot,
  SelectTrigger,
  SelectValue,
  SelectViewport,
} from 'reka-ui';

const EMPTY_VALUE = '__stats_select_empty__';

const props = defineProps({
  modelValue: {
    type: [String, Number],
    default: '',
  },
  options: {
    type: Array,
    default: () => [],
  },
  placeholder: {
    type: String,
    default: 'Select',
  },
  ariaLabel: {
    type: String,
    default: 'Select option',
  },
  triggerClass: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['update:modelValue', 'change']);
const currentValue = ref(String(props.modelValue ?? ''));

watch(() => props.modelValue, (value) => {
  currentValue.value = String(value ?? '');
});

const normalizedOptions = computed(() => props.options.map((option) => ({
  label: String(option?.label ?? ''),
  value: String(option?.value ?? ''),
  disabled: option?.disabled === true,
})));

const internalValue = computed(() => {
  const value = currentValue.value;

  return value === '' ? EMPTY_VALUE : value;
});

const selectedLabel = computed(() => {
  const selected = normalizedOptions.value.find((option) => option.value === currentValue.value);

  return selected?.label || props.placeholder;
});

const itemValue = (value) => (String(value ?? '') === '' ? EMPTY_VALUE : String(value));

const updateValue = (value) => {
  const next = value === EMPTY_VALUE ? '' : String(value ?? '');

  currentValue.value = next;
  emit('update:modelValue', next);
  emit('change', next);
};
</script>

<template>
  <SelectRoot :model-value="internalValue" @update:model-value="updateValue">
    <SelectTrigger :class="triggerClass" :aria-label="ariaLabel">
      <SelectValue :aria-label="selectedLabel">
        <span class="min-w-0 truncate">{{ selectedLabel }}</span>
      </SelectValue>
      <SelectIcon as-child>
        <svg
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
          class="size-4 shrink-0 text-gray-500 transition-transform duration-150 data-[state=open]:rotate-180"
        >
          <path
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
          />
        </svg>
      </SelectIcon>
    </SelectTrigger>

    <SelectPortal>
      <SelectContent
        position="popper"
        align="start"
        :side-offset="6"
        class="z-[1000] max-h-72 min-w-[var(--reka-select-trigger-width)] overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg ring-1 ring-black/5 data-[state=open]:animate-in data-[state=closed]:animate-out"
      >
        <SelectViewport class="p-1">
          <SelectItem
            v-for="option in normalizedOptions"
            :key="itemValue(option.value)"
            :value="itemValue(option.value)"
            :disabled="option.disabled"
            class="relative flex h-9 cursor-default select-none items-center rounded px-3 pr-9 text-sm text-gray-700 outline-none data-[disabled]:pointer-events-none data-[highlighted]:bg-indigo-50 data-[highlighted]:text-indigo-700 data-[disabled]:text-gray-300"
          >
            <SelectItemText>
              <span class="block truncate">{{ option.label }}</span>
            </SelectItemText>
            <SelectItemIndicator class="absolute right-3 inline-flex items-center justify-center text-indigo-600">
              <svg viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true">
                <path
                  fill-rule="evenodd"
                  clip-rule="evenodd"
                  d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                />
              </svg>
            </SelectItemIndicator>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
