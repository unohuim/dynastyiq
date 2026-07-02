@props([
    'id',
    'label',
    'model',
])

@php
    $inputRef = $id . '-input';
@endphp

<div>
    <label for="{{ $id }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
    <div
        class="mt-1"
        @click="$refs[@js($inputRef)].focus(); $refs[@js($inputRef)].showPicker?.()"
    >
        <input
            id="{{ $id }}"
            x-ref="{{ $inputRef }}"
            x-model="{{ $model }}"
            type="date"
            {{ $attributes->merge(['class' => 'block w-full cursor-pointer rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500']) }}
        >
    </div>
</div>
