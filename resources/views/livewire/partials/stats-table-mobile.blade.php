<div
	
	x-model="$store.playerStats.query"
	x-show="$store.playerStats.filteredAndSorted.length"
	class="relative space-y-1"
>
	{{-- 🔍 Search --}}
	<div class="p-3">

		<input
			type="text"
			x-model="$store.playerStats.query"
			placeholder="Filter by player name"
			class="w-full px-4 py-2 text-sm border rounded-full shadow-sm focus:outline-none focus:ring"
		/>
	</div>

	
	{{-- 📋 Player Card Component --}}
<template x-for="player in $store.playerStats.filteredAndSorted" :key="player.player_id">

	<div class="flex min-h-[4rem] overflow-hidden bg-white">
		
		{{-- 🟦 Team Abbreviation Column --}}
		<div class="w-[2rem] flex-none">
			<div
				class="h-full w-full flex items-center justify-center origin-center rotate-[-90deg]
							 text-white text-[1.25rem] font-extrabold leading-none tracking-tight"
				x-init="$el.parentElement.style.background = teamBg(player.player?.team_abbrev)"
				x-text="player.player?.team_abbrev ?? '—'"
			></div>
		</div>

		{{-- 📄 Player Content Area --}}
		<div class="flex-1 px-3 pt-1">
			
			
			<!-- 🧑 Name Row with POS and 🧠 Sorted Stat -->
			<div class="flex justify-between items-start mb-1">
			    <!-- Name + POS -->
			    <div class="flex items-center gap-2">
			        <span class="inline-block bg-gray-100 text-gray-800 text-xs font-bold px-2 py-0.5 rounded">
			            <span x-text="player.player?.pos_type ?? '—'"></span>
			        </span>
			        <span class="font-semibold text-lg leading-tight" x-text="player.player_name"></span>
			    </div>

			    <!-- 🧠 Sorted Stat (label + value, top-right) -->
<div class="flex items-center gap-1 text-sm text-gray-600">
	<span class="uppercase tracking-wide"
		x-text="$store.playerStats.labelMap[$store.playerStats.sortField] ?? $store.playerStats.sortField">
	</span>
	<span class="text-sm font-semibold text-gray-900"
		x-text="Number(player[$store.playerStats.sortField]) % 1 === 0
			? player[$store.playerStats.sortField]
			: Number(player[$store.playerStats.sortField] ?? 0).toFixed(2)">
	</span>
</div>
			</div>



			{{-- 🔢 Bottom Row with Expand and Non-Sorted Stats --}}
			<div 
				class="flex items-center mt-2 text-sm text-gray-800 flex-wrap-reverse"
				:class="player.isMulti ? 'justify-between' : 'justify-end'"
			>
				<!-- Expand Button (only if multi-stats) -->
				<template x-if="player.isMulti">
					<button
						@click="player.expanded = !player.expanded"
						class="text-xs border border-gray-300 rounded px-2 py-0.5 hover:bg-gray-100"
					>
						<span x-text="player.expanded ? '− Season' : '+ Season'"></span>
					</button>
				</template>

				<!-- Remaining Stats (excluding the sorted one) -->
				<div class="flex justify-end gap-4 flex-wrap-reverse">
					<template x-for="(val, key) in player" :key="key">
						<template x-if="['PTS','G','A','GP','age','AVGPTS','avgPTSpGP','AVGTOI'].includes(key) && key !== $store.playerStats.sortField">
							<div class="flex items-center gap-1">
								<span class="uppercase text-gray-500 text-xs"
									x-text="$store.playerStats.labelMap[key] ?? key">
								</span>
								<span class="text-xs font-medium text-gray-800"
									x-text="Number(val) % 1 === 0 ? val : Number(val ?? 0).toFixed(2)">
								</span>
							</div>
						</template>
					</template>
				</div>
			</div>





			{{-- 🔽 Expandable Row (Optional Season Stats) --}}
			<template x-if="$store.playerStats.expanded.includes(player.player_id)">
				<div class="mt-2 text-xs text-gray-600 space-y-1">
					<template x-for="(row, index) in player.stats.slice(1)" :key="index">
						<div class="border-t pt-1">
							<div><strong>Season:</strong> <span x-text="row.season_id"></span></div>
							<div><strong>GP:</strong> <span x-text="row.GP"></span>, <strong>PTS:</strong> <span x-text="row.PTS"></span></div>
						</div>
					</template>
				</div>
			</template>


		</div>
	</div>
</template>





	{{-- ⚙️ FAB Button --}}
	<div class="fixed bottom-4 right-4 z-10">
		<button
			@click="$store.playerStats.sortOpen = !$store.playerStats.sortOpen"
			class="w-12 h-12 rounded-full bg-blue-300 text-white flex items-center justify-center"
			aria-label="Options"
		>
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
				stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
				<path stroke-linecap="round" stroke-linejoin="round"
					d="M6 13.5V3.75m0 9.75a1.5 1.5 0 0 1 0 3m0-3a1.5 1.5 0 0 0 0 3m0 3.75V16.5m12-3V3.75m0 9.75a1.5 1.5 0 0 1 0 3m0-3a1.5 1.5 0 0 0 0 3m0 3.75V16.5m-6-9V3.75m0 3.75a1.5 1.5 0 0 1 0 3m0-3a1.5 1.5 0 0 0 0 3m0 9.75V10.5" />
			</svg>
		</button>
	</div>

	
	{{-- 🔧 Sort Drawer --}}
	<div
		x-show="$store.playerStats.sortOpen"
		x-transition
		class="fixed inset-x-0 bottom-0 z-20 bg-white border-t border-gray-300 p-4 shadow-lg"
	>
		<div class="flex justify-between items-center mb-2">
			<span class="font-semibold text-sm text-gray-800">Sort By</span>
			<button @click="$store.playerStats.sortOpen = false" class="text-gray-500 text-sm">Close</button>
		</div>

		<div class="grid grid-cols-3 gap-2 text-xs">
			<template x-for="field in [
				{ key: 'PTS', label: 'PTS' },
				{ key: 'age', label: 'Age' },
				{ key: 'GP', label: 'GP' },
				{ key: 'avgPTSpGP', label: 'PTS/GP' },
				{ key: 'G', label: 'Goals' },
				{ key: 'A', label: 'Assists' }
			]" :key="field.key">
				<button
					@click="$store.playerStats.toggleSort(field.key)"
					class="flex items-center justify-center gap-1 px-2 py-1 rounded bg-gray-100 text-sm font-medium"
					:class="{
						'border border-green-400 bg-white': $store.playerStats.sortField === field.key
					}"
				>
					<span x-text="field.label"></span>
					<template x-if="$store.playerStats.sortField === field.key">
						<span x-text="$store.playerStats.sortDirection === 'asc' ? '↑' : '↓'"></span>
					</template>
				</button>
			</template>
		</div>
	</div>



	{{-- 🧲 Lazyload Sentinel --}}
	<div x-ref="sentinel" class="h-6"></div>


</div>
