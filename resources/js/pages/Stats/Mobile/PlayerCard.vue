<script setup>
import { computed, ref } from 'vue';
import { deferAvatarImageSrc, formatStatValue, statValueForKey, teamBg } from '../../../components/StatsPage/stats-utils.js';

const SORT_ALIASES = {
  contract_value_num: ['contract_value_num', 'contract_value'],
  contract_last_year_num: ['contract_last_year_num', 'contract_last_year'],
  gp: ['gp', 'games_played'],
};

const MOBILE_IDENTITY_KEYS = new Set([
  'player',
  'name',
  'age',
  'team',
  'league',
  'pos',
  'position',
  'pos_type',
  'contract',
  'contract_value',
  'contract_value_num',
  'contract_last_year',
  'contract_last_year_num',
]);

const props = defineProps({
  player: {
    type: Object,
    required: true,
  },
  headings: {
    type: Array,
    default: () => [],
  },
  settings: {
    type: Object,
    default: () => ({}),
  },
});

const avatarFailed = ref(false);

const playerName = computed(() => props.player?.name ?? 'Unknown');
const avatarUrl = computed(() => props.player?.avatar_url || props.player?.head_shot_url || '');
const displayKey = computed(() => props.settings.displayKey || props.settings.sortKey || firstMobileMetricKey(props.headings, 'gp'));
const statLabel = computed(() => headingLabel(props.headings, displayKey.value));
const statValue = computed(() => formatStatValue(displayKey.value, statValueForKey(props.player, displayKey.value)));
const positionValue = computed(() => displayPosition(props.player?.pos ?? props.player?.position ?? props.player?.pos_type));
const positionType = computed(() => displayPosition(props.player?.pos_type ?? props.player?.type));
const leagueName = computed(() => formatStatValue('league', props.player?.league));
const capLabel = computed(() => {
  const rawCap = props.player?.contract_value;
  let millions = null;

  if (typeof rawCap === 'number') {
    millions = rawCap / 1e6;
  } else if (typeof rawCap === 'string') {
    const parsed = parseFloat(rawCap.replace(/[^0-9.]/g, ''));
    millions = Number.isFinite(parsed) ? (parsed <= 100 ? parsed : parsed / 1e6) : null;
  }

  const lastYear = String(props.player?.contract_last_year ?? '').trim();

  return `$${(millions ?? 0).toFixed(2)}M${lastYear ? ` | ${lastYear}` : ''}`;
});
const selectedStatKeys = computed(() => {
  let statKeys = mobileMetricKeys(props.headings).filter((key) => statValueForKey(props.player, key) !== undefined);

  if (statValueForKey(props.player, 'gp') !== undefined && String(props.settings.sortKey) !== 'gp') {
    statKeys = ['gp', ...statKeys.filter((key) => key !== 'gp')];
  }

  const hiddenAliases = aliasSet(props.settings.sortKey);
  const selectedKeys = [];

  statKeys.forEach((key) => {
    if (!key || selectedKeys.includes(key)) return;
    if (hiddenAliases.has(String(key))) return;
    if (String(key) === String(props.settings.sortKey)) return;
    selectedKeys.push(key);
  });

  return selectedKeys.slice(0, 6);
});

function aliasSet(key) {
  return new Set(SORT_ALIASES[String(key)] || [String(key)]);
}

function mobileMetricKeys(headings) {
  return (Array.isArray(headings) ? headings : [])
    .map((heading) => String(heading?.key ?? ''))
    .filter((key) => key && !MOBILE_IDENTITY_KEYS.has(key));
}

function firstMobileMetricKey(headings, fallback = 'gp') {
  return mobileMetricKeys(headings)[0] || fallback;
}

function headingLabel(headings, key) {
  return (Array.isArray(headings) ? headings : []).find((heading) => heading?.key === key)?.label || key;
}

function displayPosition(raw) {
  return String(raw ?? '').split(/[,\s/]+/).find(Boolean)?.trim().toUpperCase() || '';
}

function playerInitials(name = '') {
  return String(name)
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || '?';
}

function positionClasses(shapeType) {
  if (shapeType === 'F' || shapeType === 'D') {
    return 'inline-flex h-5 w-5 items-center justify-center rounded border text-[8px] font-bold leading-none text-gray-600';
  }

  if (shapeType === 'G') {
    return 'inline-flex h-5 w-5 items-center justify-center rounded-full border-2 text-[8px] font-bold leading-none text-gray-600';
  }

  return 'inline-flex h-5 w-5 items-center justify-center rounded border-2 border-gray-200 text-[8px] font-bold leading-none text-gray-600';
}

function positionBorderColor(shapeType) {
  if (shapeType === 'F') return '#7CCCF2';
  if (shapeType === 'D') return '#FAE919';
  if (shapeType === 'G') return '#fecaca';

  return null;
}

function mountAvatar(img) {
  if (!img) return;

  deferAvatarImageSrc(img, avatarUrl.value);
}
</script>

<template>
  <div class="player-stats-card-mobile" data-vue-stats-mobile-card>
    <div class="player-stats-team-strip-mobile" :style="{ background: teamBg(player?.team) }">
      <div class="player-stats-team-text-mobile">{{ player?.team ?? '-' }}</div>
    </div>

    <div class="player-stats-content-mobile">
      <span class="player-stats-icon-rail-mobile">
        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center self-center">
          <span
            :class="positionClasses(positionType)"
            :style="{ borderColor: positionBorderColor(positionType) }"
          >
            {{ positionValue || positionType || '-' }}
          </span>
        </span>

        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center self-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-500 ring-1 ring-gray-200">
          <img
            v-if="avatarUrl && !avatarFailed"
            :ref="mountAvatar"
            alt=""
            loading="lazy"
            class="h-7 w-7 rounded-full object-cover"
            @error="avatarFailed = true"
          />
          <template v-else>{{ playerInitials(playerName) }}</template>
        </span>
      </span>

      <div class="player-stats-top-row-mobile">
        <div class="min-w-0 flex flex-1 self-stretch">
          <div class="player-stats-identity-mobile">
            <span class="player-stats-name-stack-mobile">
              <span class="player-stats-name-line-mobile">
                <span class="player-stats-name-mobile">{{ playerName }}</span>
              </span>

              <span class="player-stats-detail-line-mobile">
                <span v-if="leagueName" class="player-stats-league-mobile">{{ leagueName }}</span>
                <span class="player-stats-meta-mobile">
                  <div class="player-stats-age-mobile">{{ player?.age ? `AGE ${player.age}` : '' }}</div>
                  <span class="player-stats-aav-mobile">{{ capLabel }}</span>
                </span>
              </span>
            </span>
          </div>
        </div>

        <div class="shrink-0 max-w-[5.5rem] overflow-hidden pt-1">
          <div class="flex min-w-0 shrink-0 items-center gap-1">
            <span class="player-stats-sorted-label-mobile truncate">{{ statLabel }}</span>
            <span class="player-stats-sorted-value-mobile shrink-0">{{ statValue }}</span>
          </div>
        </div>
      </div>

      <div class="player-stats-bottom-row-mobile">
        <div class="player-stats-stat-group-mobile">
          <div
            v-for="key in selectedStatKeys"
            :key="key"
            class="player-stats-stat-mobile"
          >
            <span class="player-stats-stat-key-mobile">{{ headingLabel(headings, key) }}</span>
            <span class="player-stats-stat-val-mobile">{{ formatStatValue(key, statValueForKey(player, key)) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
