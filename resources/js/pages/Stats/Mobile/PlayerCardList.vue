<script setup>
import { computed, ref } from 'vue';
import { formatStatValue, groupRowsByProspectPosition, isLeagueProspectMode, sortData, statValueForKey } from '../../../components/StatsPage/stats-utils.js';
import PlayerCard from './PlayerCard.vue';

const props = defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  headings: {
    type: Array,
    default: () => [],
  },
  settings: {
    type: Object,
    default: () => ({}),
  },
  searchTerm: {
    type: String,
    default: '',
  },
});
const emit = defineEmits(['sort-change']);
const failedImages = ref(new Set());
const identityKeys = new Set(['player', 'name', 'team', 'league', 'pos', 'position', 'pos_type', '__owner', '__rk']);
const supplementalKeys = new Set(['age', 'contract', 'contract_value', 'contract_value_num', 'contract_last_year', 'contract_last_year_num']);
const compactColumns = computed(() => {
  const priority = props.settings.dashboardGoalies ? ['gaa', 'sv_pct', 'wins']
    : props.settings.dashboardProspects ? ['age', 'team', 'pts', 'gp'] : ['pts', 'gp'];
  const available = props.headings.filter(heading => !identityKeys.has(heading.key)
    || (props.settings.dashboardProspects && ['age', 'team'].includes(heading.key)));
  const keys = [...new Set([
    ...(props.settings.leagueUserSortActive ? [props.settings.sortKey] : []),
    ...priority, ...available.filter(heading => !supplementalKeys.has(heading.key)).map(heading => heading.key),
    ...available.map(heading => heading.key),
  ])];
  return keys.map(key => available.find(heading => heading.key === key)).filter(Boolean);
});
const shortName = player => {
  const parts = String(player?.name || 'Unknown').trim().split(/\s+/);
  return parts.length > 1 ? `${parts[0][0]}. ${parts.slice(1).join(' ')}` : parts[0];
};
const initials = player => String(player?.name || '?').trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('');
const identity = player => [!props.settings.dashboardProspects && player.team,
  player.pos || player.position || player.pos_type,
  props.settings.dashboardProspects && player.league].filter(Boolean).join(' · ');
const avatar = player => player.avatar_url || player.head_shot_url;
const compactValue = (player, heading) => {
  const raw = statValueForKey(player, heading.key);
  if (raw === null || raw === undefined || raw === '') return '—';
  if (heading.key === 'sv_pct' && Number.isFinite(Number(raw))) {
    const value = Number(raw);
    return (value > 1 ? value / 100 : value).toFixed(3).replace(/^0\./, '.');
  }
  if (['gaa', 'pts_pg'].includes(heading.key) && Number.isFinite(Number(raw))) return Number(raw).toFixed(2);
  const formatted = formatStatValue(heading.key, raw);
  return typeof formatted === 'number' && !Number.isInteger(formatted) ? formatted.toFixed(2) : formatted;
};
const sortColumn = key => emit('sort-change', { sortKey: key,
  sortDirection: props.settings.sortKey === key ? props.settings.sortDirection === 'desc' ? 'asc' : 'desc'
    : ['name', 'gaa'].includes(key) ? 'asc' : 'desc' });

const filteredRows = computed(() => {
  const term = props.searchTerm.toLowerCase();
  const sorted = sortData(props.rows, props.settings.sortKey, props.settings.sortDirection);

  return sorted.filter((row) => String(row?.name ?? '').toLowerCase().includes(term));
});

const displayRows = computed(() => {
  if (!isLeagueProspectMode(props.settings) || props.settings.leagueUserSortActive === true) {
    return filteredRows.value;
  }

  return groupRowsByProspectPosition(filteredRows.value).flatMap((group) => group.rows);
});
</script>

<template>
  <div data-vue-stats-mobile-card-list>
    <div v-if="displayRows.length === 0" class="px-4 py-6 text-center text-xs text-slate-400">
      No players match the current view.
    </div>

    <div v-if="settings.dashboardTable && displayRows.length" class="overflow-x-auto overscroll-x-contain bg-[#03131f] text-slate-100" tabindex="0" role="region" aria-label="Player statistics; scroll for more columns" data-compact-player-table>
      <table class="w-full border-separate border-spacing-0 text-left text-[11px] tabular-nums">
        <thead class="text-[9px] uppercase tracking-wide text-slate-400">
          <tr class="h-7">
            <th scope="col" class="sticky left-0 z-20 w-6 min-w-6 border-b border-[#143144] bg-[#071d2c] pl-2 pr-1 font-medium">#</th>
            <th scope="col" class="sticky left-6 z-20 min-w-[150px] border-b border-r border-[#143144] bg-[#071d2c] px-1 font-medium" :aria-sort="settings.sortKey === 'name' ? settings.sortDirection === 'asc' ? 'ascending' : 'descending' : 'none'"><button type="button" class="flex h-7 w-full items-center gap-1 focus-visible:ring-2 focus-visible:ring-sky-400" @click="sortColumn('name')">Player</button></th>
            <th v-for="heading in compactColumns" :key="heading.key" scope="col" class="min-w-12 whitespace-nowrap border-b border-[#143144] bg-[#071d2c] px-2 text-right font-medium" :aria-sort="settings.sortKey === heading.key ? settings.sortDirection === 'asc' ? 'ascending' : 'descending' : 'none'"><button type="button" class="h-7 w-full text-right focus-visible:ring-2 focus-visible:ring-sky-400" @click="sortColumn(heading.key)">{{ heading.label }}<span v-if="settings.sortKey === heading.key" class="ml-0.5 text-sky-400" aria-hidden="true">{{ settings.sortDirection === 'asc' ? '↑' : '↓' }}</span></button></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(player, index) in displayRows" :key="player.player_id ?? player.nhl_player_id ?? player.id ?? player.name" class="h-11" data-compact-player-row>
            <td class="sticky left-0 z-10 border-b border-[#143144] bg-[#03131f] pl-2 pr-1 text-[10px] text-slate-400">{{ index + 1 }}</td>
            <th scope="row" class="sticky left-6 z-10 border-b border-r border-[#143144] bg-[#03131f] px-1 py-0 font-normal">
              <div class="flex h-11 items-center gap-1.5">
                <img v-if="avatar(player) && !failedImages.has(avatar(player))" :src="avatar(player)" alt="" loading="lazy" class="h-9 w-8 shrink-0 object-contain object-bottom" @error="failedImages = new Set([...failedImages, avatar(player)])" />
                <span v-else class="flex h-9 w-8 shrink-0 items-center justify-center rounded bg-[#102b3e] text-[10px] text-slate-400" aria-hidden="true">{{ initials(player) }}</span>
                <div class="min-w-0 max-w-[125px]"><p class="truncate text-[11px] font-medium leading-4" :title="player.name">{{ shortName(player) }}</p><p class="truncate text-[9px] leading-3 text-slate-400">{{ identity(player) }}</p></div>
              </div>
            </th>
            <td v-for="heading in compactColumns" :key="heading.key" class="whitespace-nowrap border-b border-[#143144] px-2 text-right" :class="heading.key === settings.sortKey ? 'font-semibold text-white' : 'text-slate-300'">{{ compactValue(player, heading) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <template v-if="!settings.dashboardTable">
    <PlayerCard
      v-for="player in displayRows"
      :key="player?.player_id ?? player?.nhl_player_id ?? player?.id ?? player?.name"
      :player="player"
      :headings="headings"
      :settings="settings"
    />
    </template>
  </div>
</template>
