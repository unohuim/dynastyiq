<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardSection from './Section.vue';

const props = defineProps({ dashboard: { type: Object, required: true } });
const teamTab = ref('offense');
const playerTab = ref('up');
const lineTab = ref('gf');
const moreHeadlines = ref(false);
const storyStrip = ref(null);
const storyScroll = ref({ position: 0, maximum: 0, visible: 0, total: 0 });
const storyDrag = ref(null);
const stories = computed(() => [...props.dashboard.featured, ...props.dashboard.headlines]
    .filter((story, index, all) => all.findIndex(candidate => candidate.id === story.id) === index));
const storyThumbWidth = computed(() => storyScroll.value.total > 0
    ? Math.min(100, storyScroll.value.visible / storyScroll.value.total * 100) : 100);
const storyThumbLeft = computed(() => storyScroll.value.maximum > 0
    ? storyScroll.value.position / storyScroll.value.maximum * (100 - storyThumbWidth.value) : 0);
const performerStrip = ref(null);
const performerPosition = ref(0);
const performerMaximum = ref(0);
const performerVisibleWidth = ref(0);
const performerTotalWidth = ref(0);
let performerDrag = null;
const performerThumbWidth = computed(() => performerTotalWidth.value > 0
    ? Math.min(100, performerVisibleWidth.value / performerTotalWidth.value * 100) : 100);
const performerThumbLeft = computed(() => performerMaximum.value > 0
    ? performerPosition.value / performerMaximum.value * (100 - performerThumbWidth.value) : 0);
const teamTabs = [
    { key: 'offense', label: 'Offense', unit: 'Goals / game' },
    { key: 'defense', label: 'Defense', unit: 'Goals against / game' },
    { key: 'special_teams', label: 'Special Teams', unit: 'PP goals / game' },
];
const playerTabs = [{ key: 'up', label: 'Trending Up' }, { key: 'down', label: 'Trending Down' }, { key: 'breakout', label: 'Breakout' }];
const lineTabs = [
    { key: 'gf', label: 'G', unit: 'Goals for' },
    { key: 'corsi', label: 'Corsi', unit: 'Shot attempts for − against' },
    { key: 'cf_pct', label: 'CF%', unit: 'Shot attempts for / total attempts' },
    { key: 'shifts', label: 'Shifts', unit: 'Shared 5v5 shifts' },
    { key: 'toi_seconds', label: 'TOI', unit: 'Shared 5v5 time' },
];
const lineUnit = computed(() => lineTabs.find(tab => tab.key === lineTab.value)?.unit);
const lineLabel = computed(() => lineTabs.find(tab => tab.key === lineTab.value)?.label);
const secondaryLineTabs = computed(() => lineTabs.filter(tab => tab.key !== lineTab.value));
const lines = computed(() => [...(props.dashboard.line_combinations ?? [])]
    .filter(line => Number.isFinite(line[lineTab.value]))
    .filter(line => lineTab.value !== 'cf_pct' || line.toi_seconds >= 300)
    .sort((a, b) => b[lineTab.value] - a[lineTab.value]
        || b.goal_diff - a.goal_diff || (b.cf_pct ?? -1) - (a.cf_pct ?? -1)
        || b.toi_seconds - a.toi_seconds || a.unit_id - b.unit_id || a.game_id - b.game_id).slice(0, 5));
const lineValue = (line, key) => !Number.isFinite(line[key]) ? '—'
    : key === 'toi_seconds' ? toi(line.toi_seconds)
        : key === 'cf_pct' ? `${decimal(line.cf_pct)}%`
            : key === 'corsi' && line.corsi > 0 ? `+${line.corsi}` : String(line[key]);
const teams = computed(() => [...props.dashboard.team_trends]
    .sort((a, b) => Number.isFinite(b[teamTab.value]) - Number.isFinite(a[teamTab.value])
        || (teamTab.value === 'defense' ? (a.defense ?? 0) - (b.defense ?? 0) : (b[teamTab.value] ?? 0) - (a[teamTab.value] ?? 0))));
const players = computed(() => [...props.dashboard.player_trends]
    .filter(player => playerTab.value === 'down' ? player.delta < 0 : playerTab.value === 'breakout' ? player.breakout : player.delta > 0)
    .sort((a, b) => playerTab.value === 'down' ? a.delta - b.delta : b.delta - a.delta).slice(0, 25));
const teamUnit = computed(() => teamTabs.find(tab => tab.key === teamTab.value)?.unit);
const headlinePreview = computed(() => props.dashboard.headlines.slice(0, 5));
const externalLinkAttributes = href => href && new URL(href, window.location.href).origin !== window.location.origin
    ? { target: '_blank', rel: 'noopener noreferrer' } : {};
const initials = name => (name || '?').split(' ').map(part => part[0]).slice(0, 2).join('');
const decimal = (value, places = 1) => Number.isFinite(value) ? value.toFixed(places) : '—';
const signed = value => Number.isFinite(value) ? `${value > 0 ? '+' : ''}${value.toFixed(1)}` : '—';
const goaliePercentage = value => Number.isFinite(value) ? value.toFixed(3).replace(/^0/, '') : '—';
const shortDate = value => value ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'America/Toronto' }).format(new Date(value.length === 10 ? `${value}T12:00:00Z` : value)) : '';
const performanceDate = computed(() => props.dashboard.performance_date
    ? new Intl.DateTimeFormat('en-US', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'America/Toronto' })
        .format(new Date(`${props.dashboard.performance_date}T12:00:00Z`)).replace(',', '') : '');
const toi = seconds => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
const injuryLabel = row => row.availability === 'out' ? 'Out' : row.availability === 'questionable' ? 'Questionable' : 'Reported';
const deltaClass = (value, defense = false) => !Number.isFinite(value) || value === 0 ? 'text-slate-500' : (defense ? value < 0 : value > 0) ? 'text-emerald-600' : 'text-rose-600';

function updateStoryScroll() {
    const strip = storyStrip.value;
    const visible = strip?.clientWidth ?? 0;
    const total = strip?.scrollWidth ?? 0;
    const maximum = Math.max(0, total - visible);
    storyScroll.value = { visible, total, maximum, position: Math.max(0, Math.min(maximum, strip?.scrollLeft ?? 0)) };
}

function dragStoryScrollbar(event) {
    const strip = storyStrip.value;
    if (!strip || storyScroll.value.maximum <= 0) return;
    const bounds = event.currentTarget.getBoundingClientRect();
    if (!bounds.width) return;
    const pointer = (event.clientX - bounds.left) / bounds.width * 100;
    if (event.type === 'pointerdown') {
        if (event.button !== 0) return;
        const overThumb = pointer >= storyThumbLeft.value && pointer <= storyThumbLeft.value + storyThumbWidth.value;
        storyDrag.value = { id: event.pointerId, offset: overThumb ? pointer - storyThumbLeft.value : storyThumbWidth.value / 2 };
        event.currentTarget.setPointerCapture?.(event.pointerId);
    }
    if (!storyDrag.value || storyDrag.value.id !== event.pointerId) return;
    strip.scrollLeft = Math.max(0, Math.min(storyScroll.value.maximum,
        (pointer - storyDrag.value.offset) / 100 * strip.scrollWidth));
    updateStoryScroll();
}

function stopStoryDrag(event) {
    if (storyDrag.value?.id !== event.pointerId) return;
    storyDrag.value = null;
    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
}

function storyScrollbarKey(event) {
    const strip = storyStrip.value;
    if (!strip || !['ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) return;
    event.preventDefault();
    const step = strip.querySelector('article')?.offsetWidth + 12 || strip.clientWidth;
    strip.scrollLeft = event.key === 'Home' ? 0 : event.key === 'End' ? storyScroll.value.maximum
        : strip.scrollLeft + (['ArrowLeft', 'PageUp'].includes(event.key) ? -step : step);
    updateStoryScroll();
}

watch(storyStrip, (strip, _previous, onCleanup) => {
    updateStoryScroll();
    if (!strip || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(updateStoryScroll);
    observer.observe(strip);
    onCleanup(() => observer.disconnect());
}, { flush: 'post' });
watch(() => stories.value.length, async () => {
    await nextTick();
    updateStoryScroll();
}, { flush: 'post' });

function updatePerformerScroll() {
    const strip = performerStrip.value;
    performerVisibleWidth.value = strip?.clientWidth ?? 0;
    performerTotalWidth.value = strip?.scrollWidth ?? 0;
    performerMaximum.value = strip ? Math.max(0, strip.scrollWidth - strip.clientWidth) : 0;
    performerPosition.value = Math.max(0, Math.min(performerMaximum.value, strip?.scrollLeft ?? 0));
}

function movePerformers(direction) {
    const strip = performerStrip.value;
    if (!strip) return;
    strip.scrollBy({ left: direction * strip.clientWidth,
        behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 'auto' : 'smooth' });
}

function dragPerformerScrollbar(event) {
    const strip = performerStrip.value;
    if (!strip || performerMaximum.value <= 0) return;
    const bounds = event.currentTarget.getBoundingClientRect();
    if (!bounds.width) return;
    const pointer = (event.clientX - bounds.left) / bounds.width * 100;
    if (event.type === 'pointerdown') {
        if (event.button !== 0) return;
        const overThumb = pointer >= performerThumbLeft.value && pointer <= performerThumbLeft.value + performerThumbWidth.value;
        performerDrag = { id: event.pointerId, offset: overThumb ? pointer - performerThumbLeft.value : performerThumbWidth.value / 2 };
        event.currentTarget.setPointerCapture?.(event.pointerId);
    }
    if (!performerDrag || performerDrag.id !== event.pointerId) return;
    strip.scrollLeft = Math.max(0, Math.min(performerMaximum.value, (pointer - performerDrag.offset) / 100 * strip.scrollWidth));
    updatePerformerScroll();
}

function stopPerformerDrag(event) {
    if (performerDrag?.id !== event.pointerId) return;
    performerDrag = null;
    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
}

function performerScrollbarKey(event) {
    const strip = performerStrip.value;
    if (!strip || !['ArrowLeft', 'ArrowRight', 'Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) return;
    event.preventDefault();
    const step = event.key.startsWith('Page') ? strip.clientWidth : strip.clientWidth / 4;
    strip.scrollLeft = event.key === 'Home' ? 0 : event.key === 'End' ? performerMaximum.value
        : strip.scrollLeft + (['ArrowLeft', 'PageUp'].includes(event.key) ? -step : step);
    updatePerformerScroll();
}

watch(performerStrip, (strip, _previous, onCleanup) => {
    updatePerformerScroll();
    if (!strip || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(updatePerformerScroll);
    observer.observe(strip);
    onCleanup(() => observer.disconnect());
}, { flush: 'post' });
watch(() => props.dashboard.performers.length, async () => {
    await nextTick();
    updatePerformerScroll();
}, { flush: 'post' });

function navigateTabs(event, group) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const tabs = group === 'team' ? teamTabs : group === 'line' ? lineTabs : playerTabs;
    const active = group === 'team' ? teamTab : group === 'line' ? lineTab : playerTab;
    const index = tabs.findIndex(tab => tab.key === active.value);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
        : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    active.value = tabs[next].key;
    event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[next]?.focus();
}
</script>

<template>
    <Head title="Home · DynastyIQ" />
    <main class="mx-auto grid w-full max-w-[1800px] gap-2 bg-slate-50 px-3 py-2 text-slate-900 sm:px-5 lg:px-7" :class="!dashboard.search && !moreHeadlines ? 'xl:h-[calc(100dvh-196px)] xl:min-h-[550px] xl:grid-rows-[minmax(112px,0.9fr)_232px_minmax(160px,1.3fr)]' : ''" aria-label="NHL dashboard">
        <h1 class="sr-only">DynastyIQ hockey dashboard</h1>

        <DashboardSection v-if="dashboard.search" :title="`Search results for “${dashboard.query}”`">
            <div class="grid gap-5 md:grid-cols-2">
                <div><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Players &amp; teams</h3>
                    <a v-for="player in dashboard.search.players" :key="player.url" :href="player.url" v-bind="externalLinkAttributes(player.url)" class="flex items-center gap-3 rounded p-2 hover:bg-slate-50">
                        <img v-if="player.avatar" :src="player.avatar" alt="" class="h-10 w-10 object-contain" />
                        <span class="text-sm font-semibold">{{ player.name }} <span class="font-normal text-slate-500">{{ player.position }} · {{ player.team }}</span></span>
                    </a>
                    <p v-if="!dashboard.search.players.length" class="text-sm text-slate-500">No matching players.</p>
                </div>
                <div><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Headlines</h3>
                    <a v-for="story in dashboard.search.stories" :key="story.id" :href="story.url" v-bind="externalLinkAttributes(story.url)" class="block rounded py-2 text-sm font-medium hover:text-blue-600">{{ story.title }}</a>
                    <p v-if="!dashboard.search.stories.length" class="text-sm text-slate-500">No matching headlines in the current feed.</p>
                </div>
            </div>
        </DashboardSection>

        <div class="flex min-h-0 min-w-0 flex-col">
            <p class="mb-1 text-right text-[10px] text-slate-400">Stories: NHL.com<template v-if="dashboard.news_updated_at"> · Updated {{ shortDate(dashboard.news_updated_at) }}</template><template v-if="dashboard.news_unavailable"> · Showing the last available feed</template></p>
        <div v-if="stories.length" class="flex min-h-0 min-w-0 flex-1 flex-col">
        <section id="featured-story-cards" ref="storyStrip" tabindex="0" class="grid min-h-0 min-w-0 grid-flow-col auto-cols-[100%] gap-3 overflow-x-auto overflow-y-hidden overscroll-x-contain [scrollbar-width:none] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 md:flex-1 md:grid-flow-row md:auto-cols-auto md:grid-cols-3 md:snap-none md:overflow-visible xl:grid-cols-[1.2fr_1fr_1fr] [&::-webkit-scrollbar]:hidden" :class="storyDrag ? 'snap-none' : 'snap-x snap-mandatory'" aria-label="Featured NHL stories" @scroll.passive="updateStoryScroll">
            <article v-for="(story, index) in stories" :key="story.id" class="relative isolate flex h-40 snap-start snap-always overflow-hidden rounded-lg bg-slate-900 text-white md:h-64 lg:h-[260px] xl:h-full xl:min-h-0" :class="index >= dashboard.featured.length ? 'md:hidden' : ''">
                <img v-if="story.image_url" :src="story.image_url" alt="" class="absolute inset-0 -z-20 h-full w-full object-contain object-top" :loading="index === 0 ? 'eager' : 'lazy'" />
                <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black via-black/50 to-black/5" />
                <div class="flex min-h-0 w-full flex-col items-start p-3">
                    <span class="rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="index === 0 ? 'bg-blue-600' : index === 1 ? 'bg-violet-700' : 'bg-emerald-600'">{{ story.category }}</span>
                    <div class="mt-auto pt-1">
                        <h2 class="line-clamp-2 text-base font-extrabold leading-tight tracking-tight md:text-xl xl:text-lg"><a :href="story.url" v-bind="externalLinkAttributes(story.url)" :title="story.title" class="rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">{{ story.title }}</a></h2>
                        <p v-if="story.summary" class="mt-1 hidden text-xs leading-snug text-white/90 md:line-clamp-1 [@media(min-width:1280px)_and_(max-height:800px)]:hidden">{{ story.summary }}</p>
                        <a :href="story.url" v-bind="externalLinkAttributes(story.url)" class="mt-2 inline-flex min-h-7 items-center gap-3 rounded bg-blue-600 px-3 text-xs font-semibold transition-colors duration-150 hover:bg-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white motion-reduce:transition-none">Read story <span aria-hidden="true">→</span></a>
                    </div>
                    <span class="sr-only">Source: NHL.com, {{ shortDate(story.published_at) }}</span>
                </div>
            </article>
        </section>
            <svg v-if="storyScroll.maximum > 1" role="scrollbar" aria-label="Browse NHL stories" aria-controls="featured-story-cards" aria-orientation="horizontal" :aria-valuemin="0" :aria-valuemax="Math.round(storyScroll.maximum)" :aria-valuenow="Math.round(storyScroll.position)" tabindex="0" viewBox="0 0 100 12" preserveAspectRatio="none" class="group h-3 w-full shrink-0 touch-none cursor-grab rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:cursor-grabbing md:hidden" @pointerdown.prevent="dragStoryScrollbar" @pointermove="dragStoryScrollbar" @pointerup="stopStoryDrag" @pointercancel="stopStoryDrag" @lostpointercapture="stopStoryDrag" @keydown="storyScrollbarKey">
                <rect x="0" y="4" width="100" height="4" rx="0.6" class="fill-slate-200" />
                <rect :x="storyThumbLeft" y="3" :width="storyThumbWidth" height="6" rx="0.8" class="fill-slate-400 transition-colors duration-150 group-hover:fill-slate-500 motion-reduce:transition-none" />
            </svg>
        </div>
        <div v-else class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600" role="status">NHL stories are currently unavailable. <a :href="dashboard.links.news" v-bind="externalLinkAttributes(dashboard.links.news)" class="font-medium text-blue-600">Read the latest on NHL.com ›</a></div>
        </div>

        <div class="contents min-h-0 gap-3 md:grid xl:grid-cols-12">
            <DashboardSection title="Last Night's Top Performers" :href="dashboard.links.stats" class="order-1 md:order-none xl:col-span-5">
                <div class="mb-1 flex shrink-0 items-center justify-between text-[10px] text-slate-500"><span>{{ performanceDate || 'Latest completed game day' }}</span><div class="flex items-center gap-2"><span>Standouts first</span><div v-if="dashboard.performers.length" class="flex items-center gap-1"><button type="button" aria-label="Previous performers" :disabled="performerPosition <= 1" class="flex h-4 w-5 items-center justify-center rounded text-slate-600 transition-colors duration-150 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:opacity-30 motion-reduce:transition-none" @click="movePerformers(-1)"><svg aria-hidden="true" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg></button><button type="button" aria-label="Next performers" :disabled="performerPosition >= performerMaximum - 1" class="flex h-4 w-5 items-center justify-center rounded text-slate-600 transition-colors duration-150 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:opacity-30 motion-reduce:transition-none" @click="movePerformers(1)"><svg aria-hidden="true" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg></button></div></div></div>
                <div v-if="dashboard.performers.length" class="flex min-h-0 flex-1 flex-col">
                <div id="top-performer-cards" ref="performerStrip" role="region" aria-label="Last night’s top performers, scroll horizontally" tabindex="0" class="grid min-h-0 grid-flow-col auto-cols-[calc((100%_-_0.5rem)/2)] gap-2 overflow-x-auto overflow-y-hidden overscroll-x-contain [scrollbar-width:none] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 sm:auto-cols-[calc((100%_-_1.5rem)/4)] [&::-webkit-scrollbar]:hidden" @scroll.passive="updatePerformerScroll">
                    <a v-for="player in dashboard.performers" :key="player.nhl_player_id" :href="player.url" v-bind="externalLinkAttributes(player.url)" class="flex min-w-0 flex-col rounded-md border border-slate-200/80 bg-gradient-to-b from-white to-slate-50 p-1 transition-colors duration-150 hover:border-blue-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 motion-reduce:transition-none">
                        <div class="relative flex h-[76px] shrink-0 items-end justify-center overflow-hidden">
                            <img v-if="player.avatar" :src="player.avatar" alt="" class="h-full w-full object-contain object-bottom" loading="lazy" />
                            <span v-else class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-lg font-bold text-slate-500">{{ initials(player.name) }}</span>
                            <img v-if="player.team_logo" :src="player.team_logo" alt="" class="absolute right-0 top-0 h-7 w-7 object-contain" loading="lazy" />
                        </div>
                        <h3 class="mt-0.5 truncate text-[11px] font-bold leading-tight" :title="player.name">{{ player.name }}</h3>
                        <p class="text-[9px] leading-tight text-slate-500">{{ player.position }} · {{ player.team }}</p>
                        <div v-if="player.goalie" class="mt-0.5 flex justify-between gap-1 text-[10px]"><span><b class="text-xs">{{ player.saves }}</b> SV</span><span><b>{{ player.goals_against }}</b> GA</span><b>{{ goaliePercentage(player.save_percentage) }}</b></div>
                        <div v-else class="mt-0.5 flex justify-between gap-1 text-[10px]"><span><b class="text-xs">{{ player.goals }}</b> G</span><span><b class="text-xs">{{ player.assists }}</b> A</span><span><b class="text-xs">{{ player.points }}</b> PTS</span></div>
                        <span class="mt-0.5 block max-w-full self-start truncate rounded bg-blue-50 px-1.5 py-0.5 text-[9px] font-medium leading-3 text-blue-700">{{ player.badge }}</span>
                    </a>
                </div>
                    <svg v-if="performerMaximum > 1" role="scrollbar" aria-label="Browse top performers" aria-controls="top-performer-cards" aria-orientation="horizontal" :aria-valuemin="0" :aria-valuemax="Math.round(performerMaximum)" :aria-valuenow="Math.round(performerPosition)" :aria-disabled="performerMaximum <= 0" :tabindex="performerMaximum > 0 ? 0 : -1" viewBox="0 0 100 12" preserveAspectRatio="none" class="group h-3 w-full shrink-0 touch-none cursor-grab rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 active:cursor-grabbing" @pointerdown.prevent="dragPerformerScrollbar" @pointermove="dragPerformerScrollbar" @pointerup="stopPerformerDrag" @pointercancel="stopPerformerDrag" @lostpointercapture="stopPerformerDrag" @keydown="performerScrollbarKey">
                        <rect x="0" y="4" width="100" height="4" rx="0.6" class="fill-slate-200" />
                        <rect :x="performerThumbLeft" y="3" :width="performerThumbWidth" height="6" rx="0.8" class="fill-slate-400 transition-colors duration-150 group-hover:fill-slate-500 motion-reduce:transition-none" />
                    </svg>
                </div>
                <p v-else class="flex flex-1 items-center justify-center py-4 text-center text-xs text-slate-500">Player performances will appear when completed boxscores are available.</p>
            </DashboardSection>

            <DashboardSection title="Notable Signings & Moves" :href="dashboard.links.transactions" class="order-3 md:order-none xl:col-span-4">
                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="move in dashboard.transactions" :key="move.id" :href="move.url" v-bind="externalLinkAttributes(move.url)" class="flex min-h-8 items-center gap-2 py-1 hover:text-blue-600">
                        <img v-if="move.avatar" :src="move.avatar" alt="" class="h-6 w-6 shrink-0 rounded bg-slate-50 object-contain" loading="lazy" />
                        <span v-else class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-slate-50 text-[10px] font-semibold text-slate-500">{{ initials(move.name) }}</span>
                        <div class="min-w-0 flex-1"><h3 class="truncate text-[11px] font-semibold leading-tight" :title="move.description || `${move.name} · ${move.type}`">{{ move.description || `${move.name} · ${move.type}` }}</h3><p class="truncate text-[9px] leading-tight text-slate-500">{{ move.name }}<template v-if="move.team"> · {{ move.team }}</template></p></div>
                        <time :datetime="move.date" class="self-start whitespace-nowrap text-[9px] text-slate-400">{{ shortDate(move.date) }}</time>
                    </a>
                </div>
                <p v-if="!dashboard.transactions.length" class="py-4 text-center text-xs text-slate-500">No recent signings or moves are available.</p>
            </DashboardSection>

            <DashboardSection title="Injury Watch" :href="dashboard.links.injuries" class="order-3 md:order-none xl:col-span-3">
                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="player in dashboard.injuries" :key="player.id" :href="player.url" v-bind="externalLinkAttributes(player.url)" class="flex min-h-8 items-center gap-2 py-0.5 hover:text-blue-600">
                        <img v-if="player.avatar" :src="player.avatar" alt="" class="h-6 w-6 shrink-0 rounded bg-slate-50 object-contain" loading="lazy" />
                        <span v-else class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-slate-50 text-[10px] text-slate-500">{{ initials(player.name) }}</span>
                        <div class="min-w-0 flex-1"><h3 class="truncate text-[11px] font-semibold leading-tight">{{ player.name }}</h3><p class="text-[9px] leading-tight text-slate-500">{{ player.position }} · {{ player.team }}</p></div>
                        <div class="flex min-w-0 max-w-[46%] flex-col items-end text-right"><span class="inline-block rounded-full px-1.5 text-[9px] font-medium leading-4" :class="player.availability === 'out' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'">{{ injuryLabel(player) }}</span><p class="max-w-full truncate text-[9px] leading-tight text-slate-500" :title="player.status || player.body_part">{{ player.body_part || player.status || 'Details pending' }}</p><time v-if="player.anticipated_return_date" :datetime="player.anticipated_return_date" class="text-[9px] leading-tight text-slate-500">Return {{ shortDate(player.anticipated_return_date) }}</time><span v-else class="text-[9px] leading-tight text-slate-400">Return unknown</span></div>
                    </a>
                </div>
                <p v-if="!dashboard.injuries.length" class="py-4 text-center text-xs text-slate-500">No current injury reports.</p>
            </DashboardSection>
        </div>

        <div class="contents min-h-0 gap-3 md:grid md:grid-cols-2 xl:grid-cols-12">
            <DashboardSection title="Team Trends" :href="dashboard.links.stats" class="order-3 h-80 md:order-none xl:col-span-3 xl:h-auto">
                <div role="tablist" aria-label="Team trend metric" class="mb-2 grid grid-cols-3 gap-1 rounded bg-slate-50 p-0.5">
                    <button v-for="tab in teamTabs" :id="`team-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :tabindex="teamTab === tab.key ? 0 : -1" :aria-selected="teamTab === tab.key" aria-controls="team-trend-results" class="min-h-8 rounded border px-1 text-[10px] font-medium transition-colors duration-150 motion-reduce:transition-none" :class="teamTab === tab.key ? 'border-blue-200 bg-white text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900'" @click="teamTab = tab.key" @keydown="navigateTabs($event, 'team')">{{ tab.label }}</button>
                </div>
                <p class="mb-2 text-[10px] text-slate-400">{{ teamUnit }} · 10 GP vs 10 GP at 20+ games; otherwise 5 GP vs 5 GP</p>
                <div id="team-trend-results" role="tabpanel" :aria-labelledby="`team-tab-${teamTab}`" tabindex="0" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-y-contain focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500">
                    <a v-for="(team, index) in teams" :key="team.team" :href="team.url" v-bind="externalLinkAttributes(team.url)" class="flex min-h-8 items-center gap-2 py-1 text-xs hover:text-blue-600">
                        <span class="w-3 text-[10px] text-slate-500">{{ index + 1 }}</span><img v-if="team.logo" :src="team.logo" alt="" class="h-5 w-5 object-contain" loading="lazy" />
                        <span class="min-w-0 flex-1"><span class="block truncate font-semibold">{{ team.name || team.team }}</span><span class="block text-[9px] text-slate-400">{{ team.games }} GP vs {{ team.previous_games }} GP</span></span><b class="tabular-nums">{{ decimal(team[teamTab]) }}</b><span class="w-10 text-right text-[10px] tabular-nums" :class="deltaClass(team[`${teamTab}_delta`], teamTab === 'defense')">{{ signed(team[`${teamTab}_delta`]) }}</span>
                    </a>
                    <p v-if="!teams.length" class="py-8 text-center text-sm text-slate-500">Recent team results are not yet available.</p>
                </div>
            </DashboardSection>

            <DashboardSection title="Player Trends" :href="dashboard.links.stats" class="order-3 h-80 md:order-none xl:col-span-3 xl:h-auto">
                <div role="tablist" aria-label="Player scoring form" class="mb-2 grid grid-cols-3 gap-1 rounded bg-slate-50 p-0.5">
                    <button v-for="tab in playerTabs" :id="`player-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :tabindex="playerTab === tab.key ? 0 : -1" :aria-selected="playerTab === tab.key" aria-controls="player-trend-results" class="min-h-8 rounded border px-1 text-[10px] font-medium transition-colors duration-150 motion-reduce:transition-none" :class="playerTab === tab.key ? 'border-blue-200 bg-white text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900'" @click="playerTab = tab.key" @keydown="navigateTabs($event, 'player')">{{ tab.label }}</button>
                </div>
                <p class="mb-2 text-[10px] text-slate-400">Points / game · Last 5 vs previous 5 appearances</p>
                <div id="player-trend-results" role="tabpanel" :aria-labelledby="`player-tab-${playerTab}`" tabindex="0" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-y-contain focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500">
                    <a v-for="(player, index) in players" :key="player.nhl_player_id" :href="player.url" v-bind="externalLinkAttributes(player.url)" class="flex min-h-8 items-center gap-2 py-1 hover:text-blue-600">
                        <span class="w-3 text-[10px] text-slate-500">{{ index + 1 }}</span><img v-if="player.avatar" :src="player.avatar" alt="" class="h-6 w-6 rounded object-contain" loading="lazy" />
                        <div class="min-w-0 flex-1"><h3 class="truncate text-xs font-semibold">{{ player.name }}</h3><p class="text-[10px] text-slate-400">{{ player.position }} · {{ player.team }}</p></div>
                        <div class="text-right text-[11px] font-semibold tabular-nums" :class="deltaClass(player.delta)">{{ player.delta_pct === null ? `${signed(player.delta)} P/GP` : `${signed(player.delta_pct)}%` }}<p class="mt-0.5 text-[9px] font-normal text-slate-400">{{ decimal(player.previous, 2) }} → {{ decimal(player.current, 2) }}</p></div>
                    </a>
                    <p v-if="!players.length" class="py-8 text-center text-sm text-slate-500">No players in this group yet.</p>
                </div>
            </DashboardSection>

            <DashboardSection title="Last Night’s Top Lines" :href="dashboard.links.line_combinations" class="order-2 md:order-none xl:col-span-3 [&_h2]:text-xs [&>div:first-child>a]:text-[10px]">
                <div role="tablist" aria-label="5v5 line metric" class="mb-1.5 grid shrink-0 grid-cols-5 gap-1">
                    <button v-for="tab in lineTabs" :id="`line-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :title="tab.unit" :tabindex="lineTab === tab.key ? 0 : -1" :aria-selected="lineTab === tab.key" aria-controls="line-trend-results" class="min-h-7 rounded-md px-0.5 py-1 text-[10px] font-semibold transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 motion-reduce:transition-none" :class="lineTab === tab.key ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'" @click="lineTab = tab.key" @keydown="navigateTabs($event, 'line')">{{ tab.label }}</button>
                </div>
                <p class="mb-2 text-[9px] text-slate-400">{{ performanceDate }} · 5v5 · {{ lineUnit }}<span v-if="lineTab === 'cf_pct'"> · Min. 5:00 TOI</span></p>
                <div id="line-trend-results" role="tabpanel" :aria-labelledby="`line-tab-${lineTab}`" class="min-h-0 flex-1 overflow-y-auto">
                    <a v-for="(line, index) in lines" :key="`${line.game_id}-${line.unit_id}`" :href="line.url" class="flex items-center gap-2 border-b border-slate-100 py-3 transition-colors duration-150 last:border-b-0 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 motion-reduce:transition-none">
                        <span class="w-3 shrink-0 text-[10px] font-semibold tabular-nums text-slate-600">{{ index + 1 }}</span>
                        <img v-if="line.team_logo" :src="line.team_logo" :alt="line.team" class="h-10 w-10 shrink-0 object-contain" loading="lazy" />
                        <span v-else class="w-10 shrink-0 text-center text-[9px] font-medium text-slate-500">{{ line.team }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h3 :title="line.players.join(' · ')" class="min-w-0 truncate text-[10px] font-semibold leading-snug text-slate-950">{{ line.players.join(' · ') }}</h3>
                                <span :aria-label="`${lineValue(line, lineTab)} ${lineLabel}`" class="shrink-0 whitespace-nowrap rounded-md bg-blue-50 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-blue-600">{{ lineValue(line, lineTab).replace(/%$/, '') }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-1.5 gap-y-1 text-[8px] leading-3 tabular-nums">
                                <span v-for="tab in secondaryLineTabs" :key="tab.key" class="whitespace-nowrap border-r border-slate-200 pr-1.5 last:border-r-0 last:pr-0"><span class="text-slate-500">{{ tab.label }}</span>{{ ' ' }}<b class="font-semibold" :class="tab.key === 'corsi' && line.corsi < 0 ? 'text-red-600' : 'text-slate-950'">{{ lineValue(line, tab.key) }}</b></span>
                            </div>
                        </div>
                    </a>
                    <p v-if="!lines.length" class="py-6 text-center text-[10px] text-slate-500">{{ dashboard.line_combinations?.length ? 'No lines have data for this metric.' : '5v5 line performances will appear when completed shift and play-by-play data are available.' }}</p>
                </div>
            </DashboardSection>

            <DashboardSection title="Around the NHL" :href="dashboard.links.news" external class="order-3 hidden md:order-none md:flex xl:col-span-3">
                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="story in headlinePreview" :key="story.id" :href="story.url" v-bind="externalLinkAttributes(story.url)" class="flex min-h-8 items-center gap-2 py-1 hover:text-blue-600">
                        <img v-if="story.image_url" :src="story.image_url" alt="" class="h-6 w-11 shrink-0 rounded object-cover" loading="lazy" />
                        <h3 class="min-w-0 flex-1 line-clamp-2 text-[10px] font-medium leading-tight" :title="story.title">{{ story.title }}</h3><time :datetime="story.published_at" class="self-start whitespace-nowrap text-[9px] text-slate-400">{{ shortDate(story.published_at) }}</time>
                    </a>
                </div>
                <div v-if="dashboard.headlines.length > headlinePreview.length" class="mt-2 border-t border-slate-100 pt-2">
                    <button type="button" :aria-expanded="moreHeadlines" aria-controls="dashboard-more-headlines" class="flex items-center gap-2 rounded py-1 text-xs font-medium text-blue-600" @click="moreHeadlines = !moreHeadlines">More headlines ({{ dashboard.headlines.length - headlinePreview.length }}) <span aria-hidden="true" class="transition-transform duration-300 ease-out motion-reduce:transition-none" :class="moreHeadlines ? 'rotate-180' : ''">⌄</span></button>
                    <div id="dashboard-more-headlines" :inert="!moreHeadlines" :aria-hidden="!moreHeadlines" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="moreHeadlines ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
                        <div class="min-h-0 overflow-hidden"><a v-for="story in dashboard.headlines.slice(headlinePreview.length)" :key="story.id" :href="story.url" v-bind="externalLinkAttributes(story.url)" class="flex gap-2 border-t border-slate-100 py-2 text-[11px] font-medium hover:text-blue-600"><span class="flex-1">{{ story.title }}</span><time :datetime="story.published_at" class="whitespace-nowrap text-[9px] font-normal text-slate-400">{{ shortDate(story.published_at) }}</time></a></div>
                    </div>
                </div>
                <p v-if="!dashboard.headlines.length" class="py-8 text-center text-sm text-slate-500">More NHL headlines will appear here.</p>
            </DashboardSection>
        </div>
    </main>
</template>
