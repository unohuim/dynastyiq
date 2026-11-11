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
    if (['matchup', 'factors'].includes(name) && game.value.prediction?.revision_id)
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
const insightValue = (value, percentage) => value == null ? '—' : `${number(value)}${percentage ? '%' : ''}`;
const matchupRows = [['record', 'Season record'], ['l5_record', 'Last 5 Games'],
  ['venue_season_record', 'Home/Away · Season'], ['venue_l10_record', 'Home/Away · Last 10'], ['venue_l5_record', 'Home/Away · Last 5'],
  ['h2h_record', 'Head-to-head · Last 10'],
  ['gf_gp', 'GF/GP'], ['ga_gp', 'GA/GP'], ['xgf_gp', 'xGF/GP'], ['xga_gp', 'xGA/GP'],
  ['pp_pct', 'PP%'], ['pk_pct', 'PK%'], ['pp_opps_gp', 'PP opportunities/GP'], ['pp_opps_allowed_gp', 'PP opportunities allowed/GP']];
const drivers = computed(() => game.value.prediction_drivers);
const driverRows = [
  ['l5_used', 'Last 5 wins used'], ['ev_form_change_pct', 'EV attempts · Recent form'],
  ['ev_goals_before_form', 'EV goals · Before recent form'], ['ev_goals', 'Projected EV goals'],
  ['pp_offense_l20', 'Lineup PP goals/GP · Last 20'], ['pp_allowed_l5', 'PP goals allowed/GP · Last 5'],
  ['pp_source', 'PP estimate based on'], ['pp_goals', 'Projected PP goals'],
  ['sog', 'Projected SOG'], ['total_goals', 'Projected goals'],
];
const signedPercent = value => value == null ? '—' : `${Number(value) > 0 ? '+' : ''}${number(value, 1)}%`;
const driverValue = (team, key) => key === 'l5_used'
  ? team?.l5_wins != null && team?.l5_games > 0 ? `${team.l5_wins}/${team.l5_games}` : '—'
  : key === 'pp_source' ? team?.pp_source ?? '—'
    : key === 'ev_form_change_pct' ? signedPercent(team?.[key]) : number(team?.[key]);
const scoringKeys = ['gf_gp', 'ga_gp', 'xgf_gp', 'xga_gp'];
const normLabel = trend => trend?.norm_period === 'season_to_date' ? 'This season' : 'Last season';
const scoringChange = (form, key) => scoringKeys.includes(key) ? form?.scoring_trend?.[key]?.change_pct : null;
const venueRecordWindows = { venue_season_record: 'season_to_date', venue_l10_record: 'last_10', venue_l5_record: 'last_5' };
const matchupValue = (form, key) => venueRecordWindows[key]
  ? record(form?.venue_records?.[venueRecordWindows[key]])
  : scoringKeys.includes(key) && form?.scoring_trend
  ? number(form.scoring_trend[key]?.value)
  : key.endsWith('record') ? record(form, key === 'record' ? '' : key.replace('record', ''))
  : form?.[key] == null ? '—' : `${number(form[key])}${key.endsWith('_pct') ? '%' : ''}`;
const record = (form, prefix = '') => form && Number(form[`${prefix}gp`]) > 0 ? `${form[`${prefix}wins`]}–${form[`${prefix}losses`]}–${form[`${prefix}otl`]}` : '—';
const dateTime = computed(() => game.value.start_time_utc ? new Intl.DateTimeFormat(undefined, {
  weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
}).format(new Date(game.value.start_time_utc)) : game.value.game_date ?? '');
const shortDate = (date) => new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(`${date}T12:00:00`));
const playerStat = (player) => player.position === 'G' ? `${player.saves ?? '—'} SV · ${player.goals_against ?? '—'} GA` : `${player.goals ?? '—'} G · ${player.assists ?? '—'} A`;
let request;
let disposed = false;
function refresh() {
  if (disposed || busy.value) return;
  busy.value = true;
  router.reload({ only: ['game'], preserveScroll: true,
    onCancelToken: (token) => { request = token; },
    onFinish: () => { busy.value = false; request = null; loadPanels(); },
  });
}
useLiveGameFeed({
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
watch(mode, (next, previous) => { if (next === 'preview' || previous === 'preview') activeTab.value = 'summary'; loadPanels(); }, { flush: 'sync' });
watch(() => props.game.nhl_game_id, () => {
  activeTab.value = 'summary';
  previewTab.value = 'matchup';
  for (const name of Object.keys(panels)) delete panels[name];
  loadPanels();
});
onMounted(() => { loadPanels(); });
onBeforeUnmount(() => { disposed = true; cancelPanels(); request?.cancel(); });
</script>

<template>
  <Head :title="title" />
  <main class="min-h-screen bg-[#08141E] text-[#E7EEF6]" :class="mode !== 'preview' ? 'lg:h-full lg:min-h-0' : ''">
    <div class="mx-auto max-w-[1536px] px-3 py-3 sm:px-4 sm:py-2" :class="mode !== 'preview' ? 'lg:flex lg:h-full lg:min-h-0 lg:flex-col' : ''">
      <div class="mb-1 flex shrink-0 items-center justify-between gap-3 text-xs">
        <Link :href="game.game_date ? `/games?date=${game.game_date}` : '/games'" class="rounded text-[#91A8BA] transition-colors duration-150 hover:text-white focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none">‹ All games</Link>
        <span role="status" class="text-[#91A8BA]">{{ busy ? 'Updating…' : dateTime }}</span>
      </div>
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

                </div>
              </div>
              <p v-else class="mt-3 text-xs text-[#91A8BA]">Saved prediction details are not yet available.</p>
              <div v-if="Object.keys(analysis.teams ?? {}).length" class="mt-4" data-player-factors>
                <h3 class="text-xs font-semibold">Players · Last 10 Games</h3>
                <p class="mt-1 text-[10px] text-[#91A8BA]">Changes compared with {{ (analysis.baseline_label ?? 'historical seasons').toLowerCase() }}.</p>
                <div v-for="(team, side) in analysis.teams" :key="side" class="mt-3">
                  <h4 class="mb-1 text-[11px] font-semibold text-sky-300">{{ team.abbrev }}</h4>
                  <ol v-if="team.contributors?.length" class="divide-y divide-[#29404F]">
                    <li v-for="player in team.contributors" :key="player.id" class="py-2" data-player-factor>
                      <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-xs">
                        <span class="font-semibold">{{ player.name }}<span v-if="player.points_per_game != null" class="ml-2 font-mono text-[10px] font-normal text-[#A6B9C9]">{{ number(player.points_per_game) }} PTS/GP</span></span>
                        <span class="font-mono text-[10px] text-[#A6B9C9]">{{ number(player.goals) }} projected G · EV {{ number(player.ev_goals) }} · PP {{ number(player.pp_goals) }}</span>
                      </div>
                      <dl v-if="player.comparisons?.length" class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[10px]">
                        <div v-for="comparison in player.comparisons" :key="comparison.metric" class="flex items-baseline gap-1">
                          <dt class="text-[#91A8BA]">{{ comparison.metric }}</dt>
                          <dd class="font-mono">{{ insightValue(comparison.recent, comparison.is_percentage) }}
                            <span v-if="comparison.delta_pct != null" :class="comparison.delta_pct > 0 ? 'text-emerald-400' : comparison.delta_pct < 0 ? 'text-red-400' : 'text-[#91A8BA]'" :aria-label="`${comparison.delta_pct > 0 ? 'Up' : comparison.delta_pct < 0 ? 'Down' : 'Unchanged'} ${number(Math.abs(comparison.delta_pct), 1)}% versus ${comparison.baseline_label}`">({{ comparison.delta_pct > 0 ? '↑' : comparison.delta_pct < 0 ? '↓' : '–' }}{{ number(Math.abs(comparison.delta_pct), 1) }}%)</span>
                          </dd>
                        </div>
                      </dl>
                      <p v-else class="mt-1 text-[10px] text-[#91A8BA]">Historical comparisons unavailable for this saved prediction.</p>
                    </li>
                  </ol>
                  <p v-else class="text-xs text-[#91A8BA]">Individual contributions unavailable for this saved prediction.</p>
                </div>
              </div>
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
            <p class="mt-1 text-[11px] text-[#91A8BA]">GF, GA, xGF and xGA: Last 5 Games, with change versus each team’s norm. Other averages: season to date. Head-to-head: last 10 meetings. Home/Away records: this season’s home games for the host and away games for the visitor.</p>
            <p v-if="game.matchup_expected_pending" role="status" class="mt-1 text-[10px] text-[#91A8BA]">Preparing expected-goal statistics…</p>
            <table class="mx-auto mt-4 w-full max-w-xl table-fixed text-xs" data-matchup-comparison>
              <thead><tr><th scope="col" class="w-[44%] py-2 text-left text-[#91A8BA]">Statistic</th><th v-for="side in sides" :key="side" scope="col" class="py-2 text-center text-sky-300">{{ game[side].team_abbrev }}<span class="ml-1 text-[9px] font-normal text-[#91A8BA]">{{ side === 'home' ? 'Home' : 'Away' }}</span><span v-if="game[side].form?.scoring_trend" class="mt-1 block text-[9px] font-normal text-[#91A8BA]">Norm: {{ normLabel(game[side].form.scoring_trend) }} · {{ game[side].form.scoring_trend.norm_games }} GP</span></th></tr></thead>
              <tbody><tr v-for="[key, label] in matchupRows" :key="key" class="border-t border-[#29404F]/50"><th scope="row" class="py-2 text-left font-normal text-[#91A8BA]">{{ label }}</th><td v-for="side in sides" :key="side" class="py-2 text-center font-mono">{{ matchupValue(game[side].form, key) }}<span v-if="scoringChange(game[side].form, key) != null" class="ml-1 whitespace-nowrap text-[10px] text-[#91A8BA]" :aria-label="`${scoringChange(game[side].form, key) > 0 ? 'Up' : scoringChange(game[side].form, key) < 0 ? 'Down' : 'Unchanged'} ${number(Math.abs(scoringChange(game[side].form, key)), 1)}% versus ${normLabel(game[side].form.scoring_trend)}`">({{ scoringChange(game[side].form, key) > 0 ? '↑' : scoringChange(game[side].form, key) < 0 ? '↓' : '' }}{{ number(Math.abs(scoringChange(game[side].form, key)), 1) }}%)</span></td></tr></tbody>
            </table>
            <div v-if="drivers?.revision_id" class="mx-auto mt-5 max-w-xl border-t border-[#29404F] pt-4" data-prediction-drivers>
              <div class="flex flex-wrap items-baseline justify-between gap-2"><h3 class="text-xs font-semibold">What drives this prediction</h3><span class="text-[10px] text-[#91A8BA]">Prediction #{{ drivers.revision_id }}</span></div>
              <p class="mt-1 text-[10px] text-[#91A8BA]">Saved inputs and scoring effects used for this forecast.</p>
              <table class="mt-2 w-full table-fixed text-[11px]">
                <thead><tr><th scope="col" class="w-[44%] py-2 text-left text-[#91A8BA]">Factor</th><th v-for="side in sides" :key="side" scope="col" class="py-2 text-center text-sky-300">{{ drivers.teams[side]?.abbrev }}</th></tr></thead>
                <tbody><tr v-for="[key, label] in driverRows" :key="key" class="border-t border-[#29404F]/50"><th scope="row" class="py-2 text-left font-normal text-[#91A8BA]">{{ label }}</th><td v-for="side in sides" :key="side" class="break-words py-2 text-center" :class="key === 'pp_source' ? 'text-[10px]' : 'font-mono'">{{ driverValue(drivers.teams[side], key) }}</td></tr>
                  <tr v-if="sides.some(side => drivers.teams[side]?.schedule_applied)" class="border-t border-[#29404F]/50"><th scope="row" class="py-2 text-left font-normal text-[#91A8BA]">B2B · Fourth game in 7</th><td v-for="side in sides" :key="side" class="py-2 text-center text-[10px]"><span v-if="drivers.teams[side]?.schedule_applied">EV/PP attempts: F {{ signedPercent(drivers.teams[side].forward_sat_change_pct) }} · D {{ signedPercent(drivers.teams[side].defense_sat_change_pct) }}</span><span v-else>—</span></td></tr>
                </tbody>
              </table>
              <p v-if="drivers.home_probability_schedule_change_points != null && drivers.home_probability_schedule_change_points !== 0" class="mt-2 text-[10px] text-[#91A8BA]">Schedule adjustment to {{ game.home.team_abbrev }} win probability: {{ drivers.home_probability_schedule_change_points > 0 ? '+' : '' }}{{ number(drivers.home_probability_schedule_change_points, 2) }} percentage points.</p>
            </div>
            <div class="mx-auto mt-4 grid max-w-xl grid-cols-2 gap-4 border-t border-[#29404F] pt-3">
              <div v-for="side in sides" :key="side" class="text-center"><p class="text-[10px] uppercase tracking-wider text-[#91A8BA]">{{ game[side].team_abbrev }} · Starting goalie</p><p class="mt-1 text-sm font-semibold">{{ game[side].starting_goalie?.name ?? 'Not reported' }}</p><p v-if="game[side].starting_goalie" class="mt-1 text-xs capitalize text-[#91A8BA]">{{ game[side].starting_goalie.status ?? 'Projected' }}</p></div>
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
