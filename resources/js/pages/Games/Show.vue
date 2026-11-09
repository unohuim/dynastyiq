<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import GameSectionState from './GameSectionState.vue';
import GameLiveDetails from './GameLiveDetails.vue';
import { applyGamePanel } from './game-panels';
const LineupTeam = defineAsyncComponent(() => import('./LineupTeam.vue'));
const GameCard = defineAsyncComponent(() => import('./GameCard.vue'));
import { useLiveGameFeed } from '../../layouts/live-game-feed';
import { scoreboardStatus } from '../../layouts/scoreboard';

const props = defineProps({ game: { type: Object, required: true }, canManageLineups: { type: Boolean, default: false }, detailsUrl: { type: String, default: null } });
const game = ref(props.game);
watch(() => props.game, value => {
  if (Number(game.value.nhl_game_id) !== Number(value.nhl_game_id)) { game.value = value; return; }
  if (game.value.mode !== 'preview' && value.mode === 'preview') return;
  game.value = { ...game.value, ...value, away: { ...game.value.away, ...value.away }, home: { ...game.value.home, ...value.home } };
});
const busy = ref(false);
const mode = computed(() => game.value.mode ?? 'preview');
const sides = ['away', 'home'];
const title = computed(() => `${game.value.away.team_abbrev ?? 'Away'} at ${game.value.home.team_abbrev ?? 'Home'} · ${mode.value === 'recap' ? 'Recap' : mode.value === 'live' ? 'Live' : 'Preview'}`);
const status = computed(() => mode.value === 'live' ? scoreboardStatus(game.value) : game.value.status);
const sections = computed(() => mode.value === 'preview'
  ? [['matchup', 'Matchup'], ['lineups', 'Lineups'], ['meetings', 'Head-to-head'], ['factors', 'Factors']]
  : [['summary', 'Game stats'], ['goals', 'Scoring'], ['boxscore', 'Boxscore'], ['play_by_play', 'Play-by-play']]);
const liveTabs = [['summary', 'Summary'], ['boxscore', 'Boxscore'], ['play_by_play', 'Play-by-play'], ['shot_map', 'Game map'], ['game_flow', 'Game flow']];
const activeTab = ref('summary');
const previewTab = ref('matchup');
function selectPreviewTab(name, focus = false) {
  if (!sections.value.some(([id]) => id === name)) return;
  previewTab.value = name;
  cancelPanels();
  if (!panelReady(name) || (name === 'matchup' && game.value.matchup_expected_pending)) void loadPanel(name);
  if (focus) void nextTick(() => document.getElementById(`preview-tab-${name}`)?.focus());
}
function navigatePreviewTabs(event) {
  const tabs = sections.value;
  const index = tabs.findIndex(([name]) => name === previewTab.value);
  const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length
    : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length
      : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
  if (next === null) return;
  event.preventDefault();
  selectPreviewTab(tabs[next][0], true);
}
function selectLiveTab(name) {
  if (!liveTabs.some(([id]) => id === name)) return;
  activeTab.value = name;
  void nextTick(() => document.getElementById(`tab-${name}`)?.focus());
}
const clockLabel = computed(() => {
  if (mode.value !== 'live') return status.value;
  if (game.value.in_intermission) return status.value;
  return game.value.period_type === 'SO' ? 'Shootout' : game.value.time_remaining ?? 'Live';
});
const periodHeading = computed(() => {
  if (mode.value !== 'live') return 'Game';
  const period = Number(game.value.period_number);
  if (game.value.period_type === 'SO') return 'Shootout';
  return period > 3 ? `${period === 4 ? '' : period - 3}Overtime` : ['1st period', '2nd period', '3rd period'][period - 1] ?? 'In progress';
});
const detailStates = computed(() => Object.fromEntries(sections.value.map(([name]) => [name, panelState(name)])));
function navigateTabs(event) {
  const index = liveTabs.findIndex(([name]) => name === activeTab.value);
  const next = event.key === 'ArrowRight' ? (index + 1) % liveTabs.length
    : event.key === 'ArrowLeft' ? (index + liveTabs.length - 1) % liveTabs.length
      : event.key === 'Home' ? 0 : event.key === 'End' ? liveTabs.length - 1 : null;
  if (next === null) return;
  event.preventDefault();
  activeTab.value = liveTabs[next][0];
  void nextTick(() => document.getElementById(`tab-${activeTab.value}`)?.focus());
}
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
    if (name === 'factors' && game.value.prediction?.revision_id)
      url.searchParams.set('prediction_revision_id', game.value.prediction.revision_id);
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
    if (name === 'matchup' && payload.data.matchup_expected_pending && attempt < 30 && previewTab.value === 'matchup')
      panelTimers.set(name, window.setTimeout(() => { panelTimers.delete(name); void loadPanel(name, attempt + 1); }, 2000));
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
  if (mode.value === 'preview') {
    if (props.detailsUrl) for (const [name] of sections.value) panels[name] = { status: 'loading' };
    void loadPanel(previewTab.value);
    return;
  }
  for (const [name] of sections.value) void loadPanel(name);
}
const number = (value, digits = 2) => value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toFixed(digits);
const probability = (side) => game.value.prediction?.probabilities?.[side] == null ? '—' : `${number(game.value.prediction.probabilities[side] * 100, 1)}%`;
const analysis = computed(() => game.value.preview_analysis ?? { teams: {}, insights: [] });
const analysisTime = value => value ? new Date(/(?:Z|[+-]\d\d:\d\d)$/.test(value) ? value : `${value.replace(' ', 'T')}Z`).toLocaleString() : '—';
const insightValue = (value, percentage) => `${number(value)}${percentage ? '%' : ''}`;
const matchupRows = [['record', 'Season record'], ['l10_record', 'Last 10 Games'], ['h2h_record', 'Head-to-head'],
  ['gf_gp', 'GF/GP'], ['ga_gp', 'GA/GP'], ['xgf_gp', 'xGF/GP'], ['xga_gp', 'xGA/GP'],
  ['pp_pct', 'PP%'], ['pk_pct', 'PK%'], ['pp_opps_gp', 'PP opportunities/GP']];
const matchupValue = (form, key) => key.endsWith('record') ? record(form, key === 'record' ? '' : key.replace('record', ''))
  : form?.[key] == null ? '—' : `${number(form[key])}${key.endsWith('_pct') ? '%' : ''}`;
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
watch(mode, (next, previous) => { if (next === 'preview' || previous === 'preview') activeTab.value = 'summary'; scheduleRefresh(); loadPanels(); }, { flush: 'sync' });
watch(() => props.game.nhl_game_id, () => {
  activeTab.value = 'summary';
  previewTab.value = 'matchup';
  for (const name of Object.keys(panels)) delete panels[name];
  loadPanels();
});
onMounted(() => { scheduleRefresh(); loadPanels(); });
onBeforeUnmount(() => { disposed = true; cancelPanels(); window.clearTimeout(timer); request?.cancel(); });
</script>

<template>
  <Head :title="title" />
  <main class="min-h-screen bg-[#08141E] text-[#E7EEF6]" :class="mode !== 'preview' ? 'lg:h-full lg:min-h-0' : ''">
    <div class="mx-auto max-w-[1536px] px-3 py-3 sm:px-4 sm:py-2" :class="mode !== 'preview' ? 'lg:flex lg:h-full lg:min-h-0 lg:flex-col' : ''">
      <div class="mb-1 flex shrink-0 items-center justify-between gap-3 text-xs">
        <Link :href="game.game_date ? `/games?date=${game.game_date}` : '/games'" class="rounded text-[#91A8BA] transition-colors duration-150 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none">‹ All games</Link>
        <span role="status" class="text-[#91A8BA]">{{ busy ? 'Updating…' : dateTime }}</span>
      </div>
      <p v-if="liveWarning && mode === 'live'" role="status" class="pointer-events-none fixed bottom-24 right-3 z-40 max-w-[calc(100vw-1.5rem)] rounded-md border border-amber-300/25 bg-[#172534] px-3 py-2 text-xs text-amber-300 shadow-lg md:bottom-3">{{ liveWarning }}</p>
      <p v-if="game.live_data_unavailable" role="status" class="rounded-xl border border-[#29404F] bg-[#0D1D29] p-6 text-sm text-[#B6C7D5]">Live NHL data is temporarily unavailable. This page will retry automatically.</p>
      <template v-else>
        <header class="shrink-0 overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]">
          <div class="flex items-center justify-between gap-3 border-b border-[#29404F] bg-[#0B1924] px-4 py-1.5 sm:px-5">
            <h1 class="inline-flex shrink-0 items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-[#B8D5EA] sm:text-xs"><i v-if="mode === 'live'" aria-hidden="true" class="size-3 rounded-full bg-rose-500" />{{ mode === 'recap' ? 'Game recap' : mode === 'live' ? 'Live game' : 'Game preview' }}</h1>
            <span class="inline-flex items-center gap-1.5 text-[10px] font-semibold" :class="mode === 'live' ? 'text-emerald-400' : 'text-[#91A8BA]'"><i v-if="mode === 'live'" aria-hidden="true" class="size-1.5 rounded-full bg-emerald-400" />{{ mode === 'live' ? status : game.status }}<span class="hidden font-normal text-[#A6B9C9] md:inline">｜ {{ game.venue }} · {{ dateTime }}</span></span>
          </div>
          <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 px-3 py-3 sm:gap-5 sm:px-8 sm:py-1 lg:px-20">
            <template v-for="(side, index) in sides" :key="side">
              <div v-if="index === 1" class="max-w-24 text-center sm:max-w-none">
                <div class="rounded-xl border border-[#29404F] bg-[#0A1823] px-2 py-2.5 sm:min-w-36 sm:px-5 sm:py-2">
                  <p v-if="mode !== 'preview'" class="text-[9px] uppercase text-[#B8D5EA] sm:text-xs">{{ periodHeading }}</p>
                  <p class="font-mono text-[13px] font-bold tabular-nums sm:mt-1 sm:text-2xl" :class="mode === 'live' ? 'text-emerald-400' : 'text-[#B8D5EA]'">{{ mode === 'preview' ? 'VS' : clockLabel }}</p>
                </div>
              </div>
              <div class="flex min-w-0 items-center justify-center gap-2 sm:gap-5" :class="side === 'home' ? 'flex-row-reverse' : ''">
                <div class="flex min-w-0 flex-col items-center gap-1.5 text-center sm:gap-3" :class="side === 'home' ? 'sm:flex-row-reverse sm:text-right' : 'sm:flex-row sm:text-left'">
                  <img v-if="game[side].team_logo" :src="game[side].team_logo" alt="" class="size-10 shrink-0 object-contain sm:size-16 lg:size-16" />
                  <div class="min-w-0"><p class="hidden truncate text-base font-bold sm:block lg:text-lg">{{ game[side].team_name ?? game[side].team_abbrev }}</p><p class="font-semibold tracking-wide sm:mt-1 sm:text-xs sm:text-[#91A8BA]">{{ game[side].team_abbrev ?? '—' }}</p><p v-if="mode !== 'preview'" class="mt-1 whitespace-nowrap font-mono text-[10px] text-[#91A8BA]">SOG {{ game[side].sog ?? '—' }}</p></div>
                </div>
                <p v-if="mode !== 'preview'" class="shrink-0 font-mono text-[38px] font-bold leading-none tabular-nums sm:text-5xl lg:text-[56px]">{{ game[side].score ?? '—' }}</p>
                <div v-else class="min-w-0 text-center"><p class="font-mono text-lg font-semibold tabular-nums text-sky-300 sm:text-3xl">{{ probability(side) }}</p><p class="mt-1 text-[8px] uppercase tracking-wider text-[#91A8BA] sm:text-[9px]">Win probability</p></div>
              </div>
            </template>
          </div>
          <div v-if="mode === 'preview'" class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-t border-[#29404F] px-3 py-2.5 text-[10px] text-[#91A8BA] sm:text-xs">
            <span>{{ dateTime }}</span><span v-if="game.venue">· {{ game.venue }}</span>
            <span v-if="mode === 'preview' && !game.prediction">· Prediction not yet available</span>
            <template v-if="mode === 'preview' && game.prediction?.score"><span class="font-mono text-sky-300">Projected score {{ number(game.prediction.score.away) }} – {{ number(game.prediction.score.home) }}</span><span v-if="game.prediction?.total_goals != null" class="font-mono">Total goals {{ number(game.prediction.total_goals) }}</span></template>
          </div>
        </header>
        <div v-if="mode === 'preview'" role="tablist" aria-label="Game sections" class="my-5 flex gap-5 overflow-x-auto border-b border-[#29404F] text-xs font-semibold sm:text-sm" @keydown="navigatePreviewTabs">
          <button v-for="[id, label] in sections" :id="`preview-tab-${id}`" :key="id" type="button" role="tab" :aria-selected="previewTab === id" :aria-controls="`preview-panel-${id}`" :tabindex="previewTab === id ? 0 : -1" class="min-h-9 shrink-0 border-b-2 pb-3 transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" :class="previewTab === id ? 'border-sky-400 text-sky-300' : 'border-transparent text-[#A6B9C9] hover:border-sky-400 hover:text-white'" @click="selectPreviewTab(id)">{{ label }}</button>
        </div>
        <div v-else role="tablist" aria-label="Game sections" class="my-1 flex shrink-0 overflow-x-auto border-b border-[#29404F]" @keydown="navigateTabs">
          <button v-for="[id, label] in liveTabs" :id="`tab-${id}`" :key="id" type="button" role="tab" :aria-selected="activeTab === id" :aria-controls="`panel-${id === 'shot_map' ? 'summary' : id}`" :tabindex="activeTab === id ? 0 : -1" class="min-h-9 shrink-0 border-b-2 px-4 text-xs font-semibold transition-colors duration-200 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sky-400 motion-reduce:transition-none sm:px-6 sm:text-sm" :class="activeTab === id ? 'border-sky-400 text-sky-300' : 'border-transparent text-[#91A8BA]'" @click="activeTab = id">{{ label }}</button>
        </div>
        <Transition v-if="mode === 'preview'" mode="out-in" enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" leave-from-class="opacity-100" leave-to-class="opacity-0">
          <div :id="`preview-panel-${previewTab}`" :key="previewTab" role="tabpanel" :aria-labelledby="`preview-tab-${previewTab}`" tabindex="0">
          <template v-if="previewTab === 'factors'">
          <GameSectionState name="factors" title="Prediction factors" :state="panelState('factors')" @retry="loadPanel('factors')" />
          <section v-if="panelReady('factors')" id="factors" class="rounded-xl border border-[#29404F] bg-[#0D1D29] p-4 sm:p-6">
            <div class="mb-5 border-b border-[#29404F] pb-5" data-prediction-explanation>
              <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 class="text-sm font-semibold">Why this pick</h2><span v-if="analysis.revision_id" class="text-[10px] text-[#91A8BA]">Prediction #{{ analysis.revision_id }} · {{ analysisTime(analysis.predicted_at) }}</span></div>
              <p class="mt-1 text-xs text-[#91A8BA]">Projected team scoring and the players contributing most.</p>
              <div v-if="Object.keys(analysis.teams ?? {}).length" class="mt-4 grid gap-4 lg:grid-cols-2">
                <div v-for="(team, side) in analysis.teams" :key="side" class="min-w-0 rounded-lg border border-[#29404F] bg-[#102330] p-3">
                  <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold text-sky-300">{{ team.abbrev }}</h3><span class="font-mono text-sm">{{ number(team.goals) }} goals</span></div>
                  <p class="mt-1 text-[11px] text-[#91A8BA]">EV {{ number(team.ev_goals) }} · PP {{ number(team.pp_goals) }} · SOG {{ number(team.sog) }}</p>
                  <ol v-if="team.contributors?.length" class="mt-3 divide-y divide-[#29404F]">
                    <li v-for="player in team.contributors" :key="player.id" class="py-3 first:pt-0 last:pb-0">
                      <div class="flex items-baseline justify-between gap-3 text-xs"><span class="min-w-0 font-semibold">{{ player.name }}</span><span class="shrink-0 font-mono text-sky-200">{{ number(player.goals) }} G</span></div>
                      <p class="mt-1 text-[11px] text-[#A6B9C9]">EV {{ number(player.ev_goals) }} · PP {{ number(player.pp_goals) }}<span v-if="player.share_pct != null"> · {{ number(player.share_pct, 1) }}% of team goals</span></p>
                      <dl v-if="player.comparisons?.length" class="mt-2 space-y-2 text-[10px] leading-relaxed">
                        <div v-for="comparison in player.comparisons" :key="comparison.metric"><dt class="font-semibold text-[#A6B9C9]">{{ comparison.metric }}</dt><dd class="mt-0.5 grid grid-cols-2 gap-3"><span><span class="block text-[#91A8BA]">{{ comparison.baseline_label }}</span><b class="font-mono text-[#E7EEF6]">{{ insightValue(comparison.baseline, comparison.is_percentage) }}</b></span><span><span class="block text-[#91A8BA]">{{ comparison.recent_label }}</span><b class="font-mono text-sky-200">{{ insightValue(comparison.recent, comparison.is_percentage) }}</b></span></dd></div>
                      </dl>
                      <p v-else class="mt-1 text-[10px] text-[#91A8BA]">Historical comparisons unavailable for this saved prediction.</p>
                    </li>
                  </ol>
                  <p v-else class="mt-3 text-xs text-[#91A8BA]">Individual contributions unavailable for this saved prediction.</p>
                </div>
              </div>
              <p v-else class="mt-3 text-xs text-[#91A8BA]">Saved prediction details are not yet available.</p>
            </div>
            <div class="mb-5 border-b border-[#29404F] pb-5" data-matchup-insights>
              <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 class="text-sm font-semibold">Matchup insights</h2><span v-if="analysis.context_at" class="text-[10px] text-[#91A8BA]">Context through {{ analysisTime(analysis.context_at) }}</span></div>
              <p class="mt-1 text-xs text-[#91A8BA]">Historical context; these facts do not necessarily influence the prediction.</p>
              <ul v-if="analysis.insights?.length" class="mt-3 grid gap-3 sm:grid-cols-2">
                <li v-for="(fact, index) in analysis.insights" :key="index" class="min-w-0 rounded-lg border border-[#29404F] p-3">
                  <p class="text-xs font-semibold"><span class="mr-2 text-sky-300">{{ fact.team }}</span>{{ fact.name === fact.team ? 'Team' : fact.name }} · {{ fact.metric }}</p>
                  <p class="mt-2 text-xs"><span class="font-mono text-white">{{ insightValue(fact.value, fact.is_percentage) }}</span> <span class="text-[#91A8BA]">{{ fact.period }} vs</span> <span class="font-mono text-white">{{ insightValue(fact.baseline, fact.is_percentage) }}</span> <span class="text-[#91A8BA]">{{ fact.baseline_label }}</span></p>
                  <p class="mt-1 text-[11px] text-[#A6B9C9]">{{ number(fact.delta_pct, 1) }}% {{ fact.direction }} · {{ fact.games }} GP vs {{ fact.baseline_games }} GP</p>
                </li>
              </ul>
              <p v-else class="mt-3 text-xs text-[#91A8BA]">No qualifying differences in the available context. Insights require at least 5 GP and a 25% difference from historical averages.</p>
            </div>
          </section>
          </template>
          <template v-else-if="previewTab === 'matchup'">
          <GameSectionState name="matchup" title="The matchup" :state="panelState('matchup')" @retry="loadPanel('matchup')" />
          <section v-if="panelReady('matchup')" id="matchup" class="rounded-xl border border-[#29404F] bg-[#0D1D29] p-4 sm:p-6">
            <h2 class="text-sm font-semibold">The matchup</h2>
            <p class="mt-1 text-[11px] text-[#91A8BA]">Season to date · Head-to-head record this season</p>
            <p v-if="game.matchup_expected_pending" role="status" class="mt-1 text-[10px] text-[#91A8BA]">Preparing expected-goal statistics…</p>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:gap-8">
              <div v-for="side in sides" :key="side">
                <p class="mb-3 text-xs font-semibold text-sky-300">{{ game[side].team_abbrev }}</p>
                <dl class="space-y-2 text-xs"><div v-for="[key, label] in matchupRows" :key="key" class="flex justify-between gap-2"><dt class="text-[#91A8BA]">{{ label }}</dt><dd class="font-mono">{{ matchupValue(game[side].form, key) }}</dd></div></dl>
                <div class="mt-4 border-t border-[#29404F] pt-3"><p class="text-[10px] uppercase tracking-wider text-[#91A8BA]">Starting goalie</p><p class="mt-1 text-sm font-semibold">{{ game[side].starting_goalie?.name ?? 'Not reported' }}</p><p v-if="game[side].starting_goalie" class="mt-1 text-xs capitalize text-[#91A8BA]">{{ game[side].starting_goalie.status ?? 'Projected' }}</p></div>
              </div>
            </div>
          </section>
          </template>
          <template v-else-if="previewTab === 'lineups'">
          <GameSectionState name="lineups" title="Lineups" :state="panelState('lineups')" @retry="loadPanel('lineups')" />
          <section v-if="panelReady('lineups')" id="lineups" class="mt-5 scroll-mt-5">
            <h2 class="mb-3 text-sm font-semibold">Lineups</h2>
            <details v-if="canManageLineups" class="mb-4 rounded-lg border border-[#29404F] p-3"><summary class="cursor-pointer text-xs font-semibold text-sky-300">Manage lineups and starters</summary><GameCard :game="game" :can-manage-lineups="true" class="mt-3" @lineup-submitted="refresh" @goalie-selected="refresh" /></details>
            <div class="grid gap-4 xl:grid-cols-2"><LineupTeam :team="game.away" side="Away" /><LineupTeam :team="game.home" side="Home" /></div>
          </section>
          </template>
          <template v-else-if="previewTab === 'meetings'">
          <GameSectionState name="meetings" title="Last five meetings" :state="panelState('meetings')" @retry="loadPanel('meetings')" />
          <section v-if="panelReady('meetings')" id="meetings" class="mt-5 scroll-mt-5 rounded-xl border border-[#29404F] bg-[#0D1D29] p-4 sm:p-6">
            <h2 class="mb-3 text-sm font-semibold">Last five meetings</h2>
            <div v-if="game.head_to_head?.length" class="divide-y divide-[#29404F]">
              <Link v-for="meeting in game.head_to_head" :key="meeting.id" :href="`/games/${meeting.id}`" prefetch class="flex items-center justify-between gap-3 rounded py-3 text-xs transition-colors duration-150 hover:text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none"><span class="text-[#91A8BA]">{{ shortDate(meeting.date) }}</span><span>{{ meeting.away }} <b class="font-mono">{{ meeting.away_score }}–{{ meeting.home_score }}</b> {{ meeting.home }}</span><span aria-hidden="true">›</span></Link>
            </div><p v-else class="text-xs text-[#91A8BA]">No previous completed meetings available.</p>
          </section>
          </template>
          </div>
        </Transition>
        <GameLiveDetails v-else :game="game" :tab="activeTab" :states="detailStates" @retry="loadPanel" @navigate="selectLiveTab" />
      </template>
    </div>
  </main>
</template>
