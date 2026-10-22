<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import DashboardSection from './Section.vue';

const props = defineProps({ dashboard: { type: Object, required: true } });
const teamTab = ref('offense');
const playerTab = ref('up');
const moreHeadlines = ref(false);
const teamTabs = [
    { key: 'offense', label: 'Offense', unit: 'Goals / game' },
    { key: 'defense', label: 'Defense', unit: 'Goals against / game' },
    { key: 'special_teams', label: 'Special Teams', unit: 'PP goals / game' },
];
const playerTabs = [{ key: 'up', label: 'Trending Up' }, { key: 'down', label: 'Trending Down' }, { key: 'breakout', label: 'Breakout' }];
const teams = computed(() => [...props.dashboard.team_trends]
    .filter(team => team[teamTab.value] !== null)
    .sort((a, b) => teamTab.value === 'defense' ? a.defense - b.defense : b[teamTab.value] - a[teamTab.value]).slice(0, 5));
const players = computed(() => [...props.dashboard.player_trends]
    .filter(player => playerTab.value === 'down' ? player.delta < 0 : playerTab.value === 'breakout' ? player.breakout : player.delta > 0)
    .sort((a, b) => playerTab.value === 'down' ? a.delta - b.delta : b.delta - a.delta).slice(0, 5));
const teamUnit = computed(() => teamTabs.find(tab => tab.key === teamTab.value)?.unit);
const headlinePreview = computed(() => props.dashboard.headlines.slice(0, 5));
const initials = name => (name || '?').split(' ').map(part => part[0]).slice(0, 2).join('');
const decimal = (value, places = 1) => Number.isFinite(value) ? value.toFixed(places) : '—';
const signed = value => Number.isFinite(value) ? `${value > 0 ? '+' : ''}${value.toFixed(1)}` : '—';
const goaliePercentage = value => Number.isFinite(value) ? value.toFixed(3).replace(/^0/, '') : '—';
const shortDate = value => value ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'America/Toronto' }).format(new Date(value.length === 10 ? `${value}T12:00:00Z` : value)) : '';
const performanceDate = computed(() => shortDate(props.dashboard.performance_date));
const injuryLabel = row => row.availability === 'out' ? 'Out' : row.availability === 'questionable' ? 'Questionable' : 'Reported';
const deltaClass = (value, defense = false) => !Number.isFinite(value) || value === 0 ? 'text-slate-500' : (defense ? value < 0 : value > 0) ? 'text-emerald-600' : 'text-rose-600';

function navigateTabs(event, group) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const tabs = group === 'team' ? teamTabs : playerTabs;
    const active = group === 'team' ? teamTab : playerTab;
    const index = tabs.findIndex(tab => tab.key === active.value);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
        : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    active.value = tabs[next].key;
    event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[next]?.focus();
}
</script>

<template>
    <Head title="Home · DynastyIQ" />
    <main class="mx-auto grid w-full max-w-[1800px] gap-2 bg-slate-50 px-3 py-2 text-slate-900 sm:px-5 lg:px-7" :class="!dashboard.search && !moreHeadlines ? 'xl:h-[calc(100dvh-196px)] xl:min-h-[550px] xl:grid-rows-[minmax(132px,0.9fr)_184px_minmax(180px,1.3fr)_auto]' : ''" aria-label="NHL dashboard">
        <h1 class="sr-only">DynastyIQ hockey dashboard</h1>

        <DashboardSection v-if="dashboard.search" :title="`Search results for “${dashboard.query}”`">
            <div class="grid gap-5 md:grid-cols-2">
                <div><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Players &amp; teams</h3>
                    <a v-for="player in dashboard.search.players" :key="player.url" :href="player.url" class="flex items-center gap-3 rounded p-2 hover:bg-slate-50">
                        <img v-if="player.avatar" :src="player.avatar" alt="" class="h-10 w-10 object-contain" />
                        <span class="text-sm font-semibold">{{ player.name }} <span class="font-normal text-slate-500">{{ player.position }} · {{ player.team }}</span></span>
                    </a>
                    <p v-if="!dashboard.search.players.length" class="text-sm text-slate-500">No matching players.</p>
                </div>
                <div><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Headlines</h3>
                    <a v-for="story in dashboard.search.stories" :key="story.id" :href="story.url" class="block rounded py-2 text-sm font-medium hover:text-blue-600">{{ story.title }}</a>
                    <p v-if="!dashboard.search.stories.length" class="text-sm text-slate-500">No matching headlines in the current feed.</p>
                </div>
            </div>
        </DashboardSection>

        <section v-if="dashboard.featured.length" class="grid min-h-0 gap-3 md:grid-cols-3 xl:grid-cols-[1.2fr_1fr_1fr]" aria-label="Featured NHL stories">
            <article v-for="(story, index) in dashboard.featured" :key="story.id" class="relative isolate flex h-64 overflow-hidden rounded-lg bg-slate-900 text-white lg:h-[260px] xl:h-full xl:min-h-0">
                <img v-if="story.image_url" :src="story.image_url" alt="" class="absolute inset-0 -z-20 h-full w-full object-cover" :loading="index === 0 ? 'eager' : 'lazy'" />
                <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black via-black/50 to-black/5" />
                <div class="flex min-h-0 w-full flex-col items-start p-3">
                    <span class="rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide" :class="index === 0 ? 'bg-blue-600' : index === 1 ? 'bg-violet-700' : 'bg-emerald-600'">{{ story.category }}</span>
                    <div class="mt-auto pt-1">
                        <h2 class="line-clamp-2 text-xl font-extrabold leading-tight tracking-tight xl:text-lg"><a :href="story.url" :title="story.title" class="rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">{{ story.title }}</a></h2>
                        <p v-if="story.summary" class="mt-1 line-clamp-1 text-xs leading-snug text-white/90 [@media(min-width:1280px)_and_(max-height:800px)]:hidden">{{ story.summary }}</p>
                        <a :href="story.url" class="mt-2 inline-flex min-h-7 items-center gap-3 rounded bg-blue-600 px-3 text-xs font-semibold transition-colors duration-150 hover:bg-blue-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white motion-reduce:transition-none">Read story <span aria-hidden="true">→</span></a>
                    </div>
                    <span class="sr-only">Source: NHL.com, {{ shortDate(story.published_at) }}</span>
                </div>
            </article>
        </section>
        <div v-else class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-600" role="status">NHL stories are currently unavailable. <a :href="dashboard.links.news" class="font-medium text-blue-600">Read the latest on NHL.com ›</a></div>

        <div class="grid min-h-0 gap-3 xl:grid-cols-12">
            <DashboardSection title="Top Performers" :href="dashboard.links.stats" class="xl:col-span-5">
                <div class="mb-1 flex shrink-0 items-center justify-between text-[10px] text-slate-500"><span>{{ performanceDate ? `Completed games · ${performanceDate}` : 'Latest completed game day' }}</span><span>Standouts first</span></div>
                <div v-if="dashboard.performers.length" class="grid min-h-0 grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-4">
                    <a v-for="player in dashboard.performers" :key="player.nhl_player_id" :href="player.url" class="flex min-w-0 flex-col rounded-md border border-slate-200/80 bg-gradient-to-b from-white to-slate-50 p-1.5 transition-colors duration-150 hover:border-blue-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 motion-reduce:transition-none">
                        <div class="relative flex h-7 shrink-0 items-end justify-center overflow-hidden">
                            <img v-if="player.avatar" :src="player.avatar" alt="" class="max-h-full w-full object-contain object-bottom" loading="lazy" />
                            <span v-else class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500">{{ initials(player.name) }}</span>
                            <img v-if="player.team_logo" :src="player.team_logo" alt="" class="absolute right-0 top-0 h-5 w-5 object-contain" loading="lazy" />
                        </div>
                        <h3 class="mt-1 truncate text-[11px] font-bold leading-tight" :title="player.name">{{ player.name }}</h3>
                        <p class="text-[9px] leading-tight text-slate-500">{{ player.position }} · {{ player.team }}</p>
                        <div v-if="player.goalie" class="mt-1 flex justify-between gap-1 text-[10px]"><span><b class="text-xs">{{ player.saves }}</b> SV</span><span><b>{{ player.goals_against }}</b> GA</span><b>{{ goaliePercentage(player.save_percentage) }}</b></div>
                        <div v-else class="mt-1 flex justify-between gap-1 text-[10px]"><span><b class="text-xs">{{ player.goals }}</b> G</span><span><b class="text-xs">{{ player.assists }}</b> A</span><span><b class="text-xs">{{ player.points }}</b> PTS</span></div>
                        <span class="mt-1 block max-w-full self-start truncate rounded bg-blue-50 px-1.5 py-0.5 text-[9px] font-medium leading-3 text-blue-700">{{ player.badge }}</span>
                    </a>
                </div>
                <p v-else class="flex flex-1 items-center justify-center py-4 text-center text-xs text-slate-500">Player performances will appear when completed boxscores are available.</p>
            </DashboardSection>

            <DashboardSection title="Notable Signings & Moves" :href="dashboard.links.transactions" class="xl:col-span-4">
                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="move in dashboard.transactions" :key="move.id" :href="move.url" class="flex min-h-8 items-center gap-2 py-1 hover:text-blue-600">
                        <img v-if="move.avatar" :src="move.avatar" alt="" class="h-6 w-6 shrink-0 rounded bg-slate-50 object-contain" loading="lazy" />
                        <span v-else class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-slate-50 text-[10px] font-semibold text-slate-500">{{ initials(move.name) }}</span>
                        <div class="min-w-0 flex-1"><h3 class="truncate text-[11px] font-semibold leading-tight" :title="move.description || `${move.name} · ${move.type}`">{{ move.description || `${move.name} · ${move.type}` }}</h3><p class="truncate text-[9px] leading-tight text-slate-500">{{ move.name }}<template v-if="move.team"> · {{ move.team }}</template></p></div>
                        <time :datetime="move.date" class="self-start whitespace-nowrap text-[9px] text-slate-400">{{ shortDate(move.date) }}</time>
                    </a>
                </div>
                <p v-if="!dashboard.transactions.length" class="py-4 text-center text-xs text-slate-500">No recent signings or moves are available.</p>
            </DashboardSection>

            <DashboardSection title="Injury Watch" :href="dashboard.links.injuries" class="xl:col-span-3">
                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="player in dashboard.injuries" :key="player.id" :href="player.url" class="flex min-h-8 items-center gap-2 py-0.5 hover:text-blue-600">
                        <img v-if="player.avatar" :src="player.avatar" alt="" class="h-6 w-6 shrink-0 rounded bg-slate-50 object-contain" loading="lazy" />
                        <span v-else class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-slate-50 text-[10px] text-slate-500">{{ initials(player.name) }}</span>
                        <div class="min-w-0 flex-1"><h3 class="truncate text-[11px] font-semibold leading-tight">{{ player.name }}</h3><p class="text-[9px] leading-tight text-slate-500">{{ player.position }} · {{ player.team }}</p></div>
                        <div class="flex min-w-0 max-w-[46%] flex-col items-end text-right"><span class="inline-block rounded-full px-1.5 text-[9px] font-medium leading-4" :class="player.availability === 'out' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700'">{{ injuryLabel(player) }}</span><p class="max-w-full truncate text-[9px] leading-tight text-slate-500" :title="player.status || player.body_part">{{ player.body_part || player.status || 'Details pending' }}</p></div>
                    </a>
                </div>
                <p v-if="!dashboard.injuries.length" class="py-4 text-center text-xs text-slate-500">No current injury reports.</p>
            </DashboardSection>
        </div>

        <div class="grid min-h-0 gap-3 md:grid-cols-2 xl:grid-cols-12">
            <DashboardSection title="Team Trends" :href="dashboard.links.stats" class="xl:col-span-3">
                <div role="tablist" aria-label="Team trend metric" class="mb-2 grid grid-cols-3 gap-1 rounded bg-slate-50 p-0.5">
                    <button v-for="tab in teamTabs" :id="`team-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :tabindex="teamTab === tab.key ? 0 : -1" :aria-selected="teamTab === tab.key" aria-controls="team-trend-results" class="min-h-8 rounded border px-1 text-[10px] font-medium transition-colors duration-150 motion-reduce:transition-none" :class="teamTab === tab.key ? 'border-blue-200 bg-white text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900'" @click="teamTab = tab.key" @keydown="navigateTabs($event, 'team')">{{ tab.label }}</button>
                </div>
                <p class="mb-2 text-[10px] text-slate-400">Last 10 games vs previous 10 · {{ teamUnit }}</p>
                <div id="team-trend-results" role="tabpanel" :aria-labelledby="`team-tab-${teamTab}`" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="(team, index) in teams" :key="team.team" :href="team.url" class="flex min-h-8 items-center gap-2 py-1 text-xs hover:text-blue-600">
                        <span class="w-3 text-[10px] text-slate-500">{{ index + 1 }}</span><img v-if="team.logo" :src="team.logo" alt="" class="h-5 w-5 object-contain" loading="lazy" />
                        <span class="min-w-0 flex-1 truncate font-semibold">{{ team.name || team.team }}</span><b class="tabular-nums">{{ decimal(team[teamTab]) }}</b><span class="w-10 text-right text-[10px] tabular-nums" :class="deltaClass(team[`${teamTab}_delta`], teamTab === 'defense')">{{ signed(team[`${teamTab}_delta`]) }}</span>
                    </a>
                    <p v-if="!teams.length" class="py-8 text-center text-sm text-slate-500">Recent team results are not yet available.</p>
                </div>
            </DashboardSection>

            <DashboardSection title="Player Heat Check" :href="dashboard.links.stats" class="xl:col-span-3">
                <div role="tablist" aria-label="Player scoring form" class="mb-2 grid grid-cols-3 gap-1 rounded bg-slate-50 p-0.5">
                    <button v-for="tab in playerTabs" :id="`player-tab-${tab.key}`" :key="tab.key" type="button" role="tab" :tabindex="playerTab === tab.key ? 0 : -1" :aria-selected="playerTab === tab.key" aria-controls="player-trend-results" class="min-h-8 rounded border px-1 text-[10px] font-medium transition-colors duration-150 motion-reduce:transition-none" :class="playerTab === tab.key ? 'border-blue-200 bg-white text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-900'" @click="playerTab = tab.key" @keydown="navigateTabs($event, 'player')">{{ tab.label }}</button>
                </div>
                <p class="mb-2 text-[10px] text-slate-400">Points / game · Last 5 vs previous 5 appearances</p>
                <div id="player-trend-results" role="tabpanel" :aria-labelledby="`player-tab-${playerTab}`" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="(player, index) in players" :key="player.nhl_player_id" :href="player.url" class="flex min-h-8 items-center gap-2 py-1 hover:text-blue-600">
                        <span class="w-3 text-[10px] text-slate-500">{{ index + 1 }}</span><img v-if="player.avatar" :src="player.avatar" alt="" class="h-6 w-6 rounded object-contain" loading="lazy" />
                        <div class="min-w-0 flex-1"><h3 class="truncate text-xs font-semibold">{{ player.name }}</h3><p class="text-[10px] text-slate-400">{{ player.position }} · {{ player.team }}</p></div>
                        <div class="text-right text-[11px] font-semibold tabular-nums" :class="deltaClass(player.delta)">{{ player.delta_pct === null ? `${signed(player.delta)} P/GP` : `${signed(player.delta_pct)}%` }}<p class="mt-0.5 text-[9px] font-normal text-slate-400">{{ decimal(player.previous, 2) }} → {{ decimal(player.current, 2) }}</p></div>
                    </a>
                    <p v-if="!players.length" class="py-8 text-center text-sm text-slate-500">No players in this group yet.</p>
                </div>
            </DashboardSection>

            <DashboardSection title="Must-Watch Video & Analysis" :href="dashboard.links.video" class="xl:col-span-3">
                <template v-if="dashboard.videos.length">
                    <a :href="dashboard.videos[0].url" class="group relative block min-h-16 overflow-hidden rounded bg-slate-900 text-white xl:flex-1">
                        <img v-if="dashboard.videos[0].image_url" :src="dashboard.videos[0].image_url" alt="" class="aspect-video w-full object-cover xl:absolute xl:inset-0 xl:h-full" loading="lazy" />
                        <div v-else class="aspect-video xl:hidden" />
                        <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/10 to-transparent" />
                        <span aria-hidden="true" class="absolute left-1/2 top-1/2 flex h-10 w-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-lg text-slate-900">▶</span>
                        <h3 class="absolute bottom-0 line-clamp-2 w-full p-3 text-xs font-bold">{{ dashboard.videos[0].title }}</h3>
                    </a>
                    <div class="mt-2 grid shrink-0 grid-cols-3 gap-2"><a v-for="video in dashboard.videos.slice(1)" :key="video.id" :href="video.url" class="min-w-0 hover:text-blue-600"><img v-if="video.image_url" :src="video.image_url" alt="" class="aspect-video w-full rounded object-cover xl:h-9 xl:aspect-auto" loading="lazy" /><h3 class="mt-1 line-clamp-2 text-[10px] font-semibold leading-tight">{{ video.title }}</h3></a></div>
                </template>
                <p v-else class="py-10 text-center text-sm text-slate-500">No videos are available right now. <a :href="dashboard.links.video" class="text-blue-600">Watch on NHL.com ›</a></p>
            </DashboardSection>

            <DashboardSection title="Around the NHL" :href="dashboard.links.news" class="xl:col-span-3">
                <div class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <a v-for="story in headlinePreview" :key="story.id" :href="story.url" class="flex min-h-8 items-center gap-2 py-1 hover:text-blue-600">
                        <img v-if="story.image_url" :src="story.image_url" alt="" class="h-6 w-11 shrink-0 rounded object-cover" loading="lazy" />
                        <h3 class="min-w-0 flex-1 line-clamp-2 text-[10px] font-medium leading-tight" :title="story.title">{{ story.title }}</h3><time :datetime="story.published_at" class="self-start whitespace-nowrap text-[9px] text-slate-400">{{ shortDate(story.published_at) }}</time>
                    </a>
                </div>
                <div v-if="dashboard.headlines.length > headlinePreview.length" class="mt-2 border-t border-slate-100 pt-2">
                    <button type="button" :aria-expanded="moreHeadlines" aria-controls="dashboard-more-headlines" class="flex items-center gap-2 rounded py-1 text-xs font-medium text-blue-600" @click="moreHeadlines = !moreHeadlines">More headlines ({{ dashboard.headlines.length - headlinePreview.length }}) <span aria-hidden="true" class="transition-transform duration-300 ease-out motion-reduce:transition-none" :class="moreHeadlines ? 'rotate-180' : ''">⌄</span></button>
                    <div id="dashboard-more-headlines" :inert="!moreHeadlines" :aria-hidden="!moreHeadlines" class="grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none" :class="moreHeadlines ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'">
                        <div class="min-h-0 overflow-hidden"><a v-for="story in dashboard.headlines.slice(headlinePreview.length)" :key="story.id" :href="story.url" class="flex gap-2 border-t border-slate-100 py-2 text-[11px] font-medium hover:text-blue-600"><span class="flex-1">{{ story.title }}</span><time :datetime="story.published_at" class="whitespace-nowrap text-[9px] font-normal text-slate-400">{{ shortDate(story.published_at) }}</time></a></div>
                    </div>
                </div>
                <p v-if="!dashboard.headlines.length" class="py-8 text-center text-sm text-slate-500">More NHL headlines will appear here.</p>
            </DashboardSection>
        </div>
        <p class="text-right text-[10px] text-slate-400">Stories &amp; video: NHL.com<template v-if="dashboard.news_updated_at"> · Updated {{ shortDate(dashboard.news_updated_at) }}</template><template v-if="dashboard.news_unavailable"> · Showing the last available feed</template></p>
    </main>
</template>
