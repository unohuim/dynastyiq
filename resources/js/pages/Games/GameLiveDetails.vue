<script setup>
import { computed, ref, watch } from 'vue';
import GameSectionState from './GameSectionState.vue';

const props = defineProps({ game: { type: Object, required: true }, tab: { type: String, default: 'summary' }, states: { type: Object, required: true } });
defineEmits(['retry', 'navigate']);
const sides = ['away', 'home'];
const period = ref('all');
const team = ref('all');
const shotType = ref('sog');
const playType = ref('all');
const selectedId = ref(null);
const periodLabel = value => props.game.period_scores?.some(row => Number(row.period) === Number(value) && row.label === 'SO') ? 'SO' : Number(value) > 3 ? (Number(value) === 4 ? 'OT' : `${Number(value) - 3}OT`) : `P${value}`;
const periods = computed(() => [...new Set([...(props.game.plays ?? []), ...(props.game.shots ?? [])].map(row => Number(row.period)))].sort((a, b) => a - b));
const inPeriod = row => period.value === 'all' || Number(row.period) === Number(period.value);
const shots = computed(() => (props.game.shots ?? []).filter(row => inPeriod(row)
  && (team.value === 'all' || row.side === team.value)
  && (shotType.value === 'all' || row.type === 'goal' || (shotType.value === 'sog' && row.type === 'shot-on-goal'))));
const selected = computed(() => shots.value.find(row => String(row.id) === String(selectedId.value)));
const selectedGeometry = computed(() => [
  selected.value?.distance == null ? null : `${Number(selected.value.distance).toFixed(1)} ft`,
  selected.value?.angle == null ? null : `${Number(selected.value.angle).toFixed(1)}°`,
].filter(Boolean).join(' · '));
const plays = computed(() => (props.game.plays ?? []).filter(row => inPeriod(row)
  && (playType.value === 'all' || (playType.value === 'shots' ? ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot'].includes(row.type) : row.type === playType.value))).slice().reverse());
const goals = computed(() => [...(props.game.events ?? [])].reverse());
const state = name => props.states[name] ?? { status: 'ready' };
const ready = name => state(name).status === 'ready';
const sideOf = event => event.side ?? (event.team_id == null ? undefined : sides.find(side => props.game[side]?.team_id != null && Number(props.game[side].team_id) === Number(event.team_id)));
const number = value => value == null ? '—' : value;
const gsaxLabel = value => value == null || !Number.isFinite(Number(value)) ? '—' : `${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}`;
const bar = row => {
  if (row.away == null || row.home == null || Number(row.away) + Number(row.home) === 0) return 50;
  return Math.max(0, Math.min(100, Number(row.away) * 100 / (Number(row.away) + Number(row.home))));
};
const goalieRows = side => (props.game[side]?.boxscore ?? []).filter(row => row.position === 'G' && ((row.toi != null && row.toi !== '00:00') || Number(row.saves) > 0 || Number(row.goals_against) > 0));
// Brighter team accents retain contrast against the dark rink; unknown teams use side colors.
const accents = { ANA: '#FC6B27', ARI: '#CA6976', BOS: '#FFBF3F', BUF: '#FDBB2F', CAR: '#FF2758',
  CBJ: '#7399D2', CGY: '#F13852', CHI: '#F34260', COL: '#AF6687', DAL: '#39C48A', DET: '#F23955',
  EDM: '#FF7B36', FLA: '#E5AE58', LAK: '#BBC4CB', MIN: '#68AD87', MTL: '#F45A74', NJD: '#EF4261',
  NSH: '#FFBF3F', NYI: '#F99140', NYR: '#6096F4', OTT: '#ED5572', PHI: '#FF773F', PIT: '#FFBF3F',
  SEA: '#99D9D9', SJS: '#39B6BD', STL: '#6096EF', TBL: '#699CEB', TOR: '#72A4ED', UTA: '#82B5D8',
  VAN: '#49BC93', VGK: '#D2B578', WPG: '#92AED7', WSH: '#EE5470' };
const color = side => {
  const accent = accents[props.game[side]?.team_abbrev];
  if (side === 'home' && accent && accent === accents[props.game.away?.team_abbrev]) return '#9EDBE8';
  return accent ?? (side === 'away' ? '#66B9F5' : '#FF8A57');
};
const shotPanel = computed(() => props.tab === 'shot_map' ? 'shot_map' : 'summary');
const recent = computed(() => [...(props.game.plays ?? [])].filter(play =>
  ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot', 'penalty', 'hit', 'faceoff', 'giveaway', 'takeaway'].includes(play.type)).reverse().slice(0, 4));
const badge = play => ({ goal: 'GOAL', 'shot-on-goal': 'SOG', 'missed-shot': 'MISS', 'blocked-shot': 'BLK', penalty: 'PEN', hit: 'HIT', faceoff: 'FO', giveaway: 'GIVE', takeaway: 'TAKE' })[play.type] ?? '';
const shotsAgainst = goalie => goalie.shots_against ?? (goalie.saves != null && goalie.goals_against != null ? Number(goalie.saves) + Number(goalie.goals_against) : null);
const saveRate = goalie => goalie.saves != null && shotsAgainst(goalie) > 0 ? Number(goalie.saves) / shotsAgainst(goalie) : null;
const saveLabel = goalie => saveRate(goalie) == null ? '—' : saveRate(goalie).toFixed(3).replace(/^0/, '');
const attempts = computed(() => (props.game.plays ?? []).filter(play => play.period_type !== 'SO'
  && sides.includes(play.side) && ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot'].includes(play.type)
  && /^\d{1,2}:[0-5]\d$/.test(play.time ?? '')).map(play => ({ ...play,
    second: (Number(play.period) - 1) * 1200 + Number(play.time.split(':')[0]) * 60 + Number(play.time.split(':')[1]),
  })).sort((a, b) => a.second - b.second || a.order - b.order));
const flow = computed(() => {
  const maxTime = Math.max(3600, ...attempts.value.map(play => play.second));
  const totals = Object.fromEntries(sides.map(side => [side, attempts.value.filter(play => play.side === side).length]));
  const maxCount = Math.max(5, Math.ceil(Math.max(totals.away, totals.home) / 10) * 10);
  const x = second => 48 + second * 896 / maxTime;
  const y = count => 300 - count * 260 / maxCount;
  return { maxTime, maxCount, totals, ticks: [0, maxCount / 2, maxCount],
    periods: Array.from({ length: Math.floor(maxTime / 1200) + 1 }, (_, index) => ({ x: x(index * 1200), label: index * 20 })),
    lines: sides.map(side => {
      let count = 0;
      const points = [`48,300`];
      for (const play of attempts.value) {
        if (play.side !== side) continue;
        points.push(`${x(play.second)},${y(count)}`, `${x(play.second)},${y(++count)}`);
      }
      // Do not extend evidence into minutes that have not been played.
      if (attempts.value.length) points.push(`${x(attempts.value.at(-1).second)},${y(count)}`);
      return { side, points: points.join(' ') };
    }),
  };
});
watch(() => props.game.nhl_game_id, () => { period.value = 'all'; team.value = 'all'; selectedId.value = null; playType.value = 'all'; shotType.value = 'sog'; });
watch(shots, () => { if (!selected.value) selectedId.value = null; });
</script>

<template>
  <div v-show="tab === 'summary' || tab === 'shot_map'" class="min-h-0 lg:flex-1 lg:overflow-y-auto" role="tabpanel" id="panel-summary" :aria-labelledby="`tab-${shotPanel}`">
    <div class="grid min-h-0 gap-2 lg:h-full" :class="tab === 'summary' ? 'lg:grid-cols-[minmax(0,1.86fr)_minmax(300px,1fr)] lg:grid-rows-[minmax(0,1fr)_minmax(172px,0.5fr)]' : ''">
        <section aria-labelledby="rink-heading" class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#29404F] px-3 py-2">
            <h2 id="rink-heading" class="text-sm font-semibold">Shot map</h2>
            <div class="flex flex-wrap gap-2 text-[11px]">
              <select v-model="period" aria-label="Shot map period" class="rounded-md border-[#385166] bg-[#0A1823] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">All periods</option><option v-for="value in periods" :key="value" :value="String(value)">{{ periodLabel(value) }}</option></select>
              <select v-model="team" aria-label="Shot map team" class="rounded-md border-[#385166] bg-[#0A1823] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">Both teams</option><option v-for="side in sides" :key="side" :value="side">{{ game[side]?.team_abbrev }}</option></select>
              <select v-model="shotType" aria-label="Shot map attempts" class="rounded-md border-[#385166] bg-[#0A1823] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="sog">SOG + goals</option><option value="goal">Goals</option><option value="all">All attempts</option></select>
            </div>
          </div>
          <div class="flex min-h-0 flex-col px-3 pb-2 pt-2 lg:flex-1">
            <svg viewBox="-3 -3 206 91" role="group" aria-labelledby="rink-title rink-description" class="w-full lg:min-h-0 lg:flex-1 lg:basis-0">
              <title id="rink-title">Game shot locations</title><desc id="rink-description">Official rink coordinates. Teams change ends between periods. Select a marker for event details.</desc>
              <rect x="0" y="0" width="200" height="85" rx="22" fill="#0C1C29" stroke="#799BB4" stroke-width=".6" />
              <path d="M75 .5v84M125 .5v84" stroke="#246DCC" stroke-width="1.2" />
              <path d="M100 .5v84M11 9v67M189 9v67" stroke="#F32C60" stroke-width=".55" />
              <circle cx="100" cy="42.5" r="15" fill="none" stroke="#246DCC" stroke-width=".6" /><circle cx="100" cy="42.5" r="1" fill="#F32C60" />
              <g v-for="x in [31, 169]" :key="x" fill="none" stroke="#F32C60" stroke-width=".45"><g v-for="y in [20.5,64.5]" :key="y"><circle :cx="x" :cy="y" r="12" /><path :d="`M${x-3} ${y}h6M${x} ${y-3}v6`" /></g></g>
              <path d="M11 36.5a6 6 0 0 1 0 12zM189 36.5a6 6 0 0 0 0 12z" fill="#254768" stroke="#F32C60" stroke-width=".5" />
              <path d="M11 39.5H8v6h3M189 39.5h3v6h-3" fill="none" stroke="#B9C9D8" stroke-width=".65" />
              <g v-for="shot in shots" :key="shot.id" role="button" tabindex="0" :aria-label="`${shot.player ?? 'Player unavailable'}, ${shot.label}, ${periodLabel(shot.period)} ${shot.time ?? ''}`" :aria-pressed="String(selectedId) === String(shot.id)" class="cursor-pointer focus:outline-none" @focus="selectedId = shot.id" @click="selectedId = shot.id" @keydown.enter.prevent="selectedId = shot.id" @keydown.space.prevent="selectedId = shot.id">
                <title>{{ shot.player ?? 'Player unavailable' }} · {{ shot.label }} · {{ periodLabel(shot.period) }} {{ shot.time }}</title>
                <circle :cx="shot.x + 100" :cy="42.5 - shot.y" r="3.2" fill="transparent" stroke="none" />
                <circle :cx="shot.x + 100" :cy="42.5 - shot.y" :r="shot.type === 'goal' ? 2.5 : 1.2" :fill="color(shot.side)" :stroke="shot.type === 'goal' || String(selectedId) === String(shot.id) ? '#F3F8FC' : '#102132'" :stroke-width="shot.type === 'goal' || String(selectedId) === String(shot.id) ? .85 : .18" :opacity="1" />
              </g>
            </svg>
            <div class="mt-2 grid h-5 shrink-0 grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 text-[10px] text-[#A6B9C9]" aria-label="Shot map footer">
              <div class="flex items-center gap-1.5 whitespace-nowrap sm:gap-3">
                <span v-for="side in sides" :key="side" class="inline-flex items-center gap-1"><svg aria-hidden="true" viewBox="0 0 10 10" class="size-2.5"><circle cx="5" cy="5" r="5" :fill="color(side)" /></svg>{{ game[side]?.team_abbrev }}<span class="hidden lg:inline">shot</span></span>
                <span class="inline-flex items-center gap-1"><i aria-hidden="true" class="size-2 rounded-full border border-white" /><span class="sr-only sm:not-sr-only">Goal</span></span>
              </div>
              <p aria-live="polite" aria-atomic="true" class="min-w-0 truncate text-center" :title="selected ? `${selected.player ?? 'Player unavailable'} · ${selected.label} · ${periodLabel(selected.period)} ${selected.time ?? ''}${selected.detail ? ' · ' + selected.detail : ''}${selectedGeometry ? ' · ' + selectedGeometry : ''}` : undefined">
                <template v-if="selected"><span class="font-semibold text-[#E7EEF6]">{{ selected.player ?? 'Player unavailable' }}</span> · {{ selected.label }} · {{ periodLabel(selected.period) }} {{ selected.time }}<span v-if="selected.detail"> · {{ selected.detail }}</span><span v-if="selectedGeometry"> · {{ selectedGeometry }}</span></template>
                <span v-else class="text-[#7890A4]">Select a shot</span>
              </p>
              <span class="whitespace-nowrap text-right">{{ shots.length }}<span class="sr-only sm:not-sr-only"> shots plotted</span><span class="hidden xl:inline"> · {{ game.mode === 'live' ? 'updates live' : 'final' }}</span></span>
            </div>
            <div v-if="!ready('play_by_play')" class="mt-3 flex items-center justify-between gap-3 text-xs text-[#91A8BA]" :role="state('play_by_play').status === 'error' ? 'alert' : 'status'"><span>{{ state('play_by_play').status === 'error' ? state('play_by_play').message : 'Loading shot locations…' }}</span><button v-if="state('play_by_play').status === 'error'" type="button" class="rounded px-2 py-1 text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400" @click="$emit('retry', 'play_by_play')">Retry</button></div>
            <p v-else-if="!shots.length" class="mt-3 text-xs text-[#91A8BA]">{{ game.plays_available !== true ? 'Shot locations are temporarily unavailable.' : 'No recorded shots match these filters.' }}</p>
          </div>
        </section>

      <aside v-show="tab === 'summary'" class="grid min-h-0 min-w-0 content-start gap-2 lg:grid-rows-[auto_minmax(0,1fr)]">
        <section class="overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]" aria-label="Period scoring">
          <h2 class="border-b border-[#29404F] px-3 py-1 text-xs font-semibold">Linescore</h2>
          <div class="overflow-x-auto"><table class="w-full text-center text-xs"><thead class="bg-[#0A1823] text-xs text-[#AECBE0]"><tr><th scope="col" class="px-4 py-0.5 text-left font-medium"><span class="sr-only">Team</span></th><th v-for="value in game.period_scores ?? []" :key="value.period" scope="col" class="px-2 py-0.5 font-medium">{{ value.label }}</th><th scope="col" class="px-4 py-0.5 font-medium">T</th></tr></thead><tbody class="divide-y divide-[#29404F]"><tr v-for="side in sides" :key="side"><th scope="row" class="px-3 py-1 text-left"><span class="flex items-center gap-3"><img v-if="game[side]?.team_logo" :src="game[side].team_logo" alt="" class="size-4 object-contain" />{{ game[side]?.team_abbrev }}</span></th><td v-for="value in game.period_scores ?? []" :key="value.period" class="px-2 py-1 font-mono text-[#B6C7D5]">{{ number(value[side]) }}</td><td class="px-3 py-1 font-mono text-sm font-bold">{{ number(game[side]?.score) }}</td></tr></tbody></table></div>
        </section>
        <GameSectionState name="summary" title="Team stats" :state="state('summary')" @retry="$emit('retry', 'summary')" />
        <section v-if="ready('summary')" id="summary" class="min-h-0 overflow-y-auto rounded-xl border border-[#29404F] bg-[#0D1D29]">
          <h2 class="border-b border-[#29404F] px-3 py-0.5 text-xs font-semibold">Team stats</h2>
          <div class="space-y-0.5 px-3 py-0.5"><div v-for="row in game.comparison ?? []" :key="row.label">
            <div class="mb-0.5 flex items-center justify-between gap-2 text-[11px] leading-3"><span class="font-mono font-semibold">{{ number(row.away) }}</span><span class="text-[10px] leading-3 text-[#AECBE0]">{{ row.label }}</span><span class="font-mono font-semibold">{{ number(row.home) }}</span></div>
            <svg aria-hidden="true" viewBox="0 0 100 2" preserveAspectRatio="none" class="h-1 w-full overflow-hidden rounded-full"><rect width="100" height="2" fill="#213442" /><template v-if="row.away != null && row.home != null && Number(row.away) + Number(row.home) > 0"><rect width="100" height="2" :fill="color('home')" /><rect :width="bar(row)" height="2" :fill="color('away')" /></template></svg>
          </div><div v-if="game.away?.pp_opportunities != null || game.home?.pp_opportunities != null" class="flex justify-between border-t border-[#29404F] pt-3 text-xs"><span class="font-mono">{{ number(game.away.pp_goals) }}/{{ number(game.away.pp_opportunities) }}</span><span class="text-[#AECBE0]">Power play</span><span class="font-mono">{{ number(game.home.pp_goals) }}/{{ number(game.home.pp_opportunities) }}</span></div></div>
        </section>
      </aside>
      <div v-show="tab === 'summary'" class="grid min-h-0 min-w-0 gap-3 sm:grid-cols-[1.07fr_1fr]">
        <GameSectionState name="goals" title="Scoring summary" :state="state('goals')" @retry="$emit('retry', 'goals')" />
        <section v-if="ready('goals')" id="goals" class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]">
          <div class="flex items-center justify-between gap-2 border-b border-[#29404F] px-3 py-2"><h2 class="text-sm font-semibold">Scoring summary</h2><span class="shrink-0 text-[11px] text-[#AECBE0]">Latest first</span></div>
          <ol v-if="goals.length" class="min-h-0 max-h-64 flex-1 divide-y divide-[#29404F] overflow-y-auto"><li v-for="event in goals" :key="event.id" class="flex items-center gap-3 px-3 py-2"><div class="w-10 shrink-0 font-mono text-xs text-[#AECBE0]"><p>{{ periodLabel(event.period) }}</p><p class="mt-1">{{ event.time ?? '—' }}</p></div><img v-if="game[sideOf(event)]?.team_logo" :src="game[sideOf(event)].team_logo" alt="" class="size-8 shrink-0 object-contain" /><div class="min-w-0 flex-1"><p class="text-[13px] font-semibold">{{ event.scorer }}</p><p class="mt-1 text-[11px] leading-relaxed text-[#AECBE0]">{{ event.assists?.length ? event.assists.join(' · ') : 'Unassisted' }}</p></div><span class="shrink-0 rounded-md border border-[#29404F] bg-[#0A1823] px-2.5 py-2 font-mono text-sm font-semibold">{{ event.away_score ?? '—' }}–{{ event.home_score ?? '—' }}</span></li></ol>
          <p v-else class="p-4 text-xs text-[#AECBE0]">{{ game.events_available === false ? 'Scoring details are temporarily unavailable.' : 'No scoring events available yet.' }}</p>
        </section>
        <section class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]" aria-label="Recent plays">
          <div class="flex items-center justify-between border-b border-[#29404F] px-3 py-1.5"><h2 class="text-xs font-semibold">Recent plays</h2><button type="button" class="rounded text-xs text-[#AECBE0] transition-colors duration-150 hover:text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400 motion-reduce:transition-none" @click="$emit('navigate', 'play_by_play')">View all</button></div>
          <ol v-if="recent.length" class="min-h-0 flex-1 overflow-y-auto divide-y divide-[#29404F]"><li v-for="play in recent" :key="play.id" class="flex items-center gap-2 px-3 py-1"><span class="w-10 shrink-0 font-mono text-[11px] text-[#AECBE0]"><span class="sr-only">{{ periodLabel(play.period) }} </span>{{ play.time }}</span><img v-if="game[play.side]?.team_logo" :src="game[play.side].team_logo" alt="" class="size-6 shrink-0 object-contain" /><div class="min-w-0 flex-1"><p class="text-[11px] leading-3 font-medium">{{ play.label }}</p><p class="mt-0.5 truncate text-[9px] leading-3 text-[#AECBE0]">{{ [play.player, play.detail].filter(Boolean).join(' · ') }}</p></div><span class="shrink-0 rounded-md border px-1.5 py-1 font-mono text-[10px]" :class="play.type === 'goal' || play.type === 'shot-on-goal' ? 'border-rose-500 text-rose-400' : 'border-[#688FA9] text-[#AECBE0]'">{{ badge(play) }}</span></li></ol>
          <p v-else class="p-4 text-xs text-[#AECBE0]">{{ !ready('play_by_play') ? 'Loading recent plays…' : game.plays_available !== true ? 'Recent plays are temporarily unavailable.' : 'No plays recorded yet.' }}</p>
        </section>
      </div>
      <section v-show="tab === 'summary'" class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]" aria-label="Game goalies">
        <div class="flex items-center justify-between border-b border-[#29404F] px-3 py-1.5"><h2 class="text-xs font-semibold">Goalies</h2><div class="grid w-44 shrink-0 grid-cols-4 text-center text-[11px] text-[#AECBE0]"><span>SV</span><span>SA</span><span>SV%</span><span title="Goals saved above expected">GSAx</span></div></div>
        <div class="min-h-0 overflow-y-auto"><template v-for="side in sides" :key="side"><div v-for="goalie in goalieRows(side)" :key="goalie.id" class="flex items-center gap-3 border-b border-[#29404F] px-3 py-1.5 last:border-0"><img v-if="game[side]?.team_logo" :src="game[side].team_logo" alt="" class="size-9 shrink-0 object-contain" /><div class="min-w-0 flex-1"><div class="flex items-center justify-between gap-2"><div class="min-w-0"><p class="truncate text-xs leading-4 font-semibold">{{ goalie.name }}</p><p class="mt-0.5 text-[10px] leading-3 text-[#AECBE0]">{{ game[side]?.team_abbrev }}</p></div><div class="grid w-44 shrink-0 grid-cols-4 text-center font-mono text-xs"><span>{{ number(goalie.saves) }}</span><span>{{ number(shotsAgainst(goalie)) }}</span><span>{{ saveLabel(goalie) }}</span><span title="Goals saved above expected" :class="goalie.gsax != null && Number(goalie.gsax) > 0 ? 'text-emerald-400' : goalie.gsax != null && Number(goalie.gsax) < 0 ? 'text-red-400' : 'text-[#AECBE0]'">{{ gsaxLabel(goalie.gsax) }}</span></div></div><svg aria-hidden="true" viewBox="0 0 100 2" preserveAspectRatio="none" class="mt-1 h-1.5 w-full overflow-hidden rounded-full"><rect width="100" height="2" fill="#213442" /><rect v-if="saveRate(goalie) != null" :width="Math.min(100, Math.max(0, saveRate(goalie) * 100))" height="2" :fill="color(side)" /></svg></div></div></template></div>
        <p v-if="!sides.some(side => goalieRows(side).length)" class="p-4 text-xs text-[#AECBE0]">{{ !ready('boxscore') ? 'Loading goalies…' : 'Goalie statistics are temporarily unavailable.' }}</p>
      </section>
    </div>
  </div>
  <div v-show="tab === 'game_flow'" id="panel-game_flow" class="min-h-0 lg:flex-1 lg:overflow-y-auto" role="tabpanel" aria-labelledby="tab-game_flow">
    <section class="rounded-xl border border-[#29404F] bg-[#0D1D29]" aria-label="Game flow">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#29404F] px-3 py-2"><h2 class="text-sm font-semibold">Game flow · cumulative shot attempts</h2><div class="flex gap-4 text-xs"><span v-for="side in sides" :key="side" class="inline-flex items-center gap-2"><svg viewBox="0 0 10 10" class="size-3" aria-hidden="true"><circle cx="5" cy="5" r="5" :fill="color(side)" /></svg>{{ game[side]?.team_abbrev }} {{ game.plays_available === true ? flow.totals[side] : '—' }}</span></div></div>
      <GameSectionState name="play_by_play" title="Game flow" :state="state('play_by_play')" @retry="$emit('retry', 'play_by_play')" />
      <div v-if="ready('play_by_play') && game.plays_available === true" class="p-3 sm:p-5"><svg viewBox="0 0 970 350" role="img" aria-label="Cumulative shot attempts by elapsed game time" class="w-full"><g v-for="tick in flow.ticks" :key="tick"><line x1="48" x2="944" :y1="300 - tick * 260 / flow.maxCount" :y2="300 - tick * 260 / flow.maxCount" stroke="#29404F" /><text x="36" :y="304 - tick * 260 / flow.maxCount" text-anchor="end" fill="#AECBE0" font-size="13">{{ tick }}</text></g><g v-for="mark in flow.periods" :key="mark.label"><line :x1="mark.x" :x2="mark.x" y1="40" y2="300" stroke="#29404F" stroke-dasharray="3 5" /><text :x="mark.x" y="322" text-anchor="middle" fill="#AECBE0" font-size="13">{{ mark.label }} min</text></g><polyline v-for="line in flow.lines" :key="line.side" :points="line.points" :stroke="color(line.side)" :stroke-dasharray="line.side === 'home' ? '7 3' : undefined" fill="none" stroke-width="3" /></svg><p class="text-xs text-[#AECBE0]">Goals, saved shots, misses and blocked attempts. Shootouts excluded.</p></div>
      <p v-else-if="ready('play_by_play')" class="p-5 text-xs text-[#AECBE0]">Game flow is temporarily unavailable.</p>
    </section>
  </div>
  <div v-show="tab === 'boxscore'" class="min-h-0 lg:flex-1 lg:overflow-y-auto" role="tabpanel" id="panel-boxscore" aria-labelledby="tab-boxscore">
    <GameSectionState name="boxscore" title="Boxscore" :state="state('boxscore')" @retry="$emit('retry', 'boxscore')" />
    <section v-if="ready('boxscore')" id="boxscore" class="grid items-start gap-4 xl:grid-cols-2"><article v-for="side in sides" :key="side" class="min-w-0 overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]"><h2 class="flex items-center gap-2 border-b border-[#29404F] px-3 py-2 text-sm font-semibold"><img v-if="game[side]?.team_logo" :src="game[side].team_logo" alt="" class="size-7 object-contain" />{{ game[side]?.team_name ?? game[side]?.team_abbrev }}</h2><div v-if="game[side]?.boxscore?.length" class="overflow-x-auto"><table class="w-full text-right text-xs"><thead class="bg-[#0A1823] text-[10px] text-[#91A8BA]"><tr><th scope="col" class="px-4 py-3 text-left font-medium">Player</th><th scope="col" class="px-2 font-medium">G / A</th><th scope="col" class="px-2 font-medium">SOG</th><th scope="col" class="px-4 font-medium">TOI</th></tr></thead><tbody class="divide-y divide-[#29404F]"><tr v-for="player in game[side].boxscore" :key="player.id"><th scope="row" class="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-medium">{{ player.name ?? '—' }}<span class="ml-1 text-[9px] text-[#7890A4]">{{ player.position }}</span></th><td class="whitespace-nowrap px-2 font-mono text-[11px]">{{ player.position === 'G' ? `${number(player.saves)} SV · ${number(player.goals_against)} GA` : `${number(player.goals)} / ${number(player.assists)}` }}</td><td class="px-2 font-mono text-[11px]">{{ player.position === 'G' ? '—' : number(player.sog) }}</td><td class="whitespace-nowrap px-4 font-mono text-[11px] text-[#A6B9C9]">{{ player.toi ?? '—' }}</td></tr></tbody></table></div><p v-else class="px-4 py-5 text-xs text-[#91A8BA]">Boxscore details have not been imported yet.</p></article></section>
  </div>
  <div v-show="tab === 'play_by_play'" class="min-h-0 lg:flex-1 lg:overflow-y-auto" role="tabpanel" id="panel-play_by_play" aria-labelledby="tab-play_by_play">
    <GameSectionState name="play_by_play" title="Play-by-play" :state="state('play_by_play')" @retry="$emit('retry', 'play_by_play')" />
    <section v-if="ready('play_by_play')" id="play_by_play" class="overflow-hidden rounded-xl border border-[#29404F] bg-[#0D1D29]">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#29404F] px-3 py-2"><h2 class="text-sm font-semibold">Play-by-play</h2><div class="flex gap-2"><select v-model="period" aria-label="Play-by-play period" class="rounded-md border-[#385166] bg-[#0A1823] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">All periods</option><option v-for="value in periods" :key="value" :value="String(value)">{{ periodLabel(value) }}</option></select><select v-model="playType" aria-label="Play-by-play event type" class="rounded-md border-[#385166] bg-[#0A1823] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">All events</option><option value="goal">Goals</option><option value="shots">Shot attempts</option><option value="penalty">Penalties</option><option value="hit">Hits</option><option value="faceoff">Faceoffs</option></select></div></div>
      <ol v-if="plays.length" class="max-h-[640px] divide-y divide-[#29404F] overflow-y-auto"><li v-for="play in plays" :key="play.id" class="flex items-start gap-3 border-l-2 px-4 py-3" :class="play.type === 'goal' ? 'border-sky-400 bg-sky-400/5' : 'border-transparent'"><div class="w-12 shrink-0 font-mono text-[10px] text-[#91A8BA]"><p>{{ play.period_type === 'SO' ? 'SO' : periodLabel(play.period) }}</p><p class="mt-1">{{ play.time ?? '—' }}</p></div><img v-if="game[play.side]?.team_logo" :src="game[play.side].team_logo" alt="" class="size-7 shrink-0 object-contain" /><div class="min-w-0 flex-1"><p class="text-xs"><b :class="play.type === 'goal' ? 'text-sky-300' : 'text-[#DBE7F1]'">{{ play.label }}</b><span v-if="play.player" class="ml-2">{{ play.player }}</span></p><p v-if="play.detail || play.duration" class="mt-1 text-[10px] text-[#91A8BA]">{{ play.detail }}<span v-if="play.duration"> · {{ play.duration }} min</span></p></div><span v-if="play.type === 'goal'" class="shrink-0 font-mono text-xs font-semibold">{{ number(play.away_score) }}–{{ number(play.home_score) }}</span></li></ol>
      <p v-else class="p-5 text-xs text-[#91A8BA]">{{ game.plays_available !== true ? 'Play-by-play is temporarily unavailable.' : 'No events match these filters.' }}</p>
    </section>
  </div>
</template>
