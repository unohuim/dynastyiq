@php
    $flashMessages = collect([
        'success' => session('success') ?? session('status'),
        'error' => session('error'),
        'info' => session('info'),
    ])->filter()->map(function ($message, $type) {
        $message = is_array($message)
            ? implode(' ', \Illuminate\Support\Arr::flatten($message))
            : (string) $message;

        return [
            'message' => $message,
            'type' => $type,
        ];
    })->values();
@endphp

<div
    x-data="toastStack({ flashes: @js($flashMessages) })"
    x-init="boot()"
    @toast.window="handleEvent($event)"
    class="pointer-events-none fixed inset-0 z-50 flex items-start justify-end px-4 py-6 sm:p-6"
>
    <div
        x-ref="liveRegion"
        id="toast-live-region"
        class="mt-4 flex w-full max-w-sm flex-col gap-3 sm:mt-6"
        aria-live="polite"
        aria-atomic="true"
        tabindex="-1"
    >
        <span class="sr-only">Notification messages</span>

        <template x-for="toast in toasts" :key="toast.id">
            <div
                class="pointer-events-auto relative isolate overflow-hidden rounded-lg shadow-lg ring-1 ring-black/5 text-white px-4 py-3 transition transform duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-white/60 focus-visible:ring-offset-slate-900/60"
                :class="toastClasses(toast.type)"
                :data-toast-id="toast.id"
                :role="toast.type === 'error' ? 'alert' : 'status'"
                :aria-live="toast.type === 'error' ? 'assertive' : 'polite'"
                x-show="!toast.closing"
                x-transition:enter="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                x-init="$nextTick(() => focusRegion())"
            >
                <span class="absolute left-0 top-0 h-full w-1" :class="toastAccent(toast.type)" aria-hidden="true"></span>

                <div class="flex items-start gap-3 pr-8">
                    <span class="text-lg leading-none pt-0.5" aria-hidden="true" x-text="toastIcon(toast.type)"></span>
                    <p class="text-sm font-medium leading-5" x-text="toast.message"></p>
                </div>

                <button
                    type="button"
                    class="absolute right-2 top-2 rounded-full p-1 text-white/80 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    aria-label="Dismiss notification"
                    @click="close(toast.id)"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </template>
    </div>
</div>
