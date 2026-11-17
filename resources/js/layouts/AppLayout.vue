<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, shallowRef, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogTitle, DialogDescription,
    DropdownMenuRoot, DropdownMenuTrigger, DropdownMenuPortal, DropdownMenuContent, DropdownMenuItem,
} from 'reka-ui';
import AccountPanel from './AccountPanel.vue';
import MobileScoreboard from './MobileScoreboard.vue';
import { connectShellRealtime } from './shell-realtime';

const page = usePage();
const statsPage = computed(() => page.component === 'Stats/Index');
const gamePage = computed(() => page.component === 'Games/Show');
const schedulePage = computed(() => page.component === 'Tools/Schedule');
const shell = computed(() => page.props.shell);
const user = computed(() => page.props.auth?.user);
const drawer = ref(null);
const drawerMode = ref(null);
const toolsSheet = computed(() => drawerMode.value === 'tools');
const toolPending = ref(null);
const toolFailure = ref(null);
let toolCancelToken;
let toolAttempt = 0;
const newsOpen = ref(false);
const toolsOpen = ref(false);
const mobileNewsOpen = ref(false);
const liveGame = shallowRef(null);
const liveConnected = ref(false);
provide('nhlLiveGame', liveGame);
provide('nhlLiveConnected', liveConnected);
const toasts = ref([]);
const timers = new Map();
let sequence = 0;
let opener;
let disconnect;
let mounted = false;
const mobileLinks = computed(() => (shell.value?.primary ?? []).filter(link => link.mobile !== false));
const toolLinks = computed(() => shell.value?.tools ?? []);
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
    drawerMode.value = mode;
    drawer.value = mode;
}

/** Cancel only this explicit navigation attempt, retaining existing page content. */
function closeDrawer() {
    toolAttempt++;
    toolCancelToken?.cancel();
    toolCancelToken = null;
    toolPending.value = null;
    toolFailure.value = null;
    drawer.value = null;
}

/** Keep native modified-link behavior; ordinary taps get immediate scoped feedback. */
function visitTool(link, event) {
    if (event && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) return;
    event?.preventDefault();
    if (toolPending.value) return;
    const attempt = ++toolAttempt;
    toolPending.value = link;
    toolFailure.value = null;
    const fail = () => {
        if (attempt === toolAttempt) {
            toolCancelToken = null;
            toolPending.value = null;
            toolFailure.value = link;
        }
        return false;
    };
    try {
        router.visit(link.href, {
            onCancelToken: token => {
                if (attempt === toolAttempt) toolCancelToken = token;
                else token.cancel();
            },
            onSuccess: () => {
                if (attempt !== toolAttempt) return;
                toolCancelToken = null;
                toolPending.value = null;
                drawer.value = null;
            },
            onError: fail,
            onHttpException: fail,
            onNetworkError: fail,
            onCancel: fail,
            onFinish: () => {
                if (attempt === toolAttempt && toolPending.value) fail();
            },
        });
    } catch {
        fail();
    }
}

function restoreFocus(event) {
    event.preventDefault();
    if (opener?.isConnected) opener.focus();
}

function reconnect() {
    disconnect?.();
    if (!mounted || !shell.value) return;
    disconnect = connectShellRealtime({
        userId: user.value?.id, organizationId: shell.value.organization.id, csrf: shell.value.csrf,
        refresh: () => router.reload({ only: ['shell'], preserveScroll: true }), notify,
        gameUpdate: snapshot => { liveGame.value = snapshot; },
        connectionState: connected => { liveConnected.value = connected; },
    });
}

watch(() => page.url, url => {
    drawer.value = new URL(url, 'https://dynastyiq.invalid').searchParams.get('drawer') === 'account' && user.value ? 'account' : null;
    if (drawer.value) drawerMode.value = drawer.value;
    toolAttempt++;
    toolCancelToken = null;
    toolPending.value = null;
    toolFailure.value = null;
    newsOpen.value = false;
    toolsOpen.value = false;
    mobileNewsOpen.value = false;
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
    closeDrawer();
    disconnect?.();
    window.removeEventListener('toast', onToast);
    timers.forEach(clearTimeout);
});
</script>

<template>
    <div :class="[statsPage ? 'bg-[#020e17] text-slate-100' : gamePage ? 'bg-[#101923] text-[#E7EEF6] lg:flex lg:h-dvh lg:flex-col lg:overflow-hidden' : 'bg-gray-50 text-gray-900', schedulePage ? 'flex h-dvh flex-col overflow-hidden pb-[calc(5rem+env(safe-area-inset-bottom))] md:pb-0' : 'min-h-screen pb-20 md:pb-0']">
        <a href="#page-content" class="sr-only fixed left-4 top-4 z-[200] rounded-lg bg-white px-4 py-2 shadow focus:not-sr-only">Skip to content</a>
        <header v-if="shell" class="hidden shrink-0 border-b border-[#1F2B37] bg-[#101923] text-[#E7EEF6] md:block">
            <div class="flex w-full items-center justify-between gap-6 px-6 py-2">
                <a :href="shell.primary[0].href" :aria-label="shell.primary[0].label" :aria-current="shell.primary[0].active ? 'page' : undefined" class="shrink-0 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400">
                    <svg aria-hidden="true" class="h-14 w-16" viewBox="0 0 928 804" preserveAspectRatio="xMidYMid meet">
                        <defs>
                            <filter id="desktop-brand-transparent" color-interpolation-filters="sRGB">
                                <feColorMatrix type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  4 0 0 0 -0.6" />
                            </filter>
                        </defs>
                        <image :href="'/images/logo.png'" width="928" height="804" filter="url(#desktop-brand-transparent)" />
                    </svg>
                </a>
                <nav aria-label="Primary navigation" class="hidden flex-1 flex-wrap items-center gap-x-5 gap-y-2 md:flex">
                    <a v-for="link in shell.primary.slice(1)" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="border-b-2 py-2 text-sm font-medium transition-colors duration-150 motion-reduce:transition-none" :class="link.active ? 'border-sky-400 text-white' : 'border-transparent text-[#C9D5E0] hover:text-white'">{{ link.label }}</a>
                    <DropdownMenuRoot v-model:open="newsOpen">
                        <DropdownMenuTrigger class="flex items-center gap-1 py-2 text-sm font-medium transition-colors duration-150 hover:text-white motion-reduce:transition-none" :class="shell.news.some(link => link.active) ? 'text-white' : 'text-[#C9D5E0]'">
                            <span>News</span>
                            <svg aria-hidden="true" class="h-3 w-3 shrink-0 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="newsOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
                        </DropdownMenuTrigger>
                        <DropdownMenuPortal force-mount><Transition enter-active-class="transition-opacity duration-200 ease-out motion-reduce:transition-none" leave-active-class="transition-opacity duration-100 ease-in motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DropdownMenuContent v-if="newsOpen" force-mount :side-offset="8" class="z-40 min-w-48 rounded-lg border border-gray-200 bg-white p-1 shadow-lg"><DropdownMenuItem v-for="link in shell.news" :key="link.href" as-child><a :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded px-3 py-2 text-sm outline-none data-[highlighted]:bg-gray-100">{{ link.label }}</a></DropdownMenuItem></DropdownMenuContent></Transition></DropdownMenuPortal>
                    </DropdownMenuRoot>
                    <DropdownMenuRoot v-if="toolLinks.length" v-model:open="toolsOpen">
                        <DropdownMenuTrigger aria-label="Open tools menu" class="flex items-center gap-1 py-2 text-sm font-medium transition-colors duration-150 hover:text-white motion-reduce:transition-none" :class="toolLinks.some(link => link.active) ? 'text-white' : 'text-[#C9D5E0]'">
                            <span>Tools</span>
                            <svg aria-hidden="true" class="h-3 w-3 transition-transform duration-300 ease-out motion-reduce:transition-none" :class="toolsOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
                        </DropdownMenuTrigger>
                        <DropdownMenuPortal force-mount><Transition enter-active-class="transition-opacity duration-200 ease-out motion-reduce:transition-none" leave-active-class="transition-opacity duration-100 ease-in motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DropdownMenuContent v-if="toolsOpen" force-mount :side-offset="8" class="z-40 min-w-48 rounded-lg border border-gray-200 bg-white p-1 text-gray-700 shadow-lg"><DropdownMenuItem v-for="link in toolLinks" :key="link.href" as-child><a :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded px-3 py-2 text-sm outline-none data-[highlighted]:bg-gray-100">{{ link.label }}</a></DropdownMenuItem></DropdownMenuContent></Transition></DropdownMenuPortal>
                    </DropdownMenuRoot>
                </nav>
                <button v-if="user" type="button" aria-label="Notification settings" class="hidden h-9 w-9 shrink-0 items-center justify-center rounded text-slate-200 transition-colors duration-150 hover:bg-white/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none lg:flex" @click="openDrawer('account', $event)"><svg aria-hidden="true" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 18.75a2.25 2.25 0 0 1-4.5 0m10.5-2.25H3.75c1.5-1.5 2.25-3 2.25-6a6 6 0 0 1 12 0c0 3 .75 4.5 2.25 6Z" /></svg></button>
                <button v-if="user" type="button" aria-label="Open account" class="flex shrink-0 items-center rounded-lg p-1 text-sm transition-colors duration-150 hover:bg-white/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" @click="openDrawer('account', $event)"><img v-if="shell.avatar" :src="shell.avatar" alt="" class="h-9 w-9 rounded-full object-cover" /><span v-else aria-hidden="true" class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-50 font-semibold text-indigo-700">{{ user.name?.slice(0, 1) }}</span></button>
                <a v-else :href="shell.login_url" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">Sign in</a>
            </div>
        </header>
        <MobileScoreboard class="shrink-0" v-if="shell?.scoreboard_url" :endpoint="shell.scoreboard_url" :games-url="shell.scoreboard_games_url" :selected-game-id="gamePage ? page.props.game?.nhl_game_id : null" />
        <div id="page-content" tabindex="-1" :class="schedulePage ? 'min-h-0 flex-1 overflow-hidden' : gamePage ? 'lg:min-h-0 lg:flex-1 lg:overflow-y-auto' : ''"><slot /></div>
        <nav v-if="shell" aria-label="Mobile navigation" class="fixed inset-x-0 bottom-0 z-30 border-t bg-gray-900 pb-[env(safe-area-inset-bottom)] text-gray-100 shadow md:hidden">
            <ul class="flex items-center justify-between px-4 py-2 text-xs font-medium text-gray-300">
                <li v-for="(link, index) in shell.primary.slice(0, 2)" :key="link.href" class="flex-1 text-center">
                    <a :href="link.href" :aria-label="index === 0 ? link.label : undefined" :aria-current="link.active ? 'page' : undefined" class="flex min-h-11 flex-col items-center justify-center transition-colors duration-150 motion-reduce:transition-none" :class="link.active ? 'text-white' : ''">
                        <svg v-if="index === 0" aria-hidden="true" class="h-11 w-12 shrink-0" viewBox="0 0 928 804" preserveAspectRatio="xMidYMid meet">
                            <defs>
                                <filter id="mobile-brand-transparent" color-interpolation-filters="sRGB">
                                    <feColorMatrix type="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  4 0 0 0 -0.6" />
                                </filter>
                            </defs>
                            <image :href="'/images/logo.png'" width="928" height="804" filter="url(#mobile-brand-transparent)" />
                        </svg>
                        <svg v-else aria-hidden="true" class="mb-0.5 h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v6.75A1.125 1.125 0 016.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125Z" />
                        </svg>
                        <span v-if="index !== 0">{{ link.label }}</span>
                    </a>
                </li>
                <li class="flex-1 text-center">
                    <DropdownMenuRoot v-model:open="mobileNewsOpen">
                        <DropdownMenuTrigger aria-label="Open news menu" class="flex min-h-11 w-full flex-col items-center justify-center transition-colors duration-150 motion-reduce:transition-none" :class="shell.news.some(link => link.active) ? 'text-white' : ''">
                            <svg aria-hidden="true" class="mb-0.5 h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h9m-9 4.5h9m-9 4.5h5.25M5.25 3.75h13.5A1.5 1.5 0 0120.25 5.25v13.5a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5V5.25a1.5 1.5 0 011.5-1.5Z" />
                            </svg>
                            News
                        </DropdownMenuTrigger>
                        <DropdownMenuPortal force-mount>
                            <Transition enter-active-class="transition-opacity duration-200 ease-out motion-reduce:transition-none" leave-active-class="transition-opacity duration-150 ease-in motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0">
                                <DropdownMenuContent v-if="mobileNewsOpen" force-mount side="top" align="center" :side-offset="12" class="z-40 w-48 overflow-hidden rounded-md bg-white py-1 text-left text-sm text-gray-700 shadow-lg ring-1 ring-black/10">
                                    <DropdownMenuItem v-for="link in shell.news" :key="link.href" as-child>
                                        <a :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block px-4 py-2 outline-none data-[highlighted]:bg-gray-100">{{ link.label }}</a>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </Transition>
                        </DropdownMenuPortal>
                    </DropdownMenuRoot>
                </li>
                <li v-if="toolLinks.length" class="flex-1 text-center">
                    <button type="button" aria-label="Open tools navigation" aria-haspopup="dialog" :aria-expanded="drawer === 'tools'" aria-controls="tools-navigation-panel" class="flex min-h-11 w-full flex-col items-center justify-center" :class="toolLinks.some(link => link.active) ? 'text-white' : ''" @click="openDrawer('tools', $event)">
                        <svg aria-hidden="true" class="mb-0.5 h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5h14v14H5zM8 3v4m8-4v4M5 10h14m-10 3h2m2 0h2m-6 3h2" /></svg>
                        Tools
                    </button>
                </li>
                <li v-if="user" class="flex-1 text-center">
                    <button type="button" class="flex min-h-11 w-full flex-col items-center justify-center" @click="openDrawer('navigation', $event)">
                        <svg aria-hidden="true" class="mb-0.5 h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                        </svg>
                        Menu
                    </button>
                </li>
                <li v-if="user" class="flex-1 text-center">
                    <button type="button" aria-label="Open account" class="flex min-h-11 w-full flex-col items-center justify-center" @click="openDrawer('account', $event)">
                        <img :src="shell.avatar || 'https://www.gravatar.com/avatar/?d=mp&amp;s=64'" alt="" class="h-9 w-9 rounded-full object-cover ring-2 ring-gray-200" />
                        <span class="sr-only">Account</span>
                    </button>
                </li>
                <li v-else class="flex-1 text-center">
                    <a :href="shell.login_url" class="flex min-h-11 flex-col items-center justify-center">
                        <img :src="'/images/Discord-Symbol-White.svg'" alt="" class="h-6 w-6" />
                        Sign In
                    </a>
                </li>
            </ul>
        </nav>
        <DialogRoot :open="!!drawer" @update:open="open => { if (!open) closeDrawer(); }">
            <DialogPortal force-mount>
                <div class="pointer-events-none fixed inset-x-0 top-0 z-50 h-[100dvh] overflow-hidden">
                    <Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" enter-from-class="opacity-0" leave-to-class="opacity-0"><DialogOverlay v-if="drawer" force-mount class="pointer-events-auto absolute inset-0 bg-gray-950/35" /></Transition>
                    <Transition enter-active-class="transition-transform duration-500 ease-out motion-reduce:transition-none" leave-active-class="transition-transform duration-300 ease-in motion-reduce:transition-none" :enter-from-class="toolsSheet ? 'translate-y-full md:translate-y-0 md:translate-x-full' : 'translate-x-full'" :leave-to-class="toolsSheet ? 'translate-y-full md:translate-y-0 md:translate-x-full' : 'translate-x-full'">
                        <DialogContent v-if="drawer && shell" :id="drawer === 'tools' ? 'tools-navigation-panel' : undefined" force-mount class="pointer-events-auto absolute flex min-h-0 w-full flex-col overflow-hidden shadow-2xl" :class="toolsSheet ? 'inset-x-0 bottom-0 h-[50dvh] rounded-t-3xl border border-b-0 border-slate-600 bg-slate-900 text-slate-100 ring-1 ring-sky-400/20 md:inset-x-auto md:right-0 md:top-0 md:h-full md:max-w-md md:rounded-none' : 'right-0 top-0 h-full max-w-md bg-white'" @close-auto-focus="restoreFocus">
                            <div v-if="toolsSheet" aria-hidden="true" class="mx-auto mt-3 h-1 w-10 shrink-0 rounded-full bg-slate-500" />
                            <header class="flex shrink-0 items-center justify-between border-b px-5 py-4" :class="toolsSheet ? 'border-slate-700' : 'border-gray-200'"><div><DialogTitle class="text-lg font-semibold">{{ drawer === 'account' ? 'Account' : drawer === 'tools' ? 'Tools' : 'Navigation' }}</DialogTitle><DialogDescription class="mt-1 text-xs" :class="toolsSheet ? 'text-slate-400' : 'text-gray-500'">{{ drawer === 'account' ? user?.name : toolsSheet ? 'Schedules and line combinations' : 'Explore DynastyIQ' }}</DialogDescription></div><button type="button" aria-label="Close menu" class="rounded-lg p-3 text-xl transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" :class="toolsSheet ? 'text-slate-300 hover:bg-slate-800' : 'text-gray-500 hover:bg-gray-100'" @click="closeDrawer">×</button></header>
                            <div class="min-h-0 flex-1 touch-pan-y overflow-y-auto overscroll-contain px-3 pt-5 pb-[calc(3rem+env(safe-area-inset-bottom))]">
                                <AccountPanel v-if="drawer === 'account' && user" :shell="shell" @notify="notify" />
                                <nav v-else-if="toolsSheet" aria-label="Tools navigation" :aria-busy="!!toolPending" class="space-y-3 px-2">
                                    <a v-for="link in toolLinks" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" :aria-disabled="toolPending ? 'true' : undefined" class="group flex min-h-20 items-center gap-4 rounded-2xl border px-4 py-3 transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" :class="toolPending?.href === link.href || link.active ? 'border-sky-400/60 bg-sky-400/10' : 'border-slate-700 bg-slate-800/70 hover:border-slate-500 hover:bg-slate-800'" @click="visitTool(link, $event)">
                                        <span aria-hidden="true" class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-sky-400/10 text-sky-300">
                                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path v-if="link.href.endsWith('/schedule')" stroke-linecap="round" stroke-linejoin="round" d="M8 3v4m8-4v4M4 10h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Zm3 9h2m4 0h2m-8 3h2" /><path v-else stroke-linecap="round" stroke-linejoin="round" d="M5 6h14M5 12h14M5 18h14M8 3v6m8 0v6M8 15v6" /></svg>
                                        </span>
                                        <span class="min-w-0 flex-1"><span class="block text-base font-semibold text-white">{{ link.label }}</span><span :role="toolPending?.href === link.href ? 'status' : undefined" class="mt-1 block text-xs text-slate-400">{{ toolPending?.href === link.href ? `Opening ${link.label}…` : link.href.endsWith('/schedule') ? 'Plan your week and off nights' : 'Explore the latest line combinations' }}</span></span>
                                        <svg v-if="toolPending?.href === link.href" aria-hidden="true" class="size-5 shrink-0 animate-spin text-sky-300 motion-reduce:animate-none" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" class="opacity-20" /><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
                                        <svg v-else aria-hidden="true" class="size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                                    </a>
                                    <div class="min-h-12 text-sm"><div v-if="toolFailure" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-red-400/30 bg-red-400/5 px-3 py-2"><p role="alert" class="text-red-200">Unable to open {{ toolFailure.label }}.</p><button type="button" class="rounded px-2 py-1 font-medium text-sky-300 hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400" @click="visitTool(toolFailure)">Retry</button></div></div>
                                </nav>
                                <nav v-else aria-label="All navigation" class="space-y-1"><template v-if="drawer !== 'tools'"><a v-for="link in [...mobileLinks, ...shell.news]" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded-lg px-3 py-3 text-sm hover:bg-gray-100" :class="link.active ? 'font-semibold text-indigo-700' : 'text-gray-700'">{{ link.label }}</a></template><p v-if="toolLinks.length && drawer !== 'tools'" class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Tools</p><a v-for="link in toolLinks" :key="link.href" :href="link.href" :aria-current="link.active ? 'page' : undefined" class="block rounded-lg px-3 py-3 text-sm text-gray-700 hover:bg-gray-100">{{ link.label }}</a><a v-if="user && drawer !== 'tools'" :href="shell.profile_url" class="block rounded-lg border-t border-gray-200 px-3 py-3 text-sm hover:bg-gray-100">Profile</a></nav>
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
