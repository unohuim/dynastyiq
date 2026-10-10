<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription,
    DropdownMenuRoot, DropdownMenuTrigger, DropdownMenuPortal, DropdownMenuContent, DropdownMenuItem,
} from 'reka-ui';
import AccountPanel from './AccountPanel.vue';
import { connectShellRealtime } from './shell-realtime';

const page = usePage();
const shell = computed(() => page.props.shell);
const user = computed(() => page.props.auth?.user);
const drawer = ref(null);
const newsOpen = ref(false);
const toasts = ref([]);
const timers = new Map();
let sequence = 0;
let opener;
let disconnect;
let mounted = false;
const mobileLinks = computed(() => (shell.value?.primary ?? []).filter(link => link.mobile !== false));
const toastClasses = { success: 'bg-emerald-700', error: 'bg-red-700', info: 'bg-sky-700' };

function dismiss(id) {
    clearTimeout(timers.get(id));
    timers.delete(id);
    toasts.value = toasts.value.filter(toast => toast.id !== id);
}

function notify(payload) {
    const detail = typeof payload === 'string' ? { message: payload } : payload;
    if (typeof detail?.message !== 'string' || !detail.message.trim()) return;
    const id = ++sequence;
    if (toasts.value.length >= 5) dismiss(toasts.value[0].id);
    toasts.value.push({ id, message: detail.message, type: toastClasses[detail.type] ? detail.type : 'info' });
    timers.set(id, setTimeout(() => dismiss(id), 5000));
}
provide('notify', notify);
const onToast = event => notify(event.detail);

function openDrawer(mode, event) {
    opener = event?.currentTarget ?? document.activeElement;
    drawer.value = mode;
}

function restoreFocus(event) {
    event.preventDefault();
    if (opener?.isConnected) opener.focus();
}

function reconnect() {
    disconnect?.();
    if (!mounted || !user.value || !shell.value) return;
    disconnect = connectShellRealtime({
        userId: user.value.id, organizationId: shell.value.organization.id, csrf: shell.value.csrf,
        refresh: () => router.reload({ only: ['shell'], preserveScroll: true }), notify,
    });
}

watch(() => page.url, url => {
    drawer.value = new URL(url, 'https://dynastyiq.invalid').searchParams.get('drawer') === 'account' && user.value ? 'account' : null;
    newsOpen.value = false;
}, { immediate: true });
watch(() => shell.value?.flashes, flashes => (flashes ?? []).forEach(notify), { immediate: true });
watch([() => user.value?.id, () => shell.value?.organization?.id, () => shell.value?.csrf], reconnect);
onMounted(() => {
    mounted = true;
    window.addEventListener('toast', onToast);
    reconnect();
});
onBeforeUnmount(() => {
    mounted = false;
    disconnect?.();
    window.removeEventListener('toast', onToast);
    timers.forEach(clearTimeout);
});
</script>

<template>
    <div class="min-h-screen bg-gray-50 pb-20 text-gray-900 md:pb-0">
        <a href="#page-content" class="sr-only fixed left-4 top-4 z-[200] rounded-lg bg-white px-4 py-2 shadow focus:not-sr-only">Skip to content</a>
        <header v-if="shell" class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-screen-2xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a :href="shell.primary[0].href" class="shrink-0 text-lg font-semibold tracking-tight text-gray-950">DynastyIQ</a>
                <nav aria-label="Primary navigation" class="hidden flex-1 flex-wrap items-center gap-x-5 gap-y-2 md:flex">
                    <a v-for="link in shell.primary" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="border-b-2 py-2 text-sm font-medium transition-colors duration-150 motion-reduce:transition-none" :class="link.active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-950'">{{ link.label }}</a>
                    <DropdownMenuRoot v-model:open="newsOpen">
                        <DropdownMenuTrigger class="flex items-center gap-1 py-2 text-sm font-medium" :class="shell.news.some(link => link.active) ? 'text-indigo-700' : 'text-gray-600'">News <span aria-hidden="true">⌄</span></DropdownMenuTrigger>
                        <DropdownMenuPortal force-mount><Transition enter-active-class="transition-opacity duration-200 ease-out motion-reduce:transition-none" leave-active-class="transition-opacity duration-100 ease-in motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DropdownMenuContent v-if="newsOpen" force-mount :side-offset="8" class="z-40 min-w-48 rounded-lg border border-gray-200 bg-white p-1 shadow-lg"><DropdownMenuItem v-for="link in shell.news" :key="link.href" as-child><a :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded px-3 py-2 text-sm outline-none data-[highlighted]:bg-gray-100">{{ link.label }}</a></DropdownMenuItem></DropdownMenuContent></Transition></DropdownMenuPortal>
                    </DropdownMenuRoot>
                </nav>
                <button v-if="user" type="button" aria-label="Open account" class="flex shrink-0 items-center gap-2 rounded-lg p-1 text-sm hover:bg-gray-100" @click="openDrawer('account', $event)"><img v-if="shell.avatar" :src="shell.avatar" alt="" class="h-9 w-9 rounded-full object-cover" /><span v-else aria-hidden="true" class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-50 font-semibold text-indigo-700">{{ user.name?.slice(0, 1) }}</span><span class="hidden lg:inline">{{ user.name }}</span></button>
                <a v-else :href="shell.login_url" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">Sign in</a>
            </div>
        </header>
        <div id="page-content" tabindex="-1"><slot /></div>
        <nav v-if="shell" aria-label="Mobile navigation" class="fixed inset-x-0 bottom-0 z-30 flex items-center justify-around border-t border-gray-200 bg-white px-2 pb-[env(safe-area-inset-bottom)] md:hidden">
            <a v-for="link in shell.primary.slice(0, 3)" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="px-3 py-5 text-xs font-medium" :class="link.active ? 'text-indigo-700' : 'text-gray-600'">{{ link.label }}</a>
            <button type="button" class="px-3 py-5 text-xs font-medium text-gray-600" @click="openDrawer('navigation', $event)">Menu</button>
            <button v-if="user" type="button" class="px-3 py-5 text-xs font-medium text-gray-600" @click="openDrawer('account', $event)">Account</button>
            <a v-else :href="shell.login_url" class="px-3 py-5 text-xs font-medium text-indigo-700">Sign in</a>
        </nav>
        <DialogRoot :open="!!drawer" @update:open="open => { if (!open) drawer = null; }">
            <DialogPortal force-mount>
                <div class="pointer-events-none fixed inset-x-0 top-0 z-50 h-[100dvh] overflow-hidden">
                    <Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DialogOverlay v-if="drawer" force-mount class="pointer-events-auto absolute inset-0 bg-gray-950/35" /></Transition>
                    <Transition enter-active-class="transition-transform duration-500 ease-out motion-reduce:transition-none" leave-active-class="transition-transform duration-300 ease-in motion-reduce:transition-none" enter-from-class="translate-x-full" leave-to-class="translate-x-full">
                        <DialogContent v-if="drawer && shell" force-mount class="pointer-events-auto absolute right-0 top-0 flex h-full min-h-0 w-full max-w-md flex-col overflow-hidden bg-white shadow-2xl" @close-auto-focus="restoreFocus">
                            <header class="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4"><div><DialogTitle class="text-lg font-semibold">{{ drawer === 'account' ? 'Account' : 'Navigation' }}</DialogTitle><DialogDescription class="mt-1 text-xs text-gray-500">{{ drawer === 'account' ? user?.name : 'Explore DynastyIQ' }}</DialogDescription></div><button type="button" aria-label="Close menu" class="rounded-lg p-3 text-xl text-gray-500 hover:bg-gray-100" @click="drawer = null">×</button></header>
                            <div class="min-h-0 flex-1 touch-pan-y overflow-y-auto overscroll-contain px-3 pt-5 pb-[calc(3rem+env(safe-area-inset-bottom))]">
                                <AccountPanel v-if="drawer === 'account' && user" :shell="shell" @notify="notify" />
                                <nav v-else aria-label="All navigation" class="space-y-1"><a v-for="link in [...mobileLinks, ...shell.news]" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded-lg px-3 py-3 text-sm hover:bg-gray-100" :class="link.active ? 'font-semibold text-indigo-700' : 'text-gray-700'">{{ link.label }}</a><a v-if="user" :href="shell.profile_url" class="block rounded-lg border-t border-gray-200 px-3 py-3 text-sm hover:bg-gray-100">Profile</a></nav>
                            </div>
                        </DialogContent>
                    </Transition>
                </div>
            </DialogPortal>
        </DialogRoot>
        <div class="pointer-events-none fixed inset-x-0 top-0 z-[200] flex justify-end p-4 sm:p-6" aria-label="Notifications" aria-live="polite" aria-atomic="false">
            <TransitionGroup tag="div" class="w-full max-w-sm space-y-3" enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0">
                <div v-for="toast in toasts" :key="toast.id" :role="toast.type === 'error' ? 'alert' : 'status'" class="pointer-events-auto flex items-start justify-between gap-3 rounded-lg px-4 py-3 text-sm text-white shadow-lg" :class="toastClasses[toast.type]"><p>{{ toast.message }}</p><button type="button" aria-label="Dismiss notification" class="rounded px-1 hover:bg-white/20" @click="dismiss(toast.id)">×</button></div>
            </TransitionGroup>
        </div>
    </div>
</template>
