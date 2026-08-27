<script setup>
import { computed } from 'vue';
import { groupRowsByProspectPosition, isLeagueProspectMode, sortData } from '../../../components/StatsPage/stats-utils.js';
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
    <div v-if="displayRows.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">
      No players match the current view.
    </div>

    <PlayerCard
      v-for="player in displayRows"
      :key="player?.player_id ?? player?.nhl_player_id ?? player?.id ?? player?.name"
      :player="player"
      :headings="headings"
      :settings="settings"
    />
  </div>
</template>
