<script setup>
import PlayerRow from './PlayerRow.vue';

defineProps({
  dark: { type: Boolean, default: false },
  statBarMaximums: { type: Object, default: () => ({}) },
  rows: {
    type: Array,
    default: () => [],
  },
  headings: {
    type: Array,
    default: () => [],
  },
  gridCols: {
    type: String,
    required: true,
  },
  teamIdx: {
    type: Number,
    default: -1,
  },
  leagueIdx: {
    type: Number,
    default: -1,
  },
  typeIdx: {
    type: Number,
    default: -1,
  },
  playerIdx: {
    type: Number,
    default: -1,
  },
  ownerIdx: {
    type: Number,
    default: -1,
  },
  sortKey: {
    type: String,
    default: '',
  },
});
</script>

<template>
  <PlayerRow
    v-for="(row, index) in rows"
    :key="row?.id ?? row?.player_id ?? row?.name ?? index"
    :row="row"
    :headings="headings"
    :row-index="index"
    :grid-cols="gridCols"
    :team-idx="teamIdx"
    :league-idx="leagueIdx"
    :type-idx="typeIdx"
    :player-idx="playerIdx"
    :owner-idx="ownerIdx"
    :sort-key="sortKey"
    :dark="dark"
    :stat-bar-maximums="statBarMaximums"
  />
</template>
