<script setup>
const props = defineProps({
  team: { type: Object, required: true },
  side: { type: String, required: true },
});

const players = (key) => (props.team.lineup?.players ?? [])
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
      <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="statusClass(team.lineup?.evidence_status)">{{ label(team.lineup?.evidence_status) }}</span>
    </header>

    <div v-if="team.lineup" class="space-y-6 px-5 py-5">
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
      <p class="text-sm font-medium text-gray-900">No anticipated lineup has been reported for {{ team.team_abbrev ?? 'this team' }}.</p>
      <p class="mt-1 text-sm text-gray-500">The latest current projection will appear here after evidence is imported.</p>
    </div>

    <footer v-if="team.lineup" class="border-t border-gray-100 bg-gray-50 px-5 py-4">
      <p class="text-xs text-gray-600">{{ team.lineup.source_count }} source<span v-if="team.lineup.source_count !== 1">s</span> · updated {{ localDateTime(team.lineup.last_observed_at) }}</p>
      <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2">
        <a v-for="source in team.lineup.sources" :key="source.source_id" :href="source.post_url" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500">{{ source.name }}<template v-if="source.handle"> · @{{ source.handle.replace(/^@/, '') }}</template></a>
      </div>
    </footer>
  </article>
</template>
