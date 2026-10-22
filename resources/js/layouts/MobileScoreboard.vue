<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    defaultSlateDate, shiftSlateDate, slateRolloverDelay, slateNavigationLabel,
    scoreboardStatus, mergeScoreboardGames, scoreboardDays,
} from './scoreboard';

const props = defineProps({
    endpoint: { type: String, required: true },
    gamesUrl: { type: String, required: true },
});
const selectedDate = ref(defaultSlateDate());
const clock = ref(new Date());
const games = ref([]);
const start = ref(null);
const end = ref(null);
const loading = ref(false);
const loadingEdge = ref(null);
const error = ref('');
const scroller = ref(null);
const days = computed(() => scoreboardDays(start.value, end.value, games.value));
const dateLabel = computed(() => slateNavigationLabel(selectedDate.value, clock.value));
const viewGamesUrl = computed(() => `${props.gamesUrl}?date=${encodeURIComponent(selectedDate.value)}`);
let disposed = false;
let manuallyBrowsing = false;
let request;
let requestVersion = 0;
let scrollFrame;
let rolloverTimer;
let refreshTimer;
let failedRange;
const loadedDates = new Set();

function activateRange(date) {
    if (!loadedDates.has(date)) return;
    let from = date;
    let through = date;
    while (loadedDates.has(shiftSlateDate(from, -1))) from = shiftSlateDate(from, -1);
    while (loadedDates.has(shiftSlateDate(through, 1))) through = shiftSlateDate(through, 1);
    start.value = from;
    end.value = through;
}

function alignDate(date) {
    const section = scroller.value?.querySelector(`[data-slate-date="${date}"]`);
    if (section) scroller.value.scrollLeft = section.offsetLeft;
}

async function loadRange(from, through, mode = 'replace', target = selectedDate.value) {
    if (disposed || (loading.value && mode !== 'replace')) return false;
    request?.abort();
    request = new AbortController();
    const version = ++requestVersion;
    loading.value = true;
    loadingEdge.value = mode === 'prepend' ? 'previous' : mode === 'append' ? 'next' : null;
    error.value = '';
    failedRange = null;
    try {
        const url = new URL(props.endpoint, window.location.origin);
        url.searchParams.set('start', from);
        url.searchParams.set('end', through);
        const response = await fetch(url.toString(), {
            headers: { Accept: 'application/json' }, cache: 'no-store', signal: request.signal,
        });
        if (!response.ok) throw new Error('Unable to load games.');
        const payload = await response.json();
        if (version !== requestVersion || disposed) return false;
        const oldWidth = scroller.value?.scrollWidth ?? 0;
        const oldLeft = scroller.value?.scrollLeft ?? 0;
        // Empty replacements remove cancelled/removed schedule rows from that range too.
        const retained = games.value.filter(game => game.game_date < from || game.game_date > through);
        games.value = mergeScoreboardGames(retained, payload.games);
        for (let date = from; date <= through; date = shiftSlateDate(date, 1)) loadedDates.add(date);
        if (mode !== 'refresh') activateRange(loadedDates.has(target) ? target : from);
        await nextTick();
        if (version !== requestVersion || disposed) return false;
        if (mode === 'replace') alignDate(target);
        if (mode === 'prepend' && scroller.value) {
            scroller.value.scrollLeft = oldLeft + scroller.value.scrollWidth - oldWidth;
        }
        return true;
    } catch (exception) {
        if (exception?.name !== 'AbortError' && version === requestVersion && !disposed) {
            error.value = 'Unable to load games.';
            failedRange = { from, through, mode, target };
        }
        return false;
    } finally {
        if (version === requestVersion && !disposed) {
            loading.value = false;
            loadingEdge.value = null;
        }
    }
}

async function loadAround(date) {
    if (loadedDates.has(date)) {
        activateRange(date);
        await nextTick();
        alignDate(date);
        return;
    }
    const through = shiftSlateDate(date, 7);
    let from = shiftSlateDate(date, -7);
    // Fetch only missing spans, including when a date jump overlaps a cached week.
    while (from <= through) {
        if (loadedDates.has(from)) {
            from = shiftSlateDate(from, 1);
            continue;
        }
        let to = from;
        while (to < through && !loadedDates.has(shiftSlateDate(to, 1))) to = shiftSlateDate(to, 1);
        if (!await loadRange(from, to, 'replace', date)) return;
        from = shiftSlateDate(to, 1);
    }
    activateRange(date);
    await nextTick();
    alignDate(date);
}

function retryRange() {
    if (!failedRange) return loadAround(selectedDate.value);
    const { from, through, mode, target } = failedRange;
    return loadRange(from, through, mode, target);
}

async function chooseDate(date, manual = true) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return;
    if (manual) manuallyBrowsing = true;
    ++requestVersion;
    request?.abort();
    loading.value = false;
    loadingEdge.value = null;
    error.value = '';
    failedRange = null;
    selectedDate.value = date;
    if (start.value && date >= start.value && date <= end.value) alignDate(date);
    else await loadAround(date);
}

function onScroll() {
    if (scrollFrame) return;
    scrollFrame = requestAnimationFrame(() => {
        scrollFrame = null;
        const element = scroller.value;
        if (!element || disposed) return;
        const sections = [...element.querySelectorAll('[data-slate-date]')];
        const visible = sections.find(section => section.offsetLeft + section.offsetWidth > element.scrollLeft + 1);
        if (visible) selectedDate.value = visible.dataset.slateDate;
        if (loading.value || error.value) return;
        if (!start.value || !end.value) return;
        if (element.scrollLeft < 256) {
            const from = shiftSlateDate(start.value, -7);
            loadRange(from, shiftSlateDate(start.value, -1), 'prepend');
        } else if (element.scrollWidth - element.clientWidth - element.scrollLeft < 256) {
            const through = shiftSlateDate(end.value, 7);
            loadRange(shiftSlateDate(end.value, 1), through, 'append');
        }
    });
}

function scheduleRollover() {
    clearTimeout(rolloverTimer);
    rolloverTimer = setTimeout(async () => {
        if (disposed) return;
        clock.value = new Date();
        if (!manuallyBrowsing) {
            selectedDate.value = defaultSlateDate();
            await loadAround(selectedDate.value);
        }
        scheduleRollover();
    }, slateRolloverDelay());
}

function refresh() {
    if (disposed || document.hidden || loading.value) return;
    clock.value = new Date();
    if (!manuallyBrowsing && selectedDate.value !== defaultSlateDate()) {
        selectedDate.value = defaultSlateDate();
        loadAround(selectedDate.value);
    } else if (start.value && selectedDate.value >= start.value && selectedDate.value <= end.value
        && selectedDate.value >= shiftSlateDate(clock.value.toISOString().slice(0, 10), -1)
        && selectedDate.value <= clock.value.toISOString().slice(0, 10)) {
        loadRange(selectedDate.value, selectedDate.value, 'refresh');
    }
}

const isLive = game => scoreboardStatus(game) === 'Live';
const isLoser = (game, side) => scoreboardStatus(game) === 'Final'
    && typeof game.away.score === 'number' && typeof game.home.score === 'number'
    && game[side].score < game[side === 'away' ? 'home' : 'away'].score;
const gameLabel = game => `${game.away.team_abbrev ?? 'Away'} at ${game.home.team_abbrev ?? 'Home'}, ${scoreboardStatus(game)}${game.away.score !== null && game.home.score !== null ? `, ${game.away.score}–${game.home.score}` : ''}`;

onMounted(() => {
    document.addEventListener('visibilitychange', refresh);
    selectedDate.value = defaultSlateDate();
    loadAround(selectedDate.value);
    scheduleRollover();
    refreshTimer = setInterval(refresh, 60000);
});
onBeforeUnmount(() => {
    disposed = true;
    ++requestVersion;
    request?.abort();
    document.removeEventListener('visibilitychange', refresh);
    clearTimeout(rolloverTimer);
    clearInterval(refreshTimer);
    if (scrollFrame) cancelAnimationFrame(scrollFrame);
});
</script>

<template>
    <header class="relative border-y border-[#1F2B37] bg-[#101923] text-[#E7EEF6] md:flex" aria-label="NHL scoreboard">
        <div class="flex min-h-9 items-center justify-between border-b border-[#1F2B37] px-2 md:contents" aria-label="Scoreboard date navigation">
            <div class="flex shrink-0 items-center justify-between md:order-first md:w-40 md:border-r md:border-[#1F2B37] md:px-2">
                <button type="button" aria-label="Previous scoreboard date" class="flex min-h-9 w-7 shrink-0 items-center justify-center text-xl text-[#C9D5E0] transition-colors duration-150 hover:text-white motion-reduce:transition-none" @click="chooseDate(shiftSlateDate(selectedDate, -1))">‹</button>
                <label class="relative flex min-h-9 cursor-pointer items-center justify-center rounded px-1 focus-within:ring-2 focus-within:ring-inset focus-within:ring-sky-400">
                    <span class="whitespace-nowrap text-xs font-semibold text-[#C9D5E0]">{{ dateLabel }}</span>
                    <input type="date" aria-label="Choose scoreboard date" :value="selectedDate" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" @change="chooseDate($event.target.value)" />
                </label>
                <button type="button" aria-label="Next scoreboard date" class="flex min-h-9 w-7 shrink-0 items-center justify-center text-xl text-[#C9D5E0] transition-colors duration-150 hover:text-white motion-reduce:transition-none" @click="chooseDate(shiftSlateDate(selectedDate, 1))">›</button>
            </div>
            <a :href="viewGamesUrl" class="flex min-h-9 items-center gap-1 rounded px-2 text-xs font-semibold text-[#C9D5E0] transition-colors duration-150 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none md:order-last md:w-28 md:shrink-0 md:justify-center md:rounded-none md:border-l md:border-[#1F2B37]">Games <span aria-hidden="true" class="text-xl font-normal">›</span></a>
        </div>
            <div ref="scroller" role="region" aria-label="Games by date, scroll horizontally" tabindex="0" :aria-busy="loading" class="relative flex min-h-[82px] w-full min-w-0 overflow-x-auto overscroll-x-contain [overflow-anchor:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:w-0 md:flex-1" @scroll.passive="onScroll" @touchstart.passive="manuallyBrowsing = true" @wheel.passive="manuallyBrowsing = true" @keydown="manuallyBrowsing = true">
                <section v-for="day in days" :key="day.date" :data-slate-date="day.date" :aria-label="day.labels.full" class="flex shrink-0">
                    <div class="flex w-8 shrink-0 items-center justify-center border-r border-[#1F2B37] bg-white/[0.025]">
                        <span class="whitespace-nowrap font-mono text-[9px] text-[#788997] [writing-mode:vertical-rl]">{{ day.labels.weekday }} {{ day.labels.date }}</span>
                    </div>
                    <a v-for="game in day.games" :key="game.nhl_game_id" :href="game.href" :aria-label="gameLabel(game)" class="relative w-32 shrink-0 border-r border-[#1F2B37] px-3.5 py-2.5 transition-colors duration-150 hover:bg-white/5 focus-visible:bg-white/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-400 motion-reduce:transition-none">
                        <div class="mb-1.5 font-mono text-[10px] font-semibold" :class="isLive(game) ? 'text-[#FF7A70]' : 'text-[#788997]'">{{ scoreboardStatus(game) }}</div>
                        <div v-for="side in ['away', 'home']" :key="side" class="flex h-5 items-center gap-1.5 font-mono text-[13px] font-semibold" :class="isLoser(game, side) ? 'text-[#788997]' : 'text-[#E7EEF6]'">
                            <img v-if="game[side].team_logo" :src="game[side].team_logo" alt="" class="h-[18px] w-[18px] shrink-0 object-contain" :class="isLoser(game, side) ? 'opacity-50' : ''" loading="lazy" />
                            <span v-else aria-hidden="true" class="h-[18px] w-[18px] shrink-0" />
                            <span class="min-w-0 flex-1">{{ game[side].team_abbrev ?? '—' }}</span>
                            <span>{{ game[side].score ?? '' }}</span>
                        </div>
                    </a>
                    <div v-if="!day.games.length" class="flex w-32 shrink-0 items-center justify-center border-r border-[#1F2B37] px-3 text-center text-xs text-[#788997]">No games scheduled</div>
                </section>
                <div v-if="!days.length && loading" role="status" class="flex min-h-[82px] w-full items-center justify-center gap-2 text-xs text-[#C9D5E0]"><span aria-hidden="true" class="h-4 w-4 animate-spin rounded-full border-2 border-sky-400/20 border-t-sky-300 motion-reduce:animate-none" />Loading games…</div>
            </div>
        <div v-if="loadingEdge" role="status" aria-live="polite" class="pointer-events-none absolute bottom-0 top-9 z-10 flex w-40 flex-col items-center justify-center gap-2 from-[#101923] via-[#101923]/95 to-transparent px-3 text-xs font-medium text-[#C9D5E0] md:top-0" :class="loadingEdge === 'previous' ? 'left-0 bg-gradient-to-r pr-7 md:left-40' : 'right-0 bg-gradient-to-l pl-7 md:right-28'">
            <span aria-hidden="true" class="h-5 w-5 animate-spin rounded-full border-2 border-sky-400/20 border-t-sky-300 motion-reduce:animate-none" />
            <span>{{ loadingEdge === 'previous' ? 'Loading previous week…' : 'Loading next week…' }}</span>
        </div>
        <div v-if="error" role="status" class="flex items-center justify-center gap-2 border-t border-[#1F2B37] px-3 py-1 text-xs text-gray-300 md:absolute md:inset-x-40 md:top-full md:z-20 md:bg-[#101923]">{{ error }} <button type="button" class="min-h-11 underline" @click="retryRange">Retry</button></div>
    </header>
</template>
