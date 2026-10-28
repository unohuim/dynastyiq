<script setup>
import { computed, ref, watch } from 'vue';
import GameSectionState from './GameSectionState.vue';

const props = defineProps({ game: { type: Object, required: true }, tab: { type: String, default: 'summary' }, states: { type: Object, required: true } });
defineEmits(['retry']);
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
const plays = computed(() => (props.game.plays ?? []).filter(row => inPeriod(row)
  && (playType.value === 'all' || (playType.value === 'shots' ? ['goal', 'shot-on-goal', 'missed-shot', 'blocked-shot'].includes(row.type) : row.type === playType.value))).slice().reverse());
const goals = computed(() => [...(props.game.events ?? [])].reverse());
const state = name => props.states[name] ?? { status: 'ready' };
const ready = name => state(name).status === 'ready';
const sideOf = event => event.side ?? (event.team_id == null ? undefined : sides.find(side => props.game[side]?.team_id != null && Number(props.game[side].team_id) === Number(event.team_id)));
const number = value => value == null ? '—' : value;
const bar = row => {
  if (row.away == null || row.home == null || Number(row.away) + Number(row.home) === 0) return 50;
  return Math.max(0, Math.min(100, Number(row.away) * 100 / (Number(row.away) + Number(row.home))));
};
const goalieRows = side => (props.game[side]?.boxscore ?? []).filter(row => row.position === 'G' && ((row.toi != null && row.toi !== '00:00') || Number(row.saves) > 0 || Number(row.goals_against) > 0));
watch(() => props.game.nhl_game_id, () => { period.value = 'all'; team.value = 'all'; selectedId.value = null; playType.value = 'all'; shotType.value = 'sog'; });
watch(shots, () => { if (!selected.value) selectedId.value = null; });
</script>

<template>
  <div v-show="tab === 'summary'" role="tabpanel" id="panel-summary" aria-labelledby="tab-summary">
    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(280px,1fr)]">
      <div class="min-w-0 space-y-4">
        <section aria-labelledby="rink-heading" class="overflow-hidden rounded-xl border border-[#29404F] bg-[#172534]">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#29404F] px-4 py-3">
            <h2 id="rink-heading" class="text-sm font-semibold">Shot map</h2>
            <div class="flex flex-wrap gap-2 text-[11px]">
              <select v-model="period" aria-label="Shot map period" class="rounded-md border-[#385166] bg-[#101D2A] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">All periods</option><option v-for="value in periods" :key="value" :value="String(value)">{{ periodLabel(value) }}</option></select>
              <select v-model="team" aria-label="Shot map team" class="rounded-md border-[#385166] bg-[#101D2A] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">Both teams</option><option v-for="side in sides" :key="side" :value="side">{{ game[side]?.team_abbrev }}</option></select>
              <select v-model="shotType" aria-label="Shot map attempts" class="rounded-md border-[#385166] bg-[#101D2A] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="sog">SOG + goals</option><option value="goal">Goals</option><option value="all">All attempts</option></select>
            </div>
          </div>
          <div class="px-3 pb-3 pt-5 sm:px-5">
            <svg viewBox="-3 -3 206 91" role="group" aria-labelledby="rink-title rink-description" class="w-full">
              <title id="rink-title">Game shot locations</title><desc id="rink-description">Official rink coordinates. Teams change ends between periods. Select a marker for event details.</desc>
              <rect x="0" y="0" width="200" height="85" rx="22" fill="#132335" stroke="#61788D" stroke-width=".6" />
              <path d="M75 .5v84M125 .5v84" stroke="#3266A3" stroke-width="1.2" />
              <path d="M100 .5v84M11 9v67M189 9v67" stroke="#A84859" stroke-width=".55" />
              <circle cx="100" cy="42.5" r="15" fill="none" stroke="#3266A3" stroke-width=".6" /><circle cx="100" cy="42.5" r="1" fill="#A84859" />
              <g v-for="x in [31, 169]" :key="x" fill="none" stroke="#A84859" stroke-width=".45"><g v-for="y in [20.5,64.5]" :key="y"><circle :cx="x" :cy="y" r="12" /><path :d="`M${x-3} ${y}h6M${x} ${y-3}v6`" /></g></g>
              <path d="M11 36.5a6 6 0 0 1 0 12zM189 36.5a6 6 0 0 0 0 12z" fill="#254768" stroke="#A84859" stroke-width=".5" />
              <path d="M11 39.5H8v6h3M189 39.5h3v6h-3" fill="none" stroke="#B9C9D8" stroke-width=".65" />
              <g v-for="shot in shots" :key="shot.id" role="button" tabindex="0" :aria-label="`${shot.player ?? 'Player unavailable'}, ${shot.label}, ${periodLabel(shot.period)} ${shot.time ?? ''}`" :aria-pressed="String(selectedId) === String(shot.id)" class="cursor-pointer focus:outline-none" @focus="selectedId = shot.id" @click="selectedId = shot.id" @keydown.enter.prevent="selectedId = shot.id" @keydown.space.prevent="selectedId = shot.id">
                <title>{{ shot.player ?? 'Player unavailable' }} · {{ shot.label }} · {{ periodLabel(shot.period) }} {{ shot.time }}</title>
                <circle :cx="shot.x + 100" :cy="42.5 - shot.y" r="3.2" fill="transparent" stroke="none" />
                <circle :cx="shot.x + 100" :cy="42.5 - shot.y" :r="shot.type === 'goal' ? 1.9 : 1.15" :fill="shot.side === 'away' ? '#55B4FF' : '#F4A364'" :stroke="shot.type === 'goal' || String(selectedId) === String(shot.id) ? '#F3F8FC' : '#102132'" :stroke-width="String(selectedId) === String(shot.id) ? .9 : .4" :opacity="shot.type === 'goal' ? 1 : .8" />
              </g>
            </svg>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-[10px] text-[#A6B9C9]"><div class="flex items-center gap-4"><span class="inline-flex items-center gap-1.5"><i aria-hidden="true" class="size-2 rounded-full bg-[#55B4FF]" />{{ game.away?.team_abbrev }}</span><span class="inline-flex items-center gap-1.5"><i aria-hidden="true" class="size-2 rounded-full bg-[#F4A364]" />{{ game.home?.team_abbrev }}</span><span class="inline-flex items-center gap-1.5"><i aria-hidden="true" class="size-2 rounded-full border border-white" />Goal</span></div><span>{{ shots.length }} plotted · ends change by period</span></div>
            <p v-if="selected" aria-live="polite" class="mt-3 rounded-lg border border-[#385166] bg-[#101D2A] px-3 py-2 text-xs"><span class="font-semibold">{{ selected.player ?? 'Player unavailable' }}</span> · {{ selected.label }} · {{ periodLabel(selected.period) }} {{ selected.time }}<span v-if="selected.detail" class="text-[#91A8BA]"> · {{ selected.detail }}</span></p>
            <div v-if="!ready('play_by_play')" class="mt-3 flex items-center justify-between gap-3 text-xs text-[#91A8BA]" :role="state('play_by_play').status === 'error' ? 'alert' : 'status'"><span>{{ state('play_by_play').status === 'error' ? state('play_by_play').message : 'Loading shot locations…' }}</span><button v-if="state('play_by_play').status === 'error'" type="button" class="rounded px-2 py-1 text-sky-300 focus-visible:ring-2 focus-visible:ring-sky-400" @click="$emit('retry', 'play_by_play')">Retry</button></div>
            <p v-else-if="!shots.length" class="mt-3 text-xs text-[#91A8BA]">{{ game.plays_available !== true ? 'Shot locations are temporarily unavailable.' : 'No recorded shots match these filters.' }}</p>
          </div>
        </section>
        <GameSectionState name="goals" title="Scoring summary" :state="state('goals')" @retry="$emit('retry', 'goals')" />
        <section v-if="ready('goals')" id="goals" class="rounded-xl border border-[#29404F] bg-[#172534]">
          <div class="flex items-center justify-between border-b border-[#29404F] px-4 py-3"><h2 class="text-sm font-semibold">Scoring summary</h2><span class="text-[10px] text-[#91A8BA]">Latest first</span></div>
          <ol v-if="goals.length" class="divide-y divide-[#29404F]"><li v-for="event in goals" :key="event.id" class="flex items-center gap-3 px-4 py-3"><div class="w-12 shrink-0 font-mono text-[10px] text-[#91A8BA]"><p>{{ periodLabel(event.period) }}</p><p class="mt-1">{{ event.time ?? '—' }}</p></div><img v-if="game[sideOf(event)]?.team_logo" :src="game[sideOf(event)].team_logo" alt="" class="size-8 shrink-0 object-contain" /><div class="min-w-0 flex-1"><p class="text-[13px] font-semibold">{{ event.scorer }}</p><p class="mt-1 text-[10px] leading-relaxed text-[#91A8BA]">{{ event.assists?.length ? event.assists.join(' · ') : 'Unassisted' }}</p></div><span class="shrink-0 rounded-md bg-[#101D2A] px-2 py-1.5 font-mono text-xs font-semibold">{{ event.away_score ?? '—' }}–{{ event.home_score ?? '—' }}</span></li></ol>
          <p v-else class="p-4 text-xs text-[#91A8BA]">{{ game.events_available === false ? 'Scoring details are temporarily unavailable.' : 'No scoring events available yet.' }}</p>
        </section>
      </div>
      <aside class="min-w-0 space-y-4">
        <section class="overflow-hidden rounded-xl border border-[#29404F] bg-[#172534]" aria-label="Period scoring">
          <h2 class="border-b border-[#29404F] px-4 py-3 text-sm font-semibold">Linescore</h2>
          <div class="overflow-x-auto"><table class="w-full text-center text-xs"><thead class="bg-[#101D2A] text-[10px] text-[#91A8BA]"><tr><th scope="col" class="px-4 py-3 text-left font-medium">Team</th><th v-for="value in game.period_scores ?? []" :key="value.period" scope="col" class="px-2 py-3 font-medium">{{ value.label }}</th><th scope="col" class="px-4 py-3 font-medium">T</th></tr></thead><tbody class="divide-y divide-[#29404F]"><tr v-for="side in sides" :key="side"><th scope="row" class="px-4 py-3 text-left"><span class="flex items-center gap-2"><img v-if="game[side]?.team_logo" :src="game[side].team_logo" alt="" class="size-6 object-contain" />{{ game[side]?.team_abbrev }}</span></th><td v-for="value in game.period_scores ?? []" :key="value.period" class="px-2 py-3 font-mono text-[#B6C7D5]">{{ number(value[side]) }}</td><td class="px-4 py-3 font-mono text-base font-bold">{{ number(game[side]?.score) }}</td></tr></tbody></table></div>
        </section>
        <GameSectionState name="summary" title="Game stats" :state="state('summary')" @retry="$emit('retry', 'summary')" />
        <section v-if="ready('summary')" id="summary" class="rounded-xl border border-[#29404F] bg-[#172534]">
          <h2 class="border-b border-[#29404F] px-4 py-3 text-sm font-semibold">Team stats</h2>
          <div class="space-y-4 p-4"><div v-for="row in game.comparison ?? []" :key="row.label"><div class="mb-2 flex items-center justify-between gap-2 text-xs"><span class="font-mono font-semibold">{{ number(row.away) }}</span><span class="text-[10px] text-[#91A8BA]">{{ row.label }}</span><span class="font-mono font-semibold">{{ number(row.home) }}</span></div><div aria-hidden="true" class="flex h-1.5 overflow-hidden rounded-full bg-[#29404F]"><span class="bg-[#55B4FF] transition-[width] duration-300 motion-reduce:transition-none" :style="{ width: `${bar(row)}%`, opacity: row.away == null || row.home == null ? 0 : 1 }" /><span class="flex-1 bg-[#F4A364]" :class="row.away == null || row.home == null ? 'opacity-0' : ''" /></div></div><div v-if="game.away?.pp_opportunities != null || game.home?.pp_opportunities != null" class="flex justify-between border-t border-[#29404F] pt-3 text-xs"><span class="font-mono">{{ number(game.away.pp_goals) }}/{{ number(game.away.pp_opportunities) }}</span><span class="text-[10px] text-[#91A8BA]">Power play</span><span class="font-mono">{{ number(game.home.pp_goals) }}/{{ number(game.home.pp_opportunities) }}</span></div></div>
        </section>
        <section v-if="sides.some(side => goalieRows(side).length)" class="rounded-xl border border-[#29404F] bg-[#172534]" aria-label="Game goalies"><h2 class="border-b border-[#29404F] px-4 py-3 text-sm font-semibold">Goalies</h2><div v-for="side in sides" :key="side" class="px-4"><div v-for="goalie in goalieRows(side)" :key="goalie.id" class="flex items-center justify-between gap-3 border-b border-[#29404F] py-3 text-xs last:border-0"><div><p class="font-semibold">{{ goalie.name }}</p><p class="mt-1 text-[10px] text-[#91A8BA]">{{ game[side]?.team_abbrev }}</p></div><span class="font-mono text-[11px] text-[#B6C7D5]">{{ number(goalie.saves) }} SV · {{ number(goalie.goals_against) }} GA</span></div></div></section>
      </aside>
    </div>
  </div>
  <div v-show="tab === 'boxscore'" role="tabpanel" id="panel-boxscore" aria-labelledby="tab-boxscore">
    <GameSectionState name="boxscore" title="Boxscore" :state="state('boxscore')" @retry="$emit('retry', 'boxscore')" />
    <section v-if="ready('boxscore')" id="boxscore" class="grid items-start gap-4 xl:grid-cols-2"><article v-for="side in sides" :key="side" class="min-w-0 overflow-hidden rounded-xl border border-[#29404F] bg-[#172534]"><h2 class="flex items-center gap-2 border-b border-[#29404F] px-4 py-3 text-sm font-semibold"><img v-if="game[side]?.team_logo" :src="game[side].team_logo" alt="" class="size-7 object-contain" />{{ game[side]?.team_name ?? game[side]?.team_abbrev }}</h2><div v-if="game[side]?.boxscore?.length" class="overflow-x-auto"><table class="w-full text-right text-xs"><thead class="bg-[#101D2A] text-[10px] text-[#91A8BA]"><tr><th scope="col" class="px-4 py-3 text-left font-medium">Player</th><th scope="col" class="px-2 font-medium">G / A</th><th scope="col" class="px-2 font-medium">SOG</th><th scope="col" class="px-4 font-medium">TOI</th></tr></thead><tbody class="divide-y divide-[#29404F]"><tr v-for="player in game[side].boxscore" :key="player.id"><th scope="row" class="whitespace-nowrap px-4 py-2.5 text-left text-[11px] font-medium">{{ player.name ?? '—' }}<span class="ml-1 text-[9px] text-[#7890A4]">{{ player.position }}</span></th><td class="whitespace-nowrap px-2 font-mono text-[11px]">{{ player.position === 'G' ? `${number(player.saves)} SV · ${number(player.goals_against)} GA` : `${number(player.goals)} / ${number(player.assists)}` }}</td><td class="px-2 font-mono text-[11px]">{{ player.position === 'G' ? '—' : number(player.sog) }}</td><td class="whitespace-nowrap px-4 font-mono text-[11px] text-[#A6B9C9]">{{ player.toi ?? '—' }}</td></tr></tbody></table></div><p v-else class="px-4 py-5 text-xs text-[#91A8BA]">Boxscore details have not been imported yet.</p></article></section>
  </div>
  <div v-show="tab === 'play_by_play'" role="tabpanel" id="panel-play_by_play" aria-labelledby="tab-play_by_play">
    <GameSectionState name="play_by_play" title="Play-by-play" :state="state('play_by_play')" @retry="$emit('retry', 'play_by_play')" />
    <section v-if="ready('play_by_play')" id="play_by_play" class="overflow-hidden rounded-xl border border-[#29404F] bg-[#172534]">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#29404F] px-4 py-3"><h2 class="text-sm font-semibold">Play-by-play</h2><div class="flex gap-2"><select v-model="period" aria-label="Play-by-play period" class="rounded-md border-[#385166] bg-[#101D2A] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">All periods</option><option v-for="value in periods" :key="value" :value="String(value)">{{ periodLabel(value) }}</option></select><select v-model="playType" aria-label="Play-by-play event type" class="rounded-md border-[#385166] bg-[#101D2A] py-1.5 pl-2 pr-7 text-[11px] text-[#DBE7F1] focus:border-sky-400 focus:ring-sky-400"><option value="all">All events</option><option value="goal">Goals</option><option value="shots">Shot attempts</option><option value="penalty">Penalties</option><option value="hit">Hits</option><option value="faceoff">Faceoffs</option></select></div></div>
      <ol v-if="plays.length" class="max-h-[640px] divide-y divide-[#29404F] overflow-y-auto"><li v-for="play in plays" :key="play.id" class="flex items-start gap-3 border-l-2 px-4 py-3" :class="play.type === 'goal' ? 'border-sky-400 bg-sky-400/5' : 'border-transparent'"><div class="w-12 shrink-0 font-mono text-[10px] text-[#91A8BA]"><p>{{ play.period_type === 'SO' ? 'SO' : periodLabel(play.period) }}</p><p class="mt-1">{{ play.time ?? '—' }}</p></div><img v-if="game[play.side]?.team_logo" :src="game[play.side].team_logo" alt="" class="size-7 shrink-0 object-contain" /><div class="min-w-0 flex-1"><p class="text-xs"><b :class="play.type === 'goal' ? 'text-sky-300' : 'text-[#DBE7F1]'">{{ play.label }}</b><span v-if="play.player" class="ml-2">{{ play.player }}</span></p><p v-if="play.detail || play.duration" class="mt-1 text-[10px] text-[#91A8BA]">{{ play.detail }}<span v-if="play.duration"> · {{ play.duration }} min</span></p></div><span v-if="play.type === 'goal'" class="shrink-0 font-mono text-xs font-semibold">{{ number(play.away_score) }}–{{ number(play.home_score) }}</span></li></ol>
      <p v-else class="p-5 text-xs text-[#91A8BA]">{{ game.plays_available !== true ? 'Play-by-play is temporarily unavailable.' : 'No events match these filters.' }}</p>
    </section>
  </div>
</template>
