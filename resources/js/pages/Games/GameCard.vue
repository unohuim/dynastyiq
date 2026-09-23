<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ManualLineupModal from './ManualLineupModal.vue';

const props = defineProps({ game: { type: Object, required: true }, canManageLineups: { type: Boolean, default: false } });
const isLive = computed(() => props.game.live_mode || (Boolean(props.game.game_state) && !['FUT', 'PRE', 'FINAL'].includes(props.game.game_state)));
const emit = defineEmits(['lineup-submitted']);
const manualTeam = ref(null);
function submitted(lineup) {
  emit('lineup-submitted', lineup);
  manualTeam.value = null;
}

const statusClass = (status) => ({
  reported: 'border-amber-200 bg-amber-50 text-amber-700',
  corroborated: 'border-sky-200 bg-sky-50 text-sky-700',
  official: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  strongly_corroborated: 'border-emerald-200 bg-emerald-50 text-emerald-700',
}[status] ?? 'border-gray-200 bg-gray-50 text-gray-600');
const label = (value) => String(value ?? 'not_reported').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
const goalieStatusClass = (status) => ({
  confirmed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  expected: 'border-sky-200 bg-sky-50 text-sky-700',
}[status] ?? 'border-gray-200 bg-gray-50 text-gray-600');
const localDateTime = (value) => value ? new Intl.DateTimeFormat(undefined, {
  weekday: 'short', month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
}).format(new Date(value)) : 'Start time unavailable';
const gameStateClass = (state) => state === 'FINAL'
  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
  : ['FUT', 'PRE'].includes(state)
    ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
    : 'border-red-200 bg-red-50 text-red-700';
</script>

<template>
  <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <p v-if="game.live_data_unavailable" role="status" class="px-5 py-6 text-sm text-gray-500">Live NHL boxscore temporarily unavailable.</p>
    <template v-else>
    <header class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
      <div><h2 class="text-lg font-semibold text-gray-950">{{ game.away.team_abbrev }} at {{ game.home.team_abbrev }}</h2><p class="mt-1 text-sm text-gray-600">{{ localDateTime(game.start_time_utc) }}</p></div>
      <div class="text-right"><span v-if="game.game_state_label" class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="gameStateClass(game.game_state)">{{ game.game_state_label }}</span><p class="mt-2 text-xs text-gray-400">#{{ isLive ? (game.provider_game_id ?? '—') : game.nhl_game_id }}</p></div>
    </header>
    <div class="divide-y divide-gray-100">
      <section v-for="side in ['away', 'home']" :key="side" class="flex items-center gap-4 px-5 py-4">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50"><img v-if="game[side].team_logo" :src="game[side].team_logo" :alt="`${game[side].team_abbrev} logo`" class="size-9 object-contain"><span v-else>{{ game[side].team_abbrev }}</span></div>
        <div class="min-w-0 flex-1">
          <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ side }} · {{ game[side].team_abbrev }}</p>
          <template v-if="isLive">
            <p class="mt-1 text-2xl font-semibold tabular-nums" :aria-label="`${game[side].team_abbrev} score`">{{ game[side].score ?? '—' }}</p>
            <p class="mt-2 text-xs text-gray-500">SOG: {{ game[side].sog ?? '—' }}</p>
          </template>
          <template v-else>
          <div class="mt-1 flex items-center gap-2"><button v-if="canManageLineups" type="button" :aria-label="`${game[side].lineup ? 'Update' : 'Add'} ${game[side].team_abbrev} lineup`" class="inline-flex rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-semibold text-gray-600 transition-colors duration-150 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-indigo-500 motion-reduce:transition-none" @click="manualTeam = game[side].team_abbrev">{{ game[side].lineup?.manual_override ? 'Manual Override' : label(game[side].lineup?.evidence_status) }}</button><span v-else class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="statusClass(game[side].lineup?.evidence_status)">{{ game[side].lineup?.manual_override ? 'Manual Override' : label(game[side].lineup?.evidence_status) }}</span><span v-if="game[side].lineup" class="text-xs text-gray-500">{{ game[side].lineup.source_count }} source<span v-if="game[side].lineup.source_count !== 1">s</span></span></div>
          <p v-if="game[side].lineup?.last_observed_at" class="mt-2 text-xs text-gray-500">Updated {{ localDateTime(game[side].lineup.last_observed_at) }}</p>
          </template>
        </div>
        <div v-if="isLive" class="space-y-3">
          <div v-for="goalie in game[side].goalies ?? []" :key="goalie.nhl_player_id" class="flex items-center justify-end gap-3">
            <div class="text-right"><p class="max-w-36 truncate text-sm font-semibold">{{ goalie.name ?? '—' }}</p><p class="mt-1 text-xs tabular-nums text-gray-500">GA: {{ goalie.goals_against ?? '—' }} · Saves: {{ goalie.saves ?? '—' }}</p></div>
            <img v-if="goalie.avatar_url" :src="goalie.avatar_url" :alt="goalie.name ?? 'Goalie'" class="size-12 rounded-full border border-gray-200 object-cover">
          </div>
        </div>
        <div v-else-if="game[side].starting_goalie" class="flex items-center gap-3"><div class="text-right"><p class="max-w-36 truncate text-sm font-semibold">{{ game[side].starting_goalie.name }}</p><span class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-xs font-semibold" :class="goalieStatusClass(game[side].starting_goalie.status)">{{ label(game[side].starting_goalie.status ?? 'projected') }}</span></div><img v-if="game[side].starting_goalie.avatar_url" :src="game[side].starting_goalie.avatar_url" :alt="game[side].starting_goalie.name" class="size-12 rounded-full border border-gray-200 object-cover"></div>
        <span v-if="!isLive && game[side].score !== null" class="text-2xl font-semibold tabular-nums">{{ game[side].score }}</span>
      </section>
    </div>
    <footer v-if="!isLive" class="border-t border-gray-100 bg-gray-50 px-5 py-3 text-right"><Link :href="`/games/${game.nhl_game_id}`" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500">View current lineups →</Link></footer>
    </template>
  </article>
  <ManualLineupModal v-if="manualTeam && !isLive" :game-id="Number(game.nhl_game_id)" :team="manualTeam" @close="manualTeam = null" @submitted="submitted" />
</template>
