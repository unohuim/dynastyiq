<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import GameSectionState from './GameSectionState.vue';
import { applyGamePanel } from './game-panels';
const LineupTeam = defineAsyncComponent(() => import('./LineupTeam.vue'));
const GameCard = defineAsyncComponent(() => import('./GameCard.vue'));
import { useLiveGameFeed } from '../../layouts/live-game-feed';
import { scoreboardStatus } from '../../layouts/scoreboard';

const props = defineProps({ game: { type: Object, required: true }, canManageLineups: { type: Boolean, default: false }, detailsUrl: { type: String, default: null } });
const game = ref(props.game);
watch(() => props.game, value => {
  if (game.value.mode !== 'preview' && value.mode === 'preview') return;
  game.value = { ...game.value, ...value, away: { ...game.value.away, ...value.away }, home: { ...game.value.home, ...value.home } };
});
const busy = ref(false);
const mode = computed(() => game.value.mode ?? 'preview');
const sides = ['away', 'home'];
const title = computed(() => `${game.value.away.team_abbrev ?? 'Away'} at ${game.value.home.team_abbrev ?? 'Home'} · ${mode.value === 'recap' ? 'Recap' : mode.value === 'live' ? 'Live' : 'Preview'}`);
const status = computed(() => mode.value === 'live' ? scoreboardStatus(game.value) : game.value.status);
const sections = computed(() => mode.value === 'preview'
  ? [['matchup', 'Matchup'], ['lineups', 'Lineups'], ['meetings', 'Head-to-head']]
  : [['summary', 'Game stats'], ['goals', 'Scoring'], ['boxscore', 'Boxscore']]);
const panels = reactive({});
const panelRequests = new Map();
const panelTimers = new Map();
const requestTimeouts = new Set();
let panelGeneration = 0;
const panelState = (name) => panels[name] ?? { status: props.detailsUrl ? 'loading' : 'ready' };
const panelReady = (name) => panelState(name).status === 'ready';
function cancelPanels() {
  panelGeneration++;
  panelRequests.forEach(controller => controller.abort());
  panelRequests.clear();
  panelTimers.forEach(window.clearTimeout);
  panelTimers.clear();
  requestTimeouts.forEach(window.clearTimeout);
  requestTimeouts.clear();
}
async function loadPanel(name, attempt = 0) {
  if (!props.detailsUrl || disposed) return;
  const generation = panelGeneration;
  const requestMode = mode.value;
  const requestId = game.value.nhl_game_id;
  panelRequests.get(name)?.abort();
  window.clearTimeout(panelTimers.get(name));
  panels[name] = { status: panelReady(name) ? 'ready' : 'loading' };
  const controller = new AbortController();
  panelRequests.set(name, controller);
  const timeout = window.setTimeout(() => controller.abort(), 10000);
  requestTimeouts.add(timeout);
  try {
    const url = new URL(props.detailsUrl, window.location.origin);
    url.searchParams.set('section', name);
    const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
    if (disposed || generation !== panelGeneration || panelRequests.get(name) !== controller) return;
    if (response.status === 202) {
      if (attempt >= 59) throw new Error('This section is taking longer than expected.');
      panelTimers.set(name, window.setTimeout(() => { panelTimers.delete(name); void loadPanel(name, attempt + 1); }, 2000));
      return;
    }
    if (!response.ok) throw new Error('Unable to load this section.');
    const payload = await response.json();
    if (disposed || generation !== panelGeneration || requestId !== game.value.nhl_game_id
      || mode.value !== requestMode || panelRequests.get(name) !== controller) return;
    if (payload.mode !== mode.value || payload.section !== name || Number(payload.nhl_game_id) !== Number(requestId))
      throw new Error('This section changed while loading. Please retry.');
    game.value = applyGamePanel(game.value, name, payload.data);
    panels[name] = { status: 'ready' };
  } catch (error) {
    if (!disposed && generation === panelGeneration && panelRequests.get(name) === controller) {
      panels[name] = { status: 'error', message: error?.name === 'AbortError' ? 'This section timed out.' : error.message };
    }
  } finally {
    window.clearTimeout(timeout);
    requestTimeouts.delete(timeout);
    if (panelRequests.get(name) === controller) panelRequests.delete(name);
  }
}
function loadPanels() {
  cancelPanels();
  for (const [name] of sections.value) void loadPanel(name);
}
const number = (value, digits = 2) => value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toFixed(digits);
const probability = (side) => game.value.prediction?.probabilities?.[side] == null ? '—' : `${number(game.value.prediction.probabilities[side] * 100, 1)}%`;
const record = (form, prefix = '') => form && Number(form[`${prefix}gp`]) > 0 ? `${form[`${prefix}wins`]}–${form[`${prefix}losses`]}–${form[`${prefix}otl`]}` : '—';
const dateTime = computed(() => game.value.start_time_utc ? new Intl.DateTimeFormat(undefined, {
  weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
}).format(new Date(game.value.start_time_utc)) : game.value.game_date ?? '');
const shortDate = (date) => new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(`${date}T12:00:00`));
const playerStat = (player) => player.position === 'G' ? `${player.saves ?? '—'} SV · ${player.goals_against ?? '—'} GA` : `${player.goals ?? '—'} G · ${player.assists ?? '—'} A`;
let timer;
let request;
let disposed = false;
function scheduleRefresh() {
  window.clearTimeout(timer);
  if (disposed || mode.value !== 'preview' || ['PPD', 'CNCL'].includes(game.value.game_state)) return;
  timer = window.setTimeout(() => {
    if (document.visibilityState === 'hidden') { scheduleRefresh(); return; }
    refresh();
  }, mode.value === 'live' ? 15000 : 60000);
}
function refresh() {
  if (disposed || busy.value) return;
  busy.value = true;
  router.reload({ only: ['game'], preserveScroll: true,
    onCancelToken: (token) => { request = token; },
    onFinish: () => { busy.value = false; request = null; scheduleRefresh(); loadPanels(); },
  });
}
const { warning: liveWarning } = useLiveGameFeed({
  gameId: () => Number(game.value.nhl_game_id), full: true, active: () => mode.value !== 'recap',
  update(snapshot, detail) {
    if (snapshot.game.mode === 'preview') {
      game.value = { ...game.value, game_state: snapshot.game.game_state, status: snapshot.game.status };
    } else if (detail) {
      game.value = snapshot.game;
      cancelPanels();
      for (const [name] of sections.value) panels[name] = { status: 'ready' };
    }
    else {
      game.value = { ...game.value, ...snapshot.game,
        away: { ...game.value.away, ...snapshot.game.away }, home: { ...game.value.home, ...snapshot.game.home } };
    }
  },
});
watch(mode, () => { scheduleRefresh(); loadPanels(); }, { flush: 'sync' });
watch(() => props.game.nhl_game_id, () => {
  for (const name of Object.keys(panels)) delete panels[name];
  loadPanels();
});
onMounted(() => { scheduleRefresh(); loadPanels(); });
onBeforeUnmount(() => { disposed = true; cancelPanels(); window.clearTimeout(timer); request?.cancel(); });
</script>

<template>
  <Head :title="title" />
  <main class="min-h-screen bg-[#101923] text-[#E7EEF6]">
    <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 sm:py-7 lg:px-8">
      <div class="mb-4 flex items-center justify-between gap-3 text-xs">
        <Link :href="game.game_date ? `/games?date=${game.game_date}` : '/games'" class="rounded text-[#91A8BA] transition-colors duration-150 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none">‹ All games</Link>
        <span role="status" class="text-[#91A8BA]">{{ busy ? 'Updating…' : dateTime }}</span>
      </div>
      <p v-if="liveWarning && mode === 'live'" role="status" class="mb-3 text-xs text-amber-300">{{ liveWarning }}</p>
      <p v-if="game.live_data_unavailable" role="status" class="rounded-xl border border-[#29404F] bg-[#172534] p-6 text-sm text-[#B6C7D5]">Live NHL data is temporarily unavailable. This page will retry automatically.</p>
      <template v-else>
        <header class="overflow-hidden rounded-2xl border border-[#29404F] bg-[#172534]">
          <div class="flex items-center justify-between gap-3 border-b border-[#29404F] px-4 py-3 sm:px-6">
            <h1 class="text-xs font-semibold uppercase tracking-[0.16em] text-[#A6B9C9]">{{ mode === 'recap' ? 'Game recap' : mode === 'live' ? 'Live game' : 'Game preview' }}</h1>
            <span class="font-mono text-xs font-semibold" :class="mode === 'live' ? 'text-emerald-400' : 'text-[#B6C7D5]'">{{ status }}</span>
          </div>
          <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 px-4 py-6 sm:gap-8 sm:px-10 sm:py-8">
            <template v-for="(side, index) in sides" :key="side">
              <div v-if="index === 1" class="text-center">
                <p class="font-mono text-xs uppercase tracking-widest text-[#7890A4]">{{ mode === 'preview' ? 'at' : 'Final' === status ? 'Final' : 'vs' }}</p>
                <p v-if="mode === 'preview' && game.prediction?.score" class="mt-2 whitespace-nowrap font-mono text-sm font-semibold text-sky-300">{{ number(game.prediction.score.away) }} – {{ number(game.prediction.score.home) }}</p>
                <p v-if="mode === 'preview' && game.prediction?.score" class="mt-1 text-[10px] text-[#91A8BA]">Projected score</p>
                <p v-if="mode === 'preview' && game.prediction?.total_goals != null" class="mt-1 text-[10px] text-[#91A8BA]">Total goals {{ number(game.prediction.total_goals) }}</p>
              </div>
              <div class="min-w-0 text-center">
                <img v-if="game[side].team_logo" :src="game[side].team_logo" alt="" class="mx-auto mb-2 size-16 object-contain sm:size-24" />
                <p class="text-lg font-bold sm:text-2xl">{{ game[side].team_abbrev ?? '—' }}</p>
                <p class="mt-1 truncate text-xs text-[#A6B9C9] sm:text-sm">{{ game[side].team_name }}</p>
                <p v-if="mode !== 'preview'" class="mt-2 font-mono text-4xl font-bold tabular-nums sm:text-5xl">{{ game[side].score ?? '—' }}</p>
                <p v-else class="mt-2 font-mono text-2xl font-semibold tabular-nums text-sky-300">{{ probability(side) }}</p>
                <p class="mt-1 text-[10px] uppercase tracking-wider text-[#91A8BA]">{{ mode === 'preview' ? 'Win probability' : `${game[side].sog ?? '—'} shots on goal` }}</p>
                <p v-if="game[side].form?.gp" class="mt-3 text-xs text-[#B6C7D5]">{{ record(game[side].form) }} · {{ game[side].form.gp }} GP before game</p>
              </div>
            </template>
          </div>
          <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-t border-[#29404F] px-4 py-3 text-xs text-[#91A8BA]">
            <span>{{ dateTime }}</span><span v-if="game.venue">· {{ game.venue }}</span>
            <span v-if="mode === 'preview' && !game.prediction">· Prediction not yet available</span>
          </div>
        </header>
        <nav aria-label="Game sections" class="my-5 flex gap-5 overflow-x-auto border-b border-[#29404F] text-xs font-semibold sm:text-sm">
          <a v-for="[id, label] in sections" :key="id" :href="`#${id}`" class="shrink-0 border-b-2 border-transparent pb-3 text-[#A6B9C9] transition-colors duration-150 hover:border-sky-400 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none">{{ label }}</a>
        </nav>
        <template v-if="mode === 'preview'">
          <GameSectionState name="matchup" title="The matchup" :state="panelState('matchup')" @retry="loadPanel('matchup')" />
          <section v-if="panelReady('matchup')" id="matchup" class="scroll-mt-5 rounded-xl border border-[#29404F] bg-[#172534] p-4 sm:p-6">
            <h2 class="text-sm font-semibold">The matchup</h2>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:gap-8">
              <div v-for="side in sides" :key="side">
                <p class="mb-3 text-xs font-semibold text-sky-300">{{ game[side].team_abbrev }}</p>
                <dl class="space-y-2 text-xs"><div class="flex justify-between gap-2"><dt class="text-[#91A8BA]">Last 10</dt><dd class="font-mono">{{ record(game[side].form, 'l10_') }}</dd></div><div class="flex justify-between gap-2"><dt class="text-[#91A8BA]">GF/GP</dt><dd class="font-mono">{{ number(game[side].form?.gf_gp) }}</dd></div><div class="flex justify-between gap-2"><dt class="text-[#91A8BA]">GA/GP</dt><dd class="font-mono">{{ number(game[side].form?.ga_gp) }}</dd></div></dl>
                <div class="mt-4 border-t border-[#29404F] pt-3"><p class="text-[10px] uppercase tracking-wider text-[#91A8BA]">Starting goalie</p><p class="mt-1 text-sm font-semibold">{{ game[side].starting_goalie?.name ?? 'Not reported' }}</p><p v-if="game[side].starting_goalie" class="mt-1 text-xs capitalize text-[#91A8BA]">{{ game[side].starting_goalie.status ?? 'Projected' }}</p></div>
              </div>
            </div>
          </section>
          <GameSectionState name="lineups" title="Lineups" :state="panelState('lineups')" @retry="loadPanel('lineups')" />
          <section v-if="panelReady('lineups')" id="lineups" class="mt-5 scroll-mt-5">
            <h2 class="mb-3 text-sm font-semibold">Lineups</h2>
            <details v-if="canManageLineups" class="mb-4 rounded-lg border border-[#29404F] p-3"><summary class="cursor-pointer text-xs font-semibold text-sky-300">Manage lineups and starters</summary><GameCard :game="game" :can-manage-lineups="true" class="mt-3" @lineup-submitted="refresh" @goalie-selected="refresh" /></details>
            <div class="grid gap-4 xl:grid-cols-2"><LineupTeam :team="game.away" side="Away" /><LineupTeam :team="game.home" side="Home" /></div>
          </section>
          <GameSectionState name="meetings" title="Last five meetings" :state="panelState('meetings')" @retry="loadPanel('meetings')" />
          <section v-if="panelReady('meetings')" id="meetings" class="mt-5 scroll-mt-5 rounded-xl border border-[#29404F] bg-[#172534] p-4 sm:p-6">
            <h2 class="mb-3 text-sm font-semibold">Last five meetings</h2>
            <div v-if="game.head_to_head?.length" class="divide-y divide-[#29404F]">
              <Link v-for="meeting in game.head_to_head" :key="meeting.id" :href="`/games/${meeting.id}`" prefetch class="flex items-center justify-between gap-3 rounded py-3 text-xs transition-colors duration-150 hover:text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none"><span class="text-[#91A8BA]">{{ shortDate(meeting.date) }}</span><span>{{ meeting.away }} <b class="font-mono">{{ meeting.away_score }}–{{ meeting.home_score }}</b> {{ meeting.home }}</span><span aria-hidden="true">›</span></Link>
            </div><p v-else class="text-xs text-[#91A8BA]">No previous completed meetings available.</p>
          </section>
        </template>
        <template v-else>
          <GameSectionState name="summary" title="Game stats" :state="panelState('summary')" @retry="loadPanel('summary')" />
          <section v-if="panelReady('summary')" id="summary" class="scroll-mt-5 rounded-xl border border-[#29404F] bg-[#172534] p-4 sm:p-6">
            <h2 class="text-lg font-semibold">{{ game.recap ?? (mode === 'live' ? 'Game in progress' : 'Final result') }}</h2>
            <div class="mt-4 grid grid-cols-[1fr_2fr_1fr] gap-3 border-b border-[#29404F] pb-2 text-xs font-semibold text-[#91A8BA]"><span>{{ game.away.team_abbrev }}</span><span class="text-center">Game stats</span><span class="text-right">{{ game.home.team_abbrev }}</span></div>
            <div v-for="row in game.comparison" :key="row.label" class="grid grid-cols-[1fr_2fr_1fr] gap-3 border-b border-[#29404F]/60 py-3 text-sm"><span class="font-mono font-semibold">{{ row.away ?? '—' }}</span><span class="text-center text-xs text-[#A6B9C9]">{{ row.label }}</span><span class="text-right font-mono font-semibold">{{ row.home ?? '—' }}</span></div>
            <div v-if="game.away.pp_opportunities != null || game.home.pp_opportunities != null" class="grid grid-cols-[1fr_2fr_1fr] gap-3 pt-3 text-sm"><span class="font-mono">{{ game.away.pp_goals ?? '—' }}/{{ game.away.pp_opportunities ?? '—' }}</span><span class="text-center text-xs text-[#A6B9C9]">Power play</span><span class="text-right font-mono">{{ game.home.pp_goals ?? '—' }}/{{ game.home.pp_opportunities ?? '—' }}</span></div>
          </section>
          <GameSectionState name="goals" title="Scoring summary" :state="panelState('goals')" @retry="loadPanel('goals')" />
          <section v-if="panelReady('goals')" id="goals" class="mt-5 scroll-mt-5 rounded-xl border border-[#29404F] bg-[#172534] p-4 sm:p-6">
            <h2 class="mb-3 text-sm font-semibold">Scoring summary</h2>
            <ol v-if="game.events?.length" class="divide-y divide-[#29404F]"><li v-for="event in game.events" :key="event.id" class="flex items-start gap-4 py-3"><div class="w-16 shrink-0 font-mono text-xs text-[#91A8BA]"><p>{{ event.period > 3 ? 'OT' : `P${event.period}` }}</p><p class="mt-1">{{ event.time ?? '—' }}</p></div><div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ event.scorer }}</p><p class="mt-1 text-xs text-[#91A8BA]">{{ event.assists?.length ? event.assists.join(', ') : 'Unassisted' }}</p></div><span class="font-mono text-sm font-semibold">{{ event.away_score ?? '—' }}–{{ event.home_score ?? '—' }}</span></li></ol>
            <p v-else class="text-xs text-[#91A8BA]">{{ game.events_available === false ? 'Scoring details are temporarily unavailable.' : 'No scoring events available yet.' }}</p>
          </section>
          <GameSectionState name="boxscore" title="Boxscore" :state="panelState('boxscore')" @retry="loadPanel('boxscore')" />
          <section v-if="panelReady('boxscore')" id="boxscore" class="mt-5 scroll-mt-5"><h2 class="mb-3 text-sm font-semibold">Boxscore</h2><div class="grid items-start gap-4 xl:grid-cols-2"><article v-for="side in sides" :key="side" class="overflow-hidden rounded-xl border border-[#29404F] bg-[#172534]"><h3 class="border-b border-[#29404F] px-4 py-3 text-sm font-semibold">{{ game[side].team_abbrev }}</h3><div v-if="game[side].boxscore?.length" class="overflow-x-auto"><table class="w-full text-left text-xs"><thead class="bg-[#111E2B] text-[10px] uppercase text-[#91A8BA]"><tr><th class="px-4 py-2 font-medium">Player</th><th class="px-2 py-2 font-medium">G / A</th><th class="px-2 py-2 font-medium">SOG</th><th class="px-4 py-2 text-right font-medium">TOI</th></tr></thead><tbody class="divide-y divide-[#29404F]/70"><tr v-for="player in game[side].boxscore" :key="player.id"><td class="whitespace-nowrap px-4 py-2.5"><span class="font-medium">{{ player.name ?? '—' }}</span><span class="ml-1 text-[10px] text-[#7890A4]">{{ player.position }}</span></td><td class="whitespace-nowrap px-2 py-2.5 font-mono">{{ playerStat(player) }}</td><td class="px-2 py-2.5 font-mono">{{ player.position === 'G' ? '—' : player.sog ?? '—' }}</td><td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-[#A6B9C9]">{{ player.toi ?? '—' }}</td></tr></tbody></table></div><p v-else class="px-4 py-5 text-xs text-[#91A8BA]">Boxscore details have not been imported yet.</p></article></div></section>
        </template>
      </template>
    </div>
  </main>
</template>
