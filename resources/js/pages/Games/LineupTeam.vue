<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  team: { type: Object, required: true },
  side: { type: String, required: true },
});

const activeTab = ref('lineup');
const lineup = computed(() => props.team.display_lineup ?? props.team.lineup);
const sortKey = ref(null);
const sortDirection = ref(1);
const columns = [
  { key: 'player_name', label: 'Player' },
  { key: 'sat60', label: 'SAT/60' }, { key: 'sat', label: 'SAT/GP' },
  { key: 'sog60', label: 'SOG/60' }, { key: 'sog', label: 'SOG/GP' },
  { key: 'goals60', label: 'G/60' }, { key: 'goals', label: 'G/GP' },
];
const per60 = (value, seconds) => value != null && Number(seconds) > 0 ? Number(value) * 3600 / Number(seconds) : null;
const predictionRows = computed(() => {
  const rows = (props.team.predictions ?? []).map((player) => ({
    ...player,
    sat: player.projected_sat ?? null,
    sog: player.projected_sog ?? null,
    goals: player.projected_goals ?? null,
    sat60: player.projected_sat_per_60 ?? per60(player.projected_sat, player.game_projected_toi_seconds),
    sog60: player.projected_sog_per_60 ?? per60(player.projected_sog, player.game_projected_toi_seconds),
    goals60: player.projected_goals_per_60 ?? per60(player.projected_goals, player.game_projected_toi_seconds),
  }));
  if (!sortKey.value) return rows;
  return rows.sort((a, b) => {
    const left = a[sortKey.value];
    const right = b[sortKey.value];
    if (left == null) return right == null ? 0 : 1;
    if (right == null) return -1;
    return sortDirection.value * (sortKey.value === 'player_name' ? String(left).localeCompare(String(right)) : left - right);
  });
});
const sortBy = (key) => {
  sortDirection.value = sortKey.value === key ? -sortDirection.value : 1;
  sortKey.value = key;
};
const number = (value) => value != null && Number.isFinite(Number(value)) ? Number(value).toFixed(2) : '—';
const tabId = (key) => `team-${props.side.toLowerCase()}-${key}`;
const navigateTabs = (event) => {
  if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
  event.preventDefault();
  activeTab.value = event.key === 'Home' ? 'lineup' : event.key === 'End' ? 'prediction' : activeTab.value === 'lineup' ? 'prediction' : 'lineup';
  event.currentTarget.querySelector(`#${tabId(activeTab.value)}`)?.focus();
};
const players = (key) => (lineup.value?.players ?? [])
  .filter((player) => player.line_key === key)
  .sort((a, b) => a.slot_index - b.slot_index);
const localDateTime = (value) => value
  ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : '';
const statusClass = (status) => ({
  reported: 'border-amber-200 bg-amber-50 text-amber-700',
  corroborated: 'border-sky-200 bg-sky-50 text-sky-700',
  official: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  strongly_corroborated: 'border-emerald-200 bg-emerald-50 text-emerald-700',
}[status] ?? 'border-gray-200 bg-gray-50 text-gray-600');
const label = (value) => String(value ?? 'not_reported')
  .replaceAll('_', ' ')
  .replace(/\b\w/g, (letter) => letter.toUpperCase());
</script>

<template>
  <article class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <header class="flex items-center gap-4 border-b border-gray-100 px-5 py-4">
      <div class="flex size-14 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-50">
        <img v-if="team.team_logo" :src="team.team_logo" :alt="`${team.team_abbrev} logo`" class="size-11 object-contain">
        <span v-else class="text-sm font-semibold text-gray-600">{{ team.team_abbrev ?? 'TBD' }}</span>
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ side }}</p>
        <h2 class="mt-1 text-xl font-semibold text-gray-950">{{ team.team_abbrev ?? 'TBD' }}</h2>
      </div>
      <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="statusClass(lineup?.evidence_status)">{{ label(lineup?.evidence_status) }}</span>
    </header>

    <div role="tablist" :aria-label="`${team.team_abbrev} views`" class="flex gap-6 border-b border-gray-100 px-5" @keydown="navigateTabs">
      <button v-for="tab in ['lineup', 'prediction']" :id="tabId(tab)" :key="tab" type="button" role="tab" :aria-selected="activeTab === tab" :aria-controls="`${tabId(tab)}-panel`" :tabindex="activeTab === tab ? 0 : -1" class="border-b-2 py-3 text-sm font-semibold capitalize transition-colors duration-150 motion-reduce:transition-none" :class="activeTab === tab ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-900'" @click="activeTab = tab">{{ tab }}</button>
    </div>

    <div v-show="activeTab === 'lineup'" :id="`${tabId('lineup')}-panel`" role="tabpanel" :aria-labelledby="tabId('lineup')">
    <p v-if="team.is_projected" class="px-5 pt-4 text-xs text-gray-500">Projected lineup · not reported. Players listed as out are excluded; questionable players remain eligible.</p>
    <div v-if="lineup && lineup.players?.length" class="space-y-6 px-5 py-5">
      <section>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Forwards</h3>
        <div class="mt-3 space-y-2">
          <div v-for="number in 4" :key="`F${number}`" class="grid grid-cols-[2rem_1fr] gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
            <span class="text-xs font-semibold text-gray-500">F{{ number }}</span>
            <div class="grid gap-1 sm:grid-cols-3">
              <span v-for="player in players(`F${number}`)" :key="player.slot_index" class="text-sm font-medium text-gray-900">{{ player.player_name }} <small v-if="player.resolution_status === 'unresolved'" class="text-amber-700">Unresolved</small></span>
              <span v-if="players(`F${number}`).length === 0" class="text-sm text-gray-400">Not reported</span>
            </div>
          </div>
        </div>
      </section>

      <section>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Defence</h3>
        <div class="mt-3 space-y-2">
          <div v-for="number in 3" :key="`D${number}`" class="grid grid-cols-[2rem_1fr] gap-3 rounded-lg bg-gray-50 px-3 py-2.5">
            <span class="text-xs font-semibold text-gray-500">D{{ number }}</span>
            <div class="grid gap-1 sm:grid-cols-2">
              <span v-for="player in players(`D${number}`)" :key="player.slot_index" class="text-sm font-medium text-gray-900">{{ player.player_name }} <small v-if="player.resolution_status === 'unresolved'" class="text-amber-700">Unresolved</small></span>
              <span v-if="players(`D${number}`).length === 0" class="text-sm text-gray-400">Not reported</span>
            </div>
          </div>
        </div>
      </section>

      <section v-for="group in [{ key: 'G', label: 'Goalies' }, { key: 'SCR', label: 'Scratches' }]" :key="group.key">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ group.label }}</h3>
        <div class="mt-2 flex flex-wrap gap-2">
          <span v-for="player in players(group.key)" :key="player.slot_index" class="rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1.5 text-sm font-medium text-gray-900">{{ player.player_name }} <small v-if="player.resolution_status === 'unresolved'" class="text-amber-700">Unresolved</small></span>
          <span v-if="players(group.key).length === 0" class="text-sm text-gray-400">Not reported</span>
        </div>
      </section>
    </div>

    <div v-else class="px-5 py-12 text-center">
      <p class="text-sm font-medium text-gray-900">No lineup or projected roster is available for {{ team.team_abbrev ?? 'this team' }}.</p>
    </div>

    <section class="border-t border-gray-100 px-5 py-4" aria-label="Injured players">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Injuries</h3>
      <ul v-if="team.injuries?.length" class="mt-3 divide-y divide-gray-100">
        <li v-for="(injury, index) in team.injuries" :key="injury.nhl_player_id ?? index" class="py-2 text-sm">
          <div class="flex justify-between gap-3"><span class="font-medium text-gray-900">{{ injury.player_name }}</span><span class="text-xs text-gray-600">{{ label(injury.availability) }}</span></div>
          <p class="mt-1 text-xs text-gray-500">{{ injury.body_part ?? 'Unspecified' }}<template v-if="injury.anticipated_return_text"> · {{ injury.anticipated_return_text }}</template></p>
        </li>
      </ul>
      <p v-else class="mt-2 text-sm text-gray-500">No current injuries listed.</p>
    </section>
    <footer v-if="lineup && !team.is_projected" class="border-t border-gray-100 bg-gray-50 px-5 py-4">
      <p class="text-xs text-gray-600">{{ lineup.source_count }} source<span v-if="lineup.source_count !== 1">s</span> · updated {{ localDateTime(lineup.last_observed_at) }}</p>
      <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2">
        <a v-for="source in lineup.sources" :key="source.source_id" :href="source.post_url" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500">{{ source.name }}<template v-if="source.handle"> · @{{ source.handle.replace(/^@/, '') }}</template></a>
      </div>
    </footer>
    </div>

    <div v-show="activeTab === 'prediction'" :id="`${tabId('prediction')}-panel`" role="tabpanel" :aria-labelledby="tabId('prediction')" class="px-5 py-5">
      <p class="mb-4 text-xs text-gray-500">Player projections for this game{{ team.is_projected ? ' using a projected lineup' : '' }}. /GP uses projected playing time. Missing inputs are shown as —.</p>
      <div v-if="predictionRows.length" class="overflow-x-auto">
        <table class="w-full whitespace-nowrap text-xs tabular-nums">
          <thead><tr class="border-b border-gray-200">
            <th v-for="column in columns" :key="column.key" :aria-sort="sortKey === column.key ? (sortDirection === 1 ? 'ascending' : 'descending') : 'none'" class="px-2 py-2 font-semibold text-gray-500" :class="column.key === 'player_name' ? 'pl-0 text-left' : 'text-right'">
              <button type="button" class="transition-colors duration-150 hover:text-gray-900" @click="sortBy(column.key)">{{ column.label }}<span v-if="sortKey === column.key"> {{ sortDirection === 1 ? '↑' : '↓' }}</span></button>
            </th>
          </tr></thead>
          <tbody><tr v-for="(player, index) in predictionRows" :key="player.nhl_player_id ?? index" class="border-b border-gray-100 last:border-0">
            <td class="py-3 pr-2 text-left"><span class="font-medium text-gray-900">{{ player.player_name }}</span><span class="mt-1 block text-gray-500">{{ player.line_key }} · {{ label(player.projection_source ?? 'unavailable') }}<template v-if="player.model_run_id"> #{{ player.model_run_id }}</template></span></td>
            <td v-for="column in columns.slice(1)" :key="column.key" class="px-2 py-3 text-right text-gray-700">{{ number(player[column.key]) }}</td>
          </tr></tbody>
        </table>
      </div>
      <p v-else class="text-sm text-gray-500">No player projections are available for this lineup.</p>
    </div>
  </article>
</template>
