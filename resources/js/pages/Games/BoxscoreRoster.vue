<script setup>
defineProps({ lineup: { type: Object, default: null } });
const groups = [{ role: 'forward', label: 'Forwards' }, { role: 'defense', label: 'Defensemen' }, { role: 'goalie', label: 'Goalies' }];
</script>

<template>
  <details class="rounded-lg border border-gray-200 bg-gray-50 text-sm">
    <summary class="cursor-pointer rounded-lg px-4 py-3 font-semibold text-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500">NHL boxscore roster</summary>
    <div v-if="lineup?.players?.length" class="space-y-4 px-4 pb-4">
      <section v-for="group in groups" :key="group.role">
        <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ group.label }}</h4>
        <ul class="space-y-1">
          <li v-for="player in lineup.players.filter((row) => row.lineup_role === group.role)" :key="player.nhl_player_id" class="flex items-center gap-2">
            <span class="w-7 text-right tabular-nums text-gray-500">{{ player.sweater_number ?? '—' }}</span>
            <span>{{ player.player_name ?? player.nhl_player_id }}</span>
            <span v-if="player.is_starter" class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Starter</span>
          </li>
        </ul>
      </section>
    </div>
    <p v-else class="px-4 pb-4 text-gray-500">NHL roster not yet available.</p>
  </details>
</template>
