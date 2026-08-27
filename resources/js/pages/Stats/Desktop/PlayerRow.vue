<script setup>
import { computed } from 'vue';
import PlayerAvatar from './PlayerAvatar.vue';
import { formatStatValue, statValueForKey, teamBg } from '../../../components/StatsPage/stats-utils.js';

const BORDER_COLOUR_F = '#7CCCF2';
const BORDER_COLOUR_D = '#FAE919';
const BORDER_COLOUR_G = '#fecaca';
const TXT_COLOUR_POS = '#606971';

const props = defineProps({
  row: {
    type: Object,
    required: true,
  },
  headings: {
    type: Array,
    required: true,
  },
  rowIndex: {
    type: Number,
    required: true,
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
  rowClass: {
    type: String,
    default: 'hover:bg-gray-50',
  },
  useRosterSlotColumn: {
    type: Boolean,
    default: false,
  },
});

const rowClasses = computed(() => `grid h-12 border-t px-4 py-2 text-sm transition-colors ${props.rowClass}`);

function isOwnerColumn(key) {
  return String(key) === '__owner';
}

function isCapKey(key = '') {
  return ['aav', 'cap_hit', 'contract_value', 'contract_value_num'].includes(String(key).toLowerCase());
}

function formatCap(value) {
  let number = null;

  if (typeof value === 'number') {
    number = value;
  } else if (typeof value === 'string') {
    const parsed = parseFloat(value.replace(/[$,mM]/g, ''));
    number = Number.isFinite(parsed) ? parsed : null;
  }

  if (number === null) return '';
  if (number > 1000) number /= 1e6;

  return `$${number.toFixed(2)}`;
}

function formatDesktopNumber(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(value);
  }

  if (typeof value === 'string') {
    const trimmed = value.trim();

    if (/^-?\d+(\.\d+)?$/.test(trimmed)) {
      return new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(Number(trimmed));
    }
  }

  return value ?? '';
}

function displayPosition(raw) {
  return String(raw ?? '')
    .split(/[,\s/]+/)
    .find(Boolean)?.trim().toUpperCase() || '';
}

function positionColor() {
  return TXT_COLOUR_POS;
}

function positionClasses(rawType) {
  const shapeType = displayPosition(rawType);
  const base = 'h-full w-full flex items-center justify-center font-semibold text-[9px]';

  if (shapeType === 'F' || shapeType === 'D') {
    return `${base} rounded-[6px] border transform scale-110`;
  }

  if (shapeType === 'G') {
    return `${base} rounded-full border-2`;
  }

  return `${base} rounded border-2`;
}

function positionBorderColor(rawType) {
  const shapeType = displayPosition(rawType);

  if (shapeType === 'F') return BORDER_COLOUR_F;
  if (shapeType === 'D') return BORDER_COLOUR_D;
  if (shapeType === 'G') return BORDER_COLOUR_G;

  return '#e5e7eb';
}

function positionText(row, key) {
  const value = displayPosition(row.pos ?? row.position ?? row[key] ?? row.pos_type ?? row.type);
  const shapeType = displayPosition(row.pos_type ?? row.type);

  return value || shapeType || '-';
}

function cellValue(row, key) {
  return formatStatValue(key, statValueForKey(row, key));
}
</script>

<template>
  <div :class="rowClasses" :style="{ gridTemplateColumns: gridCols }" data-vue-stats-desktop-row>
    <div
      v-for="(heading, index) in headings"
      :key="`${heading.key}:${index}`"
      class="min-w-0"
    >
      <div v-if="heading.key === '__rk'" class="flex items-center justify-center text-gray-500">
        {{ rowIndex + 1 }}
      </div>

      <div
        v-else-if="isOwnerColumn(heading.key)"
        class="sticky right-0 z-10 flex min-w-0 items-center justify-end gap-2 border-l border-gray-100 bg-white pl-3 text-right text-xs text-gray-600"
      >
        <template v-if="String(row?.fantasy_team_name ?? '').trim()">
          <PlayerAvatar
            :avatar-url="String(row?.fantasy_team_avatar_url ?? '').trim()"
            :name="String(row?.fantasy_team_name ?? '').trim()"
          />
          <span class="min-w-0 truncate font-medium" :title="String(row?.fantasy_team_name ?? '').trim()">
            {{ String(row?.fantasy_team_name ?? '').trim() }}
          </span>
        </template>
      </div>

      <div v-else-if="index === teamIdx" class="flex items-center justify-center text-gray-500">
        <div
          v-if="row?.league_roster_placeholder !== true && String(row?.team ?? '').trim() !== ''"
          class="inline-flex h-7 items-center justify-center rounded-md px-3 text-xs font-semibold tracking-wide text-white shadow-sm"
          :style="{ background: teamBg(row?.team) }"
        >
          {{ row?.team ?? '-' }}
        </div>
      </div>

      <div v-else-if="index === leagueIdx" class="flex items-center justify-center whitespace-nowrap text-xs font-semibold text-gray-500">
        {{ cellValue(row, heading.key) }}
      </div>

      <div v-else-if="index === typeIdx" class="flex items-center justify-center text-gray-500">
        <template v-if="useRosterSlotColumn">
          {{ String(row?.roster_slot ?? '').trim() }}
        </template>
        <div v-else class="flex h-8 w-full items-center justify-center">
          <div class="flex h-5 w-5 items-center justify-center">
            <div
              :class="positionClasses(row?.pos_type ?? row?.type)"
              :style="{ borderColor: positionBorderColor(row?.pos_type ?? row?.type), color: positionColor(row?.pos_type ?? row?.type) }"
            >
              {{ positionText(row, heading.key) }}
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="isCapKey(heading.key)" class="flex items-center justify-center whitespace-nowrap text-sm text-gray-500">
        {{ formatCap(statValueForKey(row, heading.key)) }}
      </div>

      <div
        v-else-if="index === playerIdx"
        class="flex min-w-0 items-center justify-start gap-2 overflow-hidden whitespace-nowrap pr-2 text-gray-700"
        :title="String(cellValue(row, heading.key) ?? '')"
      >
        <template v-if="row?.league_roster_placeholder === true">
          <span class="min-w-0 overflow-hidden text-ellipsis text-xs font-medium text-gray-400">
            {{ String(row?.roster_slot ?? '').trim() ? `Open ${String(row?.roster_slot ?? '').trim()}` : 'Open slot' }}
          </span>
        </template>
        <template v-else>
          <PlayerAvatar
            :avatar-url="row?.avatar_url || row?.head_shot_url || ''"
            :name="String(cellValue(row, heading.key) ?? '')"
          />
          <span class="min-w-0 overflow-hidden text-ellipsis">
          {{ cellValue(row, heading.key) }}
          </span>
        </template>
      </div>

      <div
        v-else
        :class="sortKey === heading.key
          ? 'flex items-center justify-center whitespace-nowrap tabular-nums text-[11px] font-semibold leading-5 text-gray-500'
          : 'flex items-center justify-center whitespace-nowrap tabular-nums text-[11px] leading-5 text-gray-500'"
      >
        {{ formatDesktopNumber(cellValue(row, heading.key)) }}
      </div>
    </div>
  </div>
</template>
