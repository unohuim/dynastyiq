@props([
    'show',
    'closeAction',
    'titleId',
    'maxWidth' => 'max-w-lg',
])

<div
    x-cloak
    class="fixed inset-0 z-50 pointer-events-none"
>
    <div
        x-show="{{ $show }}"
        x-transition.opacity.duration.300ms
        class="absolute inset-0 bg-gray-900/30 pointer-events-auto"
        @click="{{ $closeAction }}"
    ></div>

    <section
        x-show="{{ $show }}"
        x-transition:enter="transition-transform ease-out duration-500 motion-reduce:transition-none"
        x-transition:enter-start="translate-x-full motion-reduce:translate-x-0"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition-transform ease-in duration-300 motion-reduce:transition-none"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full motion-reduce:translate-x-0"
        class="absolute right-0 top-0 flex h-full w-full {{ $maxWidth }} pointer-events-auto [will-change:transform]"
        @keydown.escape.window="{{ $closeAction }}"
        @click.away="{{ $closeAction }}"
        aria-modal="true"
        role="dialog"
        aria-labelledby="{{ $titleId }}"
    >
        <div {{ $attributes->merge(['class' => 'flex h-full w-full flex-col bg-white shadow-xl']) }}>
            {{ $slot }}
        </div>
    </section>
</div>
