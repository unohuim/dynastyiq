<script setup>
import { Teleport } from 'vue';
import OwnerRow from './OwnerRow.vue';
import PlayerRow from './PlayerRow.vue';
import StatCellsRow from './StatCellsRow.vue';

defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  leftTarget: {
    type: Object,
    required: true,
  },
  statsTarget: {
    type: Object,
    required: true,
  },
  ownerTarget: {
    type: Object,
    required: true,
  },
  leftHeadings: {
    type: Array,
    default: () => [],
  },
  leftGridCols: {
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
  sortKey: {
    type: String,
    default: '',
  },
  useRosterSlotColumn: {
    type: Boolean,
    default: false,
  },
});
</script>

<template>
  <Teleport :to="leftTarget">
    <template v-for="(entry, index) in rows" :key="entry.key ?? index">
      <div
        v-if="entry.type === 'separator'"
        class="grid h-8 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide"
        :class="entry.leftClass"
        :style="{ gridTemplateColumns: leftGridCols }"
        :data-desktop-goalie-header="entry.isGoalieHeader ? 'true' : null"
      >
        <div style="grid-column: 1 / -1">{{ entry.label }}</div>
      </div>
      <PlayerRow
        v-else
        :row="entry.row"
        :headings="leftHeadings"
        :row-index="entry.rowIndex"
        :grid-cols="leftGridCols"
        :team-idx="teamIdx"
        :league-idx="leagueIdx"
        :type-idx="typeIdx"
        :player-idx="playerIdx"
        :sort-key="sortKey"
        :row-class="entry.rowClass"
        :use-roster-slot-column="useRosterSlotColumn"
      />
    </template>
  </Teleport>

  <Teleport :to="statsTarget">
    <template v-for="(entry, index) in rows" :key="entry.key ?? index">
      <div
        v-if="entry.type === 'separator'"
        class="grid h-8 px-4 py-1.5"
        :class="entry.statsClass"
        :style="{ gridTemplateColumns: entry.statsGridCols }"
      >
        <template v-if="entry.headerCells?.length">
          <div
            v-for="(cell, cellIndex) in entry.headerCells"
            :key="cellIndex"
            class="flex items-center justify-center gap-1 overflow-hidden text-ellipsis whitespace-nowrap"
          >
            {{ cell.label }}
          </div>
        </template>
      </div>
      <StatCellsRow
        v-else
        :cells="entry.statCells"
        :grid-cols="entry.statsGridCols"
        :row-class="entry.rowClass"
      />
    </template>
  </Teleport>

  <Teleport :to="ownerTarget">
    <template v-for="(entry, index) in rows" :key="entry.key ?? index">
      <div
        v-if="entry.type === 'separator'"
        class="h-8 px-4 py-1.5"
        :class="entry.statsClass"
      />
      <OwnerRow
        v-else
        :name="entry.ownerName"
        :avatar-url="entry.ownerAvatarUrl"
        :row-class="entry.rowClass"
      />
    </template>
  </Teleport>
</template>
